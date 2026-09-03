<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Exception;

use RuntimeException;

/**
 * A #[McpTool]/#[McpResource]-attributed method claims a name/URI another
 * already-registered method claims too. Thrown by McpRegistry::register()
 * before either definition is added to the registry — the visible tool/
 * resource surface an agent sees must never depend on which of the two
 * conflicting methods happened to be discovered first, so this is always
 * a hard failure, never a silent shadow.
 */
final class DuplicateDefinitionException extends RuntimeException
{
    public static function duplicateToolName(
        string $name,
        string $existingClass,
        string $existingMethod,
        string $newClass,
        string $newMethod,
    ): self {
        return new self(
            "MCP tool name \"{$name}\" is already registered by \"{$existingClass}::{$existingMethod}()\"; "
            . "\"{$newClass}::{$newMethod}()\" cannot reuse the same name.",
        );
    }

    public static function duplicateResourceUri(
        string $uri,
        string $existingClass,
        string $existingMethod,
        string $newClass,
        string $newMethod,
    ): self {
        return new self(
            "MCP resource URI \"{$uri}\" is already registered by \"{$existingClass}::{$existingMethod}()\"; "
            . "\"{$newClass}::{$newMethod}()\" cannot reuse the same URI.",
        );
    }
}
