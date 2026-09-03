<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Config\Config;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\Http\McpController;
use Kinetis\Http\StreamedResponse;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Transport\StdioTransport;
use Kinetis\Mcp\Tests\Fixtures\DisposeRecorder;
use Kinetis\Mcp\Tests\Fixtures\ScopeProbeToolController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Each MCP message is its own unit of work — the same discipline an
 * HTTP request, a queue job, and a CLI command already get. Everything
 * here asserts on behavior a tool can observe (the scope it was
 * handed, what survives to the next call, when dispose hooks fire), so
 * these tests describe the contract rather than the plumbing.
 */
final class McpScopeLifecycleTest extends TestCase
{
    private function app(): AppScope
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->instance(DisposeRecorder::class, new DisposeRecorder());
        $app->instance(McpServer::class, new McpServer($this->registry(), new McpDispatcher($app)));
        $app->boot();

        return $app;
    }

    private function kernelFor(AppScope $app): Kernel
    {
        $router = new Router();
        $router->register(McpController::class);

        return new Kernel($app, $router, middlewareGroups: ['mcp' => []]);
    }

    private function registry(): McpRegistry
    {
        $registry = new McpRegistry();
        $registry->register(ScopeProbeToolController::class);

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function toolCall(int $id, string $tool): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $tool, '_meta' => self::meta()],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private static function toolResult(array $response): array
    {
        $text = $response['result']['content'][0]['text'];
        \assert(\is_string($text));

        /** @var array<string, mixed> */
        return \json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_http_gives_each_message_its_own_scope(): void
    {
        $app = $this->app();
        $kernel = $this->kernelFor($app);

        $first = self::toolResult($this->postTool($kernel, 1));
        $second = self::toolResult($this->postTool($kernel, 2));

        // A marker registered on the first call's scope is gone on the
        // second — the scopes are per message, not shared and not the
        // disconnected autowired scope AppScope would produce (that one
        // is also fresh per get(), so the identity check below is what
        // separates the two: see the dispose test for the connected
        // half of the proof).
        self::assertFalse($first['sawEarlierMarker']);
        self::assertFalse($second['sawEarlierMarker']);
        self::assertNotSame($first['scopeObjectId'], $second['scopeObjectId']);
    }

    public function test_http_disposes_the_scope_once_the_response_is_written(): void
    {
        $app = $this->app();
        $kernel = $this->kernelFor($app);

        $response = $this->postTool($kernel, 1, 'register_dispose_hook');

        // The hook reached an AppScope-held recorder — which is only
        // possible if the scope the tool was handed is connected to the
        // real AppScope (a disconnected autowired scope would resolve a
        // fresh DisposeRecorder nobody else sees), and only counted if
        // the transport genuinely disposed the scope.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $app->get(DisposeRecorder::class)->disposals);
    }

    public function test_stdio_gives_each_message_its_own_scope(): void
    {
        $app = $this->app();
        $server = new McpServer($this->registry(), new McpDispatcher($app));

        $input = $this->streamOf(
            \json_encode(self::toolCall(1, 'probe_scope'), JSON_THROW_ON_ERROR) . "\n"
            . \json_encode(self::toolCall(2, 'probe_scope'), JSON_THROW_ON_ERROR) . "\n",
        );
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        (new StdioTransport())->run($server, $input, $output, $app);

        \rewind($output);
        $lines = \array_values(\array_filter(\explode("\n", (string) \stream_get_contents($output))));
        self::assertCount(2, $lines);

        $first = self::toolResult(\json_decode($lines[0], associative: true, flags: JSON_THROW_ON_ERROR));
        $second = self::toolResult(\json_decode($lines[1], associative: true, flags: JSON_THROW_ON_ERROR));

        self::assertFalse($first['sawEarlierMarker']);
        self::assertFalse($second['sawEarlierMarker'], 'state stashed on one message\'s scope must not survive to the next');
        self::assertNotSame($first['scopeObjectId'], $second['scopeObjectId']);
    }

    public function test_stdio_disposes_each_scope_before_reading_the_next_line(): void
    {
        $app = $this->app();
        $server = new McpServer($this->registry(), new McpDispatcher($app));

        $input = $this->streamOf(
            \json_encode(self::toolCall(1, 'register_dispose_hook'), JSON_THROW_ON_ERROR) . "\n"
            . \json_encode(self::toolCall(2, 'register_dispose_hook'), JSON_THROW_ON_ERROR) . "\n",
        );
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        (new StdioTransport())->run($server, $input, $output, $app);

        self::assertSame(2, $app->get(DisposeRecorder::class)->disposals);
    }

    /**
     * The command's own construction path — a dispatcher holding a
     * command's RequestScope — used to share that one scope across the
     * server's whole lifetime. With an AppScope handed to the
     * transport, the per-message scope wins over the fallback.
     */
    public function test_stdio_per_message_scope_wins_over_the_dispatchers_own_container(): void
    {
        $app = $this->app();
        $commandScope = $app->createRequestScope();
        $server = new McpServer($this->registry(), new McpDispatcher($commandScope));

        $input = $this->streamOf(
            \json_encode(self::toolCall(1, 'probe_scope'), JSON_THROW_ON_ERROR) . "\n"
            . \json_encode(self::toolCall(2, 'probe_scope'), JSON_THROW_ON_ERROR) . "\n",
        );
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        (new StdioTransport())->run($server, $input, $output, $app);

        \rewind($output);
        $lines = \array_values(\array_filter(\explode("\n", (string) \stream_get_contents($output))));
        $second = self::toolResult(\json_decode($lines[1], associative: true, flags: JSON_THROW_ON_ERROR));

        // Under the old shared-scope behavior the second call saw the
        // first call's marker; per-message scopes end that.
        self::assertFalse($second['sawEarlierMarker']);
    }

    public function test_without_an_app_scope_the_transport_keeps_the_old_semantics(): void
    {
        $app = $this->app();
        $commandScope = $app->createRequestScope();
        $server = new McpServer($this->registry(), new McpDispatcher($commandScope));

        $input = $this->streamOf(
            \json_encode(self::toolCall(1, 'probe_scope'), JSON_THROW_ON_ERROR) . "\n"
            . \json_encode(self::toolCall(2, 'probe_scope'), JSON_THROW_ON_ERROR) . "\n",
        );
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        (new StdioTransport())->run($server, $input, $output);

        \rewind($output);
        $lines = \array_values(\array_filter(\explode("\n", (string) \stream_get_contents($output))));
        $second = self::toolResult(\json_decode($lines[1], associative: true, flags: JSON_THROW_ON_ERROR));

        // The documented fallback: no AppScope, no per-message scopes —
        // the dispatcher's own container is shared across messages.
        self::assertTrue($second['sawEarlierMarker']);
    }


    /**
     * The streamed path: the scope lives for the whole call — the tool
     * is still reporting progress while events are written — and its
     * dispose hook fires only after the final event carrying the
     * JSON-RPC response, never between progress events.
     */
    public function test_streaming_disposes_only_after_the_final_event(): void
    {
        $app = $this->app();
        $kernel = $this->kernelFor($app);

        $request = (new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', 'register_dispose_hook');
        $request->getBody()->write(\json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'register_dispose_hook', '_meta' => [...self::meta(), 'progressToken' => 'tok']],
        ], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();

        $response = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $response);

        // Building the response has not run the tool yet — the scope,
        // and its hook, exist only while the emitter runs.
        self::assertSame(0, $app->get(DisposeRecorder::class)->disposals);

        // Nested buffers: the emitter flushes each chunk, and a single
        // ob_start() would push those flushes to real stdout.
        \ob_start();
        \ob_start();
        ($response->getEmitter())();
        \ob_end_clean();
        \ob_end_clean();

        self::assertSame(1, $app->get(DisposeRecorder::class)->disposals);
    }

    private function postTool(Kernel $kernel, int $id, string $tool = 'probe_scope'): mixed
    {
        $request = (new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', $tool);
        $request->getBody()->write(\json_encode(self::toolCall($id, $tool), JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();

        $response = $kernel->handle($request);

        if ($tool === 'register_dispose_hook') {
            return $response;
        }

        /** @var array<string, mixed> */
        return \json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return resource
     */
    private function streamOf(string $contents): mixed
    {
        $stream = \fopen('php://memory', 'r+');
        \assert($stream !== false);
        \fwrite($stream, $contents);
        \rewind($stream);

        return $stream;
    }
}
