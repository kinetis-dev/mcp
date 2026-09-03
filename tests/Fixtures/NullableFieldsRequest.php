<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Validation\ListOf;

/**
 * The MCP-side equivalent of the framework package's own
 * NullableFieldsRequest — the identical four nullable-field shapes
 * KINETIS-75 covers, so tools/list's generated inputSchema and a real
 * tools/call can be checked the same way HTTP's OpenAPI document and
 * Dispatcher already are.
 */
final readonly class NullableFieldsRequest
{
    public function __construct(
        public ?string $requiredNullable,
        public ?string $optionalNullable = null,
        public ?NullableItem $optionalItem = null,
        #[ListOf(NullableItem::class)]
        public ?array $optionalItems = null,
    ) {}
}
