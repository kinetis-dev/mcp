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
 * Mirrors kinetis/auth-jwt's own JwtAuthMiddleware::process() exactly —
 * registering the *same* instance under both CurrentUserInterface and
 * its own concrete class — without any JWT logic, so KINETIS-74's fix
 * (which must work for any middleware following this pattern, not just
 * that one package) has a fixture that doesn't require adding an
 * auth-jwt dependency to this package's own test suite.
 */
final readonly class PublishesDualIdentityMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = new ConcreteCurrentUser('agent-9', 'admin');

        $this->scope->instance(CurrentUserInterface::class, $user);
        $this->scope->instance(ConcreteCurrentUser::class, $user);

        return $handler->handle($request);
    }
}
