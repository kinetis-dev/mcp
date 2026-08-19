<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use RuntimeException;

/**
 * The exception message deliberately looks like internal detail (a fake
 * SQL error) — exactly the kind of text McpServer::callTool()'s generic
 * catch must keep out of the content it hands back to the connected
 * agent.
 */
final readonly class ThrowingToolController
{
    #[McpTool(name: 'explode', description: 'Always throws')]
    public function explode(): array
    {
        throw new RuntimeException('SQLSTATE[42S02]: table "secrets" not found at /srv/app/src/Repo.php:17');
    }
}
