<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Exception;

use RuntimeException;

/**
 * A protocol-level JSON-RPC failure (unknown method, unknown tool/resource
 * name, malformed request) — distinct from a tool *executing* and
 * failing, which MCP reports as a normal result with isError:true rather
 * than a JSON-RPC error. See McpServer::callTool()/readResource() for
 * that distinction.
 */
final class JsonRpcException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(
        string $message,
        public readonly int $rpcCode,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message);
    }

    public static function parseError(): self
    {
        return new self('Parse error.', -32700);
    }

    /**
     * Syntactically valid JSON that is not a well-formed JSON-RPC 2.0
     * request object — a missing/wrong `jsonrpc`, a missing/non-string
     * `method`, an `id` outside the supported string/int/null domain, or
     * a top-level JSON array (batching is not supported by this
     * revision). See JsonRpcCodec::validateMessage() for the exact rules.
     */
    public static function invalidRequest(): self
    {
        return new self('Invalid Request.', -32600);
    }

    /**
     * Streamable HTTP mirrors selected JSON-RPC body fields into request
     * headers; a header that doesn't match its own body field is this
     * error — an HTTP-transport-specific check with no stdio equivalent,
     * so it lives here as one more named constructor rather than a
     * hand-built envelope at the one call site that needs it.
     */
    public static function headerMismatch(string $message): self
    {
        return new self($message, -32020);
    }

    public static function methodNotFound(string $method): self
    {
        return new self("Method not found: \"{$method}\".", -32601);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, -32602);
    }

    /**
     * Deliberately no `$message` parameter — this is the envelope an
     * *unexpected* exception becomes, and that exception's own message
     * can carry SQL text, a file path, a credential, or anything else
     * internal a remote client must never see (the same containment
     * McpServer::callTool() already applies to a failing tool's own
     * message). The real exception belongs to the server's own logger,
     * never to the client-facing envelope this method builds.
     */
    public static function internalError(): self
    {
        return new self('Internal error.', -32603);
    }

    /**
     * @param list<string> $supported
     */
    public static function unsupportedProtocolVersion(array $supported, string $requested): self
    {
        return new self(
            "Unsupported protocol version \"{$requested}\".",
            -32022,
            ['supported' => $supported, 'requested' => $requested],
        );
    }
}
