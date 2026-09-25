<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\ObjectMap;

final readonly class ObjectMapArgumentToolController
{
    /**
     * @param array<string, mixed> $meta
     * @return array{meta: array<string, mixed>}
     */
    #[McpTool(name: 'object_map_argument', description: 'Reports the object map it received')]
    public function run(#[ObjectMap] array $meta): array
    {
        return ['meta' => $meta];
    }
}
