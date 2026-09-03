<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Mcp\Attributes\McpTool;

/**
 * Injects only CurrentUserInterface — nullable, so an unauthenticated
 * call reports "anonymous" rather than failing to autowire.
 */
final readonly class IdentityViaInterfaceController
{
    public function __construct(private ?CurrentUserInterface $user = null) {}

    /**
     * @return array{caller: string}
     */
    #[McpTool(name: 'identity_via_interface', description: 'Reports the caller identity via CurrentUserInterface')]
    public function whoami(): array
    {
        return ['caller' => $this->user?->id() ?? 'anonymous'];
    }
}
