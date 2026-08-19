<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

/**
 * Registered on AppScope by the test, so a dispose hook's effect stays
 * observable after the scope that ran it is gone.
 */
final class DisposeRecorder
{
    public int $disposals = 0;
}
