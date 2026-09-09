<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * A DTO-typed tool argument whose declared type accepts null, beside
 * AccountController's own non-nullable one: the pair is what separates
 * "null is this argument's value" from "null is a violation for this
 * argument".
 */
final readonly class NullableDtoArgumentToolController
{
    #[McpTool(name: 'update_user', description: 'Update a user account, or clear it with a null')]
    public function updateUser(?CreateUserRequest $data): array
    {
        return ['name' => $data?->name];
    }
}
