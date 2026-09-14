<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps X-Robots-Tag: noindex on every gateway response. The API origin is
 * machine-to-machine — nothing here should ever land in a search index.
 * Registered outermost (added last; Slim middleware is LIFO) so the header
 * also covers error responses and everything the catch-all dispatches to the
 * in-process backends. Pairs with public/robots.txt (crawl block); the
 * zero-hop nginx mode mirrors it via deploy/nginx.conf.example.
 *
 * A dedicated class (not a closure) on purpose: Slim binds closure middleware
 * to the DI container, and `bindTo()` on a static closure returns null.
 */
final class RobotsTagMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
