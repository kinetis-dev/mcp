<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\Exception\DuplicateDefinitionException;
use Kinetis\Validation\JsonSchema;
use Kinetis\Reflection\AttributeScope;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reflects a class for #[McpTool]/#[McpResource] methods the same way
 * Router reflects a controller for #[Get]/#[Post] — registration builds
 * the definitions once; McpServer reads them per request.
 *
 * A tool name and a resource URI are each globally unique across every
 * registered class — the public MCP surface an agent sees must never
 * depend on which of two conflicting classes happened to be discovered
 * first (a real correctness and security problem: the schema `tools/list`
 * advertises for a name has to be the schema `tools/call` actually
 * invokes for it). register() is idempotent per class, tracked via
 * $registeredClasses the same way EventListenerRegistry::register()
 * already is: a class already registered is a safe no-op on a second
 * call — direct repeated registration and McpDiscovery's own two-pass
 * scan (project root plus the framework segment, which can both surface
 * the same class) both rely on this rather than needing their own
 * deduplication bookkeeping. Registration is also atomic — every
 * #[McpTool]/#[McpResource] a class declares is checked, against both
 * $tools/$resources and each other, before any of them are appended or
 * the class is marked registered, so a class with one conflicting
 * definition registers none of them, not just the ones checked before
 * the conflict was found — and, since the class is never marked
 * registered on a failed attempt, retrying register() on the same
 * still-conflicting class throws again, every time, rather than
 * silently becoming a no-op or leaving a partial registration behind.
 *
 * Implements `CacheableDiscoveryInterface` — declared as this package's
 * `extra.kinetis` `discovery` class, so the framework itself compiles,
 * caches, and binds an instance of this class before `PackageBootstrap`
 * ever runs. `compile()` is the live-discovery path `McpDiscovery::discover()`
 * already provides, reduced to plain data via `toArray()`.
 */
final class McpRegistry implements CacheableDiscoveryInterface
{
    private const string TOOL_ARTIFACT_COMPONENT = 'McpRegistry tool';

    private const string RESOURCE_ARTIFACT_COMPONENT = 'McpRegistry resource';

    /** @var list<ToolDefinition> */
    private array $tools = [];

    /** @var list<ResourceDefinition> */
    private array $resources = [];

    /** @var array<class-string, true> */
    private array $registeredClasses = [];

    /**
     * @param class-string $class
     * @throws DuplicateDefinitionException
     */
    public function register(string $class): void
    {
        if (isset($this->registeredClasses[$class])) {
            return;
        }

        $reflection = AttributeScope::reflect($class);

        /** @var list<ToolDefinition> $pendingTools */
        $pendingTools = [];
        /** @var list<ResourceDefinition> $pendingResources */
        $pendingResources = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                AttributeScope::assertDeclares($method, $class);

                $tool = $attribute->newInstance();

                $pendingTools[] = new ToolDefinition(
                    name: $tool->name,
                    description: $tool->description,
                    controllerClass: $class,
                    controllerMethod: $method->getName(),
                    inputSchema: JsonSchema::forParameters($method->getParameters(), [ProgressReporter::class]),
                );
            }

