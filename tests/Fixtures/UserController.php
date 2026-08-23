<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * An ordinary route beside /mcp, so tests can prove the `mcp` group
 * runs for the endpoint and nowhere else.
 */
final readonly class UserController
{
    /**
     * @return array{id: int}
     */
    #[Get('/users/{id}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
