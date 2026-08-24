<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\ZeroParameterToolController;
use PHPUnit\Framework\TestCase;

final class McpRegistryTest extends TestCase
{
    public function test_registers_tools_and_resources_from_attributes(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $names = array_map(static fn ($tool) => $tool->name, $registry->tools());
        self::assertSame(['get_user_status', 'create_user'], $names);

        $uris = array_map(static fn ($resource) => $resource->uri, $registry->resources());
        self::assertSame(['kinetis://status'], $uris);
    }

    public function test_builds_a_scalar_input_schema_for_a_plain_tool(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $tool = $registry->findTool('get_user_status');

        self::assertNotNull($tool);
        self::assertSame(
            ['type' => 'object', 'properties' => ['userId' => ['type' => 'integer']], 'required' => ['userId']],
            $tool->inputSchema,
        );
    }

    public function test_builds_a_nested_object_schema_for_a_dto_typed_tool_parameter(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $tool = $registry->findTool('create_user');

        self::assertNotNull($tool);
        $dataSchema = $tool->inputSchema['properties']['data'];

        self::assertSame('object', $dataSchema['type']);
        self::assertSame(3, $dataSchema['properties']['name']['minLength']);
        self::assertSame('email', $dataSchema['properties']['email']['format']);
    }

    public function test_find_tool_returns_null_for_an_unknown_name(): void
    {
        $registry = new McpRegistry();

        self::assertNull($registry->findTool('does-not-exist'));
    }

    public function test_find_resource_returns_null_for_an_unknown_uri(): void
    {
        $registry = new McpRegistry();

        self::assertNull($registry->findResource('kinetis://does-not-exist'));
    }

    public function test_to_array_from_array_round_trip_behaves_identically_to_live_registration(): void
    {
        $live = new McpRegistry();
        $live->register(AccountController::class);

        $reconstructed = McpRegistry::fromArray($live->toArray());

        self::assertEquals($live->tools(), $reconstructed->tools());
        self::assertEquals($live->resources(), $reconstructed->resources());

        $tool = $reconstructed->findTool('create_user');
        self::assertNotNull($tool);
        self::assertSame(3, $tool->inputSchema['properties']['data']['properties']['name']['minLength']);
    }

    public function test_a_zero_parameter_tools_empty_properties_object_survives_a_real_var_export_cache_round_trip(): void
    {
        // JsonSchema::forParameters() casts an empty `properties` to
        // `(object) []` so it JSON-encodes as `{}`, not `[]` — a live
        // object var_export() cannot represent (it would render a
        // `::__set_state()` call stdClass doesn't define). This is the
        // real failure this test exists to catch: a zero-parameter tool
        // is exactly the case that produces one.
        $live = new McpRegistry();
        $live->register(ZeroParameterToolController::class);

        $tool = $live->findTool('zero_param_ping');
        self::assertNotNull($tool);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']);

        $directory = sys_get_temp_dir() . '/kinetis_mcp_registry_cache_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);
        $compiled = new CompiledCache(
            http: new HttpCache(
                formatVersion: CacheFormat::VERSION,
                routes: [],
                httpBindingPlans: [],
                hydrationPlans: [],
                globalMiddleware: [],
                openApiMiddleware: [],
                compiledAt: '2026-01-01T00:00:00+00:00',
            ),
            commands: new CommandCache(formatVersion: CacheFormat::VERSION, commands: [], compiledAt: '2026-01-01T00:00:00+00:00'),
            events: new EventCache(formatVersion: CacheFormat::VERSION, listeners: [], compiledAt: '2026-01-01T00:00:00+00:00'),
            plugins: new PluginCache(
                formatVersion: CacheFormat::VERSION,
                data: [McpRegistry::class => $live->toArray()],
                compiledAt: '2026-01-01T00:00:00+00:00',
            ),
        );

        try {
            // The real path: writeAll() is what would throw the real
            // CacheWriteException if toArray() hadn't already converted
            // the stdClass to a plain array.
            $store->writeAll($compiled);
            $pluginCache = $store->loadPlugins();

            self::assertNotNull($pluginCache);
            $reloaded = McpRegistry::fromArray($pluginCache->data[McpRegistry::class]);

            $reloadedTool = $reloaded->findTool('zero_param_ping');
            self::assertNotNull($reloadedTool);
            self::assertInstanceOf(\stdClass::class, $reloadedTool->inputSchema['properties']);
            self::assertSame('{}', json_encode($reloadedTool->inputSchema['properties'], JSON_THROW_ON_ERROR));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_implements_the_frameworks_cacheable_discovery_interface(): void
    {
        self::assertInstanceOf(CacheableDiscoveryInterface::class, new McpRegistry());
    }

    public function test_compile_delegates_to_discovery_and_reduces_it_to_plain_data(): void
    {
        $data = McpRegistry::compile(dirname(__DIR__));

        $reloaded = McpRegistry::fromArray($data);

        self::assertNotNull($reloaded->findResource('kinetis://docs/index'));
    }
}
