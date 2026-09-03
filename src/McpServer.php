<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Mcp\Exception\JsonRpcException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\JsonObject as ValidationJsonObject;
use Kinetis\Validation\JsonTree;
use Closure;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Handles one decoded JSON-RPC 2.0 message against a registry of
 * #[McpTool]/#[McpResource] definitions and returns the response envelope
 * to encode — or null for a notification, which per JSON-RPC 2.0 gets no
 * response at all. Transport-agnostic on purpose: StdioTransport feeds it
 * one line at a time, Kernel's /mcp endpoint feeds it one HTTP body at a
 * time, and both just encode whatever comes back.
 *
 * A tool or resource method *executing* and failing (a thrown exception,
 * a failed validation) is reported as a normal JSON-RPC result with
 * isError:true in the content — that's the MCP convention, so the calling
 * agent sees "the tool ran but failed" rather than a transport-level RPC
 * error. Only protocol-level problems (unknown method, unknown tool name,
 * malformed request) become a JSON-RPC `error` response. Anything
 * unexpected is caught at the top of handle() as -32603 Internal error —
 * a stdio transport is a long-running process; one bad request must not
 * be able to crash it, and the client-facing envelope carries a fixed,
 * generic message rather than the caught exception's own — its text can
 * carry SQL, a path, or a credential, none of which belongs to a remote
 * caller, the identical containment callTool()'s own generic "Tool
 * execution failed." already applies. Both this catch and callTool()'s
 * own log the real exception via $logger (a NullLogger by default, since
 * McpServer is constructed directly by the consumer — bin/kinetis, a
 * Kernel's $mcp param — rather than through the container, so there's
 * no AppScope-registered LoggerInterface to autowire the way
 * ExceptionHandlerMiddleware gets one) — through logSafely(), which
 * discards a logger's own failure rather than letting it escape and
 * turn an observability problem into the very crash this class exists
 * to prevent.
 */
final class McpServer
{
    /**
     * The 2026-07-28 revision's stateless, per-request model: every
     * request carries its own protocol version and capabilities in
     * `params._meta`, and there is no connection-level negotiation — no
     * initialize/notifications-initialized handshake, no `ping`. This is
     * the only protocol era this server implements.
     */
    private const PROTOCOL_VERSION = '2026-07-28';

    private const META_PROTOCOL_VERSION_KEY = 'io.modelcontextprotocol/protocolVersion';
    private const META_CLIENT_CAPABILITIES_KEY = 'io.modelcontextprotocol/clientCapabilities';
    private const META_SERVER_INFO_KEY = 'io.modelcontextprotocol/serverInfo';

    /**
     * A freshness hint, not a guarantee — servers may change the underlying
     * data before this elapses; it only tells a client how long it can
     * reasonably avoid re-fetching. One hour is a plain, reasonable
     * default for data that changes at the pace of a deployment (which
     * tools/resources exist), not the pace of a single request.
     */
    private const int CACHE_TTL_MS = 3_600_000;

    /**
     * Per the spec's own "Cacheable Results" list, caching hints are
     * required on server/discover, tools/list, and resources/read (this
     * server never implements prompts/list or resources/templates/list) —
     * and only those; tools/call is an action, not a cacheable read, and
     * must not carry one. tools/list and resources/list reflect this
     * server's own registered #[McpTool]/#[McpResource] methods, identical
     * for every caller, so "public" is accurate; resources/read defaults
     * to "private" since a consumer's own resource method could return
     * caller-specific content this server has no way to know about.
     *
     * @var array<string, string>
     */
    private const array CACHEABLE_METHOD_SCOPES = [
        'server/discover' => 'public',
        'tools/list' => 'public',
        'resources/list' => 'public',
        'resources/read' => 'private',
    ];

    public function __construct(
        private readonly McpRegistry $registry,
        private readonly McpDispatcher $dispatcher,
        private readonly string $serverName = 'Kinetis',
        private readonly string $serverVersion = '1.0.0',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $instructions = null,
    ) {}

