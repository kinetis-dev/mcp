<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * The builtin categories beyond the plain scalars — array, iterable,
 * mixed — as a real MCP tool's own top-level arguments, not just a
 * #[Body] DTO field. McpDispatcher shares Hydrator's type-mismatch
 * check, so a wrong-shaped argument here gets the identical
 * validation-error contract an HTTP request would.
 */
final readonly class BuiltinCoverageToolController
{
    /**
     * @return array{tags: array, items: iterable, note: mixed}
     */
    #[McpTool(name: 'builtin_coverage', description: 'Reports every builtin-typed argument it received')]
    public function run(
        array $tags,
        iterable $items,
        mixed $note = null,
    ): array {
        return [
            'tags' => $tags,
            'items' => $items,
            'note' => $note,
        ];
    }
}
