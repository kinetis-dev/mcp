<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\MinLength;

final readonly class CreateUserRequest
{
    public function __construct(
        #[MinLength(3)]
        public string $name,
        #[Email]
        public string $email,
    ) {}
}
