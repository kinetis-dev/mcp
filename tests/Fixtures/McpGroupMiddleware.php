<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

/**
 * What a consumer's own `mcp`-group middleware looks like from the
 * pipeline's point of view — membership is passed explicitly in tests
 * rather than discovered, so no #[AsMiddlewareGroup] here.
 */
final class McpGroupMiddleware extends RecordingMiddleware {}
