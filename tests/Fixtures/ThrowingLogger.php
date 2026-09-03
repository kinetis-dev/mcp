<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Every call throws — proving McpServer's own terminal error boundary
 * (both the outer unexpected-exception catch and callTool()'s inner
 * one) keeps returning a valid response even when the logger it reports
 * through is itself broken. Records the call before throwing, so a test
 * can still see what was attempted.
 */
final class ThrowingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];

        throw new RuntimeException('The logger itself failed.');
    }
}
