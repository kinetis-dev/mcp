<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Attributes\McpTool;

/**
 * Records every invocation into a shared, static log, so a test can
 * assert that a rejected request reached neither the tool nor the
 * resource — an empty log is the observable form of "nothing ran."
 */
final class GuardedInvocationController
{
    /** @var list<string> */
    public static array $log = [];

    /**
     * @return array{ran: bool}
     */
    #[McpTool(name: 'guarded_tool', description: 'Records that it ran')]
    public function run(): array
    {
        self::$log[] = 'tool';

        return ['ran' => true];
    }

    #[McpResource(uri: 'kinetis://guarded', name: 'guarded', description: 'Records that it was read')]
    public function read(): string
    {
        self::$log[] = 'resource';

        return 'read';
    }
}
