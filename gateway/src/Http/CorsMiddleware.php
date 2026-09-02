<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CORS for the gateway's OWN two routes — `/` and `/healthz` — and for nothing
 * else.
 *
 * **This must never become app-wide middleware.** Every other path is dispatched
 * to an upstream that owns its own CORS policy; adding a second
 * `Access-Control-Allow-Origin` there does not merely duplicate a header, it
 * BREAKS the request — a browser rejects the response outright when the header
 * appears twice, so the whole API surface would go dark. That is why the
 * gateway historically added no CORS at all (`HeaderFilter`: "CORS is
 * deliberately left to the upstreams"), and the rule stands for the catch-all.
 *
 * What that blanket rule missed is that `/` and `/healthz` have no upstream:
 * they are answered by the gateway itself, so nothing added a header and every
 * cross-origin read of them was blocked. The visible cost was the setup wizard
 * on the four static sites, whose first and most prominent check is `/healthz`:
 * it reported "Nicht erreichbar" on a completely healthy gateway, right next to
 * `/content/blog` reporting OK — because the content route travels to the
 * frontend service, which does set the header. An operator reading that has
 * every reason to conclude the API is broken.
 *
 * Registered per-route (`->add()`), never on the app, so the catch-all cannot
 * inherit it by accident. See GatewayCorsTest.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * The first-party surfaces, always allowed.
     *
     * Mirrors `tds-core-frontend-api`'s baseline for the same reason: a
     * diagnostic endpoint that stops answering because the host's `.env` is
     * stale is a diagnostic endpoint that fails exactly when it is needed.
     * `CORS_ALLOWED_ORIGINS` only ever ADDS to this (localhost in dev, an extra
     * customer domain), so no edit to a file on the host can lock the wizard
     * out of the health check it exists to run.
     *
     * @var list<string>
     */
    public const BASELINE = [
        'https://tracht-digital.de',
        'https://www.tracht-digital.de',
        'https://blog.tracht-digital.de',
        'https://tools.tracht-digital.de',
        'https://auth.tracht-digital.de',
        'https://management.tracht-digital.de',
        'https://app.tracht-digital.de',
    ];

    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    /**
     * Baseline + the comma-separated extras from `CORS_ALLOWED_ORIGINS`.
     *
     * @param callable(string,?string):string $env The Bootstrap env reader.
     */
    public static function fromEnv(callable $env): self
    {
        $extra = array_filter(array_map('trim', explode(',', (string) $env('CORS_ALLOWED_ORIGINS', ''))));
        return new self(array_values(array_unique([...self::BASELINE, ...$extra])));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // A preflight is answered here and never reaches the action. Both
        // gateway routes are mapped for OPTIONS purely so routing finds them
        // instead of handing the preflight to the catch-all — which would
        // dispatch it into a backend that has no such route.
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders(new Response(204), $origin);
        }

        return $this->withCorsHeaders($handler->handle($request), $origin);
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        if ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                // `Vary: Origin` is not cosmetic here: without it a shared cache
                // can serve one site's allowed response to another origin.
                ->withHeader('Vary', 'Origin');
        }

        // Deliberately no `Access-Control-Allow-Credentials`. Neither route
        // reads a session — `/healthz` is an unauthenticated aggregate and `/`
        // is a service index — and a diagnostic that never needs the cookie
        // should not ask a browser to attach one.
        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
