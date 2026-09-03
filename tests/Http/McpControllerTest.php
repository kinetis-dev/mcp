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
use Kinetis\Mcp\Tests\Fixtures\DisposalFailingToolController;
use Kinetis\Mcp\Tests\Fixtures\DisposalRecorder;
use Kinetis\Mcp\Tests\Fixtures\GlobalMiddleware;
use Kinetis\Mcp\Tests\Fixtures\IdentityReportingController;
use Kinetis\Mcp\Tests\Fixtures\IdentityViaBothController;
use Kinetis\Mcp\Tests\Fixtures\IdentityViaConcreteController;
use Kinetis\Mcp\Tests\Fixtures\IdentityViaInterfaceController;
use Kinetis\Mcp\Tests\Fixtures\InMemoryLogger;
use Kinetis\Mcp\Tests\Fixtures\McpGroupMiddleware;
use Kinetis\Mcp\Tests\Fixtures\NotificationExecutionRecorder;
use Kinetis\Mcp\Tests\Fixtures\ProgressNotificationToolController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use Kinetis\Mcp\Tests\Fixtures\PublishesDualIdentityMiddleware;
use Kinetis\Mcp\Tests\Fixtures\PublishesUserMiddleware;
use Kinetis\Mcp\Tests\Fixtures\RecordingMiddleware;
use Kinetis\Mcp\Tests\Fixtures\ThrowingLogger;
use Kinetis\Mcp\Tests\Fixtures\ThrowingResourceController;
use Kinetis\Mcp\Tests\Fixtures\ThrowsAfterFirstResolutionLogger;
use Kinetis\Mcp\Tests\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The /mcp endpoint as an ordinary route: every transport-level behavior
 * Kernel used to special-case — mirrored headers, origin validation, the
 * SSE progress stream, the spec's own 405s — now lives on McpController
 * and is exercised through a real Kernel::handle() call, the same way any
 * other route is.
 */
final class McpControllerTest extends TestCase
{
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

