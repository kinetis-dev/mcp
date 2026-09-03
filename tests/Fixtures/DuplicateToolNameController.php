<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * Declares a tool name AccountController already claims, from a
 * genuinely different class/method — the cross-class conflict
 * McpRegistry::register() must reject.
 */
final readonly class DuplicateToolNameController
{
    #[McpTool(name: 'get_user_status', description: 'A conflicting definition of the same tool name')]
    public function conflictingStatus(int $userId): array
    {
        return ['userId' => $userId];
    }
}
