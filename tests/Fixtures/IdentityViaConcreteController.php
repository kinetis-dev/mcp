<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * Injects only the concrete ConcreteCurrentUser class directly, the way
 * a controller needing role() — a detail only the concrete class
 * exposes, not CurrentUserInterface — would (kinetis/auth-jwt's own
 * JwtUser::claim() is the real-world equivalent). Nullable, so an
 * unauthenticated call reports "anonymous" rather than failing to
 * autowire.
 */
final readonly class IdentityViaConcreteController
{
    public function __construct(private ?ConcreteCurrentUser $user = null) {}

    /**
     * @return array{caller: string, role: string}
     */
    #[McpTool(name: 'identity_via_concrete', description: 'Reports the caller identity via the concrete user class')]
    public function whoami(): array
    {
        return ['caller' => $this->user?->id() ?? 'anonymous', 'role' => $this->user?->role() ?? 'none'];
    }
}
