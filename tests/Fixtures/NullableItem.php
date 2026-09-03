<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

final readonly class NullableItem
{
    public function __construct(
        public string $product,
        public int $quantity,
    ) {}
}
