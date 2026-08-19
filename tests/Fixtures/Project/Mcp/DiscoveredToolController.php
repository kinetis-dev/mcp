<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures\Project\Mcp;

use Kinetis\Mcp\Attributes\McpTool;

final class DiscoveredToolController
{
    #[McpTool(name: 'discovered_ping', description: 'A tool discovered via namespace scanning')]
    public function ping(): string
    {
        return 'pong';
    }
}
