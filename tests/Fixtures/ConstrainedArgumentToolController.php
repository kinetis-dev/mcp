<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;

/**
 * A tool whose scalar arguments carry constraint attributes — the same
 * ones an HTTP #[Query] parameter takes. Their keywords already appear
 * in the tool's published inputSchema, so the call has to be checked
 * against them too.
 */
final readonly class ConstrainedArgumentToolController
{
    #[McpTool(name: 'list_page', description: 'List one page of results')]
    public function listPage(
        #[GreaterThan(0)]
        int $page,
        #[In(['asc', 'desc'])]
        string $direction = 'asc',
    ): array {
        return ['page' => $page, 'direction' => $direction];
    }
}
