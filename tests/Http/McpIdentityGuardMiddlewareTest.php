<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Http;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\Compiler;
use Kinetis\Cache\HttpCache;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\InvalidConfigValueException;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\Http\McpController;
use Kinetis\Mcp\Http\McpIdentityGuardMiddleware;
use Kinetis\Mcp\Http\McpOriginMiddleware;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Tests\Fixtures\GroupOrderProject\ApplicationAuthMiddleware;
use Kinetis\Mcp\Tests\Fixtures\GuardedInvocationController;
use Kinetis\Mcp\Tests\Fixtures\PublishesConcreteUserOnlyMiddleware;
use Kinetis\Mcp\Tests\Fixtures\PublishesUserMiddleware;
use Kinetis\Mcp\Tests\Fixtures\UnreachableRequestHandler;
use Kinetis\Mcp\Transport\StdioTransport;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The `mcp` group's final guard: `/mcp` executes only for a request some
 * middleware ahead of it authenticated, or on a deployment that declared
 * the endpoint public.
 */
final class McpIdentityGuardMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        GuardedInvocationController::$log = [];
    }

    /**
     * The `_meta` every request needs — required by the 2026-07-28
     * protocol, the only revision this server implements.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
    }

    /**
     * A POST /mcp request whose mirrored headers match its own body, the
     * shape McpController::headerMismatch() requires of every request.
     *
     * @param array<string, mixed> $body
     */
    private function mcpRequest(array $body): ServerRequest
    {
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $params['_meta'] = [...$this->meta(), ...(is_array($params['_meta'] ?? null) ? $params['_meta'] : [])];
        $body['params'] = $params;

        $request = (new ServerRequest('POST', '/mcp', body: json_encode($body)))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', (string) ($body['method'] ?? null));

        $name = $params['name'] ?? $params['uri'] ?? null;

        return $name !== null ? $request->withHeader('Mcp-Name', (string) $name) : $request;
    }

    private function toolCall(): ServerRequest
    {
        return $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'guarded_tool', 'arguments' => (object) []],
        ]);
    }

    private function resourceRead(): ServerRequest
    {
        return $this->mcpRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://guarded'],
        ]);
    }

    /**
     * A Kernel whose `mcp` group is assembled in discovery's own order:
     * the origin check, then whatever the application contributed, then
     * the guard.
     *
     * @param list<class-string> $applicationMiddleware
     * @param array<string, string> $config
     */
    private function guardedKernel(array $applicationMiddleware = [], array $config = []): Kernel
    {
        [$app, $router] = $this->appAndRouter($config);

        return new Kernel($app, $router, middlewareGroups: [
            'mcp' => [McpOriginMiddleware::class, ...$applicationMiddleware, McpIdentityGuardMiddleware::class],
        ]);
    }

    /**
     * @param array<string, string> $config
     * @return array{0: AppScope, 1: Router}
     */
    private function appAndRouter(array $config = []): array
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        $registry = new McpRegistry();
        $registry->register(GuardedInvocationController::class);
        $app->instance(McpServer::class, new McpServer($registry, new McpDispatcher($app)));
        $app->boot();

        $router = new Router();
        $router->register(McpController::class);

        return [$app, $router];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded;
    }

    // --- Closed by default.

    public function test_an_anonymous_request_is_rejected(): void
    {
        $response = $this->guardedKernel()->handle($this->toolCall());

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The framework's own error shape and nothing else — no JSON-RPC
     * envelope (nothing was dispatched to produce one), no challenge
     * naming an authentication scheme this guard knows nothing about,
     * and no detail distinguishing a missing identity from a closed
     * endpoint.
     */
    public function test_the_rejection_is_the_frameworks_generic_401(): void
    {
        $response = $this->guardedKernel()->handle($this->toolCall());

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('WWW-Authenticate'));
        self::assertSame(['error' => 'Unauthenticated.'], $this->decode($response));
    }

    public function test_a_rejected_tool_call_never_reaches_the_tool(): void
    {
        $this->guardedKernel()->handle($this->toolCall());

        self::assertSame([], GuardedInvocationController::$log);
    }

    public function test_a_rejected_resource_read_never_reaches_the_resource(): void
    {
        $response = $this->guardedKernel()->handle($this->resourceRead());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], GuardedInvocationController::$log);
    }

    /**
     * A notification carries no `id`, so a dispatched one would answer
     * `202` with an empty body while still running the tool. The guard
     * settles it as the same `401` a request gets, before McpController
     * is ever constructed.
     */
    public function test_a_rejected_notification_is_neither_dispatched_nor_accepted(): void
    {
        $response = $this->guardedKernel()->handle($this->mcpRequest([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'guarded_tool', 'arguments' => (object) []],
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], GuardedInvocationController::$log);
    }

    // --- What opens it.

    public function test_middleware_registering_current_user_interface_permits_execution(): void
    {
        $kernel = $this->guardedKernel(applicationMiddleware: [PublishesUserMiddleware::class]);

        $response = $kernel->handle($this->toolCall());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tool'], GuardedInvocationController::$log);
    }

    /**
     * `CurrentUserInterface` is the whole boundary: an authenticated
     * caller published only under a concrete class is invisible to every
     * consumer depending on the portable id, this guard included.
     */
    public function test_registering_only_a_concrete_user_class_is_rejected(): void
    {
        $kernel = $this->guardedKernel(applicationMiddleware: [PublishesConcreteUserOnlyMiddleware::class]);

        $response = $kernel->handle($this->toolCall());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], GuardedInvocationController::$log);
    }

    public function test_mcp_http_public_opens_the_endpoint_to_anonymous_callers(): void
    {
        $kernel = $this->guardedKernel(config: ['MCP_HTTP_PUBLIC' => 'true']);

        $response = $kernel->handle($this->toolCall());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tool'], GuardedInvocationController::$log);
    }

    public function test_mcp_http_public_false_keeps_the_endpoint_closed(): void
    {
        $kernel = $this->guardedKernel(config: ['MCP_HTTP_PUBLIC' => 'false']);

        self::assertSame(401, $kernel->handle($this->toolCall())->getStatusCode());
    }

    /**
     * `Config::bool()` refuses a value it cannot recognize, rather than
     * letting an unrecognized one read as `false` — which here would be
     * the closed default, but only by accident of which way the
     * unparseable value happened to fall.
     */
    public function test_an_unrecognized_mcp_http_public_value_throws(): void
    {
        $app = new AppScope();
        $app->boot();
        $guard = new McpIdentityGuardMiddleware(
            new Config(['MCP_HTTP_PUBLIC' => 'yes-please']),
            $app->createRequestScope(),
        );

        $this->expectException(InvalidConfigValueException::class);

        $guard->process($this->toolCall(), new UnreachableRequestHandler());
    }

    public function test_an_unrecognized_mcp_http_public_value_never_opens_the_endpoint(): void
    {
        $kernel = $this->guardedKernel(config: ['MCP_HTTP_PUBLIC' => 'yes-please']);

        $response = $kernel->handle($this->toolCall());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], GuardedInvocationController::$log);
    }

    // --- Order: origin, then application authentication, then the guard.

    /**
     * The origin check owns the outermost position, so a disallowed
     * origin is answered on its own terms rather than as a missing
     * identity.
     */
    public function test_a_disallowed_origin_is_rejected_before_the_guard_runs(): void
    {
        $kernel = $this->guardedKernel(config: ['MCP_ALLOWED_ORIGINS' => 'https://allowed.example']);

        $response = $kernel->handle($this->toolCall()->withHeader('Origin', 'https://evil.example'));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Live discovery, against this package's own `src/` reached the way
     * an installed package is — the fixture project declares
     * `kinetis/mcp` in its `vendor/composer/installed.json` and
     * contributes one authentication middleware of its own at the
     * attribute's default priority.
     */
    public function test_discovery_orders_the_group_origin_then_application_auth_then_guard(): void
    {
        self::assertSame(
            [McpOriginMiddleware::class, ApplicationAuthMiddleware::class, McpIdentityGuardMiddleware::class],
            $this->discoveredGroup(),
        );
    }

    /**
     * Live route construction: the group exactly as discovery produced
     * it, driving a real request end to end. The application's own
     * middleware delegates without a credential rather than answering
     * itself, so the guard is what settles the request.
     */
    public function test_a_kernel_built_from_the_discovered_group_rejects_an_anonymous_request(): void
    {
        [$app, $router] = $this->appAndRouter();
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => $this->discoveredGroup()]);

        $response = $kernel->handle($this->toolCall());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], GuardedInvocationController::$log);
    }

    public function test_a_kernel_built_from_the_discovered_group_admits_an_authenticated_request(): void
    {
        [$app, $router] = $this->appAndRouter();
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => $this->discoveredGroup()]);

        $request = $this->toolCall()->withHeader(ApplicationAuthMiddleware::TOKEN_HEADER, 'valid');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tool'], GuardedInvocationController::$log);
    }

    /**
     * The compiled artifact a production boot reads instead of running
     * discovery. `middlewareGroups` travels through `Compiler` and
     * `HttpCache`'s own round trip as a plain list, so the cached
     * endpoint runs the order discovery produced.
     */
    public function test_the_compiled_route_cache_preserves_the_group_order(): void
    {
        self::assertSame(
            [McpOriginMiddleware::class, ApplicationAuthMiddleware::class, McpIdentityGuardMiddleware::class],
            $this->cachedGroup(),
        );
    }

    public function test_a_kernel_built_from_the_compiled_group_rejects_an_anonymous_request(): void
    {
        [$app, $router] = $this->appAndRouter();
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => $this->cachedGroup()]);

        $response = $kernel->handle($this->toolCall());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], GuardedInvocationController::$log);
    }

    public function test_a_kernel_built_from_the_compiled_group_admits_an_authenticated_request(): void
    {
        [$app, $router] = $this->appAndRouter();
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => $this->cachedGroup()]);

        $request = $this->toolCall()->withHeader(ApplicationAuthMiddleware::TOKEN_HEADER, 'valid');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['tool'], GuardedInvocationController::$log);
    }

    // --- The guard is the HTTP group's, and only the HTTP group's.

    /**
     * stdio has no middleware group, no `RequestScope` published by one,
     * and no `MCP_HTTP_PUBLIC` to consult: a local client that launched
     * the process already owns it. A tool call over the transport runs
     * with no identity registered anywhere.
     */
    public function test_stdio_runs_a_tool_with_no_identity_and_no_public_opt_in(): void
    {
        [$app] = $this->appAndRouter();
        $registry = new McpRegistry();
        $registry->register(GuardedInvocationController::class);
        $server = new McpServer($registry, new McpDispatcher($app));

        $input = fopen('php://memory', 'r+');
        fwrite($input, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'guarded_tool', 'arguments' => (object) [], '_meta' => $this->meta()],
        ]) . "\n");
        rewind($input);
        $output = fopen('php://memory', 'r+');

        new StdioTransport()->run($server, $input, $output);

        rewind($output);
        /** @var array<string, mixed> $response */
        $response = json_decode((string) stream_get_contents($output), true);

        self::assertSame(1, $response['id']);
        self::assertArrayNotHasKey('error', $response);
        self::assertSame(['tool'], GuardedInvocationController::$log);
    }

    /**
     * @return list<class-string>
     */
    private function discoveredGroup(): array
    {
        $groups = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__) . '/Fixtures/GroupOrderProject')['groups'];

        return $groups['mcp'];
    }

    /**
     * The discovered group after a full compile/serialize/restore round
     * trip, the state a production boot reads it in.
     *
     * @return list<class-string>
     */
    private function cachedGroup(): array
    {
        [, $router] = $this->appAndRouter();

        $compiled = new Compiler()->compile($router, middleware: ['groups' => ['mcp' => $this->discoveredGroup()]]);
        $restored = HttpCache::fromArray($compiled->http->toArray());

        self::assertSame(CacheFormat::VERSION, $restored->formatVersion);

        return $restored->middlewareGroups['mcp'];
    }
}
