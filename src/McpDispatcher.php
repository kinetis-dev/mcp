<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Mcp\Exception\UnresolvableParameterException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Throwable;
use ReflectionNamedType;

/**
 * Resolves a tool/resource method's parameters and invokes it — the MCP
 * analogue of Kinetis\Http\Dispatcher. Simpler than the HTTP version
 * because there's no #[Body]/#[Query]/path-parameter distinction to make:
 * an MCP tool call's arguments are always one flat named object, so every
 * parameter is resolved from it the same way — except a ProgressReporter-
 * typed parameter, which is always injected directly rather than looked up
 * in the arguments object.
 *
 * $bindingPlans/$hydrationPlans are optional, compiled-ahead-of-time
 * replacements for what derivePlan()/Hydrator::compilePlan() would otherwise
 * reflect fresh on every call — see Kinetis\Cache\Compiler. A tool/resource
 * or DTO absent from either map falls back to live reflection transparently.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 */
final class McpDispatcher
{
    public function __construct(
        private readonly ContainerInterface $container,
        /** @var array<string, list<array{name:string, isProgressReporter:bool, dtoClass:?string, scalarType:?string, hasDefault:bool, defaultValue:mixed}>> */
        private readonly array $bindingPlans = [],
        /** @var array<string, HydrationPlan> */
        private readonly array $hydrationPlans = [],
    ) {}

    /**
     * $scope, when given, is the per-message scope the transport created
     * for this one call — the controller and its dependencies resolve
     * from it instead of the constructor's container, which is how a
     * tool injecting RequestScope receives the live scope of its own
     * call rather than a disconnected autowired one. Omitted, the
     * constructor's container is used, which is not per-message-scoped.
     *
     * @param array<string, mixed> $arguments
     * @throws ValidationException
     */
    public function callTool(ToolDefinition $tool, array $arguments, ?ProgressReporter $progress = null, ?ContainerInterface $scope = null): mixed
    {
        $controller = ($scope ?? $this->container)->get($tool->controllerClass);
        $key = "{$tool->controllerClass}::{$tool->controllerMethod}";
        $plan = $this->bindingPlans[$key]
            ?? self::derivePlan(new ReflectionMethod($controller, $tool->controllerMethod));

        $resolved = $this->resolveFromPlan($plan, $arguments, $progress);

        $telemetry = Telemetry::global();
        $token = $telemetry->toolCallStarted($tool->name);

        try {
            // McpRegistry only ever registers public methods, same
            // reflection-free-invocation guarantee Dispatcher relies on.
            $result = $controller->{$tool->controllerMethod}(...$resolved);
            $telemetry->toolCallEnded($token, null);

            return $result;
        } catch (Throwable $e) {
            $telemetry->toolCallEnded($token, $e);

            throw $e;
        }
    }

    /**
     * $scope — see callTool().
     */
    public function readResource(ResourceDefinition $resource, ?ContainerInterface $scope = null): mixed
    {
        $controller = ($scope ?? $this->container)->get($resource->controllerClass);
        $key = "{$resource->controllerClass}::{$resource->controllerMethod}";
        $plan = $this->bindingPlans[$key]
            ?? self::derivePlan(new ReflectionMethod($controller, $resource->controllerMethod));

        $resolved = $this->resolveFromPlan($plan, [], null);

        $token = Telemetry::global()->resourceReadStarted($resource->uri);

        try {
            return $controller->{$resource->controllerMethod}(...$resolved);
        } finally {
            Telemetry::global()->resourceReadEnded($token);
        }
    }

    /**
     * Pure reflection -> plan; no call-time arguments involved. Used both by
     * the live per-call fallback above (when no compiled plan exists for
     * this tool/resource) and by Kinetis\Cache\Compiler ahead of time.
     *
     * A ProgressReporter-typed parameter is tagged rather than omitted: were
     * it absent from the plan entirely, resolving it would still need a
     * live getParameters() call to rediscover its name on every call to any
     * progress-reporting tool, reintroducing exactly the per-call reflection
     * cost this exists to remove.
     *
     * @return list<array{name:string, isProgressReporter:bool, dtoClass:?string, scalarType:?string, hasDefault:bool, defaultValue:mixed}>
     */
    public static function derivePlan(ReflectionMethod $method): array
    {
        $plan = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $isProgressReporter = $type instanceof ReflectionNamedType && $type->getName() === ProgressReporter::class;
            $hasDefault = $parameter->isDefaultValueAvailable();

            $plan[] = [
                'name' => $parameter->getName(),
                'isProgressReporter' => $isProgressReporter,
                'dtoClass' => $type instanceof ReflectionNamedType && !$type->isBuiltin() && !$isProgressReporter
                    ? $type->getName()
                    : null,
                'scalarType' => $type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null,
                'hasDefault' => $hasDefault,
                'defaultValue' => $hasDefault ? $parameter->getDefaultValue() : null,
            ];
        }

        return $plan;
    }

    /**
     * The one resolution algorithm both the live and compiled paths share —
     * the only difference between them is how $plan was obtained.
     *
     * @param list<array{name:string, isProgressReporter:bool, dtoClass:?string, scalarType:?string, hasDefault:bool, defaultValue:mixed}> $plan
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function resolveFromPlan(array $plan, array $arguments, ?ProgressReporter $progress): array
    {
        $resolved = [];

        foreach ($plan as $param) {
            $name = $param['name'];

            if ($param['isProgressReporter']) {
                $resolved[$name] = $progress ?? new ProgressReporter(null);
                continue;
            }

            if (array_key_exists($name, $arguments)) {
                $resolved[$name] = $this->resolveValueFromPlan($arguments[$name], $param);
                continue;
            }

            if ($param['hasDefault']) {
                $resolved[$name] = $param['defaultValue'];
                continue;
            }

            throw UnresolvableParameterException::forParameter($name);
        }

        return $resolved;
    }

    /**
     * Applies the same declared-type-mismatch policy
     * Kinetis\Http\Dispatcher applies to #[Query]/path parameters and
     * Hydrator applies to #[Body] fields — an MCP tool argument's JSON
     * value is exactly as typed as a JSON request body, so there's no
     * reason for a third, more permissive copy of this logic here.
     *
     * @param array{name:string, isProgressReporter:bool, dtoClass:?string, scalarType:?string, hasDefault:bool, defaultValue:mixed} $param
     * @throws ValidationException
     */
    private function resolveValueFromPlan(mixed $value, array $param): mixed
    {
        if ($param['dtoClass'] !== null) {
            if (is_array($value)) {
                /** @var class-string $dtoClass */
                $dtoClass = $param['dtoClass'];

                return Hydrator::hydrate($dtoClass, $value, $this->hydrationPlans[$dtoClass] ?? null);
            }

            if (is_scalar($value)) {
                throw ValidationException::forErrors([
                    $param['name'] => ['must be an object, ' . self::describeType($value) . ' given.'],
                ]);
            }

            // null, or already an object — pass through unchanged.
            return $value;
        }

        $scalarType = $param['scalarType'];

        if ($scalarType !== null) {
            $message = Hydrator::typeMismatchMessage($scalarType, $value);

            if ($message !== null) {
                throw ValidationException::forErrors([$param['name'] => [$message]]);
            }
        }

        return $this->castScalar($value, $scalarType);
    }

    private static function describeType(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_bool($value) => 'boolean',
            is_float($value) => 'float',
            is_int($value) => 'integer',
            is_object($value) => 'object',
            default => 'value',
        };
    }

    private function castScalar(mixed $value, ?string $scalarType): mixed
    {
        if ($scalarType === null || $value === null) {
            return $value;
        }

        return match ($scalarType) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
