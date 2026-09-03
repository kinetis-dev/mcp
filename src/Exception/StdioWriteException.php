<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Exception;

use RuntimeException;

/**
 * A JSON-RPC frame written over stdio (a progress notification or a
 * final response) could only be partially written before the output
 * stream stopped accepting data — thrown by StdioTransport before any
 * further bytes are written into what is now an unrecoverable stream: a
 * partial frame with no trailing newline corrupts the NDJSON framing for
 * every message that would follow it, so nothing more, including a
 * second protocol response describing this very failure, can safely be
 * written to the same stream afterward.
 */
final class StdioWriteException extends RuntimeException
{
    public static function partialFrame(int $written, int $total): self
    {
        return new self(
            "Only wrote {$written} of {$total} bytes for a stdio JSON-RPC frame before the output stream "
            . 'stopped accepting data — the stream is now in an unrecoverable state (a partial frame with no '
            . 'trailing newline) and nothing more can safely be written to it.',
        );
    }
}
