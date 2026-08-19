<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records its own class name into a shared, static log every time
 * process() runs, then delegates — lets tests assert the exact order a
 * pipeline actually ran in. Subclassed so each recorded identity is
 * distinguishable without a constructor argument.
 */
class RecordingMiddleware implements MiddlewareInterface
{
    /** @var list<class-string> */
    public static array $log = [];

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$log[] = static::class;

        return $handler->handle($request);
    }
}
