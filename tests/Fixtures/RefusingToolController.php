<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\McpProtocol\ToolResult;

final readonly class RefusingToolController
{
    #[McpTool(name: 'refuse', description: 'Always refuses')]
    public function refuse(): ToolResult
    {
        return ToolResult::error('Denied.');
    }
}
