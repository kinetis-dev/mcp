<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures\Project\Domain;

use Kinetis\Mcp\Attributes\McpTool;

final class UnconventionalToolController
{
    #[McpTool(name: 'unconventional_ping', description: 'A tool discovered outside any Mcp-named directory')]
    public function ping(): string
    {
        return 'pong';
    }
}
