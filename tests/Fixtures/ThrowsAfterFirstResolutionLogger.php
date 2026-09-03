<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An AppScope::bind(LoggerInterface::class, ...) factory that succeeds
 * $succeeds times — for whatever eagerly resolves LoggerInterface ahead
 * of the call under test (ExceptionHandlerMiddleware's own construction,
 * Kernel's/StdioTransport's/McpController's own TransactionGuardHook
 * calls, via TransactionGuard's own constructor dependency when
 * kinetis/persistence is installed alongside kinetis/mcp) — and throws
 * on every resolution after that, proving a disposal-failure log call
 * surviving needs more than SafeLogger::log()'s own containment: the
 * *resolution* itself (SafeLogger::logFrom(), not log()) has to be
 * covered too, since a real AppScope binding can throw on a later call
 * even when earlier ones for the identical id succeeded.
 *
 * Must be registered with `$app->bind(LoggerInterface::class, $this, shared:
 * false)` — shared caching would let the first successful resolution's
 * instance answer every later call too, never reaching a later
 * invocation of this class at all.
 */
final class ThrowsAfterFirstResolutionLogger
{
    private int $calls = 0;

    public function __construct(
        private readonly int $succeeds = 1,
    ) {}

    public function __invoke(): LoggerInterface
    {
        $this->calls++;

        if ($this->calls <= $this->succeeds) {
            return new NullLogger();
        }

        throw new RuntimeException('logger factory failed');
    }
}
