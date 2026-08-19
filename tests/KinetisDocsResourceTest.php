<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Exception\DocsResourceException;
use Kinetis\Mcp\KinetisDocsResource;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KinetisDocsResourceTest extends TestCase
{
    private const string FIXTURE_HOST = '127.0.0.1:8097';

    /** @var resource */
    private static $fixtureServerProcess;

    public static function setUpBeforeClass(): void
    {
        $fixture = __DIR__ . '/Fixtures/docs-server.php';

        self::$fixtureServerProcess = proc_open(
            ['php', '-S', self::FIXTURE_HOST, $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        // No fixed readiness signal from `php -S` other than "give it a
        // moment" — the same discipline AmpHttpClientFactoryTest's own
        // fixture server already uses.
        usleep(300_000);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$fixtureServerProcess);
        proc_close(self::$fixtureServerProcess);
    }

    private function server(): McpServer
    {
        $registry = new McpRegistry();
        $registry->register(KinetisDocsResource::class);

        $app = new AppScope();
        $app->boot();

        return new McpServer($registry, new McpDispatcher($app));
    }

    /**
     * @return list<string>
     */
    private function docSlugs(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/docs/*.md');
        self::assertNotFalse($files);

        return array_values(array_map(
            static fn (string $path): string => basename($path, '.md'),
            $files,
        ));
    }

    public function test_every_real_docs_page_has_a_matching_resource_method(): void
    {
        $reflection = new ReflectionClass(KinetisDocsResource::class);
        $registeredUris = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(McpResource::class) as $attribute) {
                $registeredUris[] = $attribute->newInstance()->uri;
            }
        }

        foreach ($this->docSlugs() as $slug) {
            self::assertContains(
                "kinetis://docs/{$slug}",
                $registeredUris,
                "docs/{$slug}.md has no matching #[McpResource] on KinetisDocsResource.",
            );
        }
    }

    public function test_reading_a_resource_returns_the_actual_file_content(): void
    {
        $resource = new KinetisDocsResource();

        self::assertSame(
            file_get_contents(dirname(__DIR__, 3) . '/docs/tutorial.md'),
            $resource->tutorial(),
        );
    }

    /**
     * Every one of the 31 #[McpResource] methods is the exact same
     * one-line delegate to read() — testing only one (above) can't catch a
     * typo'd slug in any of the other 30. Invoking every one directly,
     * rather than only through the structural (attribute-only) checks the
     * other tests here already do, is what actually proves each page's own
     * content is reachable.
     */
    public function test_every_resource_method_returns_its_own_real_file_content(): void
    {
        $resource = new KinetisDocsResource();
        $reflection = new ReflectionClass($resource);

        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(McpResource::class);

            if ($attributes === []) {
                continue;
            }

            $slug = str_replace('kinetis://docs/', '', $attributes[0]->newInstance()->uri);

            self::assertSame(
                file_get_contents(dirname(__DIR__, 3) . "/docs/{$slug}.md"),
                $method->invoke($resource),
                "{$method->getName()}() did not return docs/{$slug}.md's real content.",
            );
        }
    }

    public function test_resources_list_includes_every_docs_page(): void
    {
        $response = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list']);

        $uris = array_column($response['result']['resources'], 'uri');

        foreach ($this->docSlugs() as $slug) {
            self::assertContains("kinetis://docs/{$slug}", $uris);
        }
    }

    public function test_resources_read_returns_real_markdown_content(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/read',
            'params' => ['uri' => 'kinetis://docs/tutorial'],
        ]);

        self::assertSame('text/markdown', $response['result']['contents'][0]['mimeType']);
        self::assertStringContainsString('# Tutorial', $response['result']['contents'][0]['text']);
    }

    /**
     * read() is private and every one of its public methods hardcodes a
     * real, always-present slug, so the missing-page branch has no path
     * through the real public API at all — invoked directly via reflection
     * instead, the same technique this class's own first test already uses,
     * rather than deleting a real docs file mid-test. Both localDocsRoot
     * and remoteBaseUrl are overridden here (a nonexistent local directory,
     * the fixture server for the remote leg) so this stays a fast, local
     * test rather than making a real call to raw.githubusercontent.com.
     */
    public function test_reading_a_missing_page_throws_a_clear_error(): void
    {
        $resource = new KinetisDocsResource(
            localDocsRoot: '/nonexistent-directory',
            remoteBaseUrl: 'http://' . self::FIXTURE_HOST . '/',
        );
        $method = (new ReflectionClass($resource))->getMethod('read');

        $this->expectException(DocsResourceException::class);
        $this->expectExceptionMessage('Kinetis docs page "does-not-exist" is missing');

        // Both attempts emit a real PHP warning before returning false —
        // exactly the signal read()'s own check is designed to catch.
        // Expected here, not suppressed in production code, just at this
        // one deliberate call site.
        @$method->invoke($resource, 'does-not-exist');
    }

    /**
     * The actual gap this class was built to close: a real
     * `vendor/kinetis/framework` install has no docs/ directory at all, so
     * read() must fall back to fetching the same file remotely instead of
     * failing outright — verified against a real HTTP server (the fixture),
     * not just that the method signature accepts a URL.
     */
    public function test_falls_back_to_a_remote_fetch_when_the_local_path_is_missing(): void
    {
        $resource = new KinetisDocsResource(
            localDocsRoot: '/nonexistent-directory',
            remoteBaseUrl: 'http://' . self::FIXTURE_HOST . '/',
        );
        $method = (new ReflectionClass($resource))->getMethod('read');

        self::assertSame(
            "# Remote Fixture\n\nThis came from the remote fallback.\n",
            $method->invoke($resource, 'known-remote-page'),
        );
    }

    /**
     * The default, zero-argument construction (how the container actually
     * autowires this class) must still prefer the real local docs/ files
     * over the network — confirmed by pointing remoteBaseUrl at a host that
     * refuses connections outright; if read() reached for it at all for a
     * page that exists locally, this would hang/fail instead of returning
     * instantly.
     */
    public function test_prefers_local_content_over_the_network_by_default(): void
    {
        $resource = new KinetisDocsResource(remoteBaseUrl: 'http://127.0.0.1:1/');

        self::assertStringContainsString('# Tutorial', $resource->tutorial());
    }
}
