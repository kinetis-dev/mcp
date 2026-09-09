<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\Absent;

/**
 * A tool argument declaring the presence union a DTO field may declare.
 * A tool's arguments are one flat object with no DTO to own the
 * distinction, and the union has no truthful inputSchema — so the tool
 * is refused at registration, before any agent is shown it, and refused
 * again where a binding plan is derived, so neither path can admit what
 * the other rejects.
 */
final readonly class UnionArgumentToolController
{
    #[McpTool(name: 'union_argument', description: 'Declares a union argument')]
    public function run(string|Absent $term = Absent::Value): array
    {
        return ['term' => $term];
    }
}
