<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

final class ResourceDefinition
{
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly string $description,
        public readonly string $mimeType,
        public readonly string $controllerClass,
        public readonly string $controllerMethod,
    ) {}
}
