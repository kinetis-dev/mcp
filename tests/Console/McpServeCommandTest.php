<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Console;

use Kinetis\Mcp\Console\McpServeCommand;
use Kinetis\Mcp\McpDiscovery;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpServer;
use Kinetis\Container\AppScope;
use PHPUnit\Framework\TestCase;

/**
 * The MCP server as a command. It reads JSON-RPC one message per line
 * from stdin and writes one per line back, so driving it with in-memory
 * streams exercises the whole thing — project-root resolution, registry
 * construction, dispatcher wiring, and the transport loop — and stops
 * when the input is exhausted rather than blocking on a terminal.
 */
final class McpServeCommandTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/kinetis_mcp_serve_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot);
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['McpServeProbe\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/composer.json');
        @rmdir($this->projectRoot);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function serve(array $messages): array
    {
        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);

        foreach ($messages as $message) {
            fwrite($input, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        }

        rewind($input);

        $output = fopen('php://memory', 'r+');
        self::assertIsResource($output);

        // The binding the package bootstrap makes in a real install,
        // here pointed at this test's own scratch project root.
        $app = new AppScope();
        $projectRoot = $this->projectRoot;
        $app->bind(McpServer::class, static fn ($c): McpServer => new McpServer(
            McpDiscovery::discover($projectRoot),
            new McpDispatcher($c),
        ));
        $app->boot();

        $exit = new McpServeCommand($app->createRequestScope(), $input, $output)->serve();

        self::assertSame(0, $exit, 'the command must exit cleanly once stdin closes');

        rewind($output);
        $written = (string) stream_get_contents($output);

        $decoded = [];

        foreach (explode("\n", trim($written)) as $line) {
            if ($line !== '') {
                $decoded[] = json_decode($line, true);
            }
        }

        return $decoded;
    }

    public function test_answers_an_initialize_handshake(): void
    {
        $responses = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
        ]);

        self::assertCount(1, $responses);
        self::assertSame('2.0', $responses[0]['jsonrpc']);
        self::assertSame(1, $responses[0]['id']);
        self::assertArrayHasKey('protocolVersion', $responses[0]['result']);
    }

    /**
     * A project with nothing to serve still answers cleanly — empty
     * lists, not errors. In a real install the docs resources appear
     * here too, contributed through this package's own extra.kinetis
     * scan root, which a scratch directory with no installed.json cannot
     * carry.
     */
    public function test_lists_tools_and_resources_for_a_project_with_none_of_its_own(): void
    {
        $responses = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'],
        ]);

        self::assertCount(2, $responses);
        self::assertSame([], $responses[0]['result']['tools']);
        self::assertSame([], $responses[1]['result']['resources']);
    }

    public function test_an_unknown_method_is_a_json_rpc_error_rather_than_a_crash(): void
    {
        $responses = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'no/such/method'],
        ]);

        self::assertSame(-32601, $responses[0]['error']['code']);
    }

    /**
     * A notification carries no id and expects no reply, so the loop must
     * consume it and keep going rather than answering it.
     */
    public function test_a_notification_produces_no_response(): void
    {
        $responses = $this->serve([
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ]);

        self::assertCount(1, $responses);
        self::assertSame(1, $responses[0]['id']);
    }
}
