<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\McpProtocol\ProgressEmitter;

/**
 * Injected into a tool method by type — see McpDispatcher::derivePlan() —
 * so it can report progress on a long-running call without knowing
 * anything about the transport carrying that progress out.
 *
 * This is the Kinetis-facing name: a tool method declares a parameter of
 * this type, and the protocol package's own emitter stays behind it, so an
 * application controller imports nothing from the wire layer. report()
 * invokes the emitter synchronously, inline, on the tool method's own call
 * stack — nothing here pauses the tool, only lets it emit a message part
 * way through its own execution.
 *
 * The emitter is absent whenever the call carried no `_meta.progressToken`
 * or the transport cannot carry a notification, so report() silently does
 * nothing and tool code can always call it unconditionally.
 */
final readonly class ProgressReporter
{
    public function __construct(private ?ProgressEmitter $emitter = null) {}

    public function report(int|float $progress, int|float|null $total = null, ?string $message = null): void
    {
        $this->emitter?->report($progress, $total, $message);
    }
}
