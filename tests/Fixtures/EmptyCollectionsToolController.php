<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * Every empty-collection case a tool schema can carry, in one schema:
 * an empty top-level `required` list, which stays a JSON array; a
 * `mixed`-typed argument's own empty schema object; and a nested DTO
 * carrying a second empty object and a second empty list two levels
 * further down. Every parameter has a default, so `required` is empty
 * rather than absent.
 */
final readonly class EmptyCollectionsToolController
{
    #[McpTool(name: 'empty_collections', description: 'Carries empty JSON arrays and empty JSON objects at several depths')]
    public function run(
        mixed $note = null,
        ?EmptySchemaObjectRequest $nested = null,
    ): array {
        return ['note' => $note, 'nested' => $nested];
    }
}
