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
use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\DisposalFailingToolController;
use Kinetis\Mcp\Tests\Fixtures\DisposalRecorder;
use Kinetis\Mcp\Tests\Fixtures\GlobalMiddleware;
use Kinetis\Mcp\Tests\Fixtures\IdentityReportingController;
use Kinetis\Mcp\Tests\Fixtures\InMemoryLogger;
use Kinetis\Mcp\Tests\Fixtures\McpGroupMiddleware;
use Kinetis\Mcp\Tests\Fixtures\NotificationExecutionRecorder;
use Kinetis\Mcp\Tests\Fixtures\ProgressNotificationToolController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use Kinetis\Mcp\Tests\Fixtures\PublishesRequestNoteMiddleware;
use Kinetis\Mcp\Tests\Fixtures\PublishesUserMiddleware;
use Kinetis\Mcp\Tests\Fixtures\ReadsBodyMiddleware;
use Kinetis\Mcp\Tests\Fixtures\RecordingMiddleware;
use Kinetis\Mcp\Tests\Fixtures\RequestNoteReportingController;
use Kinetis\Mcp\Tests\Fixtures\ThrowingLogger;
use Kinetis\Mcp\Tests\Fixtures\ThrowingResourceController;
use Kinetis\Mcp\Tests\Fixtures\ThrowsAfterFirstResolutionLogger;
use Kinetis\Mcp\Tests\Fixtures\UserController;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ServerInfo;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The /mcp endpoint as an ordinary route: MCP 2025-06-18 Streamable HTTP,
 * origin validation, the `mcp` middleware group, the SSE progress stream,
 * and the spec's own 405s, all exercised through a real Kernel::handle()
 * call the same way any other route is.
 *
 * `MCP-Protocol-Version` is the only protocol header this transport has.
 * Sessions are optional in this revision and this server issues none, so
 * nothing here carries or expects an `Mcp-Session-Id`.
 */
final class McpControllerTest extends TestCase
{
    private const string PROTOCOL_VERSION = '2025-06-18';

