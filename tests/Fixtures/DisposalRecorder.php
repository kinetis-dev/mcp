<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;

/**
 * A static handoff so a test can confirm a second onDispose callback
 * still ran despite an earlier one throwing, and inspect the scope
 * afterward — the same static-handoff shape kinetis/queue's own
 * CapturedScopeHolder/DisposalCallbackHolder fixtures already establish.
 */
final class DisposalRecorder
{
    public static bool $secondRan = false;

    public static ?RequestScope $scope = null;
}
