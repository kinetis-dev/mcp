<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\ObjectMap;

/**
 * #[ObjectMap] on a parameter that is not typed `array`: refused at
 * registration and where a binding plan is derived.
 */
final readonly class ObjectMapOnAStringToolController
{
    #[McpTool(name: 'object_map_on_a_string', description: 'Declares #[ObjectMap] on a string')]
    public function run(#[ObjectMap] string $meta): string
    {
        return $meta;
    }
}
