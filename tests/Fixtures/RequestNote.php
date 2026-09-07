<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

/**
 * An arbitrary request-scoped object — nothing to do with identity,
 * which is what makes it the right probe: a middleware publishes one on
 * the request's scope and a tool injects it. The default text is what a
 * scope that never saw the middleware would autowire instead, so the
 * two cases are distinguishable by value.
 */
final readonly class RequestNote
{
    public function __construct(
        public string $text = 'autowired',
    ) {}
}
