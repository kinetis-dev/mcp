<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Transport;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Transport\StdioTransport;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use PHPUnit\Framework\TestCase;

final class StdioTransportTest extends TestCase
{
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

    public function test_processes_one_json_rpc_message_per_line(): void
    {
        $input = $this->streamOf(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']) . "\n"
            . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']) . "\n",
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
        $input = $this->streamOf(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n");
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
        $input = $this->streamOf("\n" . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']) . "\n\n");
        $output = fopen('php://memory', 'r+');

        (new StdioTransport())->run($this->server(), $input, $output);

        rewind($output);
        $lines = array_filter(explode("\n", stream_get_contents($output)));

        self::assertCount(1, $lines);
    }

    public function test_progress_notifications_are_written_as_extra_lines_before_the_final_response(): void
    {
        $input = $this->streamOf(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => 'tok']],
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
}
