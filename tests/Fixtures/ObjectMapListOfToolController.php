<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\ListOf;
use Kinetis\Validation\ObjectMap;

/**
 * #[ObjectMap] beside #[ListOf], a declaration that admits no value:
 * refused at registration and where a binding plan is derived.
 */
final readonly class ObjectMapListOfToolController
{
    /**
     * @param list<string> $tags
     * @return list<string>
     */
    #[McpTool(name: 'object_map_list_of', description: 'Declares #[ObjectMap] with #[ListOf]')]
    public function run(#[ObjectMap] #[ListOf('string')] array $tags): array
    {
        return $tags;
    }
}
