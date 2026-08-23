<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Http;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Http\StreamedResponse;
use Kinetis\Mcp\Http\McpController;
use Kinetis\Mcp\Http\McpOriginMiddleware;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\GlobalMiddleware;
use Kinetis\Mcp\Tests\Fixtures\IdentityReportingController;
use Kinetis\Mcp\Tests\Fixtures\McpGroupMiddleware;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use Kinetis\Mcp\Tests\Fixtures\PublishesUserMiddleware;
use Kinetis\Mcp\Tests\Fixtures\RecordingMiddleware;
use Kinetis\Mcp\Tests\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The /mcp endpoint as an ordinary route: every transport-level behavior
 * Kernel used to special-case — protocol eras, mirrored headers, origin
 * validation, the SSE progress stream, the spec's own 405s — now lives
 * on McpController and is exercised through a real Kernel::handle()
 * call, the same way any other route is.
 */
final class McpControllerTest extends TestCase
{
    /**
     * Without McpController registered there is no /mcp at all — the
     * endpoint exists exactly when this package's controller is
     * discovered, and nowhere in Kernel otherwise.
     */
    public function test_mcp_endpoint_is_absent_without_the_controller(): void
    {
        $app = new AppScope();
        $app->boot();
        $router = new Router();
        $router->register(UserController::class);

        $response = new Kernel($app, $router)->handle(new ServerRequest('POST', '/mcp', body: '{}'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_mcp_endpoint_handles_json_rpc_over_http_when_provided(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    /**
     * The /mcp endpoint is a literal comparison rather than a registered
     * route, so it needs the request path normalised on its own account.
     */
    public function test_mcp_endpoint_answers_with_a_trailing_slash_too(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = new ServerRequest('POST', '/mcp/', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    // --- /mcp Origin validation and #[AsMcpMiddleware]/
    // #[AsOpenApiMiddleware] scoped pipelines. ---

    /**
     * A Kernel with the /mcp route registered the way discovery would
     * register it in a real application: McpController as an ordinary
     * controller, the `mcp` middleware group carrying the origin check,
     * and McpServer bound on AppScope the way this package's bootstrap
     * binds it.
     *
     * @param list<class-string> $extraGroupMiddleware appended to the mcp group after the origin check
     * @param array<string, string> $config
     */
    private function mcpEnabledKernel(array $extraGroupMiddleware = [], array $config = []): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(McpController::class);

        return new Kernel(
            $app,
            $router,
            middlewareGroups: ['mcp' => [McpOriginMiddleware::class, ...$extraGroupMiddleware]],
        );
    }

    private function mcpToolsListRequest(): ServerRequest
    {
        return new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));
    }

    public function test_a_request_with_no_origin_header_reaches_mcp_regardless_of_the_allow_list(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $response = $kernel->handle($this->mcpToolsListRequest());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_origin_not_on_the_allow_list_is_rejected_with_403(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $request = $this->mcpToolsListRequest()->withHeader('Origin', 'https://evil.example');
        $response = $kernel->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_an_origin_on_the_allow_list_is_accepted(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $request = $this->mcpToolsListRequest()->withHeader('Origin', 'https://allowed.example');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_the_default_allow_list_rejects_any_origin_at_all(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpToolsListRequest()->withHeader('Origin', 'https://anything.example');
        $response = $kernel->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_discovered_mcp_middleware_runs_for_mcp_but_not_for_a_normal_route_or_openapi(): void
    {
        RecordingMiddleware::$log = [];
        $kernel = $this->mcpEnabledKernel(extraGroupMiddleware: [McpGroupMiddleware::class]);

        $kernel->handle($this->mcpToolsListRequest());
        self::assertSame([McpGroupMiddleware::class], RecordingMiddleware::$log);

        RecordingMiddleware::$log = [];
        $kernel->handle(new ServerRequest('GET', '/users/1'));
        self::assertSame([], RecordingMiddleware::$log);

        RecordingMiddleware::$log = [];
        $kernel->handle(new ServerRequest('GET', '/openapi.json'));
        self::assertSame([], RecordingMiddleware::$log);
    }



    /**
     * The `mcp` group is route middleware, which runs inside the global
     * pipeline rather than instead of it — global concerns keep wrapping
     * /mcp like any other route.
     */
    public function test_the_mcp_group_runs_inside_the_global_pipeline_not_instead_of_it(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [McpGroupMiddleware::class]]);

        RecordingMiddleware::$log = [];
        $kernel->handle($this->mcpToolsListRequest());

        self::assertSame([GlobalMiddleware::class, McpGroupMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_mcp_endpoint_returns_202_for_a_notification(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(202, $response->getStatusCode());
    }

    public function test_mcp_endpoint_returns_405_for_get_since_no_server_initiated_stream_is_supported(): void
    {
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle(new ServerRequest('GET', '/mcp'));

        self::assertSame(405, $response->getStatusCode());
    }

    public function test_mcp_endpoint_returns_405_for_delete_since_session_termination_is_not_supported(): void
    {
        // Checked directly against the real 2026-07-28 spec text, not
        // assumed: a server implementing only this revision "SHOULD"
        // answer 405 to a DELETE /mcp the same way it does a GET —
        // DELETE used to terminate a session under the now-removed
        // Mcp-Session-Id mechanism from earlier Streamable HTTP
        // revisions. This route is deliberately intercepted by the same
        // scoped $mcpPipeline as GET now, rather than falling through to
        // the router's own 404 for an unmatched path/method.
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle(new ServerRequest('DELETE', '/mcp'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    /**
     * @return array<string, mixed>
     */
    private function modernMcpMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];
    }

    public function test_modern_mcp_request_with_matching_headers_succeeds(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'server/discover');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('complete', $body['result']['resultType']);
    }

    public function test_modern_mcp_request_missing_the_protocol_version_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('Mcp-Method', 'server/discover');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_mcp_request_with_a_mismatched_method_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/list');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_mcp_unknown_method_maps_to_a_404(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'does/not/exist',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'does/not/exist');

        $response = $kernel->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32601, $body['error']['code']);
    }

    public function test_modern_mcp_unsupported_protocol_version_maps_to_a_400(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]],
        ])))
            ->withHeader('MCP-Protocol-Version', '1999-01-01')
            ->withHeader('Mcp-Method', 'tools/list');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32022, $body['error']['code']);
    }

    public function test_modern_tools_call_with_a_matching_mcp_name_header_succeeds(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMcpMeta(),
            ],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', 'get_user_status');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_modern_tools_call_with_a_mismatched_mcp_name_header_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMcpMeta(),
            ],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', 'create_user');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_tools_call_with_a_missing_mcp_name_header_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMcpMeta(),
            ],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_resources_read_with_a_matching_mcp_name_header_succeeds(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status', '_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'resources/read')
            ->withHeader('Mcp-Name', 'kinetis://status');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_modern_resources_read_with_a_mismatched_mcp_name_header_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status', '_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'resources/read')
            ->withHeader('Mcp-Name', 'kinetis://something-else');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_a_base64_sentinel_encoded_mcp_name_header_is_decoded_before_comparing(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $encoded = '=?base64?' . base64_encode('get_user_status') . '?=';

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMcpMeta(),
            ],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', $encoded);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_malformed_base64_sentinel_mcp_name_header_fails_closed(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMcpMeta(),
            ],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', '=?base64?not valid base64!!!?=');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_server_discover_does_not_require_an_mcp_name_header(): void
    {
        // server/discover has no name/uri in its body at all — the one
        // method already covered by the matching-headers test above, but
        // worth a dedicated assertion that this specific header isn't
        // demanded where the spec doesn't require it.
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'server/discover');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_legacy_mcp_request_ignores_missing_headers(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    public function test_a_tools_call_with_a_progress_token_returns_a_streamed_sse_response(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => 'tok']],
        ]));

        $response = $kernel->handle($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        // The emitter itself calls ob_flush()/flush() to push each chunk out
        // immediately — a single ob_start() here would have those calls push
        // straight to real stdout instead of accumulating. Nesting a second
        // buffer lets the emitter's own flushes land in the outer one, which
        // we then read back.
        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        $output = ob_get_clean();

        $events = array_values(array_filter(explode("\n\n", trim($output))));
        self::assertCount(4, $events);

        $first = json_decode(substr($events[0], strlen('data: ')), true);
        self::assertSame('notifications/progress', $first['method']);
        self::assertSame(1, $first['params']['progress']);

        $last = json_decode(substr($events[3], strlen('data: ')), true);
        self::assertSame(1, $last['id']);
        self::assertFalse($last['result']['isError']);
    }

    public function test_a_tools_call_without_a_progress_token_stays_a_buffered_json_response(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three'],
        ]));

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }


    /**
     * The streamed call runs after the request's scope is disposed, on a
     * scope of its own — and the identity an `mcp`-group middleware
     * published on the request's scope has to reach the tool there too,
     * or authentication would silently stop working the moment a client
     * asks for progress.
     */
    public function test_a_streamed_call_still_sees_the_identity_the_middleware_published(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(IdentityReportingController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [PublishesUserMiddleware::class]]);

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami_streaming', '_meta' => ['progressToken' => 'tok']],
        ]));

        $response = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        $output = ob_get_clean();

        $events = array_values(array_filter(explode("\n\n", trim($output))));
        $final = json_decode(substr(end($events), strlen('data: ')), true);
        $result = json_decode($final['result']['content'][0]['text'], true);

        self::assertSame(['caller' => 'agent-7'], $result);
    }

    /**
     * The endpoint with an empty registry — protocol-level tests that
     * need no tools at all.
     */
    private function emptyMcpKernel(): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->instance(McpServer::class, new McpServer(new McpRegistry(), new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);

        return new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);
    }

    /**
     * @param array<string, string> $config
     */
    private function progressMcpKernel(array $config = []): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(ProgressReportingController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);

        return new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);
    }
}
