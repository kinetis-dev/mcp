<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpResource;
use RuntimeException;

/**
 * Unlike a tool, McpServer::readResource() has no inner try/catch of its
 * own wrapping a resource method's execution into isError:true content —
 * only tools get that convention. A resource method throwing propagates
 * all the way to McpServer::handle()'s outer catch, the -32603 Internal
 * error path this fixture exists to exercise.
 */
final readonly class ThrowingResourceController
{
    #[McpResource(uri: 'kinetis://throws', name: 'throws', description: 'Always throws')]
    public function throws(): string
    {
        throw new RuntimeException('boom');
    }
}
