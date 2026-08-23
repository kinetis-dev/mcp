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

    public static function methodNotFound(string $method): self
    {
        return new self("Method not found: \"{$method}\".", -32601);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, -32602);
    }

    public static function internalError(string $message): self
    {
        return new self($message, -32603);
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
