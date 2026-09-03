<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpResource;

/**
 * Declares a resource URI AccountController already claims, from a
 * genuinely different class/method — the cross-class conflict
 * McpRegistry::register() must reject.
 */
final readonly class DuplicateResourceUriController
{
    #[McpResource(uri: 'kinetis://status', name: 'status', description: 'A conflicting definition of the same resource URI')]
    public function conflictingStatus(): string
    {
        return 'conflict';
    }
}
