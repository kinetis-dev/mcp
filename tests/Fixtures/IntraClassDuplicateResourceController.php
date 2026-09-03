<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpResource;

/**
 * Two different methods on the same class both claim the same resource
 * URI — a conflict register() must catch within a single call, not
 * only across two separate ones.
 */
final readonly class IntraClassDuplicateResourceController
{
    #[McpResource(uri: 'kinetis://intra-class', name: 'first', description: 'The first of two methods claiming the same URI')]
    public function first(): string
    {
        return 'first';
    }

    #[McpResource(uri: 'kinetis://intra-class', name: 'second', description: 'The second of two methods claiming the same URI')]
    public function second(): string
    {
        return 'second';
    }
}
