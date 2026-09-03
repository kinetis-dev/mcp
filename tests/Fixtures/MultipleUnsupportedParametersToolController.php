<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * Both rejected builtin categories on one tool's own arguments — used to
 * prove register() reports the first one it finds (object, reflected
 * before callable in parameter order) without silently registering a
 * tool that would only partially reject bad calls.
 */
final readonly class MultipleUnsupportedParametersToolController
{
    #[McpTool(name: 'multiple_unsupported_parameters', description: 'Never actually reachable — registration always rejects it')]
    public function run(object $extra, callable $handler): array
    {
        return ['extra' => $extra, 'handler' => $handler];
    }
}
