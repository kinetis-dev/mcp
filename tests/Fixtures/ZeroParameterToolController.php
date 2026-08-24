<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

final readonly class ZeroParameterToolController
{
    #[McpTool(name: 'zero_param_ping', description: 'Takes no arguments at all')]
    public function ping(): array
    {
        return ['message' => 'pong'];
    }
}
