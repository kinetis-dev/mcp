<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Mcp\McpDiscovery;
use PHPUnit\Framework\TestCase;

final class McpDiscoveryTest extends TestCase
{
    /**
     * The fixture project under tests/Fixtures/Project: its own
     * composer.json PSR-4 root, a tool in a Mcp/ directory and one in a
     * deliberately unconventional location — no directory convention
     * required.
     */
    private function fixtureProject(): string
    {
        return __DIR__ . '/Fixtures/Project';
    }

    /**
     * This package's own root doubles as a project whose composer.json
     * maps Kinetis\Mcp — which is how KinetisDocsResource is discovered
     * when developing the package itself. In a consumer install the same
     * class arrives through this package's extra.kinetis scan root
     * instead.
     */
    public function test_discovers_the_built_in_kinetis_docs_resource(): void
    {
        $registry = McpDiscovery::discover(dirname(__DIR__));

        self::assertNotNull($registry->findResource('kinetis://docs/index'));
    }

    public function test_discovers_a_projects_own_tools_anywhere_under_its_psr4_root(): void
    {
        $registry = McpDiscovery::discover($this->fixtureProject());

        self::assertNotNull($registry->findTool('discovered_ping'));
        self::assertNotNull($registry->findTool('unconventional_ping'));
    }

    public function test_paths_restricts_the_project_wide_scan(): void
    {
        $registry = McpDiscovery::discover($this->fixtureProject(), ['Mcp']);

        self::assertNotNull($registry->findTool('discovered_ping'));
        self::assertNull($registry->findTool('unconventional_ping'));
    }

    public function test_paths_falls_back_to_the_mcp_discovery_paths_env_var(): void
    {
        putenv('MCP_DISCOVERY_PATHS=Mcp');

        try {
            $registry = McpDiscovery::discover($this->fixtureProject());

            self::assertNotNull($registry->findTool('discovered_ping'));
            self::assertNull($registry->findTool('unconventional_ping'));
        } finally {
            putenv('MCP_DISCOVERY_PATHS');
        }
    }

    public function test_an_explicit_paths_argument_wins_over_the_env_var(): void
    {
        putenv('MCP_DISCOVERY_PATHS=DoesNotExist');

        try {
            $registry = McpDiscovery::discover($this->fixtureProject(), []);

            self::assertNotNull($registry->findTool('discovered_ping'));
            self::assertNotNull($registry->findTool('unconventional_ping'));
        } finally {
            putenv('MCP_DISCOVERY_PATHS');
        }
    }

    /**
     * The package root is both the project and the location of
     * KinetisDocsResource, so the class surfaces from more than one scan
     * pass. McpRegistry::register() has no duplicate-name check — without
     * the discoverer's own dedup, every docs resource would register
     * twice.
     */
    public function test_discovering_against_this_package_root_does_not_duplicate_the_docs_resource(): void
    {
        $registry = McpDiscovery::discover(dirname(__DIR__));

        $matches = array_filter($registry->resources(), static fn ($resource): bool => $resource->uri === 'kinetis://docs/index');

        self::assertCount(1, $matches);
    }
}
