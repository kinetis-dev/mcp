<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

/**
 * A DTO with a constructor and no parameters, so JsonSchema::forClass()
 * gives it an empty `properties` object and an empty `required` list —
 * the two JSON types that look alike in PHP, side by side, one nesting
 * level below where a zero-parameter tool puts them.
 */
final readonly class EmptySchemaObjectRequest
{
    public function __construct() {}
}
