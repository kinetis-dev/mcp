<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Transport;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\Exception\StdioWriteException;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Transport\StdioTransport;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\DisposalFailingProgressToolController;
use Kinetis\Mcp\Tests\Fixtures\DisposalFailingToolController;
use Kinetis\Mcp\Tests\Fixtures\DisposalRecorder;
use Kinetis\Mcp\Tests\Fixtures\InMemoryLogger;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use Kinetis\Mcp\Tests\Fixtures\ThrowingLogger;
use Kinetis\Mcp\Tests\Fixtures\ThrowingResourceController;
use Kinetis\Mcp\Tests\Fixtures\ThrowsAfterFirstResolutionLogger;
use Kinetis\Mcp\Tests\Fixtures\WriteControllableStreamWrapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

final class StdioTransportTest extends TestCase
{
    protected function setUp(): void
    {
        WriteControllableStreamWrapper::register();
    }

    /**
     * @param list<int|false> $writeReturns
     * @return resource
     */
    private function controllableStream(array $writeReturns): mixed
    {
        $context = stream_context_create([WriteControllableStreamWrapper::PROTOCOL => ['writeReturns' => $writeReturns]]);

        return fopen(WriteControllableStreamWrapper::PROTOCOL . '://test', 'r+b', context: $context);
    }

    /**
     * @param resource $stream
     */
    private function readAll(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    /**
     * @return array{0: McpServer, 1: AppScope}
     */
    private function progressToolServerWithApp(): array
    {
        $registry = new McpRegistry();
        $registry->register(DisposalFailingProgressToolController::class);

        $app = new AppScope();
        $app->boot();

        return [new McpServer($registry, new McpDispatcher($app)), $app];
    }

    private function server(): McpServer
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $app = new AppScope();
        $app->boot();

        return new McpServer($registry, new McpDispatcher($app));
    }

    private function progressServer(): McpServer
    {
        $registry = new McpRegistry();
        $registry->register(ProgressReportingController::class);

        $app = new AppScope();
        $app->boot();

        return new McpServer($registry, new McpDispatcher($app));
    }

    private function throwingLoggerServer(ThrowingLogger $logger): McpServer
    {
        $registry = new McpRegistry();
        $registry->register(ThrowingResourceController::class);

        $app = new AppScope();
        $app->boot();

        return new McpServer($registry, new McpDispatcher($app), logger: $logger);
    }

    /**
     * Unlike server()/progressServer()/throwingLoggerServer() above, this
     * also returns the AppScope itself — run()'s own per-message scope
     * (and thus disposal) only happens when $app is actually passed as
     * run()'s 4th argument, which every disposal-precedence test below
     * needs to exercise.
     *
     * @return array{0: McpServer, 1: AppScope}
     */
    private function disposalFailingServerWithApp(?LoggerInterface $logger = null): array
    {
        $registry = new McpRegistry();
        $registry->register(DisposalFailingToolController::class);

        $app = new AppScope();

        if ($logger !== null) {
            $app->instance(LoggerInterface::class, $logger);
        }

        $app->boot();

        return [new McpServer($registry, new McpDispatcher($app)), $app];
    }

