<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

final readonly class TypedListToolController
{
    /**
     * @return array{tags: list<string>, severity: string}
     */
    #[McpTool(name: 'typed_list', description: 'Reports the typed collection it received')]
    public function run(TypedListRequest $data): array
    {
        return ['tags' => $data->tags, 'severity' => $data->severity->value];
    }
}
