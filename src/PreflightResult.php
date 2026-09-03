<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

/**
 * The outcome of McpServer::preflight(): whether the caller should
 * proceed to dispatch $message at all, and — independently — what
 * response (if any) to send. These are deliberately two separate facts,
 * not one: a structurally invalid envelope always gets a response
 * (`respond()`), a genuinely valid message always dispatches
 * (`proceed()`), but a *notification* (no `id`) whose envelope is valid
 * yet whose MCP-specific content fails validation must neither dispatch
 * nor respond at all (`suppress()`) — per JSON-RPC 2.0's own rule that a
 * Notification's caller "would not be aware of any errors" since there
 * is no response to carry one. Collapsing this into a single nullable
 * return (null meaning both "valid, proceed" and "invalid, suppressed")
 * would leave a caller unable to tell those two apart, and either
 * dispatch a message that actually failed validation or — over HTTP —
 * open an SSE stream for a request preflight already rejected.
 */
final readonly class PreflightResult
{
    private function __construct(
        public bool $shouldDispatch,
        /** @var array<string, mixed>|null */
        public ?array $response,
    ) {}

    public static function proceed(): self
    {
        return new self(true, null);
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function respond(array $response): self
    {
        return new self(false, $response);
    }

    /**
     * A notification whose content failed validation: no response, and —
     * distinctly from proceed() — dispatch must not happen either.
     */
    public static function suppress(): self
    {
        return new self(false, null);
    }
}
