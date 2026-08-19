<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Exception;

use RuntimeException;

final class UnresolvableParameterException extends RuntimeException
{
    public static function forParameter(string $name): self
    {
        return new self("Cannot resolve parameter \"\${$name}\": missing from arguments and no default value was found.");
    }
}
