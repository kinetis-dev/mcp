<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Mcp\Exception\DuplicateDefinitionException;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\BuiltinCoverageToolController;
use Kinetis\Mcp\Tests\Fixtures\DuplicateResourceUriController;
use Kinetis\Mcp\Tests\Fixtures\DuplicateToolNameController;
use Kinetis\Mcp\Tests\Fixtures\EmptyCollectionsToolController;
use Kinetis\Mcp\Tests\Fixtures\JsonHostileSchemaValuesToolController;
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
    private const string NOT_AN_OBJECT_ROOT_MESSAGE = 'A compiled "McpRegistry tool" artifact has a malformed entry '
        . '(an "inputSchemaJson" document whose root is not a JSON object) — the cache is stale or corrupt.';

    private const string NUMERIC_MEMBER_NAME_MESSAGE = 'A compiled "McpRegistry tool" artifact has a malformed entry '
        . '(an "inputSchemaJson" object with a numeric member name) — the cache is stale or corrupt.';

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

    /**
     * The real publish/reload path — var_export() to disk through
     * CacheStore, then require() back — returning the McpRegistry
     * section exactly as it comes off disk. Every cache round-trip test
     * here goes through this rather than calling toArray()/fromArray()
     * back to back, since writeAll() is what would reject a live object
     * with CacheWriteException in the first place.
     *
     * @return array<string, mixed>
     */
    private static function publishAndReloadArtifact(McpRegistry $live): array
    {
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

            /** @var array<string, mixed> $data */
            $data = $pluginCache->data[McpRegistry::class];

            return $data;
        } finally {
            CacheStore::destroy($directory);
        }
    }

    public function test_a_zero_parameter_tools_empty_properties_object_survives_a_real_var_export_cache_round_trip(): void
    {
        // JsonSchema::forParameters() spells an empty `properties` as
        // `(object) []` so it JSON-encodes as `{}` rather than `[]`,
        // while the `required` list beside it is an empty PHP array
        // that has to stay `[]`. A compiled artifact carries plain data
        // only, so the schema goes to disk as JSON text and both keep
        // their own type. A zero-parameter tool is the case that puts
        // the pair in one schema.
        $live = new McpRegistry();
        $live->register(ZeroParameterToolController::class);

        $tool = $live->findTool('zero_param_ping');
        self::assertNotNull($tool);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']);

        $artifact = self::publishAndReloadArtifact($live);

        self::assertSame(
            '{"type":"object","properties":{},"required":[]}',
            $artifact['tools'][0]['inputSchemaJson'],
        );

        $reloadedTool = McpRegistry::fromArray($artifact)->findTool('zero_param_ping');

        self::assertNotNull($reloadedTool);
        self::assertInstanceOf(\stdClass::class, $reloadedTool->inputSchema['properties']);
        self::assertSame([], $reloadedTool->inputSchema['required']);
        self::assertSame(
            '{"type":"object","properties":{},"required":[]}',
            json_encode($reloadedTool->inputSchema, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The same real var_export()/require() cache round trip as the
     * zero-parameter case above, but for a `stdClass` at an arbitrary
     * nesting depth — a `mixed`-typed tool argument's own whole schema,
     * not the top-level "properties" key — so the restore walks the
     * tree rather than one hardcoded key.
     */
    public function test_a_mixed_typed_arguments_empty_schema_object_survives_a_real_var_export_cache_round_trip(): void
    {
        $live = new McpRegistry();
        $live->register(BuiltinCoverageToolController::class);

        $tool = $live->findTool('builtin_coverage');
        self::assertNotNull($tool);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']['note']);

        $reloadedTool = McpRegistry::fromArray(self::publishAndReloadArtifact($live))->findTool('builtin_coverage');

        self::assertNotNull($reloadedTool);
        $reloadedNote = $reloadedTool->inputSchema['properties']['note'];
        self::assertInstanceOf(\stdClass::class, $reloadedNote);
        self::assertSame('{}', json_encode($reloadedNote, JSON_THROW_ON_ERROR));

        // A populated sibling map came back a map — every node is
        // restored from its own JSON type, not from a rule about where
        // it sits.
        self::assertSame(['type' => 'array'], $reloadedTool->inputSchema['properties']['tags']);
    }

    /**
     * The adversarial pairing, through the real cache round trip: an
     * empty `#[In([])]` enum and an empty top-level `required` list,
     * which are JSON arrays, beside a `mixed`-typed argument's empty
     * schema object and a nested DTO carrying a second empty object and
     * a second empty list two levels further down. The stored JSON text
     * is the one notation that tells the two apart, so each value comes
     * back the type it went in as, whatever depth it sits at.
     */
    public function test_empty_arrays_and_empty_objects_keep_their_json_types_through_a_real_cache_round_trip(): void
    {
        $live = new McpRegistry();
        $live->register(EmptyCollectionsToolController::class);

        $tool = $live->findTool('empty_collections');
        self::assertNotNull($tool);

        $document = '{"type":"object","properties":{"choice":{"enum":[]},"note":{},'
            . '"nested":{"type":["object","null"],"properties":{},"required":[]}},"required":[]}';
        self::assertSame($document, json_encode($tool->inputSchema, JSON_THROW_ON_ERROR));

        $artifact = self::publishAndReloadArtifact($live);
        self::assertSame($document, $artifact['tools'][0]['inputSchemaJson']);

        $reloadedTool = McpRegistry::fromArray($artifact)->findTool('empty_collections');

        self::assertNotNull($reloadedTool);
        self::assertEquals($tool->inputSchema, $reloadedTool->inputSchema);
        self::assertSame($document, json_encode($reloadedTool->inputSchema, JSON_THROW_ON_ERROR));

        // Asserted value by value as well, so a failure names which of
        // the four lost its JSON type rather than only that the
        // document differs somewhere.
        self::assertSame([], $reloadedTool->inputSchema['properties']['choice']['enum']);
        self::assertSame([], $reloadedTool->inputSchema['required']);
        self::assertInstanceOf(\stdClass::class, $reloadedTool->inputSchema['properties']['note']);
        self::assertInstanceOf(\stdClass::class, $reloadedTool->inputSchema['properties']['nested']['properties']);
        self::assertSame([], $reloadedTool->inputSchema['properties']['nested']['required']);
    }

    /**
     * Schema values the stored JSON has to escape — a `#[Regex]`
     * pattern of quotes and backslashes, `#[In]` choices carrying a
     * quote, a backslash, a line break and non-ASCII characters — come
     * back through the real cache round trip byte for byte, and a
     * float-valued `#[GreaterThan]` bound comes back a float rather
     * than an int.
     */
    public function test_schema_values_that_json_must_escape_survive_a_real_cache_round_trip(): void
    {
        $live = new McpRegistry();
        $live->register(JsonHostileSchemaValuesToolController::class);

        $tool = $live->findTool('json_hostile_schema_values');
        self::assertNotNull($tool);

        $reloadedTool = McpRegistry::fromArray(self::publishAndReloadArtifact($live))
            ->findTool('json_hostile_schema_values');

        self::assertNotNull($reloadedTool);
        self::assertSame('/^"\d+\\\\"$/', $reloadedTool->inputSchema['properties']['pattern']['pattern']);
        self::assertSame(
            ['quote"', 'back\\slash', "line\nbreak", 'héllo ☃'],
            $reloadedTool->inputSchema['properties']['choice']['enum'],
        );
        self::assertSame(1.0, $reloadedTool->inputSchema['properties']['amount']['exclusiveMinimum']);
        self::assertEquals($tool->inputSchema, $reloadedTool->inputSchema);
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

    /**
     * Every required field present, plus one unexpected extra key — the
     * exactKeys() check exists specifically to catch this, since
     * reading each field individually afterward would otherwise let a
     * stray/corrupt key through silently.
     */
    public function test_from_array_rejects_a_tool_with_an_unexpected_extra_field(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage(
            'A compiled "McpRegistry tool" artifact has a malformed entry (an unexpected or missing field) — the cache is stale or corrupt.',
        );

        McpRegistry::fromArray([
            'tools' => [[
                'name' => 'ping',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'ping',
                'inputSchemaJson' => '{"type":"object"}',
                'bogus' => 'unexpected',
            ]],
            'resources' => [],
        ]);
    }

    public function test_from_array_rejects_a_resource_with_an_unexpected_extra_field(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage(
            'A compiled "McpRegistry resource" artifact has a malformed entry (an unexpected or missing field) — the cache is stale or corrupt.',
        );

        McpRegistry::fromArray([
            'tools' => [],
            'resources' => [[
                'uri' => 'kinetis://docs/x',
                'name' => 'x',
                'description' => '',
                'mimeType' => 'text/markdown',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'read',
                'bogus' => 'unexpected',
            ]],
        ]);
    }

    // KINETIS-77: `inputSchemaJson` is a tool's whole schema as JSON
    // text, and fromArray() has to refuse anything it cannot read back
    // as the schema encodeSchema() wrote — a hand-edited, truncated or
    // otherwise corrupt compiled cache file, never something toArray()
    // could produce. McpRegistry::decodeSchema()'s own docblock states
    // the contract; these tests drive real artifacts through
    // fromArray() to hold it.

    /**
     * @return array<string, mixed>
     */
    private static function artifactWithSchemaJson(mixed $inputSchemaJson): array
    {
        return [
            'tools' => [[
                'name' => 'corruptible',
                'description' => '',
                'controllerClass' => 'App\\C',
                'controllerMethod' => 'run',
                'inputSchemaJson' => $inputSchemaJson,
            ]],
            'resources' => [],
        ];
    }

    /**
     * A document cut short — the realistic way a compiled file goes
     * bad, from a partial write or a truncated copy.
     */
    public function test_from_array_rejects_an_input_schema_that_is_not_valid_json(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage(
            'A compiled "McpRegistry tool" artifact has a malformed entry (an "inputSchemaJson" value that is not '
                . 'valid JSON) — the cache is stale or corrupt.',
        );

        McpRegistry::fromArray(self::artifactWithSchemaJson('{"type":"object","properties":{"userId":{"type":"int'));
    }

    /**
     * Every JSON document whose root is not an object: an empty array,
     * a populated array, a string, null, a number, a boolean. A tool
     * input schema is a JSON object, so each of these describes
     * something this class never stored — and the empty-array root
     * matters most, since that is the value an artifact would carry if
     * the schema's own type had been lost on the way out.
     */
    public function test_from_array_rejects_an_input_schema_whose_root_is_not_a_json_object(): void
    {
        foreach (['[]', '["type","object"]', '"object"', 'null', '42', 'true'] as $document) {
            try {
                McpRegistry::fromArray(self::artifactWithSchemaJson($document));
                self::fail("Expected an InvalidCacheArtifactException for the root {$document}.");
            } catch (InvalidCacheArtifactException $exception) {
                self::assertSame(self::NOT_AN_OBJECT_ROOT_MESSAGE, $exception->getMessage());
            }
        }
    }

    /**
     * The one object shape a PHP array cannot hold apart from a list:
     * members named by the consecutive indices a JSON array would have,
     * which json_decode() returns as integer array keys and which would
     * re-encode as `["userId"]`. encodeSchema() cannot write it — a PHP
     * list is what becomes a JSON array to begin with — so it is
     * refused rather than silently re-typed on the next compile.
     */
    public function test_from_array_rejects_a_schema_object_named_by_array_indices(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage(self::NUMERIC_MEMBER_NAME_MESSAGE);

        McpRegistry::fromArray(self::artifactWithSchemaJson('{"type":"object","required":{"0":"userId"}}'));
    }

    /**
     * The same check reaches any depth, and any member name PHP reads
     * as a number — not only a run of them starting at zero.
     */
    public function test_from_array_rejects_a_nested_schema_object_with_a_numeric_member_name(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage(self::NUMERIC_MEMBER_NAME_MESSAGE);

        McpRegistry::fromArray(self::artifactWithSchemaJson('{"properties":{"choice":{"enum":{"7":"x"}}}}'));
    }

    public function test_from_array_rejects_an_input_schema_that_is_not_a_string(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage(
            'A compiled "McpRegistry tool" artifact\'s "inputSchemaJson" field is not a string — the cache is stale '
                . 'or corrupt.',
        );

        McpRegistry::fromArray(self::artifactWithSchemaJson(['type' => 'object']));
    }

    /**
     * The accepting side of the same contract, on one hand-built
     * document: an empty object and three empty arrays at the same
     * depth, plus an empty object inside a JSON array, each restored to
     * its own type. A second toArray() pass re-derives the text from
     * the restored value alone, so a compiled artifact is a fixed point
     * rather than something that drifts every time it is rebuilt.
     */
    public function test_empty_objects_and_empty_arrays_in_one_document_each_keep_their_type(): void
    {
        $document = '{"type":"object","properties":{},"required":[],"choices":[],"anyOf":[{},{"type":"string"}]}';

        $registry = McpRegistry::fromArray(self::artifactWithSchemaJson($document));
        $tool = $registry->findTool('corruptible');

        self::assertNotNull($tool);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']);
        self::assertSame([], $tool->inputSchema['required']);
        self::assertSame([], $tool->inputSchema['choices']);
        self::assertInstanceOf(\stdClass::class, $tool->inputSchema['anyOf'][0]);
        self::assertSame(['type' => 'string'], $tool->inputSchema['anyOf'][1]);
        self::assertSame($document, json_encode($tool->inputSchema, JSON_THROW_ON_ERROR));

        self::assertSame($document, $registry->toArray()['tools'][0]['inputSchemaJson']);
    }

    /**
     * The root is the one object whose JSON type comes from
     * ToolDefinition::$inputSchema's array declaration rather than from
     * the document, so a schema with no keys is written `{}`, read back
     * as `[]`, and written `{}` again.
     */
    public function test_a_schema_with_no_keys_round_trips_as_an_empty_json_object(): void
    {
        $registry = McpRegistry::fromArray(self::artifactWithSchemaJson('{}'));
        $tool = $registry->findTool('corruptible');

        self::assertNotNull($tool);
        self::assertSame([], $tool->inputSchema);
        self::assertSame('{}', $registry->toArray()['tools'][0]['inputSchemaJson']);
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
                ['name' => 'ping', 'description' => 'first', 'controllerClass' => 'App\\A', 'controllerMethod' => 'ping', 'inputSchemaJson' => '{}'],
                ['name' => 'ping', 'description' => 'second', 'controllerClass' => 'App\\B', 'controllerMethod' => 'ping', 'inputSchemaJson' => '{}'],
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
