<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;

/**
 * A CurrentUserInterface implementation with a concrete-only detail
 * (role()) — the same shape kinetis/auth-jwt's own JwtUser has (a claim,
 * jti for revocation most commonly, only reachable by injecting the
 * concrete class directly rather than the interface). Deliberately
 * generic, not JWT-specific: the guard's rule is about the portable
 * interface id, whatever concrete class a given auth package happens to
 * publish alongside it.
 */
final readonly class ConcreteCurrentUser implements CurrentUserInterface
{
    public function __construct(
        private string $userId,
        private string $userRole,
    ) {}

    #[\Override]
    public function id(): string
    {
        return $this->userId;
    }

    public function role(): string
    {
        return $this->userRole;
    }
}
