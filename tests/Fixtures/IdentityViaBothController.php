<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Mcp\Attributes\McpTool;

/**
 * Injects both CurrentUserInterface and the concrete ConcreteCurrentUser
 * class simultaneously — proving both resolve to the exact same object
 * instance, the specific guarantee KINETIS-74's fix has to preserve
 * across the streamed scope, not merely that each resolves to
 * *something*.
 */
final readonly class IdentityViaBothController
{
    public function __construct(
        private ?CurrentUserInterface $viaInterface = null,
        private ?ConcreteCurrentUser $viaConcrete = null,
    ) {}

    /**
     * @return array{sameInstance: bool, caller: string}
     */
    #[McpTool(name: 'identity_via_both', description: 'Reports whether both identity bindings resolve to the same instance')]
    public function whoami(): array
    {
        return [
            'sameInstance' => $this->viaInterface !== null && $this->viaInterface === $this->viaConcrete,
            'caller' => $this->viaInterface?->id() ?? 'anonymous',
        ];
    }
}
