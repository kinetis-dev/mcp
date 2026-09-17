<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpResource;
use RuntimeException;

/**
 * A read has no isError result of its own — that convention belongs to
 * tools — so a resource method throwing becomes the -32603 Internal error
 * KinetisMcpApplication::readResource() raises, which is the path this
 * fixture exists to exercise.
 *
 * The message deliberately looks like internal detail (a fake SQL error
 * plus a file path): exactly the kind of text the generic envelope must
 * keep away from a client, matching the discipline
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
