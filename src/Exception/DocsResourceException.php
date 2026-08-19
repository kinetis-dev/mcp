<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Exception;

use RuntimeException;

final class DocsResourceException extends RuntimeException
{
    public static function missingPage(string $slug, string $localPath, string $remoteUrl): self
    {
        return new self(
            "Kinetis docs page \"{$slug}\" is missing — checked {$localPath} (not present in this "
            . "install) and {$remoteUrl} (fetch failed or returned a non-2xx response).",
        );
    }
}