    /**
     * @return resource
     */
    private function streamOf(string $contents): mixed
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * The `_meta` every request needs — required by the 2026-07-28
     * protocol, the only revision this server implements.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
    }

    public function test_processes_one_json_rpc_message_per_line(): void
    {
        $input = $this->streamOf(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => $this->meta()]]) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]) . "\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $lines = array_filter(explode("\n", stream_get_contents($output)));

        self::assertCount(2, $lines);
        self::assertSame(1, json_decode($lines[0], true)['id']);
        self::assertSame(2, json_decode($lines[1], true)['id']);
    }

    public function test_notifications_produce_no_output_line(): void
    {
        $input = $this->streamOf(json_encode(['jsonrpc' => '2.0', 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]) . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        self::assertSame('', stream_get_contents($output));
    }

    public function test_malformed_json_gets_a_parse_error_response(): void
    {
        $input = $this->streamOf("not valid json\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertSame(-32700, $response['error']['code']);
    }

    public function test_blank_lines_are_skipped(): void
    {
        $input = $this->streamOf("\n" . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => $this->meta()]]) . "\n\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $lines = array_filter(explode("\n", stream_get_contents($output)));

        self::assertCount(1, $lines);
    }

    /**
     * PHP's bare trim() strips NUL bytes by default (its charlist is
     * " \t\n\r\0\x0B") — only the framing line terminator is stripped
     * here, so a line that's only valid JSON *because* of a wrapping NUL
     * stays invalid: the NUL bytes remain in the line json_decode()
     * actually sees.
     */
    public function test_a_nul_wrapped_line_is_a_parse_error_not_silently_stripped_into_valid_json(): void
    {
        $validJson = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]);
        $input = $this->streamOf("\x00{$validJson}\x00\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertSame(-32700, $response['error']['code']);
    }

    /**
     * The same default trim() charlist also strips vertical tab
     * (\x0B) — a second byte a bare trim() would silently remove.
     */
    public function test_a_vertical_tab_wrapped_line_is_a_parse_error_not_silently_stripped(): void
    {
        $validJson = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]);
        $input = $this->streamOf("\x0B{$validJson}\x0B\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertSame(-32700, $response['error']['code']);
    }

    /**
     * A line reduced to only spaces/tabs, on the other hand, is still a
     * deliberate blank between messages and stays skipped, exactly like
     * a genuinely empty line.
     */
    public function test_a_line_of_only_spaces_and_tabs_is_skipped(): void
    {
        $input = $this->streamOf(
            "   \t  \n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => $this->meta()]]) . "\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $lines = array_filter(explode("\n", stream_get_contents($output)));

        self::assertCount(1, $lines);
    }

    /**
     * A malformed line must not stop the loop — the next, well-formed
     * line still gets processed and answered.
     */
    public function test_a_malformed_line_does_not_stop_the_next_line_from_being_processed(): void
    {
        $validJson = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]);
        $input = $this->streamOf("\x00not valid json\x00\n{$validJson}\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(2, $lines);
        self::assertSame(-32700, json_decode($lines[0], true)['error']['code']);
        self::assertSame(2, json_decode($lines[1], true)['id']);
    }

    // --- The empty-list-vs-empty-object and present-null distinction,
    // through the real stdio wire — same shared JsonRpcCodec::decode()
    // path McpController uses, so a real JSON array/null on this
    // transport gets the identical code an HTTP request would. ---

    public function test_an_empty_json_array_params_is_rejected_over_stdio(): void
    {
        $input = $this->streamOf('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[]}' . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_an_empty_json_object_params_is_accepted_over_stdio(): void
    {
        $input = $this->streamOf(
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
            . "\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertArrayNotHasKey('error', $response);
    }

    public function test_a_present_null_meta_is_rejected_over_stdio(): void
    {
        $input = $this->streamOf('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":null}}' . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_an_empty_list_client_capabilities_is_rejected_over_stdio(): void
    {
        $input = $this->streamOf(
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":[]}}}'
            . "\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $response = json_decode(stream_get_contents($output), true);

        self::assertSame(-32602, $response['error']['code']);
    }

    /**
     * A valid-looking progressToken paired with malformed `arguments`
     * must never emit any progress notifications or invoke the tool —
     * preflight() rejects the whole message before dispatch, stdio's
     * one-line-per-message shape making this easy to prove directly:
     * exactly one line out, the error, nothing else.
     */
    public function test_a_valid_progress_token_with_malformed_arguments_emits_no_notifications(): void
    {
        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'count_to_three',
                'arguments' => [1, 2, 3],
                '_meta' => [...$this->meta(), 'progressToken' => 'tok'],
            ],
        ]) . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->progressServer(), $input, $output);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(1, $lines, 'no progress notification lines, only the one error response');
        $response = json_decode($lines[0], true);
        self::assertSame(-32602, $response['error']['code']);
    }

    /**
     * A structurally valid notification (no id) whose nested content is
     * invalid must produce zero output lines — not an error line, since
     * a notification's caller gets no response regardless of what fails.
     * A real, well-formed request on the next line still gets answered
     * normally, proving the loop isn't otherwise disrupted.
     */
    public function test_a_notification_with_malformed_meta_produces_no_output_line(): void
    {
        $validJson = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]);
        $input = $this->streamOf(
            '{"jsonrpc":"2.0","method":"tools/list","params":{"_meta":"nope"}}' . "\n"
            . "{$validJson}\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(1, $lines);
        self::assertSame(2, json_decode($lines[0], true)['id']);
    }

    public function test_progress_notifications_are_written_as_extra_lines_before_the_final_response(): void
    {
        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => [...$this->meta(), 'progressToken' => 'tok']],
        ]) . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->progressServer(), $input, $output);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(4, $lines);
        self::assertSame('notifications/progress', json_decode($lines[0], true)['method']);
        self::assertSame('notifications/progress', json_decode($lines[1], true)['method']);
        self::assertSame('notifications/progress', json_decode($lines[2], true)['method']);
        self::assertSame(1, json_decode($lines[3], true)['id']);
    }

    /**
     * The real, long-running boundary this transport exists to protect:
     * a first line whose resource throws a secret-bearing exception,
     * against a server whose own logger also throws, must not crash the
     * process or leave the secret anywhere in the written line — and the
     * loop must still process the next line normally afterward.
     */
    public function test_a_failing_line_with_a_throwing_logger_does_not_crash_the_process_or_leak_the_secret(): void
    {
        $logger = new ThrowingLogger();
        $server = $this->throwingLoggerServer($logger);

        $input = $this->streamOf(
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'resources/read',
                'params' => ['uri' => 'kinetis://throws', '_meta' => $this->meta()],
            ]) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]) . "\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($server, $input, $output);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(2, $lines, 'the process must keep running past the failing first line');

        $first = json_decode($lines[0], true);
        self::assertSame(1, $first['id']);
        self::assertSame(-32603, $first['error']['code']);
        self::assertSame('Internal error.', $first['error']['message']);
        self::assertStringNotContainsString('hunter2', $lines[0]);
        self::assertStringNotContainsString('SQLSTATE', $lines[0]);
        self::assertStringNotContainsString('logger itself failed', $lines[0]);

        $second = json_decode($lines[1], true);
        self::assertSame(2, $second['id']);
        self::assertArrayNotHasKey('error', $second, 'the second line must still be processed normally');
    }

    /**
     * The tool call itself succeeds and returns a real response — but
     * its scope's own disposal then fails. That failure must never
     * suppress the already-computed, already-written response, and a
     * later dispose callback must still run despite an earlier one
     * throwing (RequestScope::dispose()'s own existing guarantee,
     * proven here to compose with this transport's containment).
     */
    public function test_a_disposal_failure_does_not_suppress_the_already_computed_response(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        [$server, $app] = $this->disposalFailingServerWithApp();

        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => $this->meta()],
        ]) . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($server, $input, $output, $app);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(1, $lines, 'exactly one JSON-RPC line — the disposal failure must never appear as a second one');
        $response = json_decode($lines[0], true);
        self::assertSame(1, $response['id']);
        self::assertArrayNotHasKey('error', $response, 'the tool call itself succeeded');

        self::assertTrue(DisposalRecorder::$secondRan, 'a later dispose callback still ran despite an earlier one throwing');
        self::assertNotNull(DisposalRecorder::$scope);
        self::assertTrue(DisposalRecorder::$scope->isDisposed());
    }

    public function test_a_disposal_failure_does_not_stop_the_next_message_from_being_processed(): void
    {
        [$server, $app] = $this->disposalFailingServerWithApp();

        $input = $this->streamOf(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'disposal_failing_tool', '_meta' => $this->meta()]]) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]) . "\n",
        );
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($server, $input, $output, $app);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(2, $lines, 'the loop must not terminate — a second message on the same connection is still processed');
        self::assertSame(1, json_decode($lines[0], true)['id']);
        self::assertSame(2, json_decode($lines[1], true)['id']);
    }

    public function test_a_disposal_failure_is_logged_through_the_app_scope_logger(): void
    {
        $logger = new InMemoryLogger();
        [$server, $app] = $this->disposalFailingServerWithApp($logger);

        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => $this->meta()],
        ]) . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($server, $input, $output, $app);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('dispose callback failed', $logger->records[0]['context']['message']);
    }

    /**
     * SafeLogger::log($app->get(LoggerInterface::class), ...) is not
     * actually safe on its own: PHP evaluates that get() call before
     * log() is ever entered, so a throwing LoggerInterface binding
     * escapes uncaught right where disposeScope()'s own resolution
     * happens — suppressing the already-written response and crashing
     * the loop. This proves it doesn't: the fixture logger's first
     * resolution (TransactionGuardHook::registerIfAvailable(), via
     * kinetis/persistence's TransactionGuard) succeeds, and the later
     * resolution disposeScope() makes throws instead — the response must
     * still be written and the loop must still continue.
     */
    public function test_the_response_survives_even_when_the_logger_itself_cannot_be_resolved(): void
    {
        $registry = new McpRegistry();
        $registry->register(DisposalFailingToolController::class);

        $app = new AppScope();
        $loggerFactory = new ThrowsAfterFirstResolutionLogger();
        $app->bind(LoggerInterface::class, $loggerFactory(...), shared: false);
        $app->boot();

        $server = new McpServer($registry, new McpDispatcher($app));

        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => $this->meta()],
        ]) . "\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($server, $input, $output, $app);

        rewind($output);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($output))));

        self::assertCount(1, $lines, 'the response survives even though the logger itself could not be resolved to report the disposal failure');
        $response = json_decode($lines[0], true);
        self::assertSame(1, $response['id']);
        self::assertArrayNotHasKey('error', $response);
    }

    /**
     * A real output failure — fwrite() on an already-closed stream throws
     * a TypeError, the same shape a real broken pipe or an unencodable
     * response would take — must still dispose the scope and run every
     * dispose callback: preserving the "write before cleanup" ordering
     * must never mean cleanup is skipped outright when the write itself
     * is what fails. The output failure itself is the real primary
     * failure here and must propagate, exactly as it always would have —
     * only the guarantee that disposal still happens underneath it is
     * new.
     */
    public function test_an_output_failure_still_disposes_the_scope_and_runs_every_callback(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        $logger = new InMemoryLogger();
        [$server, $app] = $this->disposalFailingServerWithApp($logger);

        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => $this->meta()],
        ]) . "\n");
        $output = fopen('php://memory', 'r+');
        fclose($output);

        try {
            (new StdioTransport())->run($server, $input, $output, $app);
            self::fail('Expected the real output failure to propagate.');
        } catch (TypeError $e) {
            self::assertStringContainsString('stream', $e->getMessage());
        }

        self::assertTrue(DisposalRecorder::$secondRan, 'every dispose callback still ran despite the output failure');
        self::assertNotNull(DisposalRecorder::$scope);
        self::assertTrue(DisposalRecorder::$scope->isDisposed());

        self::assertCount(1, $logger->records, 'the disposal failure is still logged, separately from the output failure that propagated');
        self::assertSame('dispose callback failed', $logger->records[0]['context']['message']);
    }

    // KINETIS-73: fwrite() is permitted to accept fewer bytes than
    // given, or return false/0 outright — a single, unchecked fwrite()
    // call could silently emit a truncated frame with no trailing
    // newline, corrupting the NDJSON stream for every message that
    // followed it. writeFrame() now loops until every byte of a frame
    // has actually been written, and treats a terminal short write as a
    // StdioWriteException rather than a corrupted-but-unnoticed frame.
    // WriteControllableStreamWrapper drives both a recoverable sequence
    // of genuinely short writes (proving the loop correctly assembles
    // one complete frame from many small ones) and a stall after a
    // fixed prefix (proving the terminal-failure path), for both the
    // final-response write and the progress-notification write.

    /**
     * A response whose write is only 10 bytes long on the first attempt
     * — writeReturns [10, 0], the same shape packages/storage's own
     * FailingStreamWrapper tests already use for the identical PHP
     * behavior (a single userland fwrite() call can genuinely return
     * less than requested once the underlying stream's own internal
     * retry gives up at a zero-progress attempt) — must still arrive as
     * exactly one complete, correctly-framed, decodable line, proving
     * writeFrame()'s own loop resumes and finishes the write. Confirmed
     * this genuinely distinguishes the fixed code from the original
     * single, unchecked fwrite() call: run against the reverted,
     * pre-fix code directly, [10, 0] here produces a truncated 10-byte
     * line with no trailing newline and a JSON parse failure, not this
     * test's own passing assertions.
     */
    public function test_a_recoverable_short_write_to_the_final_response_still_produces_a_complete_frame(): void
    {
        $input = $this->streamOf(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $this->meta()]]) . "\n");
        $output = $this->controllableStream([10, 0]);

        (new StdioTransport())->run($this->server(), $input, $output);

        $contents = $this->readAll($output);
        self::assertSame("\n", substr($contents, -1), 'exactly one trailing newline — a complete, correctly-framed line');

        $lines = array_values(array_filter(explode("\n", $contents)));
        self::assertCount(1, $lines);

        $response = json_decode($lines[0], true);
        self::assertSame(1, $response['id']);
        self::assertArrayNotHasKey('error', $response);
    }

    /**
     * The identical short-write recovery, but landing on the *first*
     * progress notification's own write rather than the final response
     * — every notification after it, and the final response itself,
     * must still all arrive as complete, correctly-framed, decodable
     * lines once writeReturns is exhausted and later writes succeed
     * normally.
     */
    public function test_a_recoverable_short_write_to_a_progress_notification_still_produces_complete_frames(): void
    {
        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => [...$this->meta(), 'progressToken' => 'tok']],
        ]) . "\n");
        $output = $this->controllableStream([10, 0]);

        (new StdioTransport())->run($this->progressServer(), $input, $output);

        $contents = $this->readAll($output);
        self::assertSame("\n", substr($contents, -1), 'exactly one trailing newline after the very last frame');

        $lines = array_values(array_filter(explode("\n", $contents)));
        self::assertCount(4, $lines);
        self::assertSame('notifications/progress', json_decode($lines[0], true)['method']);
        self::assertSame('notifications/progress', json_decode($lines[1], true)['method']);
        self::assertSame('notifications/progress', json_decode($lines[2], true)['method']);
        self::assertSame(1, json_decode($lines[3], true)['id']);
    }

    /**
     * A write that succeeds for 10 bytes and then genuinely stalls must
     * throw StdioWriteException naming the exact byte counts, write
     * nothing beyond that 10-byte prefix — no second protocol response
     * describing the failure itself — and still dispose the scope and
     * run every dispose callback, the identical guarantee this file's
     * own TypeError-from-a-closed-stream test already proves for the
     * pre-existing failure shape.
     *
     * writeReturns is [10, 0, false], not [10, false]: PHP's own stream
     * layer already retries a short/zero-progress stream_write()
     * internally, within a single userland fwrite() call, up to the
     * first zero-progress attempt — confirmed directly, not assumed,
     * the same discipline packages/storage's own FailingStreamWrapper
     * tests already document for the identical PHP behavior. [10, false]
     * alone lets that internal retry consume both entries within one
     * fwrite() call and report the accumulated 10 as an ordinary partial
     * success, so writeFrame()'s own loop simply continues and the
     * remaining bytes are written normally on the next call — no
     * failure ever reaches writeFrame() at all. The third entry (0, then
     * false) is what survives past that internal retry to reach
     * writeFrame()'s own second fwrite() call as a genuine, immediate
     * failure.
     */
    public function test_a_terminal_write_failure_on_the_final_response_propagates_disposes_the_scope_and_writes_nothing_further(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        $logger = new InMemoryLogger();
        [$server, $app] = $this->disposalFailingServerWithApp($logger);

        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => $this->meta()],
        ]) . "\n");
        $output = $this->controllableStream([10, 0, false]);

        try {
            (new StdioTransport())->run($server, $input, $output, $app);
            self::fail('Expected a StdioWriteException.');
        } catch (StdioWriteException $e) {
            self::assertStringContainsString('Only wrote 10 of', $e->getMessage());
        }

        $contents = $this->readAll($output);
        self::assertSame(10, strlen($contents), 'nothing beyond the partial prefix was written — no second protocol response attempted');

        self::assertTrue(DisposalRecorder::$secondRan, 'every dispose callback still ran despite the write failure');
        self::assertNotNull(DisposalRecorder::$scope);
        self::assertTrue(DisposalRecorder::$scope->isDisposed());
    }

    /**
     * The identical terminal-failure guarantee, exercised through the
     * separate stash-in-the-closure-then-rethrow path a progress-
     * notification write failure takes rather than the final response's
     * own direct throw: a stall on the *first* notification must never
     * attempt the second/third notifications the tool still calls
     * report() for, nor the final response — exactly one partial prefix
     * written, nothing more — while still disposing the scope and
     * running every dispose callback.
     *
     * writeReturns is [10, 0, false], not [10, false] — see the final-
     * response test above for why the third entry is what's needed to
     * survive PHP's own internal short-write retry and reach
     * writeFrame() as a genuine failure.
     */
    public function test_a_terminal_write_failure_on_a_progress_notification_propagates_disposes_the_scope_and_writes_no_further_frames(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        [$server, $app] = $this->progressToolServerWithApp();

        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_progress_tool', '_meta' => [...$this->meta(), 'progressToken' => 'tok']],
        ]) . "\n");
        $output = $this->controllableStream([10, 0, false]);

        try {
            (new StdioTransport())->run($server, $input, $output, $app);
            self::fail('Expected a StdioWriteException.');
        } catch (StdioWriteException $e) {
            self::assertStringContainsString('Only wrote 10 of', $e->getMessage());
        }

        $contents = $this->readAll($output);
        self::assertSame(
            10,
            strlen($contents),
            'nothing beyond the first notification\'s partial prefix was written — no second notification and no final response attempted',
        );

        self::assertTrue(DisposalRecorder::$secondRan, 'every dispose callback still ran despite the notification write failure');
        self::assertNotNull(DisposalRecorder::$scope);
        self::assertTrue(DisposalRecorder::$scope->isDisposed());
    }
}
