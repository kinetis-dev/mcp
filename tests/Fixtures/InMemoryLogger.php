<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Records every log call instead of writing anywhere — shared across
 * suites (Persistence, Http, Mcp) that need to assert a framework
 * internal actually logged something, rather than re-implementing the
 * same spy in each one.
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
