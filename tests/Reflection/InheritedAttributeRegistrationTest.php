<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Reflection;

use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\Tests\Fixtures\AbstractToolBase;
use Kinetis\Mcp\Tests\Fixtures\InheritsTool;
use Kinetis\Reflection\Exception\AttributeScopeException;
use PHPUnit\Framework\TestCase;

/**
 * The framework-wide attribute-scope rules (no inheritance, abstracts
 * excluded — see Kinetis\Reflection\AttributeScope) applied to
 * McpRegistry, the same way core's own registries are covered in
 * kinetis/framework.
 */
final class InheritedAttributeRegistrationTest extends TestCase
{
    public function test_mcp_registry_rejects_an_inherited_tool(): void
    {
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage(AbstractToolBase::class);

        new McpRegistry()->register(InheritsTool::class);
    }

    public function test_mcp_registry_rejects_an_abstract_class(): void
    {
        $this->expectException(AttributeScopeException::class);

        new McpRegistry()->register(AbstractToolBase::class);
    }
}
