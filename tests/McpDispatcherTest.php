<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\ProgressReporter;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\ProgressReportingController;
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

        $result = $this->dispatcher()->callTool($tool, ['data' => ['name' => 'Alon', 'email' => 'alon@noy.cc']]);

        self::assertSame(['name' => 'Alon', 'email' => 'alon@noy.cc'], $result);
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
