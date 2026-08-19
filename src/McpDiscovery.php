<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Cache\PackageDiscovery;

/**
 * Builds a McpRegistry from every class found in a project — no
 * namespace/directory convention required — plus every class found under
 * Kinetis\Mcp specifically (framework-provided resources, e.g.
 * KinetisDocsResource), rather than requiring an explicit mcp.php
 * registration file. Kinetis\Mcp itself also contains plenty of
 * non-resource framework internals (McpServer, McpDispatcher, the
 * attribute classes, ...); registering one of those is a harmless no-op,
 * the same as McpRegistry::register() already is for any class with no
 * #[McpTool]/#[McpResource]-attributed method.
 *
 * $paths restricts the project-wide scan to one or more sub-paths
 * (relative to each PSR-4 base directory) instead of scanning the whole
 * project — for an application large enough to want a bounded scan.
 * Falls back to the MCP_DISCOVERY_PATHS environment variable
 * (comma-separated) when not passed explicitly, so a project can commit
 * this restriction without every call site needing to know about it.
 */
final class McpDiscovery
{
    /**
     * @param list<string>|null $paths
     */
    public static function discover(string $projectRoot, ?array $paths = null): McpRegistry
    {
        $registry = new McpRegistry();

        // Deduped across both passes: when the project root and framework
        // root are the same repository, a class under Kinetis\Mcp can
        // surface from both classesInProject() and
        // classesUnderFrameworkSegment(). McpRegistry::register() has no
        // duplicate-name check, so without this a tool/resource would
        // register twice.
        /** @var array<class-string, true> $seen */
        $seen = [];

        foreach ([
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Mcp'),
            ...NamespaceScanner::classesUnderPackageRoots(PackageDiscovery::scanRoots($projectRoot)),
        ] as $class) {
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $registry->register($class);
        }

        return $registry;
    }

    /**
     * @return list<string>
     */
    private static function pathsFromEnv(): array
    {
        $value = getenv('MCP_DISCOVERY_PATHS');

        if ($value === false || $value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
