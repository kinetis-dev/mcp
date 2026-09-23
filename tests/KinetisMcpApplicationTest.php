<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AccountController;
use Kinetis\Mcp\Tests\Fixtures\InMemoryLogger;
use Kinetis\Mcp\Tests\Fixtures\RefusingToolController;
use Kinetis\Mcp\Tests\Fixtures\ThrowingResourceController;
use Kinetis\Mcp\Tests\Fixtures\ThrowingToolController;
use Kinetis\Mcp\Tests\Fixtures\TypedListToolController;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ServerInfo;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The Kinetis half of the boundary: the attribute registry and the
 * validating dispatcher, published as the shared server's tools and
 * resources.
 *
 * Driven through the server rather than called directly, because the
 * claims worth proving are about what a client receives — including the
 * argument provenance the two layers have to preserve between them.
 */
final class KinetisMcpApplicationTest extends TestCase
{
    public function test_the_registry_is_published_as_the_servers_tools_and_resources(): void
    {
        $response = $this->serverFor(AccountController::class)
            ->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertNotNull($response);
        self::assertSame(
            ['get_user_status', 'create_user'],
            array_column($response['result']['tools'], 'name'),
        );
        self::assertSame('object', $response['result']['tools'][0]['inputSchema']['type']);
    }

    public function test_a_resource_read_carries_the_registered_uri_and_media_type(): void
    {
        $response = $this->serverFor(AccountController::class)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://status'],
        ]);

        self::assertNotNull($response);
        self::assertSame(
            [['uri' => 'kinetis://status', 'mimeType' => 'text/plain', 'text' => 'ok']],
            $response['result']['contents'],
        );
    }

    /**
     * The distinction the two packages exist to keep: a JSON *object*
     * whose keys happen to look sequential is not a JSON array, and a
     * `#[ListOf]` field must refuse it. Once flattened to a PHP array both
     * are identical and `array_is_list()` answers true for either, so only
     * provenance carried from the raw decode can tell them apart.
     */
    public function test_an_object_shaped_value_is_refused_where_a_list_is_declared(): void
    {
        $server = $this->serverFor(TypedListToolController::class);

        $list = $server->handle($this->call(1, 'typed_list', '{"data":{"tags":["ab","cd"]}}'));
        $object = $server->handle($this->call(2, 'typed_list', '{"data":{"tags":{"0":"ab","1":"cd"}}}'));

        self::assertNotNull($list);
        self::assertNotNull($object);
        self::assertFalse($list['result']['isError']);
        self::assertSame(['ab', 'cd'], json_decode($list['result']['content'][0]['text'], true)['tags']);

        self::assertTrue($object['result']['isError'], 'an object where a list is declared must not hydrate');
        $errors = json_decode($object['result']['content'][0]['text'], true)['errors'];
        self::assertSame(['tags'], $errors[0]['path']);
    }

    /**
     * Validation feedback is the one failure whose real messages reach the
     * client: it is what an agent corrects its next call from.
     */
    public function test_a_validation_failure_keeps_its_violations(): void
    {
        $response = $this->serverFor(AccountController::class)
            ->handle($this->call(1, 'create_user', '{"data":{"name":"Alon"}}'));

        self::assertNotNull($response);
        self::assertTrue($response['result']['isError']);
        $errors = json_decode($response['result']['content'][0]['text'], true)['errors'];
        self::assertSame(['email'], $errors[0]['path']);
    }

    public function test_an_unexpected_tool_failure_is_generic_and_logged(): void
    {
        $logger = new InMemoryLogger();
        $response = $this->serverFor(ThrowingToolController::class, $logger)
            ->handle($this->call(1, 'explode', '{}'));

        self::assertNotNull($response);
        self::assertTrue($response['result']['isError']);
        self::assertSame('Tool execution failed.', $response['result']['content'][0]['text']);
        self::assertStringNotContainsString('hunter2', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertCount(1, $logger->records);
    }

    public function test_a_returned_tool_result_reaches_the_client_as_built_and_is_not_logged(): void
    {
        $logger = new InMemoryLogger();
        $response = $this->serverFor(RefusingToolController::class, $logger)
            ->handle($this->call(1, 'refuse', '{}'));

        self::assertNotNull($response);
        self::assertTrue($response['result']['isError']);
        self::assertSame([['type' => 'text', 'text' => 'Denied.']], $response['result']['content']);
        self::assertCount(0, $logger->records);
    }

    public function test_an_unexpected_resource_failure_is_a_generic_protocol_error_and_logged(): void
    {
        $logger = new InMemoryLogger();
        $response = $this->serverFor(ThrowingResourceController::class, $logger)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://throws'],
        ]);

        self::assertNotNull($response);
        self::assertSame(-32603, $response['error']['code']);
        self::assertSame('Internal error.', $response['error']['message']);
        self::assertStringNotContainsString('hunter2', json_encode($response, JSON_THROW_ON_ERROR));
        self::assertCount(1, $logger->records);
    }

    /**
     * The adapter answers in the protocol's own vocabulary rather than
     * letting a name the registry no longer carries become a generic
     * internal error. Unreachable through the server, which resolves every
     * name against the published lists first, so it is called directly.
     */
    public function test_a_name_the_registry_does_not_carry_is_a_protocol_error(): void
    {
        $application = new KinetisMcpApplication(new McpRegistry(), new McpDispatcher(new AppScope()));

        try {
            $application->callTool('vanished', new stdClass(), new ProgressEmitter(), null);
            self::fail('A tool the registry does not carry must not be invoked.');
        } catch (JsonRpcException $e) {
            self::assertSame(-32602, $e->rpcCode);
        }

        $this->expectException(JsonRpcException::class);
        $application->readResource('kinetis://vanished', null);
    }

    /**
     * @param class-string $controller
     */
    private function serverFor(string $controller, ?InMemoryLogger $logger = null): McpServer
    {
        $registry = new McpRegistry();
        $registry->register($controller);

        $app = new AppScope();
        $app->boot();

        return new McpServer(
            new ServerInfo('Kinetis', '1.0.0'),
            $logger === null
                ? new KinetisMcpApplication($registry, new McpDispatcher($app))
                : new KinetisMcpApplication($registry, new McpDispatcher($app), $logger),
        );
    }

    /**
     * Built from real JSON so `arguments` reaches the adapter with the
     * object-versus-array provenance a decoded message carries.
     *
     * @return array<string, mixed>
     */
    private function call(int $id, string $name, string $argumentsJson): array
    {
        $decoded = json_decode(
            '{"jsonrpc":"2.0","id":' . $id . ',"method":"tools/call","params":{"name":"' . $name
            . '","arguments":' . $argumentsJson . '}}',
        );
        self::assertInstanceOf(stdClass::class, $decoded);

        return (array) $decoded;
    }
}
