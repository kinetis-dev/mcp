<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Http;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Container\TransactionGuardHook;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\StreamedResponse;
use Kinetis\Logging\SafeLogger;
use Kinetis\Mcp\Exception\JsonRpcException;
use Kinetis\Mcp\JsonRpcCodec;
use Kinetis\Mcp\McpServer;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * MCP's Streamable HTTP transport as an ordinary route, which is what
 * gives every message the full request lifecycle with nothing special
 * to wire: dispatchCore() creates the scope this controller resolves
 * from, TransactionGuard rolls back what a tool leaves open, and the
 * `mcp` middleware group — resolved from the same scope, like any route
 * middleware — can authenticate and publish CurrentUserInterface where
 * the tool actually sees it.
 *
 * Only POST is declared. GET and DELETE answer the router's own 405
 * carrying `Allow: POST`, which is exactly what the 2026-07-28
 * transport spec asks a server implementing only this revision to
 * return for either method — earlier revisions used GET for a
 * server-initiated stream and DELETE for session termination, and
 * Kinetis implements neither.
 *
 * Headers mirrored from the body (MCP-Protocol-Version, Mcp-Method,
 * Mcp-Name) are enforced on every request.
 */
#[Middleware('@mcp')]
final readonly class McpController
{
    public function __construct(
        private McpServer $mcp,
        private RequestScope $scope,
    ) {}

    #[Post('/mcp')]
    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        // getContents(), never a (string) cast: MaxBodySizeMiddleware
        // wraps this request's body in a SizeLimitedStream whose
        // __toString() cannot throw (the interface it implements
        // forbids it) and silently reports an empty string once the
        // configured cap is exceeded — a string cast here would turn a
        // real oversized-body rejection into a misleading "Parse
        // error." response instead of the 413 that middleware exists
        // to produce. getContents() is the one method that actually
        // enforces the cap; letting BodyTooLargeException propagate
        // uncaught is what lets that middleware's own catch turn it
        // into the real response.
        //
        // JsonRpcCodec::decode() is the same shared decode/structural-
        // validation path StdioTransport uses, run here *before* the
        // mirrored-header check below — a malformed envelope must get
        // the same JSON-RPC code/id semantics regardless of transport,
        // never a header-mismatch response for a body that was never a
        // valid request to begin with.
        $decoded = JsonRpcCodec::decode($request->getBody()->getContents());

        if (\array_key_exists('errorResponse', $decoded)) {
            return $this->json($decoded['errorResponse'], $this->httpStatus($decoded['errorResponse']));
        }

        $message = $decoded['message'];

        // The full nested preflight — not just the envelope decode()
        // already checked — run here too, before the header check and
        // stream selection below: a malformed _meta/clientCapabilities/
        // arguments/name/uri/progressToken must never surface as a
        // header mismatch (headerMismatch() reads the same fields to
        // build its own comparison) or open the SSE stream
        // wantsProgressStream() would otherwise start, since either
        // would commit this request to an outcome other than the
        // -32602/-32600 McpServer::handle() would give it once
        // dispatched anyway. Same validator handle() itself runs
        // unconditionally as its own first step — reused, not
        // duplicated. A notification (no `id`) whose envelope is valid
        // but whose content preflight() rejects gets neither dispatched
        // nor answered — PreflightResult::suppress() — which is exactly
        // why this stops here too, before header/stream decisions that
        // a suppressed notification must never reach either.
        $preflight = $this->mcp->preflight($message);

        if (!$preflight->shouldDispatch) {
            return $preflight->response === null
                ? new Response(202)
                : $this->json($preflight->response, $this->httpStatus($preflight->response));
        }

        $mismatch = $this->headerMismatch($request, $message);

        if ($mismatch !== null) {
            return $this->json(
                JsonRpcCodec::errorEnvelope($message['id'] ?? null, JsonRpcException::headerMismatch($mismatch)),
                400,
            );
        }

        if ($this->wantsProgressStream($message)) {
            return $this->stream($message);
        }

        // The request's own scope — created, hooked, and disposed by
        // dispatchCore() like any other route's.
        $response = $this->mcp->handle($message, scope: $this->scope);

        // Spec: a POST body containing only notifications/responses gets
        // 202 Accepted with no body once the server has accepted it. Not
        // reachable in practice — the 2026-07-28 revision defines no
        // client-to-server notifications over Streamable HTTP — but
        // harmless to keep. $message having already passed structural
        // validation above is what makes this safe: a null $response
        // here always means a genuine notification, never a malformed
        // request silently swallowed.
        if ($response === null) {
            return new Response(202);
        }

        return $this->json($response, $this->httpStatus($response));
    }

    /**
     * `progressToken` is a spec-general reserved `_meta` key — see
     * McpServer::callTool(). By the time this runs, preflight() has
     * already confirmed a *present* progressToken is string/int-typed —
     * the check here is only which requests want a stream at all, not a
     * second validation pass. `$decoded['params']` may still be the raw,
     * not-yet-flattened value JsonRpcCodec::decode() hands back (a
     * `stdClass`, when one was given), so this reads it through
     * JsonRpcCodec's object accessors rather than a plain array index.
     *
     * Streaming is request-only: `array_key_exists('id', $decoded)` is
     * checked first, not just method/progressToken, so a fully valid
     * `tools/call` *notification* — no `id` member at all — never opens
     * the SSE stream just because it happens to carry a well-formed
     * progressToken. That case still dispatches normally (the tool
     * genuinely runs, matching JSON-RPC's own "a server MUST process a
     * notification" rule) and gets the ordinary null-response → `202`
     * path serve() already has, exactly like any other notification —
     * `id: null` (the value, present as a key) is JSON-RPC's own distinct
     * "a request whose id happens to be null," never a notification, so
     * `array_key_exists()` is deliberately used here rather than a
     * `?? null` truthiness check that would conflate the two.
     *
     * @param array<string, mixed> $decoded
     */
    private function wantsProgressStream(array $decoded): bool
    {
        if (!\array_key_exists('id', $decoded)) {
            return false;
        }

        if (($decoded['method'] ?? null) !== 'tools/call') {
            return false;
        }

        $params = $decoded['params'] ?? [];
        $meta = JsonRpcCodec::objectGet($params, '_meta') ?? [];

        return JsonRpcCodec::objectHas($meta, 'progressToken');
    }

    /**
     * An SSE response scoped to this one request: zero or more
     * `notifications/progress` events, then one final event carrying the
     * JSON-RPC response. HTTP status is always 200 — headers are sent
     * before the body starts streaming, so any JSON-RPC error surfaces
     * inside the final event's payload instead.
     *
     * The emitter runs after dispatchCore() has disposed this request's
     * scope — the runtime writes the response once handle() has
     * returned — so the streamed call gets a scope of its own, alive
     * until after the final event, with the same rollback hook. What an
     * `mcp`-group middleware published as the caller's identity is
     * carried across to it: the middleware ran against this request's
     * scope, and the tool resolves from the stream's.
     *
     * Carried across under both `CurrentUserInterface` and, when the same
     * instance was *also* explicitly registered under its own concrete
     * class in the original scope, that concrete class too —
     * `kinetis/auth-jwt`'s `JwtAuthMiddleware` does exactly this (a
     * controller needing a claim only `JwtUser` itself exposes, `jti` for
     * revocation most commonly, injects the concrete class directly
     * rather than the interface), and any other middleware following the
     * identical pattern gets the same treatment — this package has no
     * dependency on either auth package and never needs one, since the
     * mechanism only ever asks the original scope "what else, if
     * anything, resolves to this exact object." Without carrying the
     * concrete alias too, a tool typed against the concrete class (rather
     * than the interface) would either autowire a disconnected instance
     * or fail outright the moment a client asked for progress, despite
     * working identically without it — the exact inconsistency this
     * package's own docs must never claim doesn't exist.
     *
     * @param array<string, mixed> $decoded
     */
    private function stream(array $decoded): ResponseInterface
    {
        $inner = new Response(200, [
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);

        $currentUser = $this->scope->isRegistered(CurrentUserInterface::class)
            ? $this->scope->get(CurrentUserInterface::class)
            : null;

        // Only ever carried forward when the *original* scope already
        // guaranteed both ids resolve to this exact instance — this is a
        // preservation of an existing binding, never the introduction of
        // a new one, so an object whose concrete class was never
        // separately registered (the plain kinetis/auth BearerAuthMiddleware
        // case, among others) carries only the interface id, exactly as
        // before this fix.
        $currentUserConcreteClass = null;

        if ($currentUser instanceof CurrentUserInterface) {
            $concreteClass = $currentUser::class;

            if ($this->scope->isRegistered($concreteClass) && $this->scope->get($concreteClass) === $currentUser) {
                $currentUserConcreteClass = $concreteClass;
            }
        }

        $app = $this->scope->appScope();
        $mcp = $this->mcp;

        $emitter = static function () use ($mcp, $decoded, $app, $currentUser, $currentUserConcreteClass): void {
            $write = static function (array $payload): void {
                echo 'data: ' . \json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";

                if (\function_exists('ob_flush')) {
                    @\ob_flush();
                }

                \flush();
            };

            $onNotification = static function (array $notification) use ($write): void {
                $write([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => $notification,
                ]);
            };

            $scope = $app->createRequestScope();

            // $mcp->handle() never throws — the same top-level
            // containment as the stdio transport, and every JSON-RPC
            // response it builds is itself already json_encode()d and
            // caught internally before being embedded as text
            // (McpServer::callTool()/handle()) — so $response is always
            // both the real, already-computed outcome and already safe
            // to encode again here. TransactionGuardHook::
            // registerIfAvailable() and write()'s own output step can
            // still genuinely fail: a broken container binding resolving
            // one of TransactionGuard's own dependencies, or — the write
            // step specifically — an ob_start() output-buffer handler
            // installed further up the call stack throwing when write()'s
            // own @ob_flush() invokes it (`@` suppresses PHP warnings,
            // not a real thrown exception, so it reaches this path
            // unchanged). Either failure propagates as the real primary
            // failure here. disposeStreamScope() below is guaranteed non-throwing
            // (see its own docblock), which is what makes it safe to run
            // in this finally regardless of what inside the try block
            // failed — a `finally` block is only dangerous when the
            // block itself can throw. Every setup step that touches
            // $scope is inside this try, not just the write, so the
            // scope always gets disposed even when one of them fails.
            try {
                if ($currentUser instanceof CurrentUserInterface) {
                    $scope->instance(CurrentUserInterface::class, $currentUser);

                    if ($currentUserConcreteClass !== null) {
                        $scope->instance($currentUserConcreteClass, $currentUser);
                    }
                }

                TransactionGuardHook::registerIfAvailable($scope);

                $response = $mcp->handle($decoded, $onNotification, $scope);

                if ($response !== null) {
                    $write($response);
                }
            } finally {
                self::disposeStreamScope($scope, $app);
            }
        };

        return new StreamedResponse($inner, $emitter);
    }

    /**
     * Disposes $scope, used only by stream()'s emitter — guaranteed
     * never to throw, which is what makes it safe to call from inside the
     * emitter's own finally regardless of whether the final event was
     * successfully written or the write itself failed. A cleanup failure
     * here is never allowed to escape and abort the stream over what is,
     * at this point, only ever server diagnostics — never a second SSE
     * event. Logged through SafeLogger::logFrom(), not log(): resolving
     * LoggerInterface from $app (AppScope) is itself covered by the same
     * containment as the logger's own log() call — $scope is already
     * disposed by the time a cleanup failure could occur, so it can no
     * longer resolve one safely, and a throwing LoggerInterface binding/
     * factory on AppScope must not be able to escape here either.
     */
    private static function disposeStreamScope(RequestScope $scope, AppScope $app): void
    {
        try {
            $scope->dispose();
        } catch (Throwable $disposeFailure) {
            SafeLogger::logFrom(
                fn (): LoggerInterface => $app->get(LoggerInterface::class),
                LogLevel::ERROR,
                'Request scope disposal failed while streaming an MCP progress response, after the final event was already computed: {message}',
                ['message' => $disposeFailure->getMessage(), 'exception' => $disposeFailure],
            );
        }
    }

    /**
     * Streamable HTTP mirrors selected JSON-RPC body fields into headers
     * so intermediaries can route and inspect requests without parsing
     * the body. Deliberately does NOT mirror `x-mcp-header`
     * tool-parameter headers — optional for servers per the spec.
     *
     * @param array<string, mixed> $decoded
     * @return string|null a human-readable mismatch description, or null if the headers are valid
     */
    private function headerMismatch(ServerRequestInterface $request, array $decoded): ?string
    {
        $expectedVersion = McpServer::requestedProtocolVersion($decoded);
        $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');

        if ($headerVersion === '' || $headerVersion !== $expectedVersion) {
            return "Header mismatch: MCP-Protocol-Version header value \"{$headerVersion}\" does not match body value \"{$expectedVersion}\".";
        }

        $method = $decoded['method'] ?? null;
        $headerMethod = $request->getHeaderLine('Mcp-Method');

        if ($headerMethod === '' || $headerMethod !== $method) {
            $bodyMethod = \is_string($method) ? $method : 'null';

            return "Header mismatch: Mcp-Method header value \"{$headerMethod}\" does not match body value \"{$bodyMethod}\".";
        }

        return $this->nameHeaderMismatch($request, $decoded, $method);
    }

    /**
     * `Mcp-Name` mirrors `params.name` (`tools/call`) or `params.uri`
     * (`resources/read`) — the spec's third method needing it,
     * `prompts/get`, has no equivalent here, since this server never
     * implements prompts. Required only for these two methods.
     * `$decoded['params']` may still be the raw, not-yet-flattened value
     * decode() hands back, so this reads it through JsonRpcCodec's object
     * accessors rather than a plain array index.
     *
     * @param array<string, mixed> $decoded
     */
    private function nameHeaderMismatch(ServerRequestInterface $request, array $decoded, mixed $method): ?string
    {
        $params = $decoded['params'] ?? [];

        $bodyName = match ($method) {
            'tools/call' => JsonRpcCodec::objectGet($params, 'name'),
            'resources/read' => JsonRpcCodec::objectGet($params, 'uri'),
            default => null,
        };

        if (!\is_string($bodyName)) {
            // Absent, or a non-string value preflight() would already
            // have rejected with -32602 before this ever runs — either
            // way, nothing here to validate the header against, and
            // never cast a malformed value for comparison or error text
            // (an array/object would emit a PHP warning on the (string)
            // cast this used to do, escalating to an ErrorException
            // under an application's own warning-to-exception handler).
            return null;
        }

        $headerName = $request->getHeaderLine('Mcp-Name');
        $decodedHeaderName = self::decodeHeaderValue($headerName);

        if ($headerName === '' || $decodedHeaderName === null || $decodedHeaderName !== $bodyName) {
            return "Header mismatch: Mcp-Name header value \"{$headerName}\" does not match body value \"{$bodyName}\".";
        }

        return null;
    }

    /**
     * Decodes a header value per the transport's Base64 sentinel format
     * (`=?base64?{...}?=`), used by a conforming client when a value
     * can't be safely represented as plain ASCII. A value not wrapped in
     * the sentinel is returned as-is; one that is but fails to decode
     * returns null, so the caller's comparison fails closed rather than
     * treating a malformed header as a match.
     */
    private static function decodeHeaderValue(string $value): ?string
    {
        if (!\str_starts_with($value, '=?base64?') || !\str_ends_with($value, '?=')) {
            return $value;
        }

        $decoded = \base64_decode(\substr($value, 9, -2), strict: true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Maps a JSON-RPC error response to the HTTP status the 2026-07-28
     * transport spec mandates for that error code. Only the codes the
     * spec documents a status for are mapped; anything else keeps the
     * transport-level default of 200 — the envelope carries the outcome.
     *
     * @param array<string, mixed> $response
     */
    private function httpStatus(array $response): int
    {
        $code = $response['error']['code'] ?? null;

        return match ($code) {
            -32020, -32021, -32022, -32600, -32602 => 400,
            -32601 => 404,
            default => 200,
        };
    }

    private function json(mixed $data, int $status): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: \json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
