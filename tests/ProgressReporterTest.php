<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Mcp\ProgressReporter;
use Kinetis\McpProtocol\ProgressEmitter;
use PHPUnit\Framework\TestCase;

/**
 * The Kinetis-facing progress API: what a tool method declares, and the
 * protocol emitter it delegates to.
 */
final class ProgressReporterTest extends TestCase
{
    public function test_report_is_a_no_op_when_no_emitter_is_given(): void
    {
        new ProgressReporter()->report(1, 2, 'halfway');

        $this->addToAssertionCount(1);
    }

    public function test_report_reaches_the_protocol_emitter_with_the_call_own_token(): void
    {
        $captured = [];
        $reporter = new ProgressReporter(new ProgressEmitter(
            static function (array $notification) use (&$captured): void {
                $captured[] = $notification;
            },
            'token-1',
        ));

        $reporter->report(1, 3, 'step one');
        $reporter->report(2);

        self::assertSame([
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
                'progressToken' => 'token-1',
                'progress' => 1,
                'total' => 3,
                'message' => 'step one',
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
                'progressToken' => 'token-1',
                'progress' => 2,
            ]],
        ], $captured);
    }
}
