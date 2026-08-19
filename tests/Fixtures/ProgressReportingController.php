<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\ProgressReporter;

final readonly class ProgressReportingController
{
    #[McpTool(name: 'count_to_three', description: 'Reports progress three times then returns done')]
    public function countToThree(ProgressReporter $progress): array
    {
        $progress->report(1, 3, 'one');
        $progress->report(2, 3, 'two');
        $progress->report(3, 3, 'three');

        return ['done' => true];
    }
}
