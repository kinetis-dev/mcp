<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Mcp\Exception\DuplicateDefinitionException;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\AdversarialSchemaValuesToolController;
use Kinetis\Mcp\Tests\Fixtures\BuiltinCoverageToolController;
use Kinetis\Mcp\Tests\Fixtures\DuplicateResourceUriController;
use Kinetis\Mcp\Tests\Fixtures\DuplicateToolNameController;
use Kinetis\Mcp\Tests\Fixtures\NullableFieldsToolController;
use Kinetis\Mcp\Tests\Fixtures\IntraClassDuplicateResourceController;
use Kinetis\Mcp\Tests\Fixtures\IntraClassDuplicateToolController;
use Kinetis\Mcp\Tests\Fixtures\MixedNewAndConflictingToolController;
use Kinetis\Mcp\Tests\Fixtures\MultipleUnsupportedParametersToolController;
use Kinetis\Mcp\Tests\Fixtures\UnsupportedCallableParameterToolController;
use Kinetis\Mcp\Tests\Fixtures\UnsupportedParameterToolController;
use Kinetis\Mcp\Tests\Fixtures\ZeroParameterToolController;
use Kinetis\Validation\Exception\JsonSchemaException;
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
        // `(object) []` so it JSON-encodes as `{}`, not `[]` — but this
        // codebase's own cache format never carries a live PHP object
        // (the "no live objects in the cache" discipline every other
        // compiled artifact here follows too), so toArray()/fromArray()
        // convert it to/from a reserved marker around the actual
        // var_export()/require() round trip. This is the real failure
        // this test exists to catch: a zero-parameter tool is exactly
        // the case that produces one.
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
            CacheStore::destroy($directory);
        }
    }

    /**
     * The same real var_export()/require() cache round trip as the
     * zero-parameter case above, but for a `stdClass` appearing at an
     * *arbitrary* nesting depth — a `mixed`-typed tool argument's own
     * full schema, not the top-level "properties" key — proving
     * normalizeSchemaForStorage()/restoreSchemaFromStorage() genuinely
     * walk the whole tree by value type, not just one hardcoded key.
     */
    public function test_a_mixed_typed_arguments_empty_schema_object_survives_a_real_var_export_cache_round_trip(): void
    {
        $live = new McpRegistry();
        $live->register(BuiltinCoverageToolController::class);

        $tool = $live->findTool('builtin_coverage');
        self::assertNotNull($tool);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']['note']);

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
            $store->writeAll($compiled);
            $pluginCache = $store->loadPlugins();

            self::assertNotNull($pluginCache);
            $reloaded = McpRegistry::fromArray($pluginCache->data[McpRegistry::class]);

            $reloadedTool = $reloaded->findTool('builtin_coverage');
            self::assertNotNull($reloadedTool);
            $reloadedNote = $reloadedTool->inputSchema['properties']['note'];
            self::assertInstanceOf(\stdClass::class, $reloadedNote);
            self::assertSame('{}', json_encode($reloadedNote, JSON_THROW_ON_ERROR));

            // A genuinely non-empty, non-object sibling value survived
            // the exact same walk unmutated — the marker only ever
            // touches a real stdClass, never a real empty array (e.g.
            // `tags`'s own `{type: 'array'}` schema, which is never
            // itself empty).
            self::assertSame(['type' => 'array'], $reloadedTool->inputSchema['properties']['tags']);
        } finally {
            CacheStore::destroy($directory);
        }
    }

    /**
     * Adversarial cache-round-trip coverage: real schema string values
     * — an #[In] enum choice and a #[Regex] pattern — that deliberately
     * equal McpRegistry's own *retired* bare-string marker literal
     * ("__kinetis_mcp_empty_object__"). The reserved-key array envelope
     * that replaced it must not mistake either for the marker, and both
     * must survive the real var_export()/require() round trip byte for
     * byte.
     */
    public function test_a_schema_string_matching_the_retired_marker_literal_survives_the_cache_round_trip_unmutated(): void
    {
        $live = new McpRegistry();
        $live->register(AdversarialSchemaValuesToolController::class);

        $tool = $live->findTool('adversarial_schema_values');
        self::assertNotNull($tool);
        self::assertSame(
            ['__kinetis_mcp_empty_object__', 'other'],
            $tool->inputSchema['properties']['choice']['enum'],
        );
        self::assertSame(
            '__kinetis_mcp_empty_object__',
            $tool->inputSchema['properties']['pattern']['pattern'],
        );

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
            $store->writeAll($compiled);
            $pluginCache = $store->loadPlugins();

            self::assertNotNull($pluginCache);
            $reloaded = McpRegistry::fromArray($pluginCache->data[McpRegistry::class]);

            $reloadedTool = $reloaded->findTool('adversarial_schema_values');
            self::assertNotNull($reloadedTool);

            // Neither string value was mistaken for the marker and
            // converted into an object — both survive as the real
            // schema strings/lists they actually are.
            self::assertSame(
                ['__kinetis_mcp_empty_object__', 'other'],
                $reloadedTool->inputSchema['properties']['choice']['enum'],
            );
            self::assertSame(
                '__kinetis_mcp_empty_object__',
                $reloadedTool->inputSchema['properties']['pattern']['pattern'],
            );
        } finally {
            CacheStore::destroy($directory);
        }
    }

    /**
     * KINETIS-76 third follow-up: the collision-free replacement for the
     * test above. A schema value that is itself an array shaped *exactly*
     * like the retired reserved-key envelope
     * (`['__kinetis_mcp_empty_object_marker__' => true]`) — the shape the
     * previous fix's own marker constant literally was — survives a real
     * `McpRegistry::fromArray()` -> `toArray()` -> `fromArray()` round
     * trip completely unmutated, in the same artifact as a real
     * `stdClass` that genuinely does need converting. Nothing in this
     * codebase's own real `JsonSchema` vocabulary can actually produce an
     * array-shaped schema *value* today (`#[In]`'s own `$choices` is
     * `list<scalar>`, never an array) — constructed directly against the
     * compiled-artifact shape instead, the same hand-crafted-artifact
     * precedent `test_from_array_rejects_a_tool_missing_a_required_field()`
     * already establishes, to prove the *mechanism* itself is
     * structurally collision-free regardless of whether real JsonSchema
     * output could ever reach this shape.
     */
    public function test_a_schema_value_shaped_like_the_retired_marker_envelope_survives_unmutated(): void
    {
        $artifact = [
            'tools' => [[
                'name' => 'adversarial_shape',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'run',
                'inputSchema' => [
                    'type' => 'object',
                    // A real value shaped exactly like the retired
                    // marker envelope, at an ordinary key — must never
                    // be mistaken for an object-conversion instruction,
                    // since nothing here compares a value against
                    // anything any more.
                    'lookalike' => ['__kinetis_mcp_empty_object_marker__' => true],
                    // A real stdClass-to-be, at its own recorded path —
                    // must still be correctly restored in the same
                    // document as the lookalike above.
                    'properties' => [],
                ],
                'inputSchemaObjectPaths' => [['properties']],
            ]],
            'resources' => [],
        ];

        $registry = McpRegistry::fromArray($artifact);
        $tool = $registry->findTool('adversarial_shape');

        self::assertNotNull($tool);
        self::assertSame(['__kinetis_mcp_empty_object_marker__' => true], $tool->inputSchema['lookalike']);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']);

        // Round-tripped a second time through toArray() -> fromArray():
        // the lookalike value must still survive, and the real object
        // must still correctly re-derive its own path from a fresh
        // structural walk, not from anything left over in the first pass.
        $roundTripped = McpRegistry::fromArray($registry->toArray());
        $reloadedTool = $roundTripped->findTool('adversarial_shape');

        self::assertNotNull($reloadedTool);
        self::assertSame(['__kinetis_mcp_empty_object_marker__' => true], $reloadedTool->inputSchema['lookalike']);
        self::assertInstanceOf(\stdClass::class, $reloadedTool->inputSchema['properties']);
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

    /**
     * fromArray()'s CacheableDiscoveryInterface contract requires
     * throwing something implementing CacheArtifactExceptionInterface
     * for malformed data — verified directly against real, malformed
     * shapes, not assumed from the interface's own docblock alone.
     */
    public function test_from_array_rejects_a_missing_top_level_field(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray(['tools' => []]);
    }

    public function test_from_array_rejects_a_tool_missing_a_required_field(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray([
            'tools' => [['name' => 'ping', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'ping']],
            'resources' => [],
        ]);
    }

    public function test_from_array_rejects_a_resource_missing_a_required_field(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray([
            'tools' => [],
            'resources' => [['uri' => 'kinetis://docs/x', 'name' => 'x', 'description' => '', 'mimeType' => 'text/markdown']],
        ]);
    }

    // KINETIS-76 third follow-up: malformed inputSchemaObjectPaths
    // artifacts — a hand-edited or otherwise corrupt compiled cache
    // file, not something toArray() itself could ever produce — must be
    // rejected loudly rather than silently misread.

    public function test_from_array_rejects_an_object_path_that_does_not_resolve_to_a_real_node(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray([
            'tools' => [[
                'name' => 'broken',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'run',
                'inputSchema' => ['type' => 'object'],
                'inputSchemaObjectPaths' => [['doesNotExist']],
            ]],
            'resources' => [],
        ]);
    }

    public function test_from_array_rejects_an_object_path_that_traverses_a_non_array_node(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray([
            'tools' => [[
                'name' => 'broken',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'run',
                'inputSchema' => ['properties' => 'not-an-array'],
                'inputSchemaObjectPaths' => [['properties', 'nested']],
            ]],
            'resources' => [],
        ]);
    }

    public function test_from_array_rejects_a_non_string_object_path_segment(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray([
            'tools' => [[
                'name' => 'broken',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'run',
                'inputSchema' => ['properties' => []],
                'inputSchemaObjectPaths' => [[42]],
            ]],
            'resources' => [],
        ]);
    }

    public function test_from_array_rejects_an_object_path_entry_that_is_not_a_list(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        McpRegistry::fromArray([
            'tools' => [[
                'name' => 'broken',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'run',
                'inputSchema' => ['properties' => []],
                'inputSchemaObjectPaths' => 'not-a-list',
            ]],
            'resources' => [],
        ]);
    }

    // KINETIS-72: two genuinely different classes/methods must never be
    // allowed to both claim the same tool name or resource URI — the
    // public surface an agent sees must not depend on which one was
    // discovered first.

    public function test_a_different_class_claiming_an_already_registered_tool_name_throws(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $this->expectException(DuplicateDefinitionException::class);
        $this->expectExceptionMessage(
            'MCP tool name "get_user_status" is already registered by '
                . '"Kinetis\Mcp\Tests\Fixtures\AccountController::getUserStatus()"; '
                . '"Kinetis\Mcp\Tests\Fixtures\DuplicateToolNameController::conflictingStatus()" '
                . 'cannot reuse the same name.',
        );

        $registry->register(DuplicateToolNameController::class);
    }

    public function test_a_different_class_claiming_an_already_registered_resource_uri_throws(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $this->expectException(DuplicateDefinitionException::class);
        $this->expectExceptionMessage(
            'MCP resource URI "kinetis://status" is already registered by '
                . '"Kinetis\Mcp\Tests\Fixtures\AccountController::status()"; '
                . '"Kinetis\Mcp\Tests\Fixtures\DuplicateResourceUriController::conflictingStatus()" '
                . 'cannot reuse the same URI.',
        );

        $registry->register(DuplicateResourceUriController::class);
    }

    /**
     * A cross-class conflict must not leave the previously-registered
     * definition disturbed either — the whole point is that the FIRST
     * one keeps winning cleanly, not that both sides end up damaged.
     */
    public function test_a_rejected_cross_class_conflict_leaves_the_original_definition_intact(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        try {
            $registry->register(DuplicateToolNameController::class);
            self::fail('Expected a DuplicateDefinitionException.');
        } catch (DuplicateDefinitionException) {
            // expected
        }

        $tool = $registry->findTool('get_user_status');
        self::assertNotNull($tool);
        self::assertSame(AccountController::class, $tool->controllerClass);
    }

    public function test_two_methods_on_the_same_class_claiming_the_same_tool_name_throws(): void
    {
        $registry = new McpRegistry();

        $this->expectException(DuplicateDefinitionException::class);
        $this->expectExceptionMessage(
            'MCP tool name "intra_class_ping" is already registered by '
                . '"Kinetis\Mcp\Tests\Fixtures\IntraClassDuplicateToolController::first()"; '
                . '"Kinetis\Mcp\Tests\Fixtures\IntraClassDuplicateToolController::second()" '
                . 'cannot reuse the same name.',
        );

        $registry->register(IntraClassDuplicateToolController::class);
    }

    public function test_two_methods_on_the_same_class_claiming_the_same_resource_uri_throws(): void
    {
        $registry = new McpRegistry();

        $this->expectException(DuplicateDefinitionException::class);
        $this->expectExceptionMessage(
            'MCP resource URI "kinetis://intra-class" is already registered by '
                . '"Kinetis\Mcp\Tests\Fixtures\IntraClassDuplicateResourceController::first()"; '
                . '"Kinetis\Mcp\Tests\Fixtures\IntraClassDuplicateResourceController::second()" '
                . 'cannot reuse the same URI.',
        );

        $registry->register(IntraClassDuplicateResourceController::class);
    }

    /**
     * An intra-class conflict must register nothing at all from that
     * class — not even a method reflected before the conflicting one
     * was found.
     */
    public function test_an_intra_class_conflict_registers_neither_method(): void
    {
        $registry = new McpRegistry();

        try {
            $registry->register(IntraClassDuplicateToolController::class);
            self::fail('Expected a DuplicateDefinitionException.');
        } catch (DuplicateDefinitionException) {
            // expected
        }

        self::assertSame([], $registry->tools());
    }

    /**
     * A class with one genuinely new definition and one that conflicts
     * with an already-registered class must add neither — atomicity
     * per class, not per method.
     */
    public function test_a_class_with_a_mix_of_new_and_conflicting_tools_registers_neither(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        try {
            $registry->register(MixedNewAndConflictingToolController::class);
            self::fail('Expected a DuplicateDefinitionException.');
        } catch (DuplicateDefinitionException) {
            // expected
        }

        self::assertNull($registry->findTool('genuinely_new_tool'));

        $names = array_map(static fn ($tool) => $tool->name, $registry->tools());
        self::assertSame(['get_user_status', 'create_user'], $names, 'AccountController\'s own tools must be unaffected.');
    }

    /**
     * A retried register() call on the same still-conflicting class must
     * throw again, every time — never silently become a no-op just
     * because it was already attempted once.
     */
    public function test_retrying_register_on_a_still_conflicting_class_throws_again(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $caught = 0;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $registry->register(DuplicateToolNameController::class);
                self::fail("Expected a DuplicateDefinitionException on attempt {$attempt}.");
            } catch (DuplicateDefinitionException) {
                $caught++;
            }
        }

        self::assertSame(2, $caught, 'the second attempt must throw again, not silently become a no-op.');
    }

    /**
     * KINETIS-72: registering the exact same class a second time is a
     * harmless no-op — direct repeated register() calls must not
     * duplicate that class's own definitions, and must not be treated
     * as a name/URI conflict against itself.
     */
    public function test_registering_the_same_class_twice_is_a_harmless_no_op(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);
        $registry->register(AccountController::class);

        $names = array_map(static fn ($tool) => $tool->name, $registry->tools());
        self::assertSame(['get_user_status', 'create_user'], $names);

        $uris = array_map(static fn ($resource) => $resource->uri, $registry->resources());
        self::assertSame(['kinetis://status'], $uris);
    }

    public function test_from_array_rejects_a_compiled_artifact_with_a_duplicate_tool_name(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('duplicate tool name "ping"');

        McpRegistry::fromArray([
            'tools' => [
                ['name' => 'ping', 'description' => 'first', 'controllerClass' => 'App\\A', 'controllerMethod' => 'ping', 'inputSchema' => [], 'inputSchemaObjectPaths' => []],
                ['name' => 'ping', 'description' => 'second', 'controllerClass' => 'App\\B', 'controllerMethod' => 'ping', 'inputSchema' => [], 'inputSchemaObjectPaths' => []],
            ],
            'resources' => [],
        ]);
    }

    public function test_from_array_rejects_a_compiled_artifact_with_a_duplicate_resource_uri(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);
        $this->expectExceptionMessage('duplicate resource uri "kinetis://x"');

        McpRegistry::fromArray([
            'tools' => [],
            'resources' => [
                ['uri' => 'kinetis://x', 'name' => 'first', 'description' => '', 'mimeType' => 'text/plain', 'controllerClass' => 'App\\A', 'controllerMethod' => 'x'],
                ['uri' => 'kinetis://x', 'name' => 'second', 'description' => '', 'mimeType' => 'text/plain', 'controllerClass' => 'App\\B', 'controllerMethod' => 'x'],
            ],
        ]);
    }

    /**
     * A class already present in a loaded compiled artifact must be a
     * harmless no-op on a subsequent live register() call — the exact
     * same idempotency guarantee a live-only registration already gets,
     * now also honored across the cache-load boundary.
     */
    public function test_registering_a_class_already_present_in_a_loaded_artifact_is_a_no_op(): void
    {
        $live = new McpRegistry();
        $live->register(AccountController::class);

        $reloaded = McpRegistry::fromArray($live->toArray());
        $reloaded->register(AccountController::class);

        $names = array_map(static fn ($tool) => $tool->name, $reloaded->tools());
        self::assertSame(['get_user_status', 'create_user'], $names);
    }

    // KINETIS-75: tools/list's own generated inputSchema (built through
    // JsonSchema::forParameters(), the same path McpDispatcherTest's real
    // calls are checked against) must show the identical nullable
    // representation and required list.

    public function test_tools_list_schema_widens_a_nullable_field_and_keeps_it_required_without_a_default(): void
    {
        $registry = new McpRegistry();
        $registry->register(NullableFieldsToolController::class);
        $tool = $registry->findTool('nullable_fields');

        self::assertNotNull($tool);
        $dataSchema = $tool->inputSchema['properties']['data'];

        self::assertSame(['string', 'null'], $dataSchema['properties']['requiredNullable']['type']);
        self::assertSame(['requiredNullable'], $dataSchema['required']);
    }

    public function test_tools_list_schema_widens_a_defaulted_nullable_scalar_and_leaves_it_optional(): void
    {
        $registry = new McpRegistry();
        $registry->register(NullableFieldsToolController::class);
        $tool = $registry->findTool('nullable_fields');

        self::assertNotNull($tool);
        $dataSchema = $tool->inputSchema['properties']['data'];

        self::assertSame(['string', 'null'], $dataSchema['properties']['optionalNullable']['type']);
        self::assertNotContains('optionalNullable', $dataSchema['required']);
    }

    /**
     * MCP tool schemas have no components/$ref mechanism to dedupe
     * into (McpRegistry::register() never passes a classSchema callback
     * to JsonSchema::forParameters()) — a nullable nested DTO stays
     * fully inlined, with its own `type` simply widened to include
     * null, unlike the OpenAPI/HTTP case, which dedupes into an
     * anyOf-wrapped $ref.
     */
    public function test_tools_list_schema_widens_a_nullable_nested_dto_when_inlined(): void
    {
        $registry = new McpRegistry();
        $registry->register(NullableFieldsToolController::class);
        $tool = $registry->findTool('nullable_fields');

        self::assertNotNull($tool);
        $itemSchema = $tool->inputSchema['properties']['data']['properties']['optionalItem'];

        self::assertSame(['object', 'null'], $itemSchema['type']);
        self::assertArrayHasKey('quantity', $itemSchema['properties']);
        self::assertNotContains('optionalItem', $tool->inputSchema['properties']['data']['required']);
    }

    public function test_tools_list_schema_widens_a_nullable_list_of_field(): void
    {
        $registry = new McpRegistry();
        $registry->register(NullableFieldsToolController::class);
        $tool = $registry->findTool('nullable_fields');

        self::assertNotNull($tool);
        $itemsSchema = $tool->inputSchema['properties']['data']['properties']['optionalItems'];

        self::assertSame(['array', 'null'], $itemsSchema['type']);
        self::assertSame('object', $itemsSchema['items']['type']);
        self::assertNotContains('optionalItems', $tool->inputSchema['properties']['data']['required']);
    }

    // KINETIS-76 follow-up: the complete, audited builtin-type policy —
    // see JsonSchema::forType()'s own docblock — proven end-to-end
    // through a real tool's own generated inputSchema, not just via
    // JsonSchema::forType() unit calls.

    public function test_tools_list_schema_covers_every_supported_builtin_category(): void
    {
        $registry = new McpRegistry();
        $registry->register(BuiltinCoverageToolController::class);
        $tool = $registry->findTool('builtin_coverage');

        self::assertNotNull($tool);
        $properties = $tool->inputSchema['properties'];

        self::assertSame(['type' => 'array'], $properties['tags']);
        self::assertSame(['type' => 'array'], $properties['items'], 'iterable gets the identical array schema as plain array');
        self::assertEquals((object) [], $properties['note'], 'mixed is the empty schema object, not the empty schema array');
        self::assertSame(['type' => 'null'], $properties['marker']);
        self::assertSame(['type' => 'boolean', 'const' => true], $properties['confirmed']);
        self::assertSame(['type' => 'boolean', 'const' => false], $properties['declined']);

        // tags/items have no default — required; every other field does.
        self::assertSame(['tags', 'items'], $tool->inputSchema['required']);
    }

    /**
     * McpRegistry::register() is a real guaranteed-to-run-before-traffic
     * boundary for MCP specifically because a tool can never be called
     * until it exists in the registry, and register() never partially
     * commits a class whose schema generation failed — so an `object`-
     * typed tool argument is rejected the moment the class is registered
     * (at discovery/boot time), never silently reachable by a real
     * tools/call request.
     */
    public function test_register_rejects_a_tool_with_an_unsupported_builtin_parameter_type(): void
    {
        $registry = new McpRegistry();

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('object');

        $registry->register(UnsupportedParameterToolController::class);
    }

    public function test_a_class_that_failed_registration_never_becomes_callable(): void
    {
        $registry = new McpRegistry();

        try {
            $registry->register(UnsupportedParameterToolController::class);
            self::fail('Expected a JsonSchemaException.');
        } catch (JsonSchemaException) {
            // expected
        }

        self::assertNull($registry->findTool('unsupported_parameter'));
    }

    /**
     * `callable`'s own equivalent of the two `object` tests above — the
     * second rejected builtin category gets the identical registration-
     * time guarantee, not just direct Hydrator/JsonSchema unit coverage.
     */
    public function test_register_rejects_a_tool_with_an_unsupported_callable_parameter_type(): void
    {
        $registry = new McpRegistry();

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('callable');

        $registry->register(UnsupportedCallableParameterToolController::class);
    }

    public function test_a_callable_parameter_class_that_failed_registration_never_becomes_callable(): void
    {
        $registry = new McpRegistry();

        try {
            $registry->register(UnsupportedCallableParameterToolController::class);
            self::fail('Expected a JsonSchemaException.');
        } catch (JsonSchemaException) {
            // expected
        }

        self::assertNull($registry->findTool('unsupported_callable_parameter'));
    }

    /**
     * A tool with two unsupported parameters at once (object, then
     * callable in declaration order) is rejected deterministically on
     * the first one JsonSchema::forParameters() reaches, not registered
     * with only the reachable half validated — register() never commits
     * a partially-checked tool either way.
     */
    public function test_register_rejects_a_tool_with_multiple_unsupported_parameters_reporting_the_first(): void
    {
        $registry = new McpRegistry();

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('object');

        $registry->register(MultipleUnsupportedParametersToolController::class);
    }
}