    /**
     * $message may carry `params` in either shape JsonRpcCodec::decode()/
     * McpServer::handle() work with — the raw, not-yet-flattened value
     * decode() hands back, or an already-flattened plain array from a
     * direct caller — objectGet() reads either correctly.
     *
     * @param array<string, mixed> $message
     */
    public static function requestedProtocolVersion(array $message): ?string
    {
        $params = $message['params'] ?? [];
        $meta = JsonRpcCodec::objectGet($params, '_meta') ?? [];
        $version = JsonRpcCodec::objectGet($meta, self::META_PROTOCOL_VERSION_KEY);

        return is_string($version) ? $version : null;
    }

    /**
     * The full request preflight: JsonRpcCodec's own envelope rules, plus
     * every MCP-specific nested requirement handle() would otherwise only
     * discover once dispatch had already begun — `_meta`'s own shape and
     * required keys, `clientCapabilities`' shape, and (method-specific)
     * `tools/call`'s `name`/`arguments`/`progressToken` or
     * `resources/read`'s `uri`. Side-effect-free: never invokes a tool or
     * resource, never emits progress, never opens a stream. `handle()`
     * runs this unconditionally as its first step; `Http\McpController`
     * also runs it directly, before the mirrored-header check and stream
     * selection, so a malformed nested value can never surface as a
     * header mismatch or open an SSE stream it will only reject once
     * dispatched anyway.
     *
     * $message's `params`, like requestedProtocolVersion()'s, may be
     * either shape — every read here goes through JsonRpcCodec's object
     * accessors specifically so a caller reaching this via decode()'s raw,
     * not-yet-flattened `params` still gets the full-fidelity shape
     * checks its own docblock describes, not the array-boundary's more
     * tolerant one.
     *
     * A structurally invalid envelope (JsonRpcCodec::validateMessage()'s
     * own concern) always produces a response, regardless of `id` — the
     * envelope is exactly what would tell us whether "no id" means
     * notification, so it can't be trusted to mean that when it's the
     * thing that's broken. Once the envelope is confirmed valid, `id`'s
     * presence is reliable, and a nested MCP-specific failure past that
     * point is suppressed entirely for a notification — no response, and
     * (see PreflightResult's own docblock for why this has to be a
     * distinct outcome from "valid") no dispatch either.
     *
     * @param array<string, mixed> $message
     */
    public function preflight(array $message): PreflightResult
    {
        $envelopeError = JsonRpcCodec::validateMessage($message);

        if ($envelopeError !== null) {
            return PreflightResult::respond($envelopeError);
        }

        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $method = $message['method'];
        $params = $message['params'] ?? [];

        try {
            $meta = $this->requireNamedObject($params, '_meta');
            $this->validateMeta($meta);

            match ($method) {
                'tools/call' => $this->preflightToolCall($params, $meta),
                'resources/read' => $this->preflightReadResource($params),
                default => null,
            };
        } catch (JsonRpcException $e) {
            return $hasId
                ? PreflightResult::respond(JsonRpcCodec::errorEnvelope($id, $e))
                : PreflightResult::suppress();
        }

        return PreflightResult::proceed();
    }

    /**
     * Reads a named-object member of $container — `_meta` off `params`,
     * `arguments` off `params`, or `clientCapabilities` off `_meta` —
     * tolerating an absent value as "none given" but rejecting a present
     * one of the wrong shape (JsonRpcCodec::isStrictJsonObject()) rather
     * than silently coercing it to an empty array. The returned value is
     * deliberately not flattened — a caller validating a member nested
     * inside it needs the same raw-shape fidelity this method itself
     * relied on to check $container.
     */
    private function requireNamedObject(mixed $container, string $key): mixed
    {
        if (!JsonRpcCodec::objectHas($container, $key)) {
            return [];
        }

        $value = JsonRpcCodec::objectGet($container, $key);

        if (!JsonRpcCodec::isStrictJsonObject($value)) {
            throw JsonRpcException::invalidParams("The \"{$key}\" member must be an object.");
        }

        return $value;
    }

