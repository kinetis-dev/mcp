<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Mcp\ProgressReporter;
use PHPUnit\Framework\TestCase;

final class ProgressReporterTest extends TestCase
{
    public function test_report_is_a_no_op_when_no_emit_closure_is_given(): void
    {
        $reporter = new ProgressReporter(null);

        $reporter->report(1, 2, 'halfway');

        $this->addToAssertionCount(1);
    }

    public function test_report_invokes_the_emit_closure_with_the_expected_payload(): void
    {
        $captured = [];
        $reporter = new ProgressReporter(
            static function (array $payload) use (&$captured): void {
                $captured[] = $payload;
            },
            'token-1',
        );

        $reporter->report(1, 3, 'step one');
        $reporter->report(2, 3);

        self::assertSame([
            ['progressToken' => 'token-1', 'progress' => 1, 'total' => 3, 'message' => 'step one'],
            ['progressToken' => 'token-1', 'progress' => 2, 'total' => 3, 'message' => null],
        ], $captured);
    }
}
