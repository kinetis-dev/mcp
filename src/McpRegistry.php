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
     * result through var_export(). `inputSchema` is the one field that
     * needs converting first: JsonSchema::forParameters() (a class/tool
     * with zero parameters) and schemaForScalar() (a `mixed`-typed
     * argument) both cast a genuinely empty schema to `(object) []`, so
     * it JSON-encodes as `{}` rather than the invalid `[]` a bare PHP
     * `[]` would produce — correct for the live wire response, but this
     * codebase's own cache format never carries a live PHP object (the
     * same "no live objects in the cache" discipline every other
     * compiled artifact here follows), so normalizeSchemaForStorage()
     * below converts every stdClass it finds, at any depth, into a
     * plain empty array plus a separately-recorded structural path
     * (`inputSchemaObjectPaths`) identifying where it was — never a
     * reserved marker value living inside the schema data itself;
     * fromArray()'s restoreSchemaFromStorage() counterpart restores the
     * cast from those recorded paths.
     *
     * @return array{tools: list<array<string, mixed>>, resources: list<array<string, mixed>>}
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
     */
    private static function toolToArray(ToolDefinition $tool): array
    {
        $normalized = self::normalizeSchemaForStorage($tool->inputSchema);

        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'controllerClass' => $tool->controllerClass,
            'controllerMethod' => $tool->controllerMethod,
            'inputSchema' => $normalized['data'],
            'inputSchemaObjectPaths' => $normalized['objectPaths'],
        ];
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
     * `CacheableDiscoveryInterface::fromArray()` contract. `inputSchema`
     * is validated only as "present and an array" — its own deeply
     * recursive JSON-Schema shape is never re-validated here, the same
     * "don't re-derive an owning abstraction's own shape rules" scope
     * `HttpCache::fromArray()` applies to `httpBindingPlans`/
     * `hydrationPlans`. Also re-checks the same tool-name/resource-URI
     * uniqueness invariant register() enforces live — `toArray()` itself
     * never produces a colliding pair, but a hand-edited or otherwise
     * corrupt compiled artifact could, and this must reject that rather
     * than silently preserve whichever entry happened to be listed first
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
            ArtifactValidation::exactKeys($tool, 'McpRegistry tool', ['name', 'description', 'controllerClass', 'controllerMethod', 'inputSchema', 'inputSchemaObjectPaths']);

            $name = ArtifactValidation::string($tool, 'McpRegistry tool', 'name');
            $inputSchema = ArtifactValidation::array($tool, 'McpRegistry tool', 'inputSchema');
            $objectPaths = self::extractObjectPaths($tool);
            $controllerClass = ArtifactValidation::string($tool, 'McpRegistry tool', 'controllerClass');

            if (isset($seenToolNames[$name])) {
                throw InvalidCacheArtifactException::malformedEntry('McpRegistry', "duplicate tool name \"{$name}\"");
            }

            $seenToolNames[$name] = true;

            $registry->tools[] = new ToolDefinition(
                name: $name,
                description: ArtifactValidation::string($tool, 'McpRegistry tool', 'description'),
                controllerClass: $controllerClass,
                controllerMethod: ArtifactValidation::string($tool, 'McpRegistry tool', 'controllerMethod'),
                inputSchema: self::restoreSchemaFromStorage($inputSchema, $objectPaths),
            );

            /** @var class-string $controllerClass */
            $registry->registeredClasses[$controllerClass] = true;
        }

        /** @var array<string, true> $seenResourceUris */
        $seenResourceUris = [];

        foreach ($resources as $resource) {
            ArtifactValidation::exactKeys($resource, 'McpRegistry resource', ['uri', 'name', 'description', 'mimeType', 'controllerClass', 'controllerMethod']);

            $uri = ArtifactValidation::string($resource, 'McpRegistry resource', 'uri');
            $controllerClass = ArtifactValidation::string($resource, 'McpRegistry resource', 'controllerClass');

            if (isset($seenResourceUris[$uri])) {
                throw InvalidCacheArtifactException::malformedEntry('McpRegistry', "duplicate resource uri \"{$uri}\"");
            }

            $seenResourceUris[$uri] = true;

            $registry->resources[] = new ResourceDefinition(
                uri: $uri,
                name: ArtifactValidation::string($resource, 'McpRegistry resource', 'name'),
                description: ArtifactValidation::string($resource, 'McpRegistry resource', 'description'),
                mimeType: ArtifactValidation::string($resource, 'McpRegistry resource', 'mimeType'),
                controllerClass: $controllerClass,
                controllerMethod: ArtifactValidation::string($resource, 'McpRegistry resource', 'controllerMethod'),
            );

            /** @var class-string $controllerClass */
            $registry->registeredClasses[$controllerClass] = true;
        }

        return $registry;
    }

    /**
     * A `stdClass` anywhere in the schema tree (JsonSchema's own
     * "encodes as `{}` not `[]`" convention — see JsonSchema::forParameters()'s
     * and schemaForScalar()'s own docblocks, both of which produce one: a
     * class/tool with zero parameters, and a `mixed`-typed one
     * respectively) needs to survive the round trip through this
     * codebase's own cache format, which never carries a live PHP object
     * (the same "no live objects in the cache" discipline every other
     * compiled artifact here follows). A blanket "restore any empty
     * array back to an object" rule would be wrong the moment a
     * genuinely empty JSON *array* value needs to survive the round trip
     * unchanged (an empty `#[In([])]` enum, for one) — telling the two
     * apart needs recording, per value, which of them it actually was.
     *
     * Recorded *structurally* — a separate list of key-paths
     * (`objectPaths`), alongside the schema data itself, never as a
     * reserved value living inside the schema data's own tree. An
     * earlier version compared every string (then every exact-shaped
     * array) value in the schema tree against a marker constant, which
     * meant a real, user-supplied schema value — an `#[In]` enum choice,
     * a `#[Regex]` pattern, a description, even a `required` field name
     * — happening to equal that exact value would be wrongly restored as
     * an object. This shape has no such risk at all, not just a
     * narrowed one: restoreSchemaFromStorage() below casts back to
     * `(object)` purely by walking to each recorded path, never by
     * comparing a schema value against anything, so no schema value —
     * regardless of what it happens to equal — can ever be mistaken for
     * one.
     *
     * @param array<string, mixed> $schema
     * @return array{data: array<string, mixed>, objectPaths: list<list<string>>}
     */
    private static function normalizeSchemaForStorage(array $schema): array
    {
        $objectPaths = [];
        $data = self::normalizeSchemaNode($schema, [], $objectPaths);

        /** @var array<string, mixed> $data */
        return ['data' => $data, 'objectPaths' => $objectPaths];
    }

    /**
     * @param list<string> $path
     * @param list<list<string>> $objectPaths
     */
    private static function normalizeSchemaNode(mixed $node, array $path, array &$objectPaths): mixed
    {
        if ($node instanceof \stdClass) {
            $objectPaths[] = $path;

            // Every stdClass this codebase's own JsonSchema ever
            // produces is genuinely empty (see this method's own
            // docblock — a zero-parameter schema, or a `mixed`-typed
            // argument's own schema) — so there's nothing inside it to
            // walk further, and no need for a rule covering a stdClass
            // recorded directly inside another recorded object path.
            return [];
        }

        if (is_array($node)) {
            $result = [];

            foreach ($node as $key => $value) {
                $result[$key] = self::normalizeSchemaNode($value, [...$path, (string) $key], $objectPaths);
            }

            return $result;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<list<string>> $objectPaths
     * @return array<string, mixed>
     */
    private static function restoreSchemaFromStorage(array $data, array $objectPaths): array
    {
        foreach ($objectPaths as $path) {
            self::castPathToObject($data, $path);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     */
    private static function castPathToObject(array &$data, array $path): void
    {
        if ($path === []) {
            // The top level is always a real array — ToolDefinition's own
            // $inputSchema type, and JsonSchema::forParameters()'s own
            // return type, guarantee it — so a recorded path can never
            // legitimately be empty; only a hand-edited or otherwise
            // corrupt artifact reaches this.
            throw InvalidCacheArtifactException::malformedEntry('McpRegistry tool', 'an "inputSchemaObjectPaths" entry with no path segments');
        }

        $key = array_shift($path);

        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            throw InvalidCacheArtifactException::malformedEntry('McpRegistry tool', 'an "inputSchemaObjectPaths" entry that does not resolve to a real array node');
        }

        if ($path === []) {
            $data[$key] = (object) $data[$key];

            return;
        }

        /** @var array<string, mixed> $nested */
        $nested = $data[$key];
        self::castPathToObject($nested, $path);
        $data[$key] = $nested;
    }

    /**
     * @param array<array-key, mixed> $tool
     * @return list<list<string>>
     */
    private static function extractObjectPaths(array $tool): array
    {
        $value = ArtifactValidation::array($tool, 'McpRegistry tool', 'inputSchemaObjectPaths');

        if (!array_is_list($value)) {
            throw InvalidCacheArtifactException::wrongFieldType('McpRegistry tool', 'inputSchemaObjectPaths', 'a list');
        }

        $paths = [];

        foreach ($value as $path) {
            if (!is_array($path) || !array_is_list($path)) {
                throw InvalidCacheArtifactException::malformedEntry('McpRegistry tool', 'a non-list entry in "inputSchemaObjectPaths"');
            }

            foreach ($path as $segment) {
                if (!is_string($segment)) {
                    throw InvalidCacheArtifactException::malformedEntry('McpRegistry tool', 'a non-string path segment in "inputSchemaObjectPaths"');
                }
            }

            /** @var list<string> $path */
            $paths[] = $path;
        }

        return $paths;
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
