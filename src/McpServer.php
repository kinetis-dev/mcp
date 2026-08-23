<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Mcp\Exception\JsonRpcException;
use Kinetis\Validation\Exception\ValidationException;
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
 * be able to crash it. That catch also logs via $logger — a NullLogger
 * by default, since McpServer is constructed directly by the consumer
 * (bin/kinetis, a Kernel's $mcp param) rather than through the container,
 * so there's no AppScope-registered LoggerInterface to autowire the way
 * ExceptionHandlerMiddleware gets one.
 */
final class McpServer
{
    /**
     * The version that defines Streamable HTTP, replacing the deprecated
     * dual-endpoint HTTP+SSE transport from 2024-11-05 — see
     * Kernel::handleMcp() for what that means for the HTTP side of this
     * server. Clients on this version identify themselves by NOT sending a
     * `_meta.io.modelcontextprotocol/protocolVersion` on requests, and
     * negotiate via the initialize/notifications-initialized handshake
     * below — that handshake is untouched by the modern era support added
     * alongside it.
     */
    private const LEGACY_PROTOCOL_VERSION = '2025-03-26';

    /**
     * The 2026-07-28 revision replaces the initialize handshake with a
     * stateless, per-request model: every request carries its own
     * protocol version and capabilities in `params._meta`, and there is no
     * connection-level negotiation to skip. Kinetis supports both eras
     * side by side (see isModernRequest()) rather than picking one —
     * real clients in the wild still speak the legacy handshake.
     */
    private const MODERN_PROTOCOL_VERSION = '2026-07-28';

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
     * Whether a decoded JSON-RPC message is using the 2026-07-28 per-request
     * model. Static and side-effect-free so Kernel's HTTP-header validation
     * (a transport concern this class doesn't know about) can make the same
     * era determination without duplicating the `_meta` key name.
     *
     * Keyed on the presence of any `io.modelcontextprotocol/`-prefixed key
     * in `_meta` — not on the mere presence of `_meta` itself, and not on
     * whether `protocolVersion` specifically is present or valid. Both
     * narrower checks are wrong for a real case: `_meta.progressToken` (see
     * ProgressReporter) is a spec-general reserved key a *legacy*
     * 2025-03-26 client can legitimately send on its own, with no
     * `io.modelcontextprotocol/*` keys at all — that must stay on the
     * legacy path. A modern request missing `protocolVersion` but still
     * carrying e.g. `io.modelcontextprotocol/clientCapabilities` must still
     * be routed to modern-path validation (a proper -32602/-32022) rather
     * than silently falling through to the legacy match arm — hence
     * matching on the namespaced prefix generally, not one specific key.
     *
     * @param array<string, mixed> $message
     */
    public static function isModernRequest(array $message): bool
    {
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        foreach (array_keys($meta) as $key) {
            if (is_string($key) && str_starts_with($key, 'io.modelcontextprotocol/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function requestedProtocolVersion(array $message): ?string
    {
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $version = $meta[self::META_PROTOCOL_VERSION_KEY] ?? null;

        return is_string($version) ? $version : null;
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
        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        /** @var array<string, mixed> $params */
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (!is_string($method)) {
            return $hasId ? $this->errorEnvelope($id, JsonRpcException::parseError()) : null;
        }

        $isModern = self::isModernRequest($message);

        try {
            if ($isModern) {
                /** @var array<string, mixed> $meta */
                $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
                $this->validateModernRequest($meta);

                $result = match ($method) {
                    'server/discover' => $this->discover(),
                    // No 'ping' arm here, deliberately — checked directly
                    // against the real 2026-07-28 changelog, not assumed
                    // still valid: this revision removed ping (along with
                    // logging/setLevel and notifications/roots/list_changed)
                    // from the core protocol entirely. The legacy arm below
                    // keeps it, since 2025-03-26 clients still send it.
                    'tools/list' => $this->listTools(),
                    'tools/call' => $this->callTool($params, $onNotification, $scope),
                    'resources/list' => $this->listResources(),
                    'resources/read' => $this->readResource($params, $scope),
                    default => throw JsonRpcException::methodNotFound($method),
                };
                $result = $this->wrapModernResult($result, $method);
            } else {
                $result = match ($method) {
                    'initialize' => $this->initialize(),
                    'notifications/initialized', 'ping' => [],
                    'tools/list' => $this->listTools(),
                    'tools/call' => $this->callTool($params, $onNotification, $scope),
                    'resources/list' => $this->listResources(),
                    'resources/read' => $this->readResource($params, $scope),
                    default => throw JsonRpcException::methodNotFound($method),
                };
            }
        } catch (JsonRpcException $e) {
            return $hasId ? $this->errorEnvelope($id, $e) : null;
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception while handling MCP method {method}: {message}', [
                'method' => $method,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $hasId ? $this->errorEnvelope($id, JsonRpcException::internalError($e->getMessage())) : null;
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
     * specific -32022 (UnsupportedProtocolVersion) instead.
     *
     * @param array<string, mixed> $meta
     */
    private function validateModernRequest(array $meta): void
    {
        $version = $meta[self::META_PROTOCOL_VERSION_KEY] ?? null;

        if (!is_string($version)) {
            throw JsonRpcException::invalidParams(
                'Missing required "_meta.' . self::META_PROTOCOL_VERSION_KEY . '".',
            );
        }

        if ($version !== self::MODERN_PROTOCOL_VERSION) {
            throw JsonRpcException::unsupportedProtocolVersion([self::MODERN_PROTOCOL_VERSION], $version);
        }

        if (!array_key_exists(self::META_CLIENT_CAPABILITIES_KEY, $meta)) {
            throw JsonRpcException::invalidParams(
                'Missing required "_meta.' . self::META_CLIENT_CAPABILITIES_KEY . '".',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(): array
    {
        return [
            'supportedVersions' => [self::MODERN_PROTOCOL_VERSION],
            // (object) casts for the same reason as initialize() below —
            // an empty capability MUST serialize as a JSON object, not [].
            'capabilities' => [
                'tools' => (object) [],
                'resources' => (object) [],
            ],
            ...($this->instructions !== null ? ['instructions' => $this->instructions] : []),
        ];
    }

    /**
     * Wraps a modern-era result in the envelope the 2026-07-28 spec
     * requires: `resultType` (Kinetis only ever returns "complete" — there's
     * no multi-round-trip flow to produce "input_required") and a
     * `_meta.serverInfo` echoing the server's identity, mirroring what
     * initialize() reports for legacy clients. All three are appended
     * after the spread so they always win over anything a handler result
     * might (incorrectly) already contain under those names.
     *
     * `$method` decides whether `ttlMs`/`cacheScope` are added at all —
     * see CACHEABLE_METHOD_SCOPES's own docblock for which methods are
     * cacheable and why `tools/call` deliberately never carries one.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function wrapModernResult(array $result, string $method): array
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
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::LEGACY_PROTOCOL_VERSION,
            // (object) casts are deliberate: json_encode(['tools' => []])
            // renders "tools":[], but the spec's capability values are
            // JSON objects ("tools":{}) even when empty — a client doing
            // strict type validation on the initialize response could
            // reject an array where an object is expected.
            'capabilities' => [
                'tools' => (object) [],
                'resources' => (object) [],
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
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
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(array $params, ?Closure $onNotification = null, ?ContainerInterface $scope = null): array
    {
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

        /** @var array<string, mixed> $meta */
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $progressToken = $meta['progressToken'] ?? null;
        $progressToken = is_int($progressToken) || is_string($progressToken) ? $progressToken : null;
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
            $this->logger->error('Tool "{tool}" threw: {message}', [
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

    /**
     * @return array<string, mixed>
     */
    private function errorEnvelope(mixed $id, JsonRpcException $e): array
    {
        $error = [
            'code' => $e->rpcCode,
            'message' => $e->getMessage(),
        ];

        if ($e->data !== null) {
            $error['data'] = $e->data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
