<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\Constraints\In;

/**
 * Every empty-collection case a tool schema can carry, in one schema:
 * an empty `#[In([])]` enum and an empty top-level `required` list,
 * which stay JSON arrays; a `mixed`-typed argument's own empty schema
 * object; and a nested DTO carrying a second empty object and a second
 * empty list two levels further down. Every parameter has a default, so
 * `required` is empty rather than absent.
 */
final readonly class EmptyCollectionsToolController
{
    #[McpTool(name: 'empty_collections', description: 'Carries empty JSON arrays and empty JSON objects at several depths')]
    public function run(
        #[In([])]
        mixed $choice = null,
        mixed $note = null,
        ?EmptySchemaObjectRequest $nested = null,
    ): array {
        return ['choice' => $choice, 'note' => $note, 'nested' => $nested];
    }
}