    /**
     * `tools/call`'s own preflight: `name` is required and must be a
     * non-empty string — checked here, not left to callTool()'s own
     * "Unknown tool" lookup, specifically so a missing/wrong-typed/empty
     * name is caught before Http\McpController ever decides whether to
     * open the SSE stream, not discovered one event after it already has.
     * A well-formed but unregistered name is deliberately *not* rejected
     * here — that's a semantic lookup callTool() itself still performs,
     * since preflight() only checks shape, never registry membership.
     * `arguments`, when present, must be object-shaped;
     * `_meta.progressToken`, when present, must be the same string/
     * integer domain McpServer's own token type supports — an absent one
     * silently means "no progress requested," matching every other
     * optional field here, but a present, wrong-typed one is rejected
     * rather than silently disabling progress: a client that explicitly
     * asked for it deserves an error, not quiet non-reporting it can't
     * tell apart from a tool that simply never reports any.
     */
    private function preflightToolCall(mixed $params, mixed $meta): void
    {
        $name = JsonRpcCodec::objectGet($params, 'name');

        if (!is_string($name) || $name === '') {
            throw JsonRpcException::invalidParams('The "name" member is required and must be a non-empty string.');
        }

        $this->requireNamedObject($params, 'arguments');

        if (JsonRpcCodec::objectHas($meta, 'progressToken')) {
            $token = JsonRpcCodec::objectGet($meta, 'progressToken');

            if (!is_string($token) && !is_int($token)) {
                throw JsonRpcException::invalidParams('The "progressToken" member must be a string or integer.');
            }
        }
    }

    /**
     * `resources/read`'s own preflight — `uri` is required and must be a
     * non-empty string, the same "shape only, never registry membership"
     * split preflightToolCall()'s own docblock explains: a well-formed
     * but unregistered uri is left to readResource()'s own lookup.
     */
    private function preflightReadResource(mixed $params): void
    {
        $uri = JsonRpcCodec::objectGet($params, 'uri');

        if (!is_string($uri) || $uri === '') {
            throw JsonRpcException::invalidParams('The "uri" member is required and must be a non-empty string.');
        }
    }

    /**
     * $onNotification, when given, is called synchronously and inline with
     * one `notifications/progress` params payload each time a `tools/call`
     * target that took a ProgressReporter parameter calls report() — see
     * ProgressReporter's own docblock for why this needs no coroutine
     * machinery. Transports decide what "sending a notification" means:
     * StdioTransport writes one more JSON-RPC line; Kernel's HTTP endpoint
     * writes one more SSE event. Omitting it (the default) behaves exactly
     * as before — report() calls become no-ops.
     *
     * $scope, when given, is the per-message scope the transport created
     * for this one message — tool and resource controllers resolve from
     * it, and the transport disposes it once the response is written.
     * Omitted, the dispatcher's own container is used, which is not
     * per-message-scoped.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, ?Closure $onNotification = null, ?ContainerInterface $scope = null): ?array
    {
        // Defends this public array boundary itself, rather than trusting
        // that a caller already ran the message through JsonRpcCodec::
        // decode() and McpServer::preflight() — the same shared rules
        // either way, so a message built directly (bypassing both
        // transports) gets identical treatment. See preflight()'s own
        // docblock for the structural-failure-vs-notification-suppression
        // split PreflightResult exists to carry correctly.
        $preflight = $this->preflight($message);

        if (!$preflight->shouldDispatch) {
            return $preflight->response;
        }

        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        /** @var string $method validated as a string by preflight() above */
        $method = $message['method'];
        // preflight() has already validated every shape this touches —
        // params itself, and (for the methods that need them) _meta,
        // clientCapabilities, arguments, and progressToken — so this is
        // a plain conversion, not a second validation pass.
        /** @var array<string, mixed> $params */
        $params = JsonRpcCodec::toArrayDeep($message['params'] ?? []);

        // toArrayDeep() above is correct for every other params member —
        // _meta/clientCapabilities/progressToken never reach Hydrator, so
        // flattening them loses nothing real — but arguments is the one
        // member that does, via McpDispatcher, and Hydrator's own array/
        // iterable type-mismatch check needs the same object-vs-array
        // distinction toArrayDeep() just discarded for the whole tree (a
        // JSON object whose own keys happen to look sequential, like
        // {"0":"a","1":"b"}, decodes to the identical PHP shape a real
        // JSON array does once flattened — array_is_list() alone cannot
        // tell them apart after the fact). Converted separately here,
        // from the *raw*, not-yet-flattened node, preserving that
        // distinction with Kinetis\Validation\JsonObject markers instead.
        // A Kinetis\Mcp\JsonObject here (a message built directly rather
        // than through JsonRpcCodec::decode() — see that class's own
        // docblock) is unwrapped to a plain array first: JsonTree::convert()
        // only understands the stdClass/array shapes decode() itself
        // produces, not this package's own hand-built-message marker.
        $rawArguments = JsonRpcCodec::objectGet($message['params'] ?? [], 'arguments');
        $rawArguments = $rawArguments instanceof JsonObject ? $rawArguments->toArray() : $rawArguments;
        $convertedArguments = JsonTree::convert($rawArguments);
        $params['arguments'] = $convertedArguments instanceof ValidationJsonObject
            ? $convertedArguments->toArray()
            : $convertedArguments;

