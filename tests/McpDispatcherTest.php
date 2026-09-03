<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\ProgressReporter;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\NullableFieldsToolController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
use Kinetis\Mcp\ToolDefinition;
use Kinetis\Validation\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class McpDispatcherTest extends TestCase
{
    private function registry(): McpRegistry
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        return $registry;
    }

    private function dispatcher(): McpDispatcher
    {
        $app = new AppScope();
        $app->boot();

        return new McpDispatcher($app);
    }

    public function test_calls_a_tool_with_scalar_arguments(): void
    {
        $tool = $this->registry()->findTool('get_user_status');
        self::assertNotNull($tool);

        $result = $this->dispatcher()->callTool($tool, ['userId' => '42']);

        self::assertSame(['userId' => 42, 'status' => 'active'], $result);
    }

    public function test_calls_a_tool_with_a_dto_argument_and_validates_it(): void
    {
        $tool = $this->registry()->findTool('create_user');
        self::assertNotNull($tool);

        $result = $this->dispatcher()->callTool($tool, ['data' => ['name' => 'Alon', 'email' => 'alon@example.com']]);

        self::assertSame(['name' => 'Alon', 'email' => 'alon@example.com'], $result);
    }

    public function test_invalid_dto_argument_throws_a_validation_exception(): void
    {
        $tool = $this->registry()->findTool('create_user');
        self::assertNotNull($tool);

        $this->expectException(ValidationException::class);
        $this->dispatcher()->callTool($tool, ['data' => ['name' => 'Al', 'email' => 'not-an-email']]);
    }

    public function test_a_non_numeric_scalar_tool_argument_throws_a_validation_exception(): void
    {
        // Same declared-type-mismatch policy applied to #[Body]/#[Query] —
        // an MCP tool argument's JSON value is exactly as typed as a
        // request body, so it gets the identical check.
        $tool = $this->registry()->findTool('get_user_status');
        self::assertNotNull($tool);

        try {
            $this->dispatcher()->callTool($tool, ['userId' => 'not-a-number']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('userId', $e->errors);
        }
    }

    public function test_a_scalar_argument_for_a_dto_typed_tool_parameter_throws_a_validation_exception(): void
    {
        $tool = $this->registry()->findTool('create_user');
        self::assertNotNull($tool);

        try {
            $this->dispatcher()->callTool($tool, ['data' => 'not-an-object']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('data', $e->errors);
        }
    }

    // KINETIS-75: a real tools/call must accept/reject exactly what the
    // tool's own inputSchema (see McpRegistryTest) declares.

    private function nullableFieldsTool(): ToolDefinition
    {
        $registry = new McpRegistry();
        $registry->register(NullableFieldsToolController::class);
        $tool = $registry->findTool('nullable_fields');
        self::assertNotNull($tool);

        return $tool;
    }

    public function test_a_defaultless_nullable_nested_field_rejects_omission(): void
    {
        $tool = $this->nullableFieldsTool();

        try {
            $this->dispatcher()->callTool($tool, ['data' => []]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            // Not "data.requiredNullable": callTool()'s own 'data' param is
            // the top-level DTO itself here, hydrated directly via
            // Hydrator::hydrate() — the dotted "parent.nested" key only
            // appears when a DTO is nested *inside* another one
            // (resolveNestedDtoValue()), which this fixture's own single
            // top-level DTO argument never is.
            self::assertSame(['is required.'], $e->errors['requiredNullable']);
        }
    }

    public function test_a_defaultless_nullable_nested_field_accepts_an_explicit_null(): void
    {
        $tool = $this->nullableFieldsTool();

        $result = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => null]]);

        self::assertSame(
            ['requiredNullable' => null, 'optionalNullable' => null, 'optionalItem' => null, 'optionalItems' => null],
            $result,
        );
    }

    public function test_a_defaulted_nullable_scalar_accepts_both_omission_and_an_explicit_null(): void
    {
        $tool = $this->nullableFieldsTool();

        $omitted = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => 'x']]);
        $explicitNull = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => 'x', 'optionalNullable' => null]]);

        self::assertNull($omitted['optionalNullable']);
        self::assertNull($explicitNull['optionalNullable']);
    }

    public function test_a_nullable_nested_dto_accepts_both_omission_and_an_explicit_null(): void
    {
        $tool = $this->nullableFieldsTool();

        $omitted = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => 'x']]);
        $explicitNull = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => 'x', 'optionalItem' => null]]);
        $present = $this->dispatcher()->callTool($tool, [
            'data' => ['requiredNullable' => 'x', 'optionalItem' => ['product' => 'widget', 'quantity' => 3]],
        ]);

        self::assertNull($omitted['optionalItem']);
        self::assertNull($explicitNull['optionalItem']);
        self::assertSame(3, $present['optionalItem']);
    }

    public function test_a_nullable_list_of_field_accepts_both_omission_and_an_explicit_null(): void
    {
        $tool = $this->nullableFieldsTool();

        $omitted = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => 'x']]);
        $explicitNull = $this->dispatcher()->callTool($tool, ['data' => ['requiredNullable' => 'x', 'optionalItems' => null]]);
        $present = $this->dispatcher()->callTool($tool, [
            'data' => [
                'requiredNullable' => 'x',
                'optionalItems' => [['product' => 'a', 'quantity' => 1], ['product' => 'b', 'quantity' => 2]],
            ],
        ]);

        self::assertNull($omitted['optionalItems']);
        self::assertNull($explicitNull['optionalItems']);
        self::assertSame(2, $present['optionalItems']);
    }

    public function test_reads_a_resource(): void
    {
        $resource = $this->registry()->findResource('kinetis://status');
        self::assertNotNull($resource);

        self::assertSame('ok', $this->dispatcher()->readResource($resource));
    }

    public function test_a_progress_reporter_typed_parameter_is_injected_directly_not_from_arguments(): void
    {
        $registry = new McpRegistry();
        $registry->register(ProgressReportingController::class);
        $tool = $registry->findTool('count_to_three');
        self::assertNotNull($tool);

        $captured = [];
        $progress = new ProgressReporter(
            static function (array $payload) use (&$captured): void {
                $captured[] = $payload;
            },
            'tok',
        );

        $result = $this->dispatcher()->callTool($tool, [], $progress);

        self::assertSame(['done' => true], $result);
        self::assertCount(3, $captured);
    }

    public function test_a_progress_reporter_typed_parameter_defaults_to_a_no_op_reporter(): void
    {
        $registry = new McpRegistry();
        $registry->register(ProgressReportingController::class);
        $tool = $registry->findTool('count_to_three');
        self::assertNotNull($tool);

        $result = $this->dispatcher()->callTool($tool, []);

        self::assertSame(['done' => true], $result);
    }

    public function test_derive_plan_tags_a_scalar_a_dto_and_a_progress_reporter_parameter_correctly(): void
    {
        $tool = $this->registry()->findTool('create_user');
        self::assertNotNull($tool);

        $app = new AppScope();
        $app->boot();
        $controller = $app->get($tool->controllerClass);
        $plan = McpDispatcher::derivePlan(new \ReflectionMethod($controller, $tool->controllerMethod));

        self::assertSame('data', $plan[0]['name']);
        self::assertNotNull($plan[0]['dtoClass']);
        self::assertFalse($plan[0]['isProgressReporter']);

        $progressRegistry = new McpRegistry();
        $progressRegistry->register(ProgressReportingController::class);
        $countToThree = $progressRegistry->findTool('count_to_three');
        self::assertNotNull($countToThree);
        $progressController = $app->get($countToThree->controllerClass);
        $progressPlan = McpDispatcher::derivePlan(new \ReflectionMethod($progressController, $countToThree->controllerMethod));

        self::assertTrue($progressPlan[0]['isProgressReporter']);
        self::assertNull($progressPlan[0]['dtoClass']);
    }

    public function test_a_hand_built_plan_resolves_arguments_identically_to_the_live_path(): void
    {
        $app = new AppScope();
        $app->boot();

        $plan = [[
            'name' => 'userId',
            'isProgressReporter' => false,
            'dtoClass' => null,
            'scalarType' => 'int',
            'hasDefault' => false,
            'defaultValue' => null,
        ]];

        $dispatcher = new McpDispatcher($app, ['Kinetis\Mcp\Tests\Fixtures\AccountController::getUserStatus' => $plan]);
        $tool = $this->registry()->findTool('get_user_status');
        self::assertNotNull($tool);

        $result = $dispatcher->callTool($tool, ['userId' => '42']);

        self::assertSame(['userId' => 42, 'status' => 'active'], $result);
    }

    public function test_a_tool_absent_from_the_plan_map_falls_back_to_live_reflection(): void
    {
        $app = new AppScope();
        $app->boot();

        // Binding plans keyed for a completely different tool — this one
        // must still dispatch correctly via live derivePlan().
        $dispatcher = new McpDispatcher($app, ['SomeOther\Class::method' => []]);
        $tool = $this->registry()->findTool('get_user_status');
        self::assertNotNull($tool);

        $result = $dispatcher->callTool($tool, ['userId' => '42']);

        self::assertSame(['userId' => 42, 'status' => 'active'], $result);
    }
}
