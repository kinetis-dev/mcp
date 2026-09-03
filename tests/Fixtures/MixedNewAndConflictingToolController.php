<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * One genuinely new tool name plus one that collides with
 * AccountController's — proves register() is atomic: a class that
 * fails must add nothing, not even the definitions checked before the
 * conflicting one was found.
 */
final readonly class MixedNewAndConflictingToolController
{
    #[McpTool(name: 'genuinely_new_tool', description: 'Would be a valid, non-conflicting registration on its own')]
    public function newTool(): string
    {
        return 'new';
    }

    #[McpTool(name: 'get_user_status', description: 'Collides with AccountController::getUserStatus()')]
    public function conflictingTool(int $userId): array
    {
        return ['userId' => $userId];
    }
}
