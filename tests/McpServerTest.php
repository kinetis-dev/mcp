<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Tests\Fixtures\InMemoryLogger;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
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

    public function test_initialize_reports_protocol_version_and_server_info(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        self::assertSame(1, $response['id']);
        // 2025-03-26 is the Streamable HTTP spec revision — not
        // 2024-11-05, which is the deprecated HTTP+SSE one.
        self::assertSame('2025-03-26', $response['result']['protocolVersion']);
        self::assertSame('Kinetis', $response['result']['serverInfo']['name']);
    }

    public function test_initialize_capabilities_encode_as_json_objects_not_arrays(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        // (object) casts in McpServer::initialize() matter here: without
        // them, json_encode would render "tools":[] instead of the
        // spec-correct "tools":{}, since PHP has no native distinction
        // between an empty array and an empty JSON object.
        $encoded = json_encode($response['result']['capabilities'], JSON_THROW_ON_ERROR);
        self::assertSame('{"tools":{},"resources":{}}', $encoded);
    }

    public function test_notifications_initialized_gets_no_response(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertNull($response);
    }

    public function test_ping_returns_an_empty_result(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);

        self::assertSame([], $response['result']);
    }

    public function test_tools_list_reports_registered_tools(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);

        $names = array_column($response['result']['tools'], 'name');
        self::assertSame(['get_user_status', 'create_user'], $names);
    }

    public function test_tools_call_invokes_the_tool_and_returns_text_content(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'get_user_status', 'arguments' => ['userId' => 7]],
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
            'params' => ['name' => 'create_user', 'arguments' => ['data' => ['name' => 'Al', 'email' => 'bad']]],
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
            'params' => ['name' => 'does-not-exist'],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_resources_list_reports_registered_resources(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/list']);

        self::assertSame('kinetis://status', $response['result']['resources'][0]['uri']);
    }

    public function test_resources_read_returns_the_resource_content(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status'],
        ]);

        self::assertSame('ok', $response['result']['contents'][0]['text']);
    }

    public function test_unknown_method_is_an_rpc_error(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'does/not/exist']);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_a_notification_for_an_unknown_method_gets_no_response(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'method' => 'does/not/exist']);

        self::assertNull($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function modernMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];
    }

    public function test_server_discover_reports_supported_versions_and_capabilities(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMeta()],
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
            'params' => ['_meta' => $this->modernMeta()],
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
            'params' => ['_meta' => $this->modernMeta()],
        ]);

        self::assertSame('This server manages user accounts.', $response['result']['instructions']);
    }

    public function test_modern_tools_list_carries_a_public_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => $this->modernMeta()],
        ]);

        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('public', $response['result']['cacheScope']);
    }

    public function test_modern_resources_list_carries_a_public_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/list',
            'params' => ['_meta' => $this->modernMeta()],
        ]);

        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('public', $response['result']['cacheScope']);
    }

    public function test_modern_resources_read_carries_a_private_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status', '_meta' => $this->modernMeta()],
        ]);

        self::assertSame(3_600_000, $response['result']['ttlMs']);
        self::assertSame('private', $response['result']['cacheScope']);
    }

    public function test_modern_tools_call_never_carries_a_caching_hint(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMeta(),
            ],
        ]);

        self::assertArrayNotHasKey('ttlMs', $response['result']);
        self::assertArrayNotHasKey('cacheScope', $response['result']);
    }

    public function test_modern_tools_call_wraps_the_result_in_a_complete_envelope(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_user_status',
                'arguments' => ['userId' => 7],
                '_meta' => $this->modernMeta(),
            ],
        ]);

        self::assertSame('complete', $response['result']['resultType']);
        self::assertFalse($response['result']['isError']);
        self::assertSame(
            ['userId' => 7, 'status' => 'active'],
            json_decode($response['result']['content'][0]['text'], true),
        );
    }

    public function test_modern_request_missing_protocol_version_is_invalid_params(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/clientCapabilities' => []]],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_modern_request_missing_client_capabilities_is_invalid_params(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function test_modern_request_with_an_unsupported_protocol_version_reports_supported_versions(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]],
        ]);

        self::assertSame(-32022, $response['error']['code']);
        self::assertSame(['2026-07-28'], $response['error']['data']['supported']);
        self::assertSame('1999-01-01', $response['error']['data']['requested']);
    }

    public function test_ping_is_not_reachable_through_the_modern_path(): void
    {
        // Checked directly against the real 2026-07-28 changelog, not
        // assumed: this revision removed `ping` from the core protocol
        // entirely (along with logging/setLevel and
        // notifications/roots/list_changed). The legacy era keeps it —
        // see test_ping_returns_an_empty_result() above, unaffected.
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'ping',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_legacy_initialize_is_not_reachable_through_the_modern_path(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'initialize',
            'params' => ['_meta' => $this->modernMeta()],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function test_is_modern_request_detects_the_meta_protocol_version_key(): void
    {
        self::assertTrue(McpServer::isModernRequest([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => ['_meta' => $this->modernMeta()],
        ]));

        self::assertFalse(McpServer::isModernRequest([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
        ]));
    }

    public function test_a_progress_token_streams_notifications_before_the_final_result_on_legacy_requests(): void
    {
        $notifications = [];
        $onNotification = static function (array $n) use (&$notifications): void {
            $notifications[] = $n;
        };

        $response = $this->progressServer()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => 'tok']],
        ], $onNotification);

        self::assertCount(3, $notifications);
        self::assertSame('tok', $notifications[0]['progressToken']);
        self::assertSame(1, $notifications[0]['progress']);
        self::assertSame(3, $notifications[2]['progress']);
        self::assertFalse($response['result']['isError']);
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
            'params' => ['name' => 'count_to_three'],
        ], $onNotification);

        self::assertSame([], $notifications);
        self::assertFalse($response['result']['isError']);
    }

    public function test_a_progress_token_streams_notifications_on_modern_requests_too(): void
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
                '_meta' => [...$this->modernMeta(), 'progressToken' => 'tok'],
            ],
        ], $onNotification);

        self::assertCount(3, $notifications);
        self::assertSame('complete', $response['result']['resultType']);
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
            'params' => ['uri' => 'kinetis://throws'],
        ]);

        self::assertSame(-32603, $response['error']['code']);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('resources/read', $logger->records[0]['context']['method']);
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
            'params' => ['name' => 'explode', 'arguments' => []],
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
}
