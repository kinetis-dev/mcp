<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Transport;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Container\TransactionGuardHook;
use Kinetis\Logging\SafeLogger;
use Kinetis\Mcp\Exception\StdioWriteException;
use Kinetis\Mcp\JsonRpcCodec;
use Kinetis\Mcp\McpServer;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

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
     * RequestScope per line, the standard rollback hook, the response
     * written, then disposal and a gc_collect_cycles() in a finally —
     * a stdio server is a persistent process by definition, the same
     * reasoning QueueWorker applies per job. A tool that stashes state on
     * the scope of one call never sees it on the next — each message
     * gets its own scope. Omitted, messages share the dispatcher's own
     * container, which is not per-message-scoped.
     *
     * @param resource $input
     * @param resource $output
     */
    public function run(McpServer $server, $input, $output, ?AppScope $app = null): void
    {
        while (($line = fgets($input)) !== false) {
            // Only the framing line terminator is removed here — never a
            // bare trim(), which by default also strips NUL and vertical-
            // tab bytes, silently turning input that was only valid JSON
            // *because* those bytes were stripped into accepted input. A
            // line reduced to nothing but spaces/tabs after that is still
            // treated as a deliberate blank between messages and skipped;
            // anything else (including a NUL/vertical-tab-wrapped line)
            // falls through to JsonRpcCodec::decode(), which rejects it
            // as a parse error rather than silently normalizing it away.
            $line = rtrim($line, "\r\n");

            if ($line === '' || trim($line, " \t") === '') {
                continue;
            }

            $decoded = JsonRpcCodec::decode($line);

            // A progress-notification write is never allowed to throw
            // out of this closure directly: ProgressReporter::report()
            // calls $emit() with no try/catch of its own, so an
            // exception here would propagate straight up through the
            // tool method and into McpServer::callTool()'s own generic
            // catch (Throwable) — which would convert a corrupted-stream
            // output failure into an ordinary "Tool execution failed."
            // JSON-RPC result and then try to write *that* into the same
            // now-partially-written stream, exactly the "second protocol
            // response after a partial frame" this transport must never
            // produce. Instead, a failure here is caught and stashed in
            // $writeFailure; every notification after the first failure
            // is skipped outright (never even attempts another write
            // into an already-corrupted stream), and $writeFailure is
            // checked once $server->handle() returns, before the final
            // response is ever written — see below.
            $writeFailure = null;

            $onNotification = function (array $notification) use ($output, &$writeFailure): void {
                if ($writeFailure !== null) {
                    return;
                }

                try {
                    $this->writeFrame($output, [
                        'jsonrpc' => '2.0',
                        'method' => 'notifications/progress',
                        'params' => $notification,
                    ]);
                } catch (StdioWriteException $e) {
                    $writeFailure = $e;
                }
            };

            $scope = $app?->createRequestScope();

            // $server->handle() never throws — its own top-level catch
            // maps anything unexpected to a -32603 JSON-RPC error, and
            // every JSON-RPC response it builds is itself already
            // json_encode()d and caught internally before being embedded
            // as text (McpServer::callTool()/handle()), so $response is
            // always both the real, already-computed outcome and already
            // safe to encode again here. TransactionGuardHook::
            // registerIfAvailable() and the write itself (writeFrame(),
            // or a closed/broken stdout) can still genuinely fail — a
            // broken container binding resolving one of TransactionGuard's
            // own dependencies, a broken output stream — and either
            // failure propagates as the real primary failure here.
            // $writeFailure (a progress-notification write that failed
            // mid-call, stashed rather than thrown — see $onNotification
            // above) is re-thrown here, before the final response is
            // ever attempted, for the identical reason: nothing may be
            // written into a stream a prior write has already left in a
            // partial, ambiguous state. disposeScope() below is
            // guaranteed non-throwing (see its own docblock), which is
            // what makes it safe to run in this finally regardless of
            // what inside the try block failed — a `finally` block is
            // only dangerous when the block itself can throw. Every call
            // that can genuinely fail is inside this try, not just the
            // final write, so a scope that was actually created always
            // gets disposed even when registering its own dispose hook,
            // or an earlier progress write, is what fails.
            try {
                if ($scope !== null) {
                    TransactionGuardHook::registerIfAvailable($scope);
                }

                $response = array_key_exists('message', $decoded)
                    ? $server->handle($decoded['message'], $onNotification, $scope)
                    : $decoded['errorResponse'];

                if ($writeFailure !== null) {
                    throw $writeFailure;
                }

                if ($response !== null) {
                    $this->writeFrame($output, $response);
                }
            } finally {
                $this->disposeScope($scope, $app);

                if ($scope !== null) {
                    gc_collect_cycles();
                }
            }
        }
    }

    /**
     * Writes one complete JSON-RPC frame — $message JSON-encoded, plus
     * the framing newline — treating it as a single atomic unit from
     * this transport's own perspective. PHP permits fwrite() to accept
     * fewer bytes than given (a partial write), return false, or return
     * exactly 0; a single, unchecked fwrite() call (this method's own
     * predecessor) can therefore silently emit a truncated frame with no
     * trailing newline, after which the next message written corrupts
     * the same NDJSON stream. This loops until every byte of the frame
     * has actually been written, never assuming one call transmits the
     * whole thing.
     *
     * $output is never put into non-blocking mode by this transport —
     * STDOUT under a real `mcp:serve` process, and every stream this
     * class is tested against, are both synchronous/blocking — so a
     * 0-byte return here is never "temporarily full, try again" the way
     * it could be on a genuinely non-blocking socket; it means the
     * stream can no longer accept data at all (most commonly a closed
     * reader on the other end of a pipe) and is treated as terminal
     * immediately, the same as `false`, rather than retried. Retrying
     * would spin indefinitely against a stream that will never report
     * progress again.
     *
     * @param resource $output
     * @param array<string, mixed> $message
     * @throws StdioWriteException when the frame could only be partially
     *     written — the byte counts it carries are exactly what a
     *     caller needs to know the stream is now in an unrecoverable,
     *     ambiguous state, so nothing more, including a second protocol
     *     response describing this very failure, may be written to it
     *     afterward.
     */
    private function writeFrame($output, array $message): void
    {
        $frame = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        $total = strlen($frame);
        $written = 0;

        while ($written < $total) {
            $result = fwrite($output, substr($frame, $written));

            if ($result === false || $result === 0) {
                throw StdioWriteException::partialFrame($written, $total);
            }

            $written += $result;
        }
    }

    /**
     * Disposes $scope, if any — guaranteed never to throw, which is what
     * makes it safe to call from inside run()'s own finally regardless of
     * whether the response was successfully written or the write itself
     * failed. A cleanup failure here is never allowed to escape and
     * terminate the whole persistent stdio transport over what is, at
     * this point, only ever server diagnostics — never a second JSON-RPC
     * message, and never written to $output. Logged through
     * SafeLogger::logFrom(), not log(): resolving LoggerInterface from
     * AppScope is itself covered by the same containment as the logger's
     * own log() call — $scope is already disposed by the time a cleanup
     * failure could occur, so it can no longer resolve one safely, and a
     * throwing LoggerInterface binding/factory on AppScope must not be
     * able to escape here either.
     */
    private function disposeScope(?RequestScope $scope, ?AppScope $app): void
    {
        if ($scope === null) {
            return;
        }

        try {
            $scope->dispose();
        } catch (Throwable $disposeFailure) {
            if ($app === null) {
                return;
            }

            SafeLogger::logFrom(
                fn (): LoggerInterface => $app->get(LoggerInterface::class),
                LogLevel::ERROR,
                'Request scope disposal failed while handling a stdio MCP message, after the response was already computed: {message}',
                ['message' => $disposeFailure->getMessage(), 'exception' => $disposeFailure],
            );
        }
    }
}
