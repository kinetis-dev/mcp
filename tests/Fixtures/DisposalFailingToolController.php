<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Mcp\Attributes\McpTool;
use RuntimeException;

/**
 * Succeeds, but registers two onDispose callbacks on its own scope — the
 * first always throws, the second records that it ran — proving the
 * stdio/streamed-HTTP transports' own disposal containment composes with
 * RequestScope::dispose()'s existing "every callback runs, even after an
 * earlier one throws" guarantee, not just that the first callback's
 * failure alone is contained. RequestScope is constructor-injected, not
 * a method parameter: McpDispatcher::resolveArguments() treats every
 * #[McpTool] method parameter as a flat MCP tool argument (save for the
 * one ProgressReporter special case) — the controller itself is what's
 * resolved through the per-message scope, per McpDispatcher::
 * callTool()'s own docblock.
 */
final readonly class DisposalFailingToolController
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    #[McpTool(name: 'disposal_failing_tool', description: 'Succeeds, but its scope fails to dispose')]
    public function run(): array
    {
        $scope = $this->scope;

        $scope->onDispose(static function (): void {
            throw new RuntimeException('dispose callback failed');
        });
        $scope->onDispose(static function () use ($scope): void {
            DisposalRecorder::$secondRan = true;
            DisposalRecorder::$scope = $scope;
        });

        return ['done' => true];
    }
}
