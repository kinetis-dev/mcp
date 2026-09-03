<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * `callable`'s own equivalent of UnsupportedParameterToolController — the
 * second rejected builtin category gets the identical registration-time
 * guarantee: never reachable by a real tool call, since register() never
 * adds a partially-built tool to the registry on failure.
 */
final readonly class UnsupportedCallableParameterToolController
{
    #[McpTool(name: 'unsupported_callable_parameter', description: 'Never actually reachable — registration always rejects it')]
    public function run(callable $handler): array
    {
        return ['handler' => $handler];
    }
}
