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
}
