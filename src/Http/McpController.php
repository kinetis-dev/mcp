<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\StreamedResponse;
use Kinetis\Mcp\McpServer;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
 * Mcp-Name) are enforced for modern (per-request `_meta`) requests
 * only: legacy 2025-03-26 clients never send them, and the spec's
 * backward-compatibility carve-out permits treating their absence as
 * the earlier revision.
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
        $decoded = \json_decode((string) $request->getBody(), associative: true);

        if (!\is_array($decoded)) {
            return $this->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']],
                200,
            );
        }

        /** @var array<string, mixed> $decoded */
        $isModern = McpServer::isModernRequest($decoded);

        if ($isModern) {
            $mismatch = $this->headerMismatch($request, $decoded);

            if ($mismatch !== null) {
                return $this->json(
                    ['jsonrpc' => '2.0', 'id' => $decoded['id'] ?? null, 'error' => ['code' => -32020, 'message' => $mismatch]],
                    400,
                );
            }
        }

        if ($this->wantsProgressStream($decoded)) {
            return $this->stream($decoded);
        }

        // The request's own scope — created, hooked, and disposed by
        // dispatchCore() like any other route's.
        $response = $this->mcp->handle($decoded, scope: $this->scope);

        // Spec: a POST body containing only notifications/responses gets
        // 202 Accepted with no body once the server has accepted it. Not
        // reachable in practice for a modern request — the 2026-07-28
        // revision defines no client-to-server notifications over
        // Streamable HTTP — but harmless to keep for either era.
        if ($response === null) {
            return new Response(202);
        }

        return $this->json($response, $isModern ? $this->httpStatus($response) : 200);
    }

    /**
     * `progressToken` is a spec-general reserved `_meta` key (see
     * McpServer::callTool()) — not restricted to the 2026-07-28
     * per-request model — so this applies to legacy and modern
     * `tools/call` requests alike, with no era-gating needed.
     *
     * @param array<string, mixed> $decoded
     */
    private function wantsProgressStream(array $decoded): bool
    {
        if (($decoded['method'] ?? null) !== 'tools/call') {
            return false;
        }

        $params = \is_array($decoded['params'] ?? null) ? $decoded['params'] : [];
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        return \array_key_exists('progressToken', $meta);
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
        $app = $this->scope->appScope();
        $mcp = $this->mcp;

        $emitter = static function () use ($mcp, $decoded, $app, $currentUser): void {
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

            if ($currentUser instanceof CurrentUserInterface) {
                $scope->instance(CurrentUserInterface::class, $currentUser);
            }

            $guardClass = 'Kinetis\\Persistence\\TransactionGuard';

            if (\class_exists($guardClass)) {
                $guard = $scope->get($guardClass);
                $scope->onDispose($guard->rollbackDangling(...));
            }

            try {
                $response = $mcp->handle($decoded, $onNotification, $scope);
            } finally {
                $scope->dispose();
            }

            if ($response !== null) {
                $write($response);
            }
        };

        return new StreamedResponse($inner, $emitter);
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
     *
     * @param array<string, mixed> $decoded
     */
    private function nameHeaderMismatch(ServerRequestInterface $request, array $decoded, mixed $method): ?string
    {
        /** @var array<string, mixed> $params */
        $params = \is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

        $bodyName = match ($method) {
            'tools/call' => $params['name'] ?? null,
            'resources/read' => $params['uri'] ?? null,
            default => null,
        };

        if ($bodyName === null) {
            // No name/uri in the body at all — callTool()/readResource()
            // reject that themselves with a more specific error; nothing
            // for this header check to validate against.
            return null;
        }

        $bodyName = \is_string($bodyName) ? $bodyName : (string) $bodyName;
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
     * Maps a modern-era JSON-RPC error response to the HTTP status the
     * 2026-07-28 transport spec mandates for that error code. Only the
     * codes the spec documents a status for are mapped; anything else
     * keeps the transport-level default of 200, matching the legacy
     * era's "status is always 200, the envelope carries the outcome".
     *
     * @param array<string, mixed> $response
     */
    private function httpStatus(array $response): int
    {
        $code = $response['error']['code'] ?? null;

        return match ($code) {
            -32020, -32021, -32022, -32602 => 400,
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
