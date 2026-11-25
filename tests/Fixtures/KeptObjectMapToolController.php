<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\ObjectMap;

final readonly class KeptObjectMapToolController
{
    /**
     * @param array<string, mixed> $values
     * @return array{values: array<string, mixed>, kept: bool}
     */
    #[McpTool(name: 'kept_object_map', description: 'Reports the object map it received')]
    public function create(string $resource, #[ObjectMap(keepObjects: true)] array $values): array
    {
        return ['values' => $values, 'kept' => ($values['settings'] ?? null) instanceof \stdClass];
    }
}
