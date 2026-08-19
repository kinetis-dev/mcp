<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\ProgressReporter;

/**
 * Reports who the caller is, from a progress-streaming tool — the one
 * combination where identity has to cross from the request's scope into
 * the stream's own.
 */
final readonly class IdentityReportingController
{
    public function __construct(private ?CurrentUserInterface $user = null) {}

    /**
     * @return array{caller: string}
     */
    #[McpTool(name: 'whoami_streaming', description: 'Reports the caller identity with progress')]
    public function whoami(ProgressReporter $progress): array
    {
        $progress->report(1, 1);

        return ['caller' => $this->user?->id() ?? 'anonymous'];
    }
}
