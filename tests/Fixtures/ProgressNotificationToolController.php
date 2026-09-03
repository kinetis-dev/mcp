<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\ProgressReporter;

/**
 * Same shape as ProgressReportingController's own count_to_three, plus a
 * static handoff (NotificationExecutionRecorder) so a test can confirm
 * this genuinely ran even when the call that invoked it — a tools/call
 * notification, with no id — produces no response to inspect.
 */
final readonly class ProgressNotificationToolController
{
    #[McpTool(name: 'count_to_three_and_record', description: 'Reports progress three times, records that it ran, then returns done')]
    public function countToThree(ProgressReporter $progress): array
    {
        $progress->report(1, 3, 'one');
        $progress->report(2, 3, 'two');
        $progress->report(3, 3, 'three');

        NotificationExecutionRecorder::$calls++;

        return ['done' => true];
    }
}
