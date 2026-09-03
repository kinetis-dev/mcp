<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * KINETIS-76 follow-up: exercises every remaining builtin type category
 * JsonSchema::forType()/Hydrator::typeMismatchMessage() give an explicit,
 * deliberate policy — array, iterable, mixed, null, true, false — as a
 * real MCP tool's own top-level arguments, not just a #[Body] DTO field.
 * McpDispatcher shares Hydrator's exact type-mismatch check, so a
 * wrong-shaped argument here gets the identical validation-error contract
 * an HTTP request would.
 */
final readonly class BuiltinCoverageToolController
{
    /**
     * @return array{tags: array, items: iterable, note: mixed, marker: null, confirmed: true, declined: false}
     */
    #[McpTool(name: 'builtin_coverage', description: 'Reports every builtin-typed argument it received')]
    public function run(
        array $tags,
        iterable $items,
        mixed $note = null,
        null $marker = null,
        true $confirmed = true,
        false $declined = false,
    ): array {
        return [
            'tags' => $tags,
            'items' => $items,
            'note' => $note,
            'marker' => $marker,
            'confirmed' => $confirmed,
            'declined' => $declined,
        ];
    }
}
