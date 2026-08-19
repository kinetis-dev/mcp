<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

final class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $controllerClass,
        public readonly string $controllerMethod,
        /** @var array<string, mixed> */
        public readonly array $inputSchema,
    ) {}
}