    /**
     * A real POST /mcp request carrying headers that match its own
     * body, per the transport's header-mirroring requirement —
     * McpController::headerMismatch() rejects any request lacking
     * them, so every ordinary (non-header-testing) test needs both.
     * $body's own params._meta, if given, is merged over the required
     * protocolVersion/clientCapabilities pair — a caller adding e.g.
     * progressToken doesn't have to repeat both. Mcp-Name is derived
     * from params.name/params.uri when present.
     *
     * @param array<string, mixed> $body
     */
    private function mcpRequest(array $body, string $path = '/mcp'): ServerRequest
    {
        $method = $body['method'] ?? null;
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $params['_meta'] = [...$this->meta(), ...(is_array($params['_meta'] ?? null) ? $params['_meta'] : [])];
        $body['params'] = $params;

        $request = (new ServerRequest('POST', $path, body: json_encode($body)))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', (string) $method);

        $name = $params['name'] ?? $params['uri'] ?? null;

        return $name !== null ? $request->withHeader('Mcp-Name', (string) $name) : $request;
    }

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

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

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

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], path: '/mcp/');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    /**
     * The same terminal-boundary regression as McpServerTest's own, run
     * through a real Kernel request/response cycle: -32603 is not one of
     * httpStatus()'s mapped codes, so this stays a 200 with the error
     * inside the JSON-RPC envelope — a broken logger must not turn that
     * into a crashed request or a leaked secret either.
     */
    public function test_a_failing_resource_with_a_throwing_logger_still_returns_a_generic_error_over_http(): void
    {
        $logger = new ThrowingLogger();
        $kernel = $this->mcpEnabledKernelWithThrowingResource($logger);

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://throws'],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $rawBody = (string) $response->getBody();
        $body = json_decode($rawBody, true);

        self::assertSame(-32603, $body['error']['code']);
        self::assertSame('Internal error.', $body['error']['message']);
        self::assertStringNotContainsString('hunter2', $rawBody);
        self::assertStringNotContainsString('SQLSTATE', $rawBody);
        self::assertStringNotContainsString('logger itself failed', $rawBody);
    }

    /**
     * A well-formed, oversized JSON-RPC body — McpController::serve()
     * itself never gets far enough to answer "Parse error." for this
     * one; MaxBodySizeMiddleware's backstop (SizeLimitedStream, enforced
     * through getContents() rather than a string cast that would
     * silently swallow the overage into "") must reject it as 413
     * before McpServer ever sees a decoded message, and before the
     * controller's own header check runs. No Content-Length header at
     * all, so the declared-header fast path can't catch this either —
     * only the actual-bytes-read backstop can.
     */
    public function test_an_oversized_mcp_body_with_no_content_length_is_rejected_with_413(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MAX_BODY_SIZE' => '50']);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['padding' => str_repeat('x', 200)],
        ]);
        self::assertGreaterThan(50, strlen($payload));

        $response = $kernel->handle(new ServerRequest('POST', '/mcp', body: $payload));

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * The same oversized body, this time with a Content-Length header
     * that understates the real size below the configured cap — the
     * declared-header check alone would pass this through, so only the
     * actual-bytes-read backstop closes it.
     */
    public function test_an_oversized_mcp_body_with_an_understated_content_length_is_rejected_with_413(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MAX_BODY_SIZE' => '50']);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['padding' => str_repeat('x', 200)],
        ]);
        self::assertGreaterThan(50, strlen($payload));

        $response = $kernel->handle(new ServerRequest(
            'POST',
            '/mcp',
            headers: ['Content-Length' => '10'],
            body: $payload,
        ));

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * The control: a genuinely small, well-formed request under the
     * same configured cap must still be processed normally — the fix
     * closes a real gap without breaking the endpoint for anyone who
     * fits under the limit. The cap is sized to comfortably fit a real
     * request's own required `_meta`, not shrunk to fit an artificially
     * tiny body.
     */
    public function test_a_small_mcp_body_under_the_configured_limit_is_processed_normally(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MAX_BODY_SIZE' => '500']);

        $request = $this->mcpRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        // getSize(), never (string) — the latter reads (and leaves
        // consumed) the same PSR-7 stream the kernel is about to read
        // from itself.
        self::assertLessThanOrEqual(500, $request->getBody()->getSize());

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
        return $this->mcpRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }

    /**
     * The same shape as mcpEnabledKernel(), but registering
     * ThrowingResourceController against a McpServer built with the
     * given logger — the fixture this file's own terminal-boundary
     * regression needs, distinct from every other test's AccountController
     * server above.
     */
    private function mcpEnabledKernelWithThrowingResource(ThrowingLogger $logger): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(ThrowingResourceController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app), logger: $logger));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);

        return new Kernel(
            $app,
            $router,
            middlewareGroups: ['mcp' => [McpOriginMiddleware::class]],
        );
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

        $request = $this->mcpRequest(['jsonrpc' => '2.0', 'method' => 'tools/list']);

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

    public function test_a_request_with_matching_headers_succeeds(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('complete', $body['result']['resultType']);
    }

    public function test_a_request_missing_the_protocol_version_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
        ])->withoutHeader('MCP-Protocol-Version');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_a_request_with_a_mismatched_method_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
        ])->withHeader('Mcp-Method', 'tools/list');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_an_unknown_method_maps_to_a_404(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'does/not/exist',
        ]);

        $response = $kernel->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32601, $body['error']['code']);
    }

    public function test_an_unsupported_protocol_version_maps_to_a_400(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ]],
        ])))
            ->withHeader('MCP-Protocol-Version', '1999-01-01')
            ->withHeader('Mcp-Method', 'tools/list');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32022, $body['error']['code']);
    }

    /**
     * Batching is not supported by the 2026-07-28 revision this server
     * implements — a top-level JSON array must be rejected the same way
     * as any other malformed envelope, never turned into a 202/no-body
     * response the way a genuine notification would produce.
     */
    public function test_a_top_level_json_array_body_is_rejected_with_400_not_202(): void
    {
        $kernel = $this->emptyMcpKernel();

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'],
        ]);

        $response = $kernel->handle(new ServerRequest('POST', '/mcp', body: $batch));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32600, $body['error']['code']);
        self::assertNull($body['id']);
    }

    /**
     * The ordering fix this class exists for: a structurally invalid
     * body must be rejected as -32600 before the mirrored-header check
     * ever runs, even when the headers themselves would also fail —
     * never -32020, which would wrongly imply the body was otherwise a
     * valid, well-formed request.
     */
    public function test_structural_validation_runs_before_the_mirrored_header_check(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            // No "jsonrpc" member at all — structurally invalid.
            'id' => 1,
            'method' => 'tools/list',
        ])))
            ->withHeader('MCP-Protocol-Version', 'not-even-close')
            ->withHeader('Mcp-Method', 'also-wrong');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32600, $body['error']['code'], 'the structural failure must win, not the header mismatch');
    }

    /**
     * A progressToken present but of the wrong type must never open an
     * SSE stream — McpServer::handle() would reject it once dispatched,
     * and by then the response is already committed to
     * text/event-stream. Refusing to stream here keeps the rejection an
     * ordinary, bufferable JSON error response instead.
     */
    public function test_a_malformed_progress_token_gets_an_ordinary_json_error_not_a_stream(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => ['not', 'valid']]],
        ]);

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32602, $body['error']['code']);
    }

    public function test_tools_call_with_a_matching_mcp_name_header_succeeds(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_tools_call_with_a_mismatched_mcp_name_header_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
        ])->withHeader('Mcp-Name', 'create_user');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_tools_call_with_a_missing_mcp_name_header_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
        ])->withoutHeader('Mcp-Name');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_resources_read_with_a_matching_mcp_name_header_succeeds(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status'],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_resources_read_with_a_mismatched_mcp_name_header_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status'],
        ])->withHeader('Mcp-Name', 'kinetis://something-else');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_a_base64_sentinel_encoded_mcp_name_header_is_decoded_before_comparing(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $encoded = '=?base64?' . base64_encode('get_user_status') . '?=';

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
        ])->withHeader('Mcp-Name', $encoded);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_malformed_base64_sentinel_mcp_name_header_fails_closed(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
        ])->withHeader('Mcp-Name', '=?base64?not valid base64!!!?=');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_server_discover_does_not_require_an_mcp_name_header(): void
    {
        // server/discover has no name/uri in its body at all — the one
        // method already covered by the matching-headers test above, but
        // worth a dedicated assertion that this specific header isn't
        // demanded where the spec doesn't require it.
        $kernel = $this->emptyMcpKernel();

        $request = $this->mcpRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover']);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_request_missing_headers_is_rejected(): void
    {
        $kernel = $this->mcpEnabledKernel();

        // Otherwise a fully valid body — preflight() runs before the
        // header check, so a body that would *also* fail preflight (a
        // missing _meta, for instance) is caught there first, with its
        // own -32602, not reported as a header mismatch. This test wants
        // to isolate the header check itself.
        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => $this->meta()],
        ]));

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    // --- The empty-list-vs-empty-object and present-null distinction,
    // through real HTTP bytes — mcpRequest()'s own merge logic silently
    // drops a malformed _meta before it ever reaches the body, so every
    // case here builds the request directly, with headers computed by
    // hand to match, isolating the shape check itself from the header
    // check already covered above. ---

    /**
     * @return array<string, string>
     */
    private function matchingHeaders(string $method, ?string $name = null): array
    {
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];

        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }

        return $headers;
    }

    public function test_an_empty_json_array_params_is_rejected_over_http(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/list'),
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[]}',
        );

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_an_empty_json_object_params_is_accepted_over_http(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/list'),
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}',
        );

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_present_null_meta_is_rejected_over_http(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/list'),
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":null}}',
        );

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_an_empty_list_client_capabilities_is_rejected_over_http(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/list'),
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":[]}}}',
        );

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /**
     * A valid-looking progressToken paired with a malformed `arguments`
     * must never open the SSE stream — the malformed value is discovered
     * by preflight() before stream selection runs at all, not once the
     * stream has already committed the response to text/event-stream.
     */
    public function test_a_valid_progress_token_with_malformed_arguments_never_streams(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/call', 'count_to_three'),
            body: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'count_to_three',
                    'arguments' => [1, 2, 3],
                    '_meta' => [...$this->meta(), 'progressToken' => 'tok'],
                ],
            ]),
        );

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /**
     * Same proof, this time with malformed clientCapabilities instead of
     * arguments — the point being that preflight() covers the full
     * request, not just the one field wantsProgressStream() itself
     * inspects.
     */
    public function test_a_valid_progress_token_with_malformed_client_capabilities_never_streams(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/call', 'count_to_three'),
            body: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'count_to_three',
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => 'nope',
                        'progressToken' => 'tok',
                    ],
                ],
            ]),
        );

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /**
     * Same proof again, this time with a malformed `name` — the field
     * nameHeaderMismatch() itself also reads. A warning-to-exception
     * error handler is installed for the duration of this test
     * specifically to prove the fix for the array-to-string cast this
     * class's own docblock used to carry: preflight() must reject the
     * malformed name before nameHeaderMismatch() ever runs, so no
     * warning is ever emitted, let alone escalated.
     */
    public function test_a_valid_progress_token_with_a_malformed_name_never_streams_and_never_warns(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'],
            body: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => ['not', 'a', 'string'],
                    '_meta' => [...$this->meta(), 'progressToken' => 'tok'],
                ],
            ]),
        );

        set_error_handler(static function (int $errno, string $errstr): never {
            throw new RuntimeException("Unexpected warning: {$errstr}");
        });

        try {
            $response = $kernel->handle($request);
        } finally {
            restore_error_handler();
        }

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /**
     * A valid progressToken paired with a *missing* name — not just a
     * malformed one — must also never open the stream: preflight()'s
     * own name check requires presence now, not just type, precisely so
     * this case is caught before wantsProgressStream() ever runs.
     */
    public function test_a_valid_progress_token_with_a_missing_name_never_streams(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'],
            body: json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    '_meta' => [...$this->meta(), 'progressToken' => 'tok'],
                ],
            ]),
        );

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    // --- Notification suppression over real HTTP: an envelope-valid
    // notification (no id) whose nested content preflight() rejects
    // must get 202, never 400 — and never open the SSE stream either,
    // even with an otherwise-valid-looking progressToken. ---

    public function test_a_notification_with_malformed_meta_gets_202_not_400(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'],
            body: '{"jsonrpc":"2.0","method":"tools/list","params":{"_meta":"nope"}}',
        );

        $response = $kernel->handle($request);

        self::assertSame(202, $response->getStatusCode());
    }

    public function test_a_notification_with_a_malformed_progress_token_gets_202_and_never_streams(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'],
            body: json_encode([
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'count_to_three',
                    '_meta' => [...$this->meta(), 'progressToken' => ['not', 'valid']],
                ],
            ]),
        );

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
    }

    /**
     * Streaming is request-only: a fully *valid* tools/call notification
     * — no `id` at all, every other field well-formed including a valid
     * progressToken — must not open the SSE stream just because a
     * progress token happens to be present. It still gets the ordinary
     * null-response → 202 path every other notification gets, and the
     * tool genuinely still runs (JSON-RPC requires a server to process a
     * notification, only never reply to it) — proven here via a static
     * recorder, since the empty 202 body itself carries no evidence
     * either way.
     */
    public function test_a_valid_tools_call_notification_with_a_valid_progress_token_never_streams_and_still_executes(): void
    {
        NotificationExecutionRecorder::$calls = 0;
        $kernel = $this->progressNotificationMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: $this->matchingHeaders('tools/call', 'count_to_three_and_record'),
            body: json_encode([
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'count_to_three_and_record',
                    '_meta' => [...$this->meta(), 'progressToken' => 'tok'],
                ],
            ]),
        );

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(1, NotificationExecutionRecorder::$calls, 'the tool must still genuinely run for a real notification');
    }

    public function test_a_tools_call_with_a_progress_token_returns_a_streamed_sse_response(): void
    {
        $kernel = $this->progressMcpKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => 'tok']],
        ]);

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

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three'],
        ]);

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

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami_streaming', '_meta' => ['progressToken' => 'tok']],
        ]);

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

    // KINETIS-74: a middleware that (like kinetis/auth-jwt's
    // JwtAuthMiddleware) publishes the same authenticated instance under
    // both CurrentUserInterface and its own concrete class must have both
    // survive into the streamed scope, resolving to the exact same
    // object — not just CurrentUserInterface, which was already carried
    // across before this fix.

    /**
     * @return array<string, mixed>
     */
    private function toolResult(ResponseInterface $response): array
    {
        if ($response instanceof StreamedResponse) {
            ob_start();
            ob_start();
            ($response->getEmitter())();
            ob_end_clean();
            $output = ob_get_clean();

            $events = array_values(array_filter(explode("\n\n", trim($output))));
            $final = json_decode(substr(end($events), strlen('data: ')), true);
        } else {
            self::assertSame(200, $response->getStatusCode());
            $final = json_decode((string) $response->getBody(), true);
        }

        return json_decode($final['result']['content'][0]['text'], true);
    }

    /**
     * @return array{0: AppScope, 1: Router}
     */
    private function dualIdentityAppAndRouter(string $controllerClass): array
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register($controllerClass);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);

        return [$app, $router];
    }

    public function test_identity_via_interface_only_matches_between_ordinary_and_streamed_calls(): void
    {
        [$app, $router] = $this->dualIdentityAppAndRouter(IdentityViaInterfaceController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [PublishesDualIdentityMiddleware::class]]);

        $ordinary = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_interface'],
        ])));

        $streamed = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_interface', '_meta' => ['progressToken' => 'tok']],
        ])));

        self::assertSame(['caller' => 'agent-9'], $ordinary);
        self::assertSame($ordinary, $streamed, 'identity via CurrentUserInterface must be identical, streamed or not');
    }

    /**
     * The concrete-class-only case — the one that was broken before this
     * fix: an ordinary call already resolved ConcreteCurrentUser
     * correctly (it never went through stream()'s own snapshot/replay at
     * all), but a streamed call previously either failed to autowire it
     * or silently constructed a disconnected instance, since only
     * CurrentUserInterface was ever carried across.
     */
    public function test_identity_via_concrete_class_only_matches_between_ordinary_and_streamed_calls(): void
    {
        [$app, $router] = $this->dualIdentityAppAndRouter(IdentityViaConcreteController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [PublishesDualIdentityMiddleware::class]]);

        $ordinary = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_concrete'],
        ])));

        $streamed = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_concrete', '_meta' => ['progressToken' => 'tok']],
        ])));

        self::assertSame(['caller' => 'agent-9', 'role' => 'admin'], $ordinary);
        self::assertSame($ordinary, $streamed, 'identity via the concrete class must be identical, streamed or not');
    }

    /**
     * Both bindings simultaneously — proving they resolve to the exact
     * same object instance, not merely that each independently resolves
     * to *something* that looks right.
     */
    public function test_identity_via_both_bindings_resolves_to_the_same_instance_for_ordinary_and_streamed_calls(): void
    {
        [$app, $router] = $this->dualIdentityAppAndRouter(IdentityViaBothController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [PublishesDualIdentityMiddleware::class]]);

        $ordinary = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_both'],
        ])));

        $streamed = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_both', '_meta' => ['progressToken' => 'tok']],
        ])));

        self::assertSame(['sameInstance' => true, 'caller' => 'agent-9'], $ordinary);
        self::assertSame($ordinary, $streamed, 'both bindings must still resolve to the same instance when streamed');
    }

    /**
     * The negative case: with no authentication at all, a streamed call
     * must not manufacture a phantom concrete-class identity out of
     * nothing — both bindings stay absent, exactly as they do for the
     * ordinary call.
     */
    public function test_an_unauthenticated_streamed_call_does_not_manufacture_a_concrete_class_identity(): void
    {
        [$app, $router] = $this->dualIdentityAppAndRouter(IdentityViaBothController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);

        $ordinary = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_both'],
        ])));

        $streamed = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'identity_via_both', '_meta' => ['progressToken' => 'tok']],
        ])));

        self::assertSame(['sameInstance' => false, 'caller' => 'anonymous'], $ordinary);
        self::assertSame($ordinary, $streamed, 'no identity must be manufactured on the streamed path either');
    }

    /**
     * The tool call itself succeeds and returns a real result — but the
     * streamed call's own scope's disposal then fails. That failure must
     * never suppress the already-written final SSE event, and a later
     * dispose callback must still run despite an earlier one throwing.
     */
    public function test_a_streamed_calls_disposal_failure_does_not_suppress_the_final_event(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(DisposalFailingToolController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => ['progressToken' => 'tok']],
        ]);

        $response = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        $output = ob_get_clean();

        $events = array_values(array_filter(explode("\n\n", trim($output))));

        self::assertCount(1, $events, 'exactly one SSE event — the disposal failure must never appear as a second one');
        $final = json_decode(substr($events[0], strlen('data: ')), true);
        self::assertSame(1, $final['id']);
        self::assertArrayNotHasKey('error', $final, 'the tool call itself succeeded');

        self::assertTrue(DisposalRecorder::$secondRan, 'a later dispose callback still ran despite an earlier one throwing');
        self::assertNotNull(DisposalRecorder::$scope);
        self::assertTrue(DisposalRecorder::$scope->isDisposed());
    }

    public function test_a_streamed_calls_disposal_failure_is_logged_through_the_app_scope_logger(): void
    {
        $logger = new InMemoryLogger();

        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->instance(LoggerInterface::class, $logger);
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(DisposalFailingToolController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => ['progressToken' => 'tok']],
        ]);

        $response = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        ob_get_clean();

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('dispose callback failed', $logger->records[0]['context']['message']);
    }

    /**
     * SafeLogger::log($app->get(LoggerInterface::class), ...) is not
     * actually safe on its own: PHP evaluates that get() call before
     * log() is ever entered, so a throwing LoggerInterface binding
     * escapes uncaught right where disposeStreamScope()'s own resolution
     * happens — suppressing the already-written final event and aborting
     * the stream. This proves it doesn't.
     *
     * $succeeds: 3 is the exact number of LoggerInterface resolutions
     * this real request path makes before disposeStreamScope()'s own —
     * ExceptionHandlerMiddleware's construction, Kernel's own
     * TransactionGuardHook call against the request's scope, and the
     * emitter's own TransactionGuardHook call against the stream's own
     * scope — confirmed empirically, not assumed; if this test starts
     * failing because it never reaches the streamed event at all, that
     * count is the first thing to re-check.
     */
    public function test_a_streamed_calls_final_event_survives_even_when_the_logger_itself_cannot_be_resolved(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $loggerFactory = new ThrowsAfterFirstResolutionLogger(succeeds: 3);
        $app->bind(LoggerInterface::class, $loggerFactory(...), shared: false);
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(DisposalFailingToolController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => ['progressToken' => 'tok']],
        ]);

        $response = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        $output = ob_get_clean();

        $events = array_values(array_filter(explode("\n\n", trim($output))));
        self::assertCount(1, $events, 'the final event survives even though the logger itself could not be resolved to report the disposal failure');
        $final = json_decode(substr($events[0], strlen('data: ')), true);
        self::assertSame(1, $final['id']);
        self::assertArrayNotHasKey('error', $final);
    }

    /**
     * A genuine output failure, not a manufactured one: PHP invokes an
     * ob_start() handler callback whenever its buffer is flushed, and a
     * callback that throws makes ob_flush() itself throw — write()'s own
     * `@ob_flush()` suppresses PHP warnings, not a real thrown exception,
     * so this reaches the exact code path a broken/closed output stream
     * would. Proves the real output failure propagates as the primary
     * failure, the stream's own scope is still fully disposed (every
     * dispose callback attempted, including a simultaneous disposal
     * failure — contained and logged separately, not instead), and the
     * one failed write attempt is never retried or duplicated.
     */
    public function test_an_output_failure_still_disposes_the_scope_and_runs_every_callback(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        $logger = new InMemoryLogger();

        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->instance(LoggerInterface::class, $logger);
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(DisposalFailingToolController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => ['progressToken' => 'tok']],
        ]);

        $response = $kernel->handle($request);
        self::assertInstanceOf(StreamedResponse::class, $response);

        // PHPUnit's own runner may already have output buffering active,
        // so the level to return to afterward is whatever was active
        // before this test's own two levels — never a hardcoded number.
        $baseLevel = ob_get_level();

        ob_start();

        $outputFailure = new RuntimeException('ob callback failed');
        ob_start(static function () use ($outputFailure): never {
            throw $outputFailure;
        });

        $threw = null;

        try {
            ($response->getEmitter())();
        } catch (Throwable $e) {
            $threw = $e;
        } finally {
            // The throwing handler's own buffer level is left un-popped
            // by the failed flush — pop every level back down to this
            // test's own outer capture regardless of what surfaces, so
            // this test can't leak buffer state into whatever PHPUnit
            // runs next.
            while (ob_get_level() > $baseLevel + 1) {
                @ob_end_clean();
            }
        }

        $capturedOutput = ob_get_clean();

        self::assertSame($outputFailure, $threw, 'the real output failure must propagate as the primary failure, unreplaced');
        self::assertSame(1, substr_count($capturedOutput, 'data: '), 'the one failed write attempt is never retried or duplicated');

        self::assertTrue(DisposalRecorder::$secondRan, 'every dispose callback still ran despite the output failure');
        self::assertNotNull(DisposalRecorder::$scope);
        self::assertTrue(DisposalRecorder::$scope->isDisposed());

        self::assertCount(1, $logger->records, 'the disposal failure is still logged, separately from the output failure that propagated');
        self::assertSame('dispose callback failed', $logger->records[0]['context']['message']);
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

    /**
     * A separate registry (ProgressNotificationToolController rather than
     * ProgressReportingController) purely so this file's own
     * NotificationExecutionRecorder-observing test doesn't share a
     * registered tool with every other progress test here.
     */
    private function progressNotificationMcpKernel(): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(ProgressNotificationToolController::class);
        $app->instance(McpServer::class, new McpServer($mcpRegistry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);

        return new Kernel($app, $router, middlewareGroups: ['mcp' => [McpOriginMiddleware::class]]);
    }
}
