<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\Tests\Fixtures\CreateUserRequest;

final readonly class AccountController
{
    #[McpTool(name: 'get_user_status', description: 'Retrieve user status by ID')]
    public function getUserStatus(int $userId): array
    {
        return ['userId' => $userId, 'status' => 'active'];
    }

    #[McpTool(name: 'create_user', description: 'Create a user account')]
    public function createUser(CreateUserRequest $data): array
    {
        return ['name' => $data->name, 'email' => $data->email];
    }

    #[McpResource(uri: 'kinetis://status', name: 'status', description: 'Server status')]
    public function status(): string
    {
        return 'ok';
    }
}
