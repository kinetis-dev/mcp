<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The inner handler for a middleware test whose whole point is that the
 * middleware never delegates — reaching this is the failure.
 */
final readonly class UnreachableRequestHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new LogicException('The inner handler must never be reached.');
    }
}
