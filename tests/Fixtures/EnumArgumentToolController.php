<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * A backed enum declared directly as a tool argument. A DTO field may
 * be one; a tool's own flat argument list may not, so registration
 * fails rather than advertising a schema McpDispatcher cannot bind.
 */
final readonly class EnumArgumentToolController
{
    #[McpTool(name: 'enum_argument', description: 'Takes a backed enum directly')]
    public function run(Severity $severity): string
    {
        return $severity->value;
    }
}
