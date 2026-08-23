<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Closure;

/**
 * Injected into a tool method (by type — see McpDispatcher::resolveArguments())
 * so it can report progress on a long-running call without knowing anything
 * about the transport carrying that progress out. report() just invokes a
 * closure synchronously, inline, on the same call stack as the tool method
 * itself — there's no coroutine/suspension involved, since nothing here
 * needs to pause the tool method, only to let it emit a message at a point
 * in its own execution.
 *
 * $emit is null whenever the calling request didn't include a
 * `_meta.progressToken` (per spec, progress notifications only make sense
 * tied to a token the client is prepared to receive them for) — report()
 * silently no-ops in that case, so tool code can always call it
 * unconditionally without checking whether it's in a streaming context.
 */
final class ProgressReporter
{
    public function __construct(
        private readonly ?Closure $emit,
        private readonly int|string|null $progressToken = null,
    ) {}

    public function report(int|float $progress, int|float|null $total = null, ?string $message = null): void
    {
        if ($this->emit === null) {
            return;
        }

        ($this->emit)([
            'progressToken' => $this->progressToken,
            'progress' => $progress,
            'total' => $total,
            'message' => $message,
        ]);
    }
}
