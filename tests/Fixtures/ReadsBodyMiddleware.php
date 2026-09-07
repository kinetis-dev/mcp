<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reads the staged body to its end before delegating, the way any
 * middleware inspecting a request body does, and records how many bytes
 * it took — leaving the cursor where a second `getContents()` would
 * answer with an empty string.
 */
final class ReadsBodyMiddleware implements MiddlewareInterface
{
    public static int $bytesRead = 0;

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$bytesRead = \strlen($request->getBody()->getContents());

        return $handler->handle($request);
    }
}
