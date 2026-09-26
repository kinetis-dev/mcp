<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\PackageBootstrap;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\McpProtocol\McpServer;
use PHPUnit\Framework\TestCase;

final class PackageBootstrapTest extends TestCase
{
    public function test_the_bound_server_uses_whatever_mcp_registry_is_already_bound(): void
    {
        // McpRegistry is never discovered here — this proves PackageBootstrap
        // genuinely resolves it from the container (the framework's own
        // PluginDiscovery::bindInstances() call, in real use) rather than
        // rediscovering it itself.
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $app = new AppScope();
        $app->instance(McpRegistry::class, $registry);
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        $server = $app->get(McpServer::class);
        self::assertInstanceOf(McpServer::class, $server);

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertNotNull($response);
        $names = array_column($response['result']['tools'], 'name');
        self::assertContains('create_user', $names);
    }

    public function test_the_bound_server_names_kinetis_on_initialize(): void
    {
        $app = new AppScope();
        $app->instance(McpRegistry::class, new McpRegistry());
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        /** @var McpServer $server */
        $server = $app->get(McpServer::class);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => (object) [
                'name' => 'probe',
                'version' => '1.0',
            ]],
        ]);

        self::assertNotNull($response);
        self::assertSame('2025-06-18', $response['result']['protocolVersion']);
        self::assertSame('Kinetis', $response['result']['serverInfo']['name']);
    }
}
