<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Transport;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Mcp\McpServer;

/**
 * The transport `php kinetis mcp:serve` actually runs — one JSON-RPC
 * message per line on stdin, one response per line on stdout, matching
 * how Claude Desktop/Cursor and other local MCP clients launch a server
 * as a subprocess. Input/output streams are injectable (defaulting to
 * STDIN/STDOUT) so this is testable against php://memory instead of the
 * real process streams; the loop ends naturally at EOF either way — a
 * closed stdin in production, or the end of a fixed in-memory buffer in
 * tests.
 *
 * Progress notifications fall out of this transport for free: stdio is
 * already one-JSON-RPC-message-per-line, so a `notifications/progress`
 * message a tool call triggers mid-invocation is just one more line
 * written before the final response line — no separate streaming concept
 * needed here the way Kernel's HTTP endpoint needs one.
 */
final class StdioTransport
{
    /**
     * $app, when given, makes each message its own unit of work: a fresh
     * RequestScope per line, the standard rollback hook, disposal once
     * the response is written, and a gc_collect_cycles() — a stdio
     * server is a persistent process by definition, the same reasoning
     * QueueWorker applies per job. A tool that stashes state on the
     * scope of one call never sees it on the next — each message gets
     * its own scope. Omitted, messages share the dispatcher's own
     * container, which is not per-message-scoped.
     *
     * @param resource $input
     * @param resource $output
     */
    public function run(McpServer $server, $input, $output, ?AppScope $app = null): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, associative: true);

            $onNotification = static function (array $notification) use ($output): void {
                fwrite($output, json_encode([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => $notification,
                ], JSON_THROW_ON_ERROR) . "\n");
            };

            $scope = $app?->createRequestScope();

            if ($scope !== null) {
                self::wireTransactionGuard($scope);
            }

            try {
                $response = is_array($decoded)
                    ? $server->handle($decoded, $onNotification, $scope)
                    : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']];
            } finally {
                $scope?->dispose();
            }

            if ($response !== null) {
                fwrite($output, json_encode($response, JSON_THROW_ON_ERROR) . "\n");
            }

            if ($scope !== null) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * The same rollback hook Kernel wires per HTTP request, behind the
     * same class_exists() gate: kinetis/persistence is optional, the
     * class name is a plain string so nothing autoloads when it is not
     * installed, and wiring is a no-op for a message that never opens a
     * transaction.
     */
    private static function wireTransactionGuard(RequestScope $scope): void
    {
        $guardClass = 'Kinetis\\Persistence\\TransactionGuard';

        if (!class_exists($guardClass)) {
            return;
        }

        $guard = $scope->get($guardClass);
        $scope->onDispose($guard->rollbackDangling(...));
    }
}
