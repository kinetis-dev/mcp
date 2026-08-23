<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

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
 */
final class McpRegistry
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
     * Dumps every registered tool/resource's fields verbatim — all already
     * plain scalars/arrays (inputSchema included), so nothing here needs
     * reflection to reverse. Used by Kinetis\Cache\Compiler.
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
                    'inputSchema' => $tool->inputSchema,
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
    public static function fromArray(array $data): self
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
                inputSchema: $inputSchema,
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
