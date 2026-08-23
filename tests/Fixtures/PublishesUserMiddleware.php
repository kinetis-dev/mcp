<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for an auth middleware: publishes a fixed identity on the
 * request's scope, the way BearerAuthMiddleware does after resolving a
 * token.
 */
final readonly class PublishesUserMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->scope->instance(CurrentUserInterface::class, new class implements CurrentUserInterface {
            #[\Override]
            public function id(): string
            {
                return 'agent-7';
            }
        });

        return $handler->handle($request);
    }
}
