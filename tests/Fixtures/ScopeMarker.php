<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

/**
 * Scope-cached state: a RequestScope caches what it autowires for its
 * own lifetime, so within one scope every resolution sees the same
 * instance — and a fresh scope starts with $seen back at false. That
 * difference is what the probe observes.
 */
final class ScopeMarker
{
    public bool $seen = false;
}
