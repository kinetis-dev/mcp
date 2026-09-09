<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Instrumentation\Telemetry;
use Kinetis\Mcp\Exception\UnresolvableParameterException;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
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
 * A tool call's arguments arrive as one decoded JSON object, so every
 * value they carry is written in InputSource::Json — the same vocabulary
 * an HTTP JSON body is written in, and the one the tool's own published
 * inputSchema promises.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 * @phpstan-type McpBindingPlanParameter array{
 *     name: string,
 *     isProgressReporter: bool,
 *     dtoClass: ?string,
 *     scalarType: ?string,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 *     allowsNull: bool,
 *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
 * }
 */
final class McpDispatcher
{
    public function __construct(
        private readonly ContainerInterface $container,
        /** @var array<string, list<McpBindingPlanParameter>> */
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
     * @return list<McpBindingPlanParameter>
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
                // An untyped parameter accepts anything, null included.
                'allowsNull' => $type === null || $type->allowsNull(),
                // Only meaningful for a scalar argument — a DTO-typed
                // one carries its own fields' rules inside its hydration
                // plan, exactly as an HTTP #[Body] parameter does.
                'constraints' => Hydrator::collectConstraints($parameter),
            ];
        }

        return $plan;
    }

    /**
     * The one resolution algorithm both the live and compiled paths share —
     * the only difference between them is how $plan was obtained.
     *
     * @param list<McpBindingPlanParameter> $plan
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
     * A scalar argument enters Hydrator::resolveScalar(), the one path a
     * #[Body] DTO field and a #[Query]/path parameter also take: null
     * handling, the declared-type check, the cast and the parameter's
     * own constraint attributes are the same code producing the same
     * violations, so a wrong-shaped argument carries the identical path,
     * code and message it would carry over HTTP — and a
     * #[GreaterThan]/#[In] a tool's inputSchema publishes is a rule the
     * call is actually checked against.
     *
     * @param McpBindingPlanParameter $param
     * @throws ValidationException
     */
    private function resolveValueFromPlan(mixed $value, array $param): mixed
    {
        if ($param['dtoClass'] !== null) {
            // A DTO-typed tool argument's own real value — a genuine JSON
            // object — arrives marked as a JsonObject once McpServer's own
            // JsonTree::convert() step is in the picture (see its own
            // docblock); unwrapped here so the existing is_array()
            // recursion below still applies unchanged.
            if ($value instanceof JsonObject) {
                $value = $value->toArray();
            }

            if (is_array($value)) {
                /** @var class-string $dtoClass */
                $dtoClass = $param['dtoClass'];

                return Hydrator::hydrate($dtoClass, $value, $this->hydrationPlans[$dtoClass] ?? null, InputSource::Json);
            }

            if (is_scalar($value)) {
                throw ValidationException::fromViolations([
                    Hydrator::objectExpectedViolation([$param['name']], $value),
                ]);
            }

            // null, or already an object — pass through unchanged.
            return $value;
        }

        [$resolved, $violations] = Hydrator::resolveScalar(
            InputSource::Json,
            [$param['name']],
            $value,
            $param['scalarType'],
            $param['allowsNull'],
            $param['constraints'],
        );

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $resolved;
    }
}
