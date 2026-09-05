<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Http;

use Kinetis\Config\Config;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The `/mcp` endpoint answers `401` unless something upstream in the
 * `mcp` group has published an identity for this request, or the
 * deployment has declared the endpoint public — so network-reachable
 * tool and resource execution is closed until an application opens it.
 * {@see McpOriginMiddleware}'s rebinding check answers "may this browser
 * origin connect," a different question from "who is calling."
 *
 * Priority 0 puts it last in the group, after the origin check at 100
 * and application authentication at the default 50, so it observes the
 * scope every middleware ahead of it has already finished writing to.
 *
 * Identity presence is `RequestScope::isRegistered()` on
 * `CurrentUserInterface` alone. `has()`/`get()` would both answer for a
 * class nobody registered — `AppScope` falls back to autowiring any
 * `class_exists()` id — so either would accept a manufactured,
 * disconnected object as proof of authentication. The interface is also
 * the whole boundary: a middleware registering only its own concrete
 * user class publishes nothing portable for a tool, this package, or any
 * other consumer to depend on, and does not open the endpoint.
 *
 * `MCP_HTTP_PUBLIC=true` is the one opt-in, read from {@see Config} —
 * the boot-time environment snapshot, not a live `getenv()` — so a value
 * that is not a recognized boolean throws
 * `Kinetis\Config\Exception\InvalidConfigValueException` like any other
 * typed config read, and the endpoint stays closed.
 */
#[AsMiddlewareGroup('mcp', priority: 0)]
final readonly class McpIdentityGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Config $config,
        private RequestScope $scope,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->config->bool('MCP_HTTP_PUBLIC', false) || $this->scope->isRegistered(CurrentUserInterface::class)) {
            return $handler->handle($request);
        }

        // The framework's own error shape, carrying nothing about the
        // request, the configuration, or which of the two conditions
        // failed. No WWW-Authenticate challenge: the scheme belongs to
        // whichever authentication middleware the application put in the
        // group, and this guard cannot name one it knows nothing about.
        return ErrorResponse::create(401, 'Unauthenticated.');
    }
}