        try {
            /** @var array<string, mixed> $meta */
            $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

            $result = match ($method) {
                'server/discover' => $this->discover(),
                // No 'ping' arm here, deliberately — checked directly
                // against the real 2026-07-28 changelog, not assumed: this
                // revision removed ping (along with logging/setLevel and
                // notifications/roots/list_changed) from the core protocol
                // entirely.
                'tools/list' => $this->listTools(),
                'tools/call' => $this->callTool($params, $meta, $onNotification, $scope),
                'resources/list' => $this->listResources(),
                'resources/read' => $this->readResource($params, $scope),
                default => throw JsonRpcException::methodNotFound($method),
            };
            $result = $this->wrapResult($result, $method);
        } catch (JsonRpcException $e) {
            return $hasId ? JsonRpcCodec::errorEnvelope($id, $e) : null;
        } catch (Throwable $e) {
            $this->logSafely('Unhandled exception while handling MCP method {method}: {message}', [
                'method' => $method,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $hasId ? JsonRpcCodec::errorEnvelope($id, JsonRpcException::internalError()) : null;
        }

        if (!$hasId) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * Per the 2026-07-28 basic spec: protocolVersion and clientCapabilities
     * are both required on every request's `_meta`. A request missing
     * either is malformed and MUST be rejected with -32602 (Invalid
     * params); a request naming an unsupported version gets the more
     * specific -32022 (UnsupportedProtocolVersion) instead. $meta may be
     * the raw, not-yet-flattened value requireNamedObject() returns — read
     * through JsonRpcCodec's object accessors so a stdClass `_meta`
     * (reached via decode()'s own not-yet-flattened params) gets exactly
     * the same checks as an already-flattened array one.
     */
    private function validateMeta(mixed $meta): void
    {
        $version = JsonRpcCodec::objectGet($meta, self::META_PROTOCOL_VERSION_KEY);

        if (!is_string($version)) {
            throw JsonRpcException::invalidParams(
                'Missing required "_meta.' . self::META_PROTOCOL_VERSION_KEY . '".',
            );
        }

        if ($version !== self::PROTOCOL_VERSION) {
            throw JsonRpcException::unsupportedProtocolVersion([self::PROTOCOL_VERSION], $version);
        }

        if (!JsonRpcCodec::objectHas($meta, self::META_CLIENT_CAPABILITIES_KEY)) {
            throw JsonRpcException::invalidParams(
                'Missing required "_meta.' . self::META_CLIENT_CAPABILITIES_KEY . '".',
            );
        }

        if (!JsonRpcCodec::isStrictJsonObject(JsonRpcCodec::objectGet($meta, self::META_CLIENT_CAPABILITIES_KEY))) {
            throw JsonRpcException::invalidParams(
                '"_meta.' . self::META_CLIENT_CAPABILITIES_KEY . '" must be an object.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(): array
    {
        return [
            'supportedVersions' => [self::PROTOCOL_VERSION],
            // (object) casts: json_encode(['tools' => []]) renders
            // "tools":[], but the spec's capability values are JSON
            // objects ("tools":{}) even when empty — a client doing
            // strict type validation could reject an array where an
            // object is expected.
            'capabilities' => [
                'tools' => (object) [],
                'resources' => (object) [],
            ],
            ...($this->instructions !== null ? ['instructions' => $this->instructions] : []),
        ];
    }

    /**
     * Wraps a result in the envelope the 2026-07-28 spec requires:
     * `resultType` (Kinetis only ever returns "complete" — there's no
     * multi-round-trip flow to produce "input_required") and a
     * `_meta.serverInfo` echoing the server's identity. All three are
     * appended after the spread so they always win over anything a
     * handler result might (incorrectly) already contain under those
     * names.
     *
     * `$method` decides whether `ttlMs`/`cacheScope` are added at all —
     * see CACHEABLE_METHOD_SCOPES's own docblock for which methods are
     * cacheable and why `tools/call` deliberately never carries one.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function wrapResult(array $result, string $method): array
    {
        $cacheScope = self::CACHEABLE_METHOD_SCOPES[$method] ?? null;

        return [
            ...$result,
            ...($cacheScope !== null ? ['ttlMs' => self::CACHE_TTL_MS, 'cacheScope' => $cacheScope] : []),
            'resultType' => 'complete',
            '_meta' => [
                self::META_SERVER_INFO_KEY => [
                    'name' => $this->serverName,
                    'version' => $this->serverVersion,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listTools(): array
    {
        $tools = array_map(
            static fn (ToolDefinition $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
            ],
            $this->registry->tools(),
        );

        return ['tools' => $tools];
    }

    /**
     * $params/$meta arrive already fully validated — preflight() (run
     * unconditionally by handle() before this is ever reached) has
     * already confirmed `arguments` is object-shaped-or-absent and
     * `_meta.progressToken` is string/int-or-absent, so this only needs
     * to extract, not re-check.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function callTool(
        array $params,
        array $meta,
        ?Closure $onNotification = null,
        ?ContainerInterface $scope = null,
    ): array {
        $name = $params['name'] ?? null;

        if (!is_string($name)) {
            throw JsonRpcException::invalidParams('Missing required param "name".');
        }

        $tool = $this->registry->findTool($name);

        if ($tool === null) {
            throw JsonRpcException::invalidParams("Unknown tool \"{$name}\".");
        }

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $progressToken = $meta['progressToken'] ?? null;
        /** @var int|string|null $progressToken already validated by preflight() */
        $progress = new ProgressReporter($progressToken !== null ? $onNotification : null, $progressToken);

        try {
            $result = $this->dispatcher->callTool($tool, $arguments, $progress, $scope);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
                'isError' => false,
            ];
        } catch (ValidationException $e) {
            return [
                'content' => [['type' => 'text', 'text' => json_encode(['errors' => $e->errors], JSON_THROW_ON_ERROR)]],
                'isError' => true,
            ];
        } catch (Throwable $e) {
            // Validation feedback above carries its real messages — that's
            // the argument feedback an agent needs to retry correctly. An
            // unexpected failure does not: its message can carry SQL error
            // text, file paths, or anything else internal, so the client
            // gets a fixed string and the real exception goes to the
            // logger — the same client-facing/logged split
            // ExceptionHandlerMiddleware applies to an HTTP 500.
            $this->logSafely('Tool "{tool}" threw: {message}', [
                'tool' => $name,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [
                'content' => [['type' => 'text', 'text' => 'Tool execution failed.']],
                'isError' => true,
            ];
        }
    }

    /**
     * Logging a caught failure must never become a second failure of its
     * own — PSR-3 places no no-throw obligation on an implementation,
     * and this class's whole point (both call sites above) is that one
     * bad request cannot crash a long-running stdio process or replace
     * the response it already decided on. A logger exception is
     * discarded silently rather than reported anywhere else: reporting
     * it would need a second logger, and this already is the terminal
     * boundary.
     *
     * @param array<string, mixed> $context
     */
    private function logSafely(string $message, array $context): void
    {
        try {
            $this->logger->error($message, $context);
        } catch (Throwable) {
            // Discarded deliberately — see this method's own docblock.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function listResources(): array
    {
        $resources = array_map(
            static fn (ResourceDefinition $resource): array => [
                'uri' => $resource->uri,
                'name' => $resource->name,
                'description' => $resource->description,
                'mimeType' => $resource->mimeType,
            ],
            $this->registry->resources(),
        );

        return ['resources' => $resources];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function readResource(array $params, ?ContainerInterface $scope = null): array
    {
        $uri = $params['uri'] ?? null;

        if (!is_string($uri)) {
            throw JsonRpcException::invalidParams('Missing required param "uri".');
        }

        $resource = $this->registry->findResource($uri);

        if ($resource === null) {
            throw JsonRpcException::invalidParams("Unknown resource \"{$uri}\".");
        }

        $content = $this->dispatcher->readResource($resource, $scope);

        return [
            'contents' => [[
                'uri' => $resource->uri,
                'mimeType' => $resource->mimeType,
                'text' => is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR),
            ]],
        ];
    }
}
