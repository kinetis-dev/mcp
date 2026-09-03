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
 *
 * The message deliberately looks like internal detail (a fake SQL error
 * plus a file path) — exactly the kind of text handle()'s generic catch
 * must keep out of the client-facing envelope, matching the discipline
 * ThrowingToolController's own message already establishes for the
 * tools/call path.
 */
final readonly class ThrowingResourceController
{
    #[McpResource(uri: 'kinetis://throws', name: 'throws', description: 'Always throws')]
    public function throws(): string
    {
        throw new RuntimeException('SQLSTATE[28000]: Access denied for user (using password: hunter2) at /srv/app/src/SecretRepo.php:99');
    }
}
