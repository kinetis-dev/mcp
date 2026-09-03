<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * KINETIS-76 follow-up: a genuinely unsupported builtin type (`object`)
 * on a tool's own top-level argument — proves McpRegistry::register()
 * already rejects this at a registration boundary that is guaranteed to
 * run before any tool call can ever reach it, since a tool can only be
 * invoked once it's present in the registry, and registration never adds
 * a partially-built tool on failure.
 */
final readonly class UnsupportedParameterToolController
{
    #[McpTool(name: 'unsupported_parameter', description: 'Never actually reachable — registration always rejects it')]
    public function run(object $extra): array
    {
        return ['extra' => $extra];
    }
}
