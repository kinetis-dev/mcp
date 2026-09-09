<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

enum Severity: string
{
    case Info = 'info';

    case Warning = 'warning';
}
