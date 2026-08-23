<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Http;

use Kinetis\Config\Config;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Origin validation for the /mcp endpoint, which the Streamable HTTP
 * specification requires on every incoming connection against DNS
 * rebinding. `MCP_ALLOWED_ORIGINS` is an exact, comma-separated list —
 * empty by default, so any request carrying an Origin header at all is
 * rejected 403 until the deployment names which ones may connect. A
 * request with no Origin header (any non-browser client — stdio never
 * sends one) passes regardless: rebinding is a browser attack, and a
 * browser always sends Origin.
 *
 * Priority 100 puts it first in the `mcp` group, ahead of whatever a
 * consumer adds at the default 50 — an authentication check has no
 * business running for an origin that was never allowed to connect.
 * Its group membership is also what guarantees the group exists
 * whenever this package is installed, which {@see McpController}'s own
 * `@mcp` reference requires.
 */
#[AsMiddlewareGroup('mcp', priority: 100)]
final readonly class McpOriginMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '' || \in_array($origin, $this->allowedOrigins(), true)) {
            return $handler->handle($request);
        }

        return ErrorResponse::create(403, "Origin \"{$origin}\" is not allowed to access this MCP endpoint.");
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $configured = $this->config->string('MCP_ALLOWED_ORIGINS', '');

        if ($configured === '') {
            return [];
        }

        // Trimmed, so a space after a comma in .env is not read as part
        // of the next origin.
        return \array_map(\trim(...), \explode(',', $configured));
    }
}
