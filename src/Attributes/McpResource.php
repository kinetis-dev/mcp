<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Attributes;

use Attribute;

/**
 * Exposes a controller method as an MCP resource: a URI an agent can list
 * and read, distinct from a #[McpTool] (which is invoked with arguments)
 * — a resource is just fetched.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpResource
{
    public function __construct(
        public string $uri,
        public string $name,
        public string $description,
        public string $mimeType = 'text/plain',
    ) {}
}
