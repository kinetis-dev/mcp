<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * A builtin type outside Hydrator::SUPPORTED_BUILTIN_TYPES on a tool's
 * own top-level argument. McpRegistry::register() rejects it at a
 * boundary guaranteed to run before any tool call: a tool can only be
 * invoked once it is present in the registry, and registration never
 * adds a partially-built tool on failure.
 */
final readonly class UnsupportedParameterToolController
{
    #[McpTool(name: 'unsupported_parameter', description: 'Never actually reachable — registration always rejects it')]
    public function run(object $extra): array
    {
        return ['extra' => $extra];
    }
}
