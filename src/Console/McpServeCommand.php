<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Container\RequestScope;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Transport\StdioTransport;

/**
 * `kinetis mcp:serve` — the stdio transport, how Claude Desktop/Cursor
 * and other local MCP clients launch a server as a subprocess.
 *
 * The server resolves from the container, where this package's own
 * bootstrap bound it — discovery runs once, on that first resolution,
 * and both this command and the /mcp route share the identical binding.
 * The command's scope stays what the dispatcher falls back to, but
 * every message gets a fresh scope of its own, created per line by the
 * transport from the real AppScope — reachable here because a command's
 * scope self-registers its parent.
 *
 * $input/$output are injectable so tests drive this against php://memory
 * rather than the real process streams.
 */
final readonly class McpServeCommand
{
    public function __construct(
        private RequestScope $scope,
        private mixed $input = STDIN,
        private mixed $output = STDOUT,
    ) {}

    #[Command('mcp:serve', description: 'Starts the MCP server over stdio')]
    public function serve(): int
    {
        /** @var McpServer $mcp */
        $mcp = $this->scope->get(McpServer::class);

        /** @var resource $input */
        $input = $this->input;
        /** @var resource $output */
        $output = $this->output;

        (new StdioTransport())->run($mcp, $input, $output, $this->scope->appScope());

        return 0;
    }
}
