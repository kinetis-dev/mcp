<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\JsonSchema;
use Kinetis\Reflection\AttributeScope;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reflects a class for #[McpTool]/#[McpResource] methods the same way
 * Router reflects a controller for #[Get]/#[Post] — registration builds
 * the definitions once; McpServer reads them per request.
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

    /**
     * @param class-string $class
     */
    public function register(string $class): void
    {
        $reflection = AttributeScope::reflect($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                AttributeScope::assertDeclares($method, $class);

                $tool = $attribute->newInstance();

                $this->tools[] = new ToolDefinition(
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

                $this->resources[] = new ResourceDefinition(
                    uri: $resource->uri,
                    name: $resource->name,
                    description: $resource->description,
                    mimeType: $resource->mimeType,
                    controllerClass: $class,
                    controllerMethod: $method->getName(),
                );
            }
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
     * needs converting first: JsonSchema::forParameters() casts an empty
     * `properties` to `(object) []` so it JSON-encodes as `{}` rather
     * than `[]` (PHP has no native empty-object type) — correct for the
     * live wire response, but var_export() has no way to represent a
     * stdClass instance that can be required back (it renders a
     * `::__set_state()` call the class doesn't define), so
     * normalizeSchemaForStorage() below converts it back to a plain
     * empty array first; fromArray()'s counterpart restores the cast.
     *
     * @return array{tools: list<array<string, mixed>>, resources: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'tools' => array_map(
                static fn (ToolDefinition $tool): array => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'controllerClass' => $tool->controllerClass,
                    'controllerMethod' => $tool->controllerMethod,
                    'inputSchema' => self::normalizeSchemaForStorage($tool->inputSchema),
                ],
                $this->tools,
            ),
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
     * Reconstructs a McpRegistry from toArray()'s output with zero
     * reflection — the compiled-cache load path's counterpart to register().
     *
     * @param array{tools: list<array<string, mixed>>, resources: list<array<string, mixed>>} $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $registry = new self();

        foreach ($data['tools'] as $tool) {
            /** @var array<string, mixed> $inputSchema */
            $inputSchema = $tool['inputSchema'];

            $registry->tools[] = new ToolDefinition(
                name: $tool['name'],
                description: $tool['description'],
                controllerClass: $tool['controllerClass'],
                controllerMethod: $tool['controllerMethod'],
                inputSchema: self::restoreSchemaFromStorage($inputSchema),
            );
        }

        foreach ($data['resources'] as $resource) {
            $registry->resources[] = new ResourceDefinition(
                uri: $resource['uri'],
                name: $resource['name'],
                description: $resource['description'],
                mimeType: $resource['mimeType'],
                controllerClass: $resource['controllerClass'],
                controllerMethod: $resource['controllerMethod'],
            );
        }

        return $registry;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalizeSchemaForStorage(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && $value instanceof \stdClass) {
                $schema[$key] = [];
            } elseif (is_array($value)) {
                $schema[$key] = self::normalizeSchemaForStorage($value);
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function restoreSchemaFromStorage(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && is_array($value) && $value === []) {
                $schema[$key] = (object) [];
            } elseif (is_array($value)) {
                $schema[$key] = self::restoreSchemaFromStorage($value);
            }
        }

        return $schema;
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
