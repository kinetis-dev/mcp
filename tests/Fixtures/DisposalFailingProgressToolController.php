<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\ProgressReporter;
use RuntimeException;

/**
 * DisposalFailingToolController's own scope/dispose-callback shape,
 * combined with ProgressReportingController's own report() calls — the
 * one fixture StdioTransportTest needs to prove a write failure on a
 * *progress notification* (not the final response) still disposes the
 * scope and runs every callback, the identical guarantee already proven
 * for a final-response write failure, but exercised through the
 * separate stash-in-the-closure-then-rethrow path notification writes
 * take.
 */
final readonly class DisposalFailingProgressToolController
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    #[McpTool(name: 'disposal_failing_progress_tool', description: 'Reports progress twice; its scope fails to dispose')]
    public function run(ProgressReporter $progress): array
    {
        $scope = $this->scope;

        $scope->onDispose(static function (): void {
            throw new RuntimeException('dispose callback failed');
        });
        $scope->onDispose(static function () use ($scope): void {
            DisposalRecorder::$secondRan = true;
            DisposalRecorder::$scope = $scope;
        });

        $progress->report(1, 2, 'one');
        $progress->report(2, 2, 'two');

        return ['done' => true];
    }
}
