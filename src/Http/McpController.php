<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\StreamedResponse;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\JsonRpcCodec;
use Kinetis\McpProtocol\McpServer;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * MCP's Streamable HTTP transport as an ordinary route, which is what
 * gives every message the full request lifecycle with nothing special to
 * wire: dispatchCore() creates the scope this controller resolves from,
 * with every AppScope::onRequestScopeCreated() initializer already run on
 * it, and the `mcp` middleware group — resolved from the same scope, like
 * any route middleware — can authenticate and publish
 * CurrentUserInterface where the tool actually sees it. That scope is
 * passed straight to the server as the message's context; this controller
 * creates none of its own and disposes none.
 *
 * Only POST is declared. GET and DELETE answer the router's own 405
 * carrying `Allow: POST`: GET opens a server-initiated stream and DELETE
 * terminates a session, and this server implements neither. Sessions are
 * optional in this revision, so no `Mcp-Session-Id` is ever issued and
 * none is ever required.
 *
 * `MCP-Protocol-Version` is the only protocol header, and the only state
 * anything here reads about which revision is in play — nothing is
 * remembered between requests to infer it from.
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
        // The body reaching here is already bounded and complete:
        // RequestBodyMiddleware settles the byte ceiling and stages the
        // whole body before any handler runs, so an oversized request is a
        // 413 that never arrives at this method. Cast rather than
        // getContents(): the staged stream is seekable and replayable, and
        // the cast is the representation that rewinds first, so a
        // middleware that already inspected the body leaves the whole
        // envelope readable here rather than an empty remainder.
        $decoded = JsonRpcCodec::decode((string) $request->getBody());

        if (\array_key_exists('errorResponse', $decoded)) {
            // Transport-level malformed input: the envelope carries the
            // JSON-RPC reason and the status says the request itself was
            // never usable.
            return $this->json($decoded['errorResponse'], 400);
        }

        if (\array_key_exists('ignored', $decoded)) {
            return new Response(202);
        }

        $message = $decoded['message'];
        $versionError = $this->protocolVersionError($request, $message);

        if ($versionError !== null) {
            return $versionError;
        }

        if ($this->wantsProgressStream($message)) {
            return $this->stream($message);
        }

        $response = $this->mcp->handle($message, null, $this->scope);

        // A valid notification is accepted and answered with no body, per
        // the transport spec. $message has already passed structural
        // validation, so a null response here is always a genuine
        // notification, never a malformed request silently swallowed.
        if ($response === null) {
            return new Response(202);
        }

        // A protocol error after a valid envelope is an ordinary JSON-RPC
        // response: the request was understood, and its outcome belongs in
        // the envelope rather than in a status code a client would have to
        // map back.
        return $this->json($response, 200);
    }

    /**
     * The one protocol-version rule this transport enforces.
     *
     * `initialize` is what establishes the version, so it may arrive
     * without the header. Every later message must carry it, and must
     * carry exactly this server's revision. A missing header on a later
     * message means the spec's 2025-03-26 fallback, which this
     * single-version server does not implement, so it is refused rather
     * than assumed: nothing is remembered from an earlier request that
     * could stand in for it.
     *
     * @param array<string, mixed> $message
     */
    private function protocolVersionError(ServerRequestInterface $request, array $message): ?ResponseInterface
    {
        $header = $request->getHeaderLine('MCP-Protocol-Version');

        if ($header === '') {
            if (($message['method'] ?? null) === 'initialize') {
                return null;
            }

            return $this->versionRefusal($message, 'The "MCP-Protocol-Version" header is required.');
        }

        if ($header === McpServer::PROTOCOL_VERSION) {
            return null;
        }

        return $this->versionRefusal(
            $message,
            'Unsupported "MCP-Protocol-Version": this server implements ' . McpServer::PROTOCOL_VERSION . ' only.',
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function versionRefusal(array $message, string $reason): ResponseInterface
    {
        return $this->json(
            JsonRpcCodec::errorEnvelope($message['id'] ?? null, JsonRpcException::invalidRequest($reason)),
            400,
        );
    }

    /**
     * Which requests want an SSE stream at all.
     *
     * Streaming is request-only: a fully valid `tools/call` *notification*
     * carrying a well-formed progress token never opens a stream, because
     * there is no response to end it with. `array_key_exists()` rather
     * than a `?? null` check, since `id: null` is a request whose id is
     * null — an invalid one this revision rejects — and never a
     * notification.
     *
     * A malformed token is deliberately not streamed either: the server
     * rejects it with -32602, and that belongs in an ordinary JSON
     * response rather than inside a stream opened for a request that was
     * never going to produce progress.
     *
     * `$message['params']` may still be the raw, not-yet-flattened value
     * JsonRpcCodec::decode() hands back, so this reads it through the
     * codec's object accessors rather than a plain array index.
     *
     * @param array<string, mixed> $message
     */
    private function wantsProgressStream(array $message): bool
    {
        if (!\array_key_exists('id', $message) || ($message['method'] ?? null) !== 'tools/call') {
            return false;
        }

        $meta = JsonRpcCodec::objectGet($message['params'] ?? null, '_meta');
        $token = JsonRpcCodec::objectGet($meta, 'progressToken');

        return JsonRpcCodec::objectHas($meta, 'progressToken') && (\is_string($token) || \is_int($token));
    }

    /**
     * An SSE response scoped to this one request: zero or more
     * `notifications/progress` events, then one final event carrying the
     * JSON-RPC response. HTTP status is always 200 — headers are sent
     * before the body starts streaming, so any JSON-RPC error surfaces
     * inside the final event's payload instead.
     *
     * The emitter dispatches on the very scope injected here, which Kernel
     * keeps alive until the stream is emitted or abandoned and disposes
     * exactly once through its own lease. So a streamed call resolves from
     * the same container an ordinary one does: whatever an `mcp`-group
     * middleware published is simply already there, and the rollback hook
     * Kernel registered covers the tool the same way. Nothing about the
     * scope's lifetime is this package's to decide; see
     * {@see \Kinetis\Http\StreamScopeLease}.
     *
     * @param array<string, mixed> $message
     */
    private function stream(array $message): ResponseInterface
    {
        $inner = new Response(200, [
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);

        $mcp = $this->mcp;
        $scope = $this->scope;

        $emitter = static function () use ($mcp, $message, $scope): void {
            $write = static function (array $payload): void {
                echo 'data: ' . \json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";

                if (\function_exists('ob_flush')) {
                    @\ob_flush();
                }

                \flush();
            };

            // $mcp->handle() never throws — the same top-level containment
            // the stdio loop relies on — so $response is always the real,
            // already-computed outcome. write()'s own output step can
            // still fail: an ob_start() handler installed further up the
            // stack throwing when @ob_flush() invokes it (`@` suppresses
            // PHP warnings, not a thrown exception). That failure is the
            // primary one, and the lease wrapping this emitter still
            // disposes the scope around it.
            $response = $mcp->handle($message, $write, $scope);

            if ($response !== null) {
                $write($response);
            }
        };

        return new StreamedResponse($inner, $emitter);
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