    /**
     * A real POST /mcp request carrying the protocol-version header every
     * non-initialize message needs.
     *
     * @param array<string, mixed> $body
     */
    private function mcpRequest(array $body, string $path = '/mcp'): ServerRequest
    {
        return new ServerRequest('POST', $path, body: json_encode($body))
            ->withHeader('MCP-Protocol-Version', self::PROTOCOL_VERSION);
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
     * `/mcp` is an ordinary Router route, and the Router normalises the
     * trailing slash on both the registered path and the request path, so
     * `/mcp/` reaches the same controller without a redirect.
     */
    public function test_mcp_endpoint_answers_with_a_trailing_slash_too(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], path: '/mcp/'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    /**
     * The terminal-boundary regression, run through a real Kernel
     * request/response cycle: a protocol error after a valid envelope
     * stays a 200 carrying the JSON-RPC error, and a broken logger must
     * not turn that into a crashed request or a leaked secret either.
     */
    public function test_a_failing_resource_with_a_throwing_logger_still_returns_a_generic_error_over_http(): void
    {
        $kernel = $this->mcpEnabledKernelWithThrowingResource(new ThrowingLogger());

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://throws'],
        ]));

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
     * itself never gets far enough to answer "Parse error." for this one.
     * RequestBodyMiddleware stages and counts the body before any handler
     * runs, so this is a 413 before the server ever sees a decoded
     * message. No Content-Length header at all, so the declared-header
     * check cannot catch it either — only the staged byte count can.
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
     * The same oversized body, this time with a Content-Length header that
     * understates the real size below the configured cap — the
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
     * The control: a genuinely small, well-formed request under the same
     * configured cap must still be processed normally — the cap closes a
     * real gap without breaking the endpoint for anyone who fits under it.
     */
    public function test_a_small_mcp_body_under_the_configured_limit_is_processed_normally(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MAX_BODY_SIZE' => '500']);

        $request = $this->mcpRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        // getSize(), never (string) — the latter reads (and leaves
        // consumed) the same PSR-7 stream the kernel is about to read from
        // itself.
        self::assertLessThanOrEqual(500, $request->getBody()->getSize());

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    /**
     * A middleware that inspected the staged body leaves its cursor at the
     * end, where `getContents()` answers with an empty string. The
     * controller reads the replayable full representation instead, so the
     * whole envelope — arguments included — still reaches the tool.
     */
    public function test_the_whole_envelope_reaches_the_tool_after_a_middleware_read_the_body(): void
    {
        ReadsBodyMiddleware::$bytesRead = 0;

        $kernel = $this->mcpEnabledKernel([ReadsBodyMiddleware::class]);

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_user',
                'arguments' => ['data' => ['name' => 'Alon', 'email' => 'alon@example.com']],
            ],
        ]));

        self::assertGreaterThan(0, ReadsBodyMiddleware::$bytesRead, 'the middleware has to have drained the body for this to prove anything');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@example.com'],
            json_decode($body['result']['content'][0]['text'], true),
        );
    }

    // --- /mcp Origin validation and the scoped `mcp` middleware group. ---

    public function test_a_request_with_no_origin_header_reaches_mcp_regardless_of_the_allow_list(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $response = $kernel->handle($this->mcpToolsListRequest());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_origin_not_on_the_allow_list_is_rejected_with_403(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $response = $kernel->handle($this->mcpToolsListRequest()->withHeader('Origin', 'https://evil.example'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_an_origin_on_the_allow_list_is_accepted(): void
    {
        $kernel = $this->mcpEnabledKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $response = $kernel->handle($this->mcpToolsListRequest()->withHeader('Origin', 'https://allowed.example'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_each_comma_separated_allowed_origin_is_trimmed(): void
    {
        $kernel = $this->mcpEnabledKernel(config: [
            'MCP_ALLOWED_ORIGINS' => 'https://first.example, https://second.example',
        ]);

        $response = $kernel->handle($this->mcpToolsListRequest()->withHeader('Origin', 'https://second.example'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_the_default_allow_list_rejects_any_origin_at_all(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $response = $kernel->handle($this->mcpToolsListRequest()->withHeader('Origin', 'https://anything.example'));

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
        $app = $this->appWith(AccountController::class);
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $kernel = $this->kernelFor($app, ['mcp' => [McpGroupMiddleware::class]]);

        RecordingMiddleware::$log = [];
        $kernel->handle($this->mcpToolsListRequest());

        self::assertSame([GlobalMiddleware::class, McpGroupMiddleware::class], RecordingMiddleware::$log);
    }

    // --- MCP 2025-06-18 Streamable HTTP. ---

    public function test_initialize_is_accepted_without_the_protocol_version_header(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'claude-code', 'version' => '2.1.273'],
            ],
        ]));

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(self::PROTOCOL_VERSION, $body['result']['protocolVersion']);
        self::assertSame('', $response->getHeaderLine('Mcp-Session-Id'));
    }

    public function test_a_subsequent_request_carrying_the_protocol_version_header_is_accepted(): void
    {
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle($this->mcpRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getBody(), true)['result']);
    }

    /**
     * A missing header on a non-initialize message means the spec's
     * 2025-03-26 fallback, which this single-version server does not
     * implement. Nothing is remembered from an earlier request to stand in
     * for it, so it is refused rather than assumed.
     */
    public function test_a_subsequent_request_without_the_protocol_version_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32600, $body['error']['code']);
        self::assertSame(1, $body['id']);
    }

    /**
     * Initializing once does not let the next request omit its header:
     * this transport keeps no negotiated state, which is what makes one
     * server instance safe behind a stateless HTTP route.
     */
    public function test_an_earlier_initialize_does_not_excuse_a_later_missing_header(): void
    {
        $kernel = $this->emptyMcpKernel();

        $kernel->handle(new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'probe', 'version' => '1.0'],
            ],
        ])));

        $response = $kernel->handle(new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ])));

        self::assertSame(400, $response->getStatusCode());
    }

    public function test_an_unsupported_protocol_version_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = $this->mcpRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->withHeader('MCP-Protocol-Version', '2026-07-28');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32600, $body['error']['code']);
        self::assertStringContainsString(self::PROTOCOL_VERSION, $body['error']['message']);
    }

    /**
     * Even `initialize` is refused when it names a version this server
     * does not speak in the header: the header is the transport's own
     * claim, distinct from the body's `protocolVersion`, which is
     * negotiated rather than refused.
     */
    public function test_initialize_with_an_unsupported_protocol_version_header_is_rejected(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'probe', 'version' => '1.0'],
            ],
        ]))->withHeader('MCP-Protocol-Version', '2024-11-05');

        self::assertSame(400, $kernel->handle($request)->getStatusCode());
    }

    /**
     * An earlier revision of this transport mirrored `method` and
     * `params.name` into headers and refused a request whose headers did
     * not match. Those headers are not part of `2025-06-18`: a client
     * still sending them is answered normally, and a value contradicting
     * the body changes nothing.
     */
    public function test_the_removed_mirrored_headers_are_ignored_rather_than_checked(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
        ])
            ->withHeader('Mcp-Method', 'resources/read')
            ->withHeader('Mcp-Name', 'some_other_tool');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['result']['isError']);
        self::assertSame(
            ['userId' => 7, 'status' => 'active'],
            json_decode($body['result']['content'][0]['text'], true),
        );
    }

    public function test_mcp_endpoint_returns_202_for_a_notification(): void
    {
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function test_a_client_response_message_is_accepted_and_never_answered(): void
    {
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => (object) [],
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function test_mcp_endpoint_returns_405_for_get_since_no_server_initiated_stream_is_supported(): void
    {
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle(new ServerRequest('GET', '/mcp'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function test_mcp_endpoint_returns_405_for_delete_since_session_termination_is_not_supported(): void
    {
        // DELETE terminates a session under the optional Mcp-Session-Id
        // mechanism. This server issues no session, so the route answers
        // the router's ordinary 405 rather than implementing one.
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle(new ServerRequest('DELETE', '/mcp'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    /**
     * A method this server does not implement is understood but
     * unanswerable, which is a JSON-RPC outcome rather than a transport
     * one: 200 with the error in the envelope.
     */
    public function test_an_unknown_method_is_a_json_rpc_error_inside_a_200(): void
    {
        $kernel = $this->emptyMcpKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/list',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(-32601, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_malformed_json_is_a_parse_error_with_a_400(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest('POST', '/mcp', body: '{"jsonrpc":')
            ->withHeader('MCP-Protocol-Version', self::PROTOCOL_VERSION);

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32700, $body['error']['code']);
        self::assertNull($body['id']);
    }

    /**
     * Batching is not part of this revision — a top-level JSON array must
     * be rejected the same way as any other malformed envelope, never
     * turned into the 202/no-body response a genuine notification gets.
     */
    public function test_a_top_level_json_array_body_is_rejected_with_400_not_202(): void
    {
        $kernel = $this->emptyMcpKernel();

        $batch = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'],
        ]);

        $request = new ServerRequest('POST', '/mcp', body: $batch)
            ->withHeader('MCP-Protocol-Version', self::PROTOCOL_VERSION);

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32600, $body['error']['code']);
        self::assertNull($body['id']);
    }

    /**
     * A structurally invalid body is rejected before the header check ever
     * runs, even when the header would also fail — reporting the header
     * would wrongly imply the body was an otherwise well-formed request.
     */
    public function test_structural_validation_runs_before_the_protocol_version_check(): void
    {
        $kernel = $this->emptyMcpKernel();

        // No "jsonrpc" member at all — structurally invalid.
        $request = new ServerRequest('POST', '/mcp', body: json_encode(['id' => 1, 'method' => 'tools/list']))
            ->withHeader('MCP-Protocol-Version', 'not-even-close');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32600, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    // --- The empty-list-versus-empty-object distinction, through real
    // HTTP bytes. ---

    public function test_an_empty_json_array_params_is_rejected_over_http(): void
    {
        $kernel = $this->emptyMcpKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: ['MCP-Protocol-Version' => self::PROTOCOL_VERSION],
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
            headers: ['MCP-Protocol-Version' => self::PROTOCOL_VERSION],
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        );

        self::assertSame(200, $kernel->handle($request)->getStatusCode());
    }

    /**
     * A nested object argument keeps its JSON provenance all the way to
     * hydration: `{}` reaching a tool as an empty object, never as the
     * empty list the same PHP value would otherwise be indistinguishable
     * from.
     */
    public function test_a_nested_empty_object_argument_survives_the_http_boundary(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = new ServerRequest(
            'POST',
            '/mcp',
            headers: ['MCP-Protocol-Version' => self::PROTOCOL_VERSION],
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"create_user","arguments":'
                . '{"data":{"name":"Alon","email":"alon@example.com"}}}}',
        );

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['result']['isError']);
    }

    /**
     * A progressToken present but of the wrong type must never open an SSE
     * stream — the server rejects it once dispatched, and by then a
     * streamed response would already be committed to text/event-stream.
     */
    public function test_a_malformed_progress_token_gets_an_ordinary_json_error_not_a_stream(): void
    {
        $kernel = $this->progressMcpKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => ['not', 'valid']]],
        ]));

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(-32602, json_decode((string) $response->getBody(), true)['error']['code']);
    }

    /**
     * A tools/call *notification* — no `id` at all — never opens a stream
     * and never runs the tool: there is no response to carry a result or
     * an error to, and every notification this revision defines asks this
     * server for nothing.
     */
    public function test_a_tools_call_notification_neither_streams_nor_runs_the_tool(): void
    {
        NotificationExecutionRecorder::$calls = 0;
        $kernel = $this->kernelWith(ProgressNotificationToolController::class);

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three_and_record', '_meta' => ['progressToken' => 'tok']],
        ]));

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(0, NotificationExecutionRecorder::$calls);
    }

    public function test_a_tools_call_with_a_progress_token_returns_a_streamed_sse_response(): void
    {
        $kernel = $this->progressMcpKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => 'tok']],
        ]));

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        $events = $this->emit($response);
        self::assertCount(4, $events);

        $first = json_decode(substr($events[0], strlen('data: ')), true);
        self::assertSame('notifications/progress', $first['method']);
        self::assertSame(1, $first['params']['progress']);
        self::assertSame('tok', $first['params']['progressToken']);

        $last = json_decode(substr($events[3], strlen('data: ')), true);
        self::assertSame(1, $last['id']);
        self::assertFalse($last['result']['isError']);
    }

    public function test_a_tools_call_without_a_progress_token_stays_a_buffered_json_response(): void
    {
        $kernel = $this->progressMcpKernel();

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three'],
        ]));

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * The identity an `mcp`-group middleware published has to reach the
     * tool on the streamed path too, or authentication would silently stop
     * working the moment a client asks for progress.
     */
    public function test_a_streamed_call_still_sees_the_identity_the_middleware_published(): void
    {
        $kernel = $this->kernelWith(IdentityReportingController::class, ['mcp' => [PublishesUserMiddleware::class]]);

        $response = $kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'whoami_streaming', '_meta' => ['progressToken' => 'tok']],
        ]));
        self::assertInstanceOf(StreamedResponse::class, $response);

        self::assertSame(['caller' => 'agent-7'], $this->toolResult($response));
    }

    /**
     * Identity is only the most visible case. A streamed call dispatches on
     * the request's own scope, so anything an `mcp`-group middleware
     * registered on it is there for the tool to inject, and a streamed call
     * reports exactly what an ordinary one does. A scope of the stream's
     * own would autowire a fresh RequestNote carrying its default text.
     */
    public function test_a_streamed_call_sees_any_object_the_middleware_published_on_the_request_scope(): void
    {
        $kernel = $this->kernelWith(
            RequestNoteReportingController::class,
            ['mcp' => [PublishesRequestNoteMiddleware::class]],
        );

        $ordinary = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'read_request_note'],
        ])));

        $streamed = $this->toolResult($kernel->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'read_request_note', '_meta' => ['progressToken' => 'tok']],
        ])));

        self::assertSame(['note' => 'published by middleware'], $ordinary);
        self::assertSame($ordinary, $streamed, 'a streamed call resolves from the request scope its middleware wrote to');
    }

    /**
     * The tool call itself succeeds and returns a real result — but
     * disposing the request scope behind the stream then fails. That
     * failure must never suppress the already-written final SSE event, and
     * a later dispose callback must still run despite an earlier one
     * throwing.
     */
    public function test_a_streamed_calls_disposal_failure_does_not_suppress_the_final_event(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        $kernel = $this->kernelWith(DisposalFailingToolController::class);

        $response = $kernel->handle($this->disposalFailingCall());
        self::assertInstanceOf(StreamedResponse::class, $response);

        $events = $this->emit($response);

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

        $app = $this->appWith(DisposalFailingToolController::class);
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        $response = $this->kernelFor($app)->handle($this->disposalFailingCall());
        self::assertInstanceOf(StreamedResponse::class, $response);

        $this->emit($response);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('dispose callback failed', $logger->records[0]['context']['message']);
    }

    /**
     * SafeLogger::log($app->get(LoggerInterface::class), ...) is not
     * actually safe on its own: PHP evaluates that get() call before log()
     * is ever entered, so a throwing LoggerInterface binding escapes
     * uncaught right where the stream lease's own resolution happens —
     * suppressing the already-written final event and aborting the stream.
     * This proves it does not.
     *
     * $succeeds: 1 is the number of LoggerInterface resolutions this real
     * request path makes before the lease's own —
     * ExceptionHandlerMiddleware's construction. If this test starts
     * failing because it never reaches the streamed event at all, that
     * count is the first thing to re-check.
     */
    public function test_a_streamed_calls_final_event_survives_even_when_the_logger_itself_cannot_be_resolved(): void
    {
        $app = $this->appWith(DisposalFailingToolController::class);
        $loggerFactory = new ThrowsAfterFirstResolutionLogger(succeeds: 1);
        $app->bind(LoggerInterface::class, $loggerFactory(...), shared: false);
        $app->boot();

        $response = $this->kernelFor($app)->handle($this->disposalFailingCall());
        self::assertInstanceOf(StreamedResponse::class, $response);

        $events = $this->emit($response);

        self::assertCount(1, $events, 'the final event survives even though the logger itself could not be resolved to report the disposal failure');
        $final = json_decode(substr($events[0], strlen('data: ')), true);
        self::assertSame(1, $final['id']);
        self::assertArrayNotHasKey('error', $final);
    }

    /**
     * A genuine output failure, not a manufactured one: PHP invokes an
     * ob_start() handler callback whenever its buffer is flushed, and a
     * callback that throws makes ob_flush() itself throw — the emitter's
     * own `@ob_flush()` suppresses PHP warnings, not a real thrown
     * exception, so this reaches the exact code path a broken or closed
     * output stream would. Proves the real output failure propagates as
     * the primary failure, the request scope behind the stream is still
     * fully disposed (every dispose callback attempted, including a
     * simultaneous disposal failure — contained and logged separately, not
     * instead), and the one failed write attempt is never retried.
     */
    public function test_an_output_failure_still_disposes_the_scope_and_runs_every_callback(): void
    {
        DisposalRecorder::$secondRan = false;
        DisposalRecorder::$scope = null;

        $logger = new InMemoryLogger();

        $app = $this->appWith(DisposalFailingToolController::class);
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        $response = $this->kernelFor($app)->handle($this->disposalFailingCall());
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
            // The throwing handler's own buffer level is left un-popped by
            // the failed flush — pop every level back down to this test's
            // own outer capture regardless of what surfaces, so this test
            // cannot leak buffer state into whatever PHPUnit runs next.
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

    // --- Fixtures. ---

    /**
     * A Kernel with the /mcp route registered the way discovery would
     * register it in a real application: McpController as an ordinary
     * controller, the `mcp` middleware group carrying the origin check,
     * and the shared server bound on AppScope the way this package's
     * bootstrap binds it.
     *
     * @param list<class-string> $extraGroupMiddleware appended to the mcp group after the origin check
     * @param array<string, string> $config
     */
    private function mcpEnabledKernel(array $extraGroupMiddleware = [], array $config = []): Kernel
    {
        $app = $this->appWith(AccountController::class, $config);
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
     * The same shape as mcpEnabledKernel(), registering
     * ThrowingResourceController against a server whose adapter logs
     * through the given logger.
     */
    private function mcpEnabledKernelWithThrowingResource(ThrowingLogger $logger): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $registry = new McpRegistry();
        $registry->register(ThrowingResourceController::class);
        $app->instance(McpServer::class, new McpServer(
            new ServerInfo('Kinetis', '1.0.0'),
            new KinetisMcpApplication($registry, new McpDispatcher($app), $logger),
        ));
        $app->boot();

        return $this->kernelFor($app);
    }

    /** The endpoint with an empty registry — protocol tests needing no tools. */
    private function emptyMcpKernel(): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->instance(McpServer::class, new McpServer(
            new ServerInfo('Kinetis', '1.0.0'),
            new KinetisMcpApplication(new McpRegistry(), new McpDispatcher($app)),
        ));
        $app->boot();

        return $this->kernelFor($app);
    }

    private function progressMcpKernel(): Kernel
    {
        return $this->kernelWith(ProgressReportingController::class);
    }

    /**
     * @param class-string $controller
     * @param array<string, list<class-string>>|null $middlewareGroups
     */
    private function kernelWith(string $controller, ?array $middlewareGroups = null): Kernel
    {
        $app = $this->appWith($controller);
        $app->boot();

        return $this->kernelFor($app, $middlewareGroups);
    }

    /**
     * @param class-string $controller
     * @param array<string, string> $config
     */
    private function appWith(string $controller, array $config = []): AppScope
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        $registry = new McpRegistry();
        $registry->register($controller);
        $app->instance(McpServer::class, new McpServer(
            new ServerInfo('Kinetis', '1.0.0'),
            new KinetisMcpApplication($registry, new McpDispatcher($app)),
        ));

        return $app;
    }

    /**
     * @param array<string, list<class-string>>|null $middlewareGroups
     */
    private function kernelFor(AppScope $app, ?array $middlewareGroups = null): Kernel
    {
        $router = new Router();
        $router->register(McpController::class);

        return new Kernel($app, $router, middlewareGroups: $middlewareGroups ?? ['mcp' => [McpOriginMiddleware::class]]);
    }

    private function disposalFailingCall(): ServerRequest
    {
        return $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'disposal_failing_tool', '_meta' => ['progressToken' => 'tok']],
        ]);
    }

    /**
     * The emitter itself calls ob_flush()/flush() to push each chunk out
     * immediately — a single ob_start() here would have those calls push
     * straight to real stdout instead of accumulating. Nesting a second
     * buffer lets the emitter's own flushes land in the outer one, which is
     * then read back.
     *
     * @return list<string>
     */
    private function emit(StreamedResponse $response): array
    {
        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        $output = (string) ob_get_clean();

        return array_values(array_filter(explode("\n\n", trim($output))));
    }

    /**
     * @return array<string, mixed>
     */
    private function toolResult(ResponseInterface $response): array
    {
        if ($response instanceof StreamedResponse) {
            $events = $this->emit($response);
            $final = json_decode(substr((string) end($events), strlen('data: ')), true);
        } else {
            self::assertSame(200, $response->getStatusCode());
            $final = json_decode((string) $response->getBody(), true);
        }

        return json_decode($final['result']['content'][0]['text'], true);
    }
}
