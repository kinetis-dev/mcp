<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
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
}
