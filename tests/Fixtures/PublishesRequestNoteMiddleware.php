<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Publishes an arbitrary object on the request's scope, the way any
 * middleware registers something for the handler behind it to inject —
 * a resolved tenant, a feature-flag set, a per-request clock.
 */
final readonly class PublishesRequestNoteMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestScope $scope) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->scope->instance(RequestNote::class, new RequestNote('published by middleware'));

        return $handler->handle($request);
    }
}
