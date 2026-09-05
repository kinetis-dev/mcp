<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates a caller but registers the resolved user under its own
 * concrete class alone, never CurrentUserInterface — the shape that
 * publishes nothing portable for a tool or another package to depend on.
 */
final readonly class PublishesConcreteUserOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->scope->instance(ConcreteCurrentUser::class, new ConcreteCurrentUser('agent-7', 'operator'));

        return $handler->handle($request);
    }
}
