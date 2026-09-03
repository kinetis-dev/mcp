<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * Two different methods on the same class both claim the same tool
 * name — a conflict register() must catch within a single call, not
 * only across two separate ones.
 */
final readonly class IntraClassDuplicateToolController
{
    #[McpTool(name: 'intra_class_ping', description: 'The first of two methods claiming the same name')]
    public function first(): string
    {
        return 'first';
    }

    #[McpTool(name: 'intra_class_ping', description: 'The second of two methods claiming the same name')]
    public function second(): string
    {
        return 'second';
    }
}
