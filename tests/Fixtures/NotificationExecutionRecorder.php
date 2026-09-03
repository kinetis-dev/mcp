<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

/**
 * A static handoff so a test can confirm a tool genuinely ran even
 * though the notification path that invoked it produces no response to
 * inspect — the same static-handoff shape DisposalRecorder already
 * establishes.
 */
final class NotificationExecutionRecorder
{
    public static int $calls = 0;
}
