# kinetis/mcp

The [Model Context Protocol](https://modelcontextprotocol.io) server for
[Kinetis](https://github.com/kinetis-dev/kinetis) — tools and resources
an AI agent can discover and call, declared with attributes and
validated exactly like HTTP routes.

```php
use Kinetis\Mcp\Attributes\McpTool;

final readonly class AccountController
{
    #[McpTool(name: 'get_user_status', description: 'Retrieve user status by ID')]
    public function getUserStatus(int $userId): array
    {
        return ['userId' => $userId, 'status' => 'active'];
    }
}
```

Two transports: stdio (`kinetis mcp:serve` — how Claude Desktop, Cursor,
and most local clients launch a server) and Streamable HTTP (`/mcp`, an
ordinary route). Both protocol eras are supported side by side — the
legacy `2025-03-26` handshake and the stateless `2026-07-28` per-request
model. Every message is its own unit of work: a fresh request scope,
transaction rollback for anything a tool leaves open, disposal once the
response is written. The `mcp` middleware group authenticates the HTTP
endpoint with the same middleware the auth packages ship for routes, and
the identity they resolve reaches the tool.

## Provides

Installing this package is what opts it in — it registers the following
automatically, through the `extra.kinetis` declaration in its
`composer.json` (see
[docs.kinetis.dev/cli.html](https://docs.kinetis.dev/cli.html)):

- **A command** on `vendor/bin/kinetis`: `mcp:serve`, the stdio
  transport.
- **A route**: `POST /mcp`, the Streamable HTTP transport, with the
  spec-required `Origin` validation as permanent middleware.
- **Resources**: every page of Kinetis's own documentation, readable by
  any connected agent as `kinetis://docs/{slug}`.
- **A service binding**: `McpServer`, built lazily on first use from
  your application's own discovered tools and resources.

Nothing else — no global middleware, event listeners, or other routes.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`:

| Key | Default | Purpose |
| --- | --- | --- |
| `MCP_ALLOWED_ORIGINS` | *(empty)* | Comma-separated exact `Origin` values allowed on `/mcp`. Empty rejects any request that sends an `Origin` header at all; requests without one (CLI clients, server-to-server) always pass. |
| `MCP_DISCOVERY_PATHS` | *(unset)* | Comma-separated sub-paths (relative to each PSR-4 base directory) restricting tool/resource discovery, for a large application that wants a bounded scan. |

## Installation

```bash
composer require kinetis/mcp
```

Requires PHP 8.4 or later. Documentation:
[docs.kinetis.dev/mcp.html](https://docs.kinetis.dev/mcp.html)

## License

MIT