            foreach ($method->getAttributes(McpResource::class) as $attribute) {
                AttributeScope::assertDeclares($method, $class);

                $resource = $attribute->newInstance();

                $pendingResources[] = new ResourceDefinition(
                    uri: $resource->uri,
                    name: $resource->name,
                    description: $resource->description,
                    mimeType: $resource->mimeType,
                    controllerClass: $class,
                    controllerMethod: $method->getName(),
                );
            }
        }

        // $class is not yet in $registeredClasses, so every name/URI
        // found here is checked against genuinely different, already-
        // registered classes (via $this->tools/$this->resources) and
        // against each other (an intra-class conflict, two of this
        // class's own methods claiming the same name/URI) in one pass —
        // nothing below can be a harmless re-registration of an already-
        // accepted definition, since that case already returned above.
        self::assertNoToolNameConflicts($pendingTools, $this->tools);
        self::assertNoResourceUriConflicts($pendingResources, $this->resources);

        array_push($this->tools, ...$pendingTools);
        array_push($this->resources, ...$pendingResources);
        $this->registeredClasses[$class] = true;
    }

    /**
     * @param list<ToolDefinition> $pending
     * @param list<ToolDefinition> $existing
     * @throws DuplicateDefinitionException
     */
    private static function assertNoToolNameConflicts(array $pending, array $existing): void
    {
        /** @var array<string, ToolDefinition> $seen */
        $seen = [];

        foreach ($existing as $tool) {
            $seen[$tool->name] = $tool;
        }

        foreach ($pending as $tool) {
            $conflict = $seen[$tool->name] ?? null;

            if ($conflict !== null) {
                throw DuplicateDefinitionException::duplicateToolName(
                    $tool->name,
                    $conflict->controllerClass,
                    $conflict->controllerMethod,
                    $tool->controllerClass,
                    $tool->controllerMethod,
                );
            }

            $seen[$tool->name] = $tool;
        }
    }

    /**
     * @param list<ResourceDefinition> $pending
     * @param list<ResourceDefinition> $existing
     * @throws DuplicateDefinitionException
     */
    private static function assertNoResourceUriConflicts(array $pending, array $existing): void
    {
        /** @var array<string, ResourceDefinition> $seen */
        $seen = [];

        foreach ($existing as $resource) {
            $seen[$resource->uri] = $resource;
        }

        foreach ($pending as $resource) {
            $conflict = $seen[$resource->uri] ?? null;

            if ($conflict !== null) {
                throw DuplicateDefinitionException::duplicateResourceUri(
                    $resource->uri,
                    $conflict->controllerClass,
                    $conflict->controllerMethod,
                    $resource->controllerClass,
                    $resource->controllerMethod,
                );
            }

            $seen[$resource->uri] = $resource;
        }
    }

    /**
     * @return list<ToolDefinition>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * The `CacheableDiscoveryInterface` half of the compile path —
     * `fromArray()` below already satisfies the other half.
     */
    #[\Override]
    public static function compile(string $projectRoot): array
    {
        return McpDiscovery::discover($projectRoot)->toArray();
    }

    /**
     * Dumps every registered tool/resource's fields verbatim — all already
     * plain scalars/arrays, so nothing here needs reflection to reverse.
     * Used by Kinetis\Cache\Compiler, and — since McpRegistry is this
     * package's own CacheableDiscoveryInterface class — by
     * Kinetis\Cache\PluginDiscovery/CacheStore too, which write the
     * result through var_export().
     *
     * `inputSchema` is the one field that cannot be dumped as it
     * stands. JSON Schema distinguishes the empty object `{}` from the
     * empty array `[]`, and a PHP array expresses only one of the two:
     * JsonSchema spells `{}` as a live `stdClass` — forParameters()'s
     * `properties` for a class or tool with no parameters, and
     * schemaForScalar()'s whole schema for a `mixed`-typed argument —
     * while `required` and an `#[In([])]` enum are ordinary empty PHP
     * arrays that have to stay arrays. A compiled artifact carries
     * plain data only: CacheStore::assertExportable() refuses an object
     * anywhere in a section before it is written, so a live stdClass
     * never reaches disk. The artifact therefore carries the schema as
     * `inputSchemaJson`, its own JSON text — a string is plain data,
     * and JSON is the notation that tells the two empties apart, so the
     * artifact holds one field with nothing alongside it to disagree
     * with. fromArray()'s decodeSchema() reads it back.
     *
     * @return array{tools: list<array<string, mixed>>, resources: list<array<string, mixed>>}
     * @throws \JsonException
     */
    public function toArray(): array
    {
        return [
            'tools' => array_map(self::toolToArray(...), $this->tools),
            'resources' => array_map(
                static fn (ResourceDefinition $resource): array => [
                    'uri' => $resource->uri,
                    'name' => $resource->name,
                    'description' => $resource->description,
                    'mimeType' => $resource->mimeType,
                    'controllerClass' => $resource->controllerClass,
                    'controllerMethod' => $resource->controllerMethod,
                ],
                $this->resources,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private static function toolToArray(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'controllerClass' => $tool->controllerClass,
            'controllerMethod' => $tool->controllerMethod,
            'inputSchemaJson' => self::encodeSchema($tool->inputSchema),
        ];
    }

    /**
     * The schema as JSON text, and decodeSchema()'s inverse.
     *
     * The root is cast to an object so a schema carrying no keys still
     * encodes as `{}`: ToolDefinition::$inputSchema is declared an
     * array, so the root's JSON type comes from that declaration rather
     * than from the data, and decodeSchema() applies the same rule in
     * reverse. Every node below the root keeps the type it has.
     *
     * JSON_PRESERVE_ZERO_FRACTION keeps a float-valued constraint bound
     * (`#[GreaterThan(1.0)]`) a float on the way back in, where the
     * default encoding would write `1` and decode to an int. This text
     * is the cache's own representation of the schema, not the bytes a
     * transport puts on the wire: a transport encodes the whole
     * `tools/list` response itself, without that flag, so the same
     * bound goes out as `1`.
     *
     * A schema that cannot be encoded — a description holding bytes
     * that are not valid UTF-8 — fails the build with json_encode()'s
     * own JsonException rather than reaching disk degraded.
     *
     * @param array<string, mixed> $schema
     * @throws \JsonException
     */
    private static function encodeSchema(array $schema): string
    {
        return json_encode((object) $schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Reconstructs a McpRegistry from toArray()'s output with zero
     * reflection — the compiled-cache load path's counterpart to
     * register(). Validates every tool/resource entry's own required
     * fields via `Kinetis\Cache\Exception\ArtifactValidation` — the same
     * discipline `Kinetis\Cache\HttpCache`/`CommandCache::fromArray()`
     * apply to their own compiled entries — throwing
     * `Kinetis\Cache\Exception\InvalidCacheArtifactException` for
     * anything missing or wrong-typed, satisfying this class's own
     * `CacheableDiscoveryInterface::fromArray()` contract.
     * `inputSchemaJson` is validated as a string that decodes into a
     * JSON document this class can hold — see decodeSchema() — and no
     * further: the schema's own deeply recursive JSON-Schema vocabulary
     * is never re-validated here, the same "don't re-derive an owning
     * abstraction's own shape rules" scope `HttpCache::fromArray()`
     * applies to `httpBindingPlans`/`hydrationPlans`. Also re-checks
     * the same tool-name/resource-URI uniqueness invariant register()
     * enforces live — `toArray()` itself never produces a colliding pair, but a
     * hand-edited or otherwise corrupt compiled artifact could, and this
     * must reject that rather than silently preserve whichever entry
     * happened to be listed first
     * — the exact ambiguity register()'s own atomicity exists to prevent.
     * Every controllerClass encountered also marks that class registered
     * ($registeredClasses), so a later live register() call for a class
     * already present in the loaded artifact is the same safe no-op it
     * would be after a live registration.
     *
     * @param array<string, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        ArtifactValidation::exactKeys($data, 'McpRegistry', ['tools', 'resources']);

        $tools = ArtifactValidation::listOfArrays($data, 'McpRegistry', 'tools');
        $resources = ArtifactValidation::listOfArrays($data, 'McpRegistry', 'resources');

        $registry = new self();

        /** @var array<string, true> $seenToolNames */
        $seenToolNames = [];

        foreach ($tools as $tool) {
            ArtifactValidation::exactKeys($tool, self::TOOL_ARTIFACT_COMPONENT, ['name', 'description', 'controllerClass', 'controllerMethod', 'inputSchemaJson']);

            $name = ArtifactValidation::string($tool, self::TOOL_ARTIFACT_COMPONENT, 'name');
            $inputSchemaJson = ArtifactValidation::string($tool, self::TOOL_ARTIFACT_COMPONENT, 'inputSchemaJson');
            $controllerClass = ArtifactValidation::string($tool, self::TOOL_ARTIFACT_COMPONENT, 'controllerClass');

            if (isset($seenToolNames[$name])) {
                throw InvalidCacheArtifactException::malformedEntry('McpRegistry', "duplicate tool name \"{$name}\"");
            }

            $seenToolNames[$name] = true;

            $registry->tools[] = new ToolDefinition(
                name: $name,
                description: ArtifactValidation::string($tool, self::TOOL_ARTIFACT_COMPONENT, 'description'),
                controllerClass: $controllerClass,
                controllerMethod: ArtifactValidation::string($tool, self::TOOL_ARTIFACT_COMPONENT, 'controllerMethod'),
                inputSchema: self::decodeSchema($inputSchemaJson),
            );

            /** @var class-string $controllerClass */
            $registry->registeredClasses[$controllerClass] = true;
        }

        /** @var array<string, true> $seenResourceUris */
        $seenResourceUris = [];

        foreach ($resources as $resource) {
            ArtifactValidation::exactKeys($resource, self::RESOURCE_ARTIFACT_COMPONENT, ['uri', 'name', 'description', 'mimeType', 'controllerClass', 'controllerMethod']);

            $uri = ArtifactValidation::string($resource, self::RESOURCE_ARTIFACT_COMPONENT, 'uri');
            $controllerClass = ArtifactValidation::string($resource, self::RESOURCE_ARTIFACT_COMPONENT, 'controllerClass');

            if (isset($seenResourceUris[$uri])) {
                throw InvalidCacheArtifactException::malformedEntry('McpRegistry', "duplicate resource uri \"{$uri}\"");
            }

            $seenResourceUris[$uri] = true;

            $registry->resources[] = new ResourceDefinition(
                uri: $uri,
                name: ArtifactValidation::string($resource, self::RESOURCE_ARTIFACT_COMPONENT, 'name'),
                description: ArtifactValidation::string($resource, self::RESOURCE_ARTIFACT_COMPONENT, 'description'),
                mimeType: ArtifactValidation::string($resource, self::RESOURCE_ARTIFACT_COMPONENT, 'mimeType'),
                controllerClass: $controllerClass,
                controllerMethod: ArtifactValidation::string($resource, self::RESOURCE_ARTIFACT_COMPONENT, 'controllerMethod'),
            );

            /** @var class-string $controllerClass */
            $registry->registeredClasses[$controllerClass] = true;
        }

        return $registry;
    }

    /**
     * Rebuilds a live schema from the artifact's JSON text —
     * encodeSchema()'s inverse, and the whole of what `inputSchemaJson`
     * is validated for.
     *
     * Decoding in object mode is what makes the representation
     * unambiguous: `{}` arrives as an empty `stdClass` and `[]` as an
     * empty array, so an empty `required` list, an `#[In([])]` enum's
     * own empty choices, and an empty schema object are three distinct
     * values before anything here looks at them, at any depth and in
     * any combination. Nothing is inferred from a value, and there is
     * no second field for the JSON text to disagree with.
     *
     * normalizeDecodedNode() then restores the shape the live
     * JsonSchema path builds — a JSON object's members as a keyed PHP
     * array, an empty JSON object as the `stdClass` PHP has no other
     * way to hold, a JSON array as a PHP list — so a tool loaded from
     * the cache and one just discovered are the same value.
     *
     * Three things make the text unusable, and each rejects the whole
     * artifact as InvalidCacheArtifactException so the framework's
     * cache loaders fall back to live discovery and recompile rather
     * than letting `tools/list` advertise — and `tools/call` validate
     * against — a schema the application never declared: text that is
     * not valid JSON, a document whose root is not a JSON object (every
     * tool input schema is one), and the numeric member name
     * normalizeDecodedObject() refuses.
     *
     * @return array<string, mixed>
     * @throws InvalidCacheArtifactException
     */
    private static function decodeSchema(string $json): array
    {
        try {
            $root = json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidCacheArtifactException::malformedEntry(
                self::TOOL_ARTIFACT_COMPONENT,
                'an "inputSchemaJson" value that is not valid JSON',
            );
        }

        if (!$root instanceof \stdClass) {
            throw InvalidCacheArtifactException::malformedEntry(
                self::TOOL_ARTIFACT_COMPONENT,
                'an "inputSchemaJson" document whose root is not a JSON object',
            );
        }

        $members = get_object_vars($root);

        // The root is the one object whose JSON type comes from
        // ToolDefinition::$inputSchema's own array declaration rather
        // than from the data, which is why encodeSchema() casts it on
        // the way out: `{}` here is a schema with no keys, not a value
        // that has to stay an object.
        return $members === [] ? [] : self::normalizeDecodedObject($members);
    }

    /**
     * One decoded node, put back into the form JsonSchema builds live.
     */
    private static function normalizeDecodedNode(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $members = get_object_vars($node);

            // The empty object is the one node a PHP array cannot
            // express, so it stays the live object JsonSchema itself
            // produces for it — see forParameters() and
            // schemaForScalar().
            return $members === [] ? $node : self::normalizeDecodedObject($members);
        }

        if (is_array($node)) {
            return array_map(self::normalizeDecodedNode(...), $node);
        }

        return $node;
    }

    /**
     * A non-empty JSON object's members, keyed the way the live schema
     * keys them.
     *
     * json_decode() hands back a member name PHP reads as an integer
     * ("0", "1", "-3") as an integer array key, and an array holding one
     * re-encodes as a JSON array rather than the object it came from.
     * encodeSchema() never writes such an object: every array in a live
     * schema is either a string-keyed map or a list, and a list is what
     * encodes as a JSON array to begin with. So a numeric member name
     * means the artifact does not describe a schema this class wrote,
     * and it is refused for the same reason every other unwritable
     * shape here is.
     *
     * @param non-empty-array<array-key, mixed> $members
     * @return array<string, mixed>
     * @throws InvalidCacheArtifactException
     */
    private static function normalizeDecodedObject(array $members): array
    {
        $normalized = [];

        foreach ($members as $name => $value) {
            if (!is_string($name)) {
                throw InvalidCacheArtifactException::malformedEntry(
                    self::TOOL_ARTIFACT_COMPONENT,
                    'an "inputSchemaJson" object with a numeric member name',
                );
            }

            $normalized[$name] = self::normalizeDecodedNode($value);
        }

        return $normalized;
    }

    public function findTool(string $name): ?ToolDefinition
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    public function findResource(string $uri): ?ResourceDefinition
    {
        foreach ($this->resources as $resource) {
            if ($resource->uri === $uri) {
                return $resource;
            }
        }

        return null;
    }
}
