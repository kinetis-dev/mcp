<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\JsonObject;
use Kinetis\Mcp\JsonRpcCodec;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Tests\Fixtures\InMemoryLogger;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\BuiltinCoverageToolController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use Kinetis\Mcp\Tests\Fixtures\ThrowingLogger;
use Kinetis\Mcp\Tests\Fixtures\ThrowingResourceController;
use Kinetis\Mcp\Tests\Fixtures\ThrowingToolController;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
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

    private function builtinCoverageServer(): McpServer
    {
        $registry = new McpRegistry();
        $registry->register(BuiltinCoverageToolController::class);

        $app = new AppScope();
        $app->boot();

        return new McpServer($registry, new McpDispatcher($app));
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
            'io.modelcontextprotocol/clientCapabilities' => new JsonObject([]),
        ];
    }

    public function test_tools_list_reports_registered_tools(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        $names = array_column($response['result']['tools'], 'name');
        self::assertSame(['get_user_status', 'create_user'], $names);
    }

    public function test_tools_call_invokes_the_tool_and_returns_text_content(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7], '_meta' => $this->meta()],
        ]);

        self::assertFalse($response['result']['isError']);
        self::assertSame(
            ['userId' => 7, 'status' => 'active'],
            json_decode($response['result']['content'][0]['text'], true),
        );
    }

    public function test_tools_call_with_invalid_dto_arguments_reports_is_error_not_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_user',
                'arguments' => ['data' => ['name' => 'Al', 'email' => 'bad']],
                '_meta' => $this->meta(),
            ],
        ]);

        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        $errors = json_decode($response['result']['content'][0]['text'], true)['errors'];
        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('email', $errors);
    }

    public function test_tools_call_with_an_unknown_tool_name_is_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'does-not-exist', '_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    /**
     * A missing `name` is caught by preflight() itself now, not left to
     * callTool()'s own registry lookup — closing the gap where a
     * progress-token-carrying request with no name at all would
     * otherwise have reached Http\McpController's SSE-stream decision
     * before this was ever discovered.
     */
    public function test_tools_call_with_a_missing_name_is_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['arguments' => new JsonObject([]), '_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_tools_call_with_an_empty_name_is_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => '', '_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_resources_list_reports_registered_resources(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'resources/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame('kinetis://status', $response['result']['resources'][0]['uri']);
    }

    public function test_resources_read_returns_the_resource_content(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status', '_meta' => $this->meta()],
        ]);

        self::assertSame('ok', $response['result']['contents'][0]['text']);
    }

    public function test_resources_read_with_a_missing_uri_is_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'resources/read',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_resources_read_with_an_empty_uri_is_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'resources/read',
            'params' => ['uri' => '', '_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_unknown_method_is_an_rpc_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'does/not/exist',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_a_notification_for_an_unknown_method_gets_no_response(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'does/not/exist',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertNull($response);
    }

    public function test_server_discover_reports_supported_versions_and_capabilities(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame('complete', $response['result']['resultType']);
        self::assertSame(['2026-07-28'], $response['result']['supportedVersions']);
        self::assertSame(
            '{"tools":{},"resources":{}}',
            json_encode($response['result']['capabilities'], JSON_THROW_ON_ERROR),
        );
        self::assertSame('Kinetis', $response['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('public', $response['result']['cacheScope']);
    }

    public function test_server_discover_omits_instructions_when_none_given(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertArrayNotHasKey('instructions', $response['result']);
    }

    public function test_server_discover_reports_a_given_instructions_string(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);
        $app = new AppScope();
        $app->boot();
        $server = new McpServer($registry, new McpDispatcher($app), instructions: 'This server manages user accounts.');

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame('This server manages user accounts.', $response['result']['instructions']);
    }

    public function test_tools_list_carries_a_public_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('public', $response['result']['cacheScope']);
    }

    public function test_resources_list_carries_a_public_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('public', $response['result']['cacheScope']);
    }

    public function test_resources_read_carries_a_private_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status', '_meta' => $this->meta()],
        ]);

        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('private', $response['result']['cacheScope']);
    }

    public function test_tools_call_never_carries_a_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->meta(),
            ],
        ]);

        self::assertArrayNotHasKey('ttlMs', $response['result']);
        self::assertArrayNotHasKey('cacheScope', $response['result']);
    }

    public function test_tools_call_wraps_the_result_in_a_complete_envelope(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->meta(),
            ],
        ]);

        self::assertSame('complete', $response['result']['resultType']);
        self::assertFalse($response['result']['isError']);
        self::assertSame(
            ['userId' => 7, 'status' => 'active'],
            json_decode($response['result']['content'][0]['text'], true),
        );
    }

    public function test_a_request_missing_protocol_version_is_invalid_params(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/clientCapabilities' => new JsonObject([])]],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_request_missing_client_capabilities_is_invalid_params(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_request_with_an_unsupported_protocol_version_reports_supported_versions(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
                'io.modelcontextprotocol/clientCapabilities' => new JsonObject([]),
            ]],
        ]);

        self::assertSame(-32022, $response['error']['code']);
        self::assertSame(['2026-07-28'], $response['error']['data']['supported']);
        self::assertSame('1999-01-01', $response['error']['data']['requested']);
    }

    /**
     * Checked directly against the real 2026-07-28 changelog, not
     * assumed: this revision removed `ping` from the core protocol
     * entirely (along with logging/setLevel and
     * notifications/roots/list_changed) — it is simply an unrecognized
     * method now, the same as any other.
     */
    public function test_ping_is_not_a_recognized_method(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'ping',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    /**
     * The pre-2026-07-28 handshake method — not implemented at all.
     */
    public function test_initialize_is_not_a_recognized_method(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'initialize',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_a_progress_token_streams_notifications_before_the_final_result(): void
    {
        $notifications = [];
        $onNotification = static function (array $n) use (&$notifications): void {
            $notifications[] = $n;
        };

        $response = $this->progressServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'count_to_three',
                '_meta' => [...$this->meta(), 'progressToken' => 'tok'],
            ],
        ], $onNotification);

        self::assertCount(3, $notifications);
        self::assertSame('tok', $notifications[0]['progressToken']);
        self::assertSame(1, $notifications[0]['progress']);
        self::assertSame(3, $notifications[2]['progress']);
        self::assertFalse($response['result']['isError']);
        self::assertSame('complete', $response['result']['resultType']);
    }

    public function test_no_progress_token_means_no_notifications_are_emitted(): void
    {
        $notifications = [];
        $onNotification = static function (array $n) use (&$notifications): void {
            $notifications[] = $n;
        };

        $response = $this->progressServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => $this->meta()],
        ], $onNotification);

        self::assertSame([], $notifications);
        self::assertFalse($response['result']['isError']);
    }

    public function test_an_unexpected_exception_becomes_internal_error_and_is_logged(): void
    {
        $registry = new McpRegistry();
        $registry->register(ThrowingResourceController::class);

        $app = new AppScope();
        $app->boot();

        $logger = new InMemoryLogger();
        $server = new McpServer($registry, new McpDispatcher($app), logger: $logger);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://throws', '_meta' => $this->meta()],
        ]);

        self::assertSame(-32603, $response['error']['code']);
        // A fixed, generic message — never the caught exception's own,
        // which (per ThrowingResourceController's own fixture message)
        // would otherwise carry a fake SQL error, a credential, and a
        // file path straight to the remote client.
        self::assertSame('Internal error.', $response['error']['message']);

        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hunter2', $encoded);
        self::assertStringNotContainsString('SQLSTATE', $encoded);
        self::assertStringNotContainsString('SecretRepo.php', $encoded);

        // The real exception still reaches a working logger — only the
        // client-facing envelope is redacted.
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('resources/read', $logger->records[0]['context']['method']);
        self::assertStringContainsString('hunter2', $logger->records[0]['context']['message']);
    }

    /**
     * A logger that itself throws must never be allowed to replace the
     * generic -32603 envelope, escape handle() entirely, or leak the
     * real exception's message some other way — the terminal boundary
     * this class exists to be.
     */
    public function test_a_throwing_logger_still_produces_the_generic_internal_error_envelope(): void
    {
        $registry = new McpRegistry();
        $registry->register(ThrowingResourceController::class);

        $app = new AppScope();
        $app->boot();

        $logger = new ThrowingLogger();
        $server = new McpServer($registry, new McpDispatcher($app), logger: $logger);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://throws', '_meta' => $this->meta()],
        ]);

        self::assertNotNull($response, 'a broken logger must not turn a request with an id into no response at all');
        self::assertSame(-32603, $response['error']['code']);
        self::assertSame('Internal error.', $response['error']['message']);

        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hunter2', $encoded);
        self::assertStringNotContainsString('SQLSTATE', $encoded);

        // The failing logger was genuinely attempted, not skipped.
        self::assertCount(1, $logger->entries);
    }

    public function test_a_throwing_tool_reports_a_generic_failure_and_logs_the_real_exception(): void
    {
        $registry = new McpRegistry();
        $registry->register(ThrowingToolController::class);

        $app = new AppScope();
        $app->boot();

        $logger = new InMemoryLogger();
        $server = new McpServer($registry, new McpDispatcher($app), logger: $logger);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'explode', 'arguments' => new JsonObject([]), '_meta' => $this->meta()],
        ]);

        // Still the "tool ran but failed" convention — never a JSON-RPC
        // error — but the content is a fixed string, not the exception's
        // own message.
        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        self::assertSame('Tool execution failed.', $response['result']['content'][0]['text']);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringContainsString('SQLSTATE[42S02]', $logger->records[0]['context']['message']);
    }

    /**
     * The tool path's own containment (a fixed content string, never
     * the real exception) must hold even when the thing reporting the
     * real exception is itself broken — a throwing tool result must
     * still be a normal MCP result, never converted into a JSON-RPC
     * error just because logging it failed.
     */
    public function test_a_throwing_tool_with_a_throwing_logger_still_reports_the_generic_result(): void
    {
        $registry = new McpRegistry();
        $registry->register(ThrowingToolController::class);

        $app = new AppScope();
        $app->boot();

        $logger = new ThrowingLogger();
        $server = new McpServer($registry, new McpDispatcher($app), logger: $logger);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'explode', 'arguments' => new JsonObject([]), '_meta' => $this->meta()],
        ]);

        self::assertNotNull($response);
        self::assertArrayNotHasKey('error', $response, 'a logger failure must never turn this into a JSON-RPC error');
        self::assertTrue($response['result']['isError']);
        self::assertSame('Tool execution failed.', $response['result']['content'][0]['text']);

        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SQLSTATE', $encoded);
        self::assertStringNotContainsString('secrets', $encoded);

        self::assertCount(1, $logger->entries, 'the failing logger was genuinely attempted, not skipped');
    }

    // --- Envelope validation: handle() defends its own array boundary,
    // the same rules JsonRpcCodecTest pins directly, reached here through
    // a real McpServer::handle() call rather than the codec alone. ---

    public function test_a_missing_jsonrpc_member_is_invalid_request_not_dispatched(): void
    {
        $response = $this->server()->handle([
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32600, $response['error']['code']);
        self::assertSame(1, $response['id']);
    }

    public function test_a_non_string_method_is_invalid_request_not_a_parse_error(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 42,
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32600, $response['error']['code']);
    }

    /**
     * A structurally invalid message missing `id` is not a
     * notification — only a valid one without an id is. A missing/
     * non-string `method` with no `id` at all must still produce an
     * error response, never the null a genuine notification returns.
     */
    public function test_an_invalid_request_with_no_id_still_gets_a_response_not_treated_as_a_notification(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 42,
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertNotNull($response, 'a structurally invalid message must never be silently swallowed');
        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }

    public function test_an_id_outside_the_supported_type_domain_is_rejected_with_a_null_id(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => ['not', 'a', 'valid', 'id'],
            'method' => 'tools/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }

    public function test_a_scalar_params_is_invalid_params_not_silently_treated_as_empty(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => 'not-an-object',
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_list_shaped_meta_is_invalid_params_not_silently_treated_as_empty(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => ['not', 'an', 'object']],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_scalar_client_capabilities_is_invalid_params(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => 'nope',
            ]],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    /**
     * A malformed `arguments` container must never reach the tool at
     * all — the fixture would otherwise report a real userId, proving
     * the tool ran, if this were silently coerced to an empty array
     * instead of rejected.
     */
    public function test_a_list_shaped_arguments_is_invalid_params_and_the_tool_never_runs(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => [1, 2, 3], '_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_a_scalar_arguments_is_invalid_params(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => 'nope', '_meta' => $this->meta()],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    /**
     * A malformed progressToken must be rejected outright, not silently
     * disable progress — a client that explicitly asked for it deserves
     * an error, not quiet non-reporting indistinguishable from a tool
     * that never reports any.
     */
    public function test_a_malformed_progress_token_is_invalid_params_and_no_notifications_are_emitted(): void
    {
        $notifications = [];
        $onNotification = static function (array $n) use (&$notifications): void {
            $notifications[] = $n;
        };

        $response = $this->progressServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'count_to_three',
                '_meta' => [...$this->meta(), 'progressToken' => ['not', 'valid']],
            ],
        ], $onNotification);

        self::assertSame(-32602, $response['error']['code']);
        self::assertSame([], $notifications);
    }

    public function test_a_present_null_params_is_treated_the_same_as_absent(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => null,
        ]);

        self::assertSame(-32602, $response['error']['code'], 'still fails validateMeta() — but as a normal missing-meta case, not a structural one');
    }

    // --- Notification suppression: once the envelope itself is valid,
    // "no id" reliably means notification, and per JSON-RPC 2.0 a
    // notification's caller gets no response even when its content is
    // invalid — never confused with the structural-failure case above,
    // which always responds regardless of id. ---

    public function test_a_notification_with_missing_meta_gets_no_response(): void
    {
        // No `params` at all — a structurally valid, empty-params
        // notification, so this exercises the *nested* `_meta`-missing
        // failure specifically, not JsonRpcCodec's own structural
        // params-shape check (a bare `[]` there would be a different,
        // always-reported failure — see the present-null/empty-array
        // tests above).
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
        ]);

        self::assertNull($response);
    }

    public function test_a_notification_with_an_unsupported_protocol_version_gets_no_response(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
                'io.modelcontextprotocol/clientCapabilities' => new JsonObject([]),
            ]],
        ]);

        self::assertNull($response);
    }

    public function test_a_notification_with_malformed_client_capabilities_gets_no_response(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => 'nope',
            ]],
        ]);

        self::assertNull($response);
    }

    /**
     * The tool must genuinely never run — not just "no response sent."
     */
    public function test_a_tools_call_notification_with_malformed_arguments_gets_no_response_and_never_invokes_the_tool(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => [1, 2, 3], '_meta' => $this->meta()],
        ]);

        self::assertNull($response);
    }

    public function test_a_tools_call_notification_with_a_missing_name_gets_no_response(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['arguments' => new JsonObject([]), '_meta' => $this->meta()],
        ]);

        self::assertNull($response);
    }

    // KINETIS-76 follow-up: the MCP error envelope/content contract for a
    // wrong-shaped builtin-typed argument, pinned end-to-end through a
    // real JSON-RPC tools/call — not just at the McpDispatcher unit level.
    // Hydrator::typeMismatchMessage() is the exact same check an HTTP
    // #[Query]/path parameter or #[Body] field gets; this proves McpServer
    // carries its ValidationException through to the same isError:true +
    // {errors: {...}} shape every DTO-argument validation failure already
    // gets (see test_tools_call_with_invalid_dto_arguments_reports_is_error_not_an_rpc_error
    // above), for a plain top-level scalar argument too.

    public function test_a_wrong_shaped_plain_array_argument_reports_is_error_with_the_field_message(): void
    {
        $response = $this->builtinCoverageServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => [
                'name' => 'builtin_coverage',
                'arguments' => ['tags' => 'not-an-array', 'items' => []],
                '_meta' => $this->meta(),
            ],
        ]);

        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        $errors = json_decode($response['result']['content'][0]['text'], true)['errors'];
        self::assertSame(['must be an array, value given.'], $errors['tags']);
    }

    public function test_a_correctly_shaped_call_across_every_supported_builtin_category_succeeds(): void
    {
        $response = $this->builtinCoverageServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'builtin_coverage',
                'arguments' => [
                    'tags' => ['a', 'b'],
                    'items' => ['c'],
                    'note' => 'anything',
                    'marker' => null,
                    'confirmed' => true,
                    'declined' => false,
                ],
                '_meta' => $this->meta(),
            ],
        ]);

        self::assertFalse($response['result']['isError']);
        self::assertSame(
            [
                'tags' => ['a', 'b'],
                'items' => ['c'],
                'note' => 'anything',
                'marker' => null,
                'confirmed' => true,
                'declined' => false,
            ],
            json_decode($response['result']['content'][0]['text'], true),
        );
    }

    public function test_a_wrong_shaped_standalone_true_argument_reports_is_error_with_the_field_message(): void
    {
        $response = $this->builtinCoverageServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'tools/call',
            'params' => [
                'name' => 'builtin_coverage',
                'arguments' => ['tags' => [], 'items' => [], 'confirmed' => false],
                '_meta' => $this->meta(),
            ],
        ]);

        self::assertTrue($response['result']['isError']);
        $errors = json_decode($response['result']['content'][0]['text'], true)['errors'];
        self::assertSame(['must be true, boolean given.'], $errors['confirmed']);
    }

    /**
     * A real tools/list JSON-RPC round trip, checking the actual wire
     * bytes — not just the in-memory schema array's object identity (see
     * McpRegistryTest for that level). A bare `[]` for `mixed`'s schema
     * would encode as the invalid JSON array `"note":[]`; this pins the
     * real serialized response so the regression this class exists to
     * prevent can never silently reappear one layer further down the
     * pipeline (McpServer::handle()'s own json_encode(), not
     * OpenApiGenerator's).
     */
    public function test_tools_list_serializes_the_mixed_property_as_a_json_object_not_an_array(): void
    {
        $response = $this->builtinCoverageServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'tools/list',
            'params' => ['_meta' => $this->meta()],
        ]);

        $encoded = json_encode($response, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"note":{}', $encoded);
        self::assertStringNotContainsString('"note":[]', $encoded);
    }

    // KINETIS-76 second follow-up: real MCP JSON decoding, not only
    // already-flattened PHP arrays — every prior tools/call test in this
    // file hand-builds its `arguments` as a plain PHP array directly,
    // which can never express "this came from a genuine JSON object" at
    // all. These two go through the real wire path instead: a raw JSON-RPC
    // message string, decoded via JsonRpcCodec::decode() exactly the way
    // StdioTransport/Http\McpController actually do.

    /**
     * The exact gap array_is_list() alone cannot close, reached through
     * the real MCP transport decode path: a JSON object whose own keys
     * happen to look like a sequential list ({"0":"a","1":"b"}) decodes
     * to the identical PHP shape a real JSON array does once flattened.
     * This can only be constructed as a literal raw JSON string — PHP's
     * own json_encode() would turn an equivalent PHP array back into a
     * real JSON array.
     */
    public function test_a_real_json_object_argument_with_sequential_numeric_keys_is_rejected(): void
    {
        $raw = '{"jsonrpc":"2.0","id":24,"method":"tools/call","params":{'
            . '"name":"builtin_coverage",'
            . '"arguments":{"tags":{"0":"a","1":"b"},"items":[]},'
            . '"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}'
            . '}}';

        ['message' => $message] = JsonRpcCodec::decode($raw);
        $response = $this->builtinCoverageServer()->handle($message);

        self::assertTrue($response['result']['isError']);
        $errors = json_decode($response['result']['content'][0]['text'], true)['errors'];
        self::assertSame(['must be a JSON array, not a JSON object.'], $errors['tags']);
    }

    /**
     * The positive counterpart, through the same real decode path: a
     * genuine JSON object argument for a DTO-typed tool parameter still
     * hydrates correctly — proves the JsonObject unwrap in
     * McpDispatcher::resolveValueFromPlan() actually recurses into it,
     * not just that the array/iterable rejection above works.
     */
    public function test_a_real_json_object_argument_for_a_dto_typed_parameter_still_hydrates(): void
    {
        $raw = '{"jsonrpc":"2.0","id":25,"method":"tools/call","params":{'
            . '"name":"create_user",'
            . '"arguments":{"data":{"name":"Alon","email":"alon@example.com"}},'
            . '"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}'
            . '}}';

        ['message' => $message] = JsonRpcCodec::decode($raw);
        $response = $this->server()->handle($message);

        self::assertFalse($response['result']['isError']);
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@example.com'],
            json_decode($response['result']['content'][0]['text'], true),
        );
    }
}
