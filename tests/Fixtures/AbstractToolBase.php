<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

abstract class AbstractToolBase
{
    #[McpTool('inherited_tool', 'A tool declared by a parent.')]
    public function tool(): array
    {
        return [];
    }
}
