<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

final readonly class NullableFieldsToolController
{
    /**
     * @return array{requiredNullable: ?string, optionalNullable: ?string, optionalItem: ?int, optionalItems: ?int}
     */
    #[McpTool(name: 'nullable_fields', description: 'Reports the nullable fields it received')]
    public function run(NullableFieldsRequest $data): array
    {
        return [
            'requiredNullable' => $data->requiredNullable,
            'optionalNullable' => $data->optionalNullable,
            'optionalItem' => $data->optionalItem?->quantity,
            'optionalItems' => $data->optionalItems === null ? null : count($data->optionalItems),
        ];
    }
}
