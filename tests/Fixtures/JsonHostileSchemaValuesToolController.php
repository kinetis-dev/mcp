<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\Regex;

/**
 * Schema values that stress the JSON text the artifact stores: a
 * `#[Regex]` pattern built from quotes and backslashes, `#[In]` choices
 * carrying a quote, a backslash, a line break and non-ASCII characters,
 * and a float-valued `#[GreaterThan]` bound that has to come back a
 * float rather than an int.
 */
final readonly class JsonHostileSchemaValuesToolController
{
    #[McpTool(name: 'json_hostile_schema_values', description: 'Exercises schema values that JSON has to escape')]
    public function run(
        #[Regex('/^"\d+\\\\"$/')]
        string $pattern,
        #[In(['quote"', 'back\\slash', "line\nbreak", 'héllo ☃'])]
        string $choice,
        #[GreaterThan(1.0)]
        float $amount,
    ): array {
        return ['pattern' => $pattern, 'choice' => $choice, 'amount' => $amount];
    }
}
