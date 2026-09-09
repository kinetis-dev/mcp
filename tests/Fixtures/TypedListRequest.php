<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;

/**
 * The scalar-list and backed-enum shapes as an MCP tool sees them:
 * inside a DTO argument, which is the only place either is declared.
 */
final readonly class TypedListRequest
{
    public function __construct(
        #[ListOf('string')]
        #[Each(MinLength::class, 2)]
        public array $tags,
        public Severity $severity = Severity::Info,
    ) {}
}
