<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Attributes;

use Attribute;

/**
 * Exposes a controller method as an MCP tool an AI agent can discover and
 * invoke. Every method parameter becomes a named property of the tool's
 * JSON Schema input (built by Kinetis\Validation\JsonSchema, the same
 * mapping the OpenAPI generator uses) — there's no #[Body]/#[Query]
 * distinction here the way there is for HTTP routes, since a tool call's
 * arguments always arrive as one flat named object.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpTool
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
