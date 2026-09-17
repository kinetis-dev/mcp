<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Closure;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Logging\SafeLogger;
use Kinetis\McpProtocol\MessageHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Makes each stdio message its own unit of work: a fresh RequestScope per
 * decoded message, disposed once the response has been computed. A stdio
 * server is a persistent process by definition — the same reasoning
 * QueueWorker applies per job — so a tool that stashes state on the scope
 * of one call must never see it on the next.
 *
 * Over HTTP there is no decorator: the request already has a scope that
 * Kernel created and disposes, and {@see Http\McpController} passes that
 * one straight through.
 *
 * Disposal happens before the transport writes the final response frame,
 * which is what a `finally` around the inner call buys. Progress
 * notifications may already have gone out by then; they belong to the tool
 * that was still running. A disposal failure never writes a second
 * protocol response and never ends the process: it is contained here and
 * only ever reported to the application's own logger.
 *
 * The incoming $context is deliberately not forwarded. The per-message
 * scope this creates is the context, and the transport calling in has none
 * of its own to contribute.
 */
final readonly class ScopedMessageHandler implements MessageHandler
{
    public function __construct(
        private MessageHandler $inner,
        private AppScope $app,
    ) {}

    /**
     * @param array<string, mixed> $message
     * @param Closure(array<string, mixed>): void|null $emit
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function handle(array $message, ?Closure $emit = null, ?object $context = null): ?array
    {
        $scope = $this->app->createRequestScope();

        try {
            return $this->inner->handle($message, $emit, $scope);
        } finally {
            $this->dispose($scope);
            // Only for a scope that was actually created: a Kinetis
            // request scope can hold cycles, and a persistent process
            // would otherwise accumulate them message after message.
            gc_collect_cycles();
        }
    }

    /**
     * Guaranteed never to throw, which is what makes it safe inside the
     * `finally` above regardless of what the inner call did. Logged
     * through SafeLogger::logFrom() rather than log(): the scope is
     * already disposed by the time a cleanup failure can occur, so the
     * logger is resolved from AppScope, and a throwing binding there must
     * not escape either.
     */
    private function dispose(RequestScope $scope): void
    {
        try {
            $scope->dispose();
        } catch (Throwable $failure) {
            SafeLogger::logFrom(
                fn (): LoggerInterface => $this->app->get(LoggerInterface::class),
                LogLevel::ERROR,
                'Request scope disposal failed while handling a stdio MCP message, after the response was already computed: {message}',
                ['message' => $failure->getMessage(), 'exception' => $failure],
            );
        }
    }
}
