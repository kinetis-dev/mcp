<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures\GroupOrderProject;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An application's own `mcp`-group authentication middleware, joining at
 * the attribute's default priority — the position every consumer gets
 * without naming a number.
 *
 * Registers an identity for a valid credential and delegates without one
 * rather than answering `401` itself, which is the shape the group's
 * final guard backstops.
 */
#[AsMiddlewareGroup('mcp')]
final readonly class ApplicationAuthMiddleware implements MiddlewareInterface
{
    public const string TOKEN_HEADER = 'X-Fixture-Token';

    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine(self::TOKEN_HEADER) === 'valid') {
            $this->scope->instance(CurrentUserInterface::class, new class implements CurrentUserInterface {
                #[\Override]
                public function id(): string
                {
                    return 'agent-7';
                }
            });
        }

        return $handler->handle($request);
    }
}
