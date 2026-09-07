<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;

/**
 * Reports the RequestNote its own call resolved, so a test can tell the
 * instance a middleware published from one a disconnected scope
 * autowired.
 */
final readonly class RequestNoteReportingController
{
    public function __construct(private RequestNote $note) {}

    /**
     * @return array{note: string}
     */
    #[McpTool(name: 'read_request_note', description: 'Reports the note published on this request scope')]
    public function read(): array
    {
        return ['note' => $this->note->text];
    }
}
