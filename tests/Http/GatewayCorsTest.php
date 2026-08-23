<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\ApiGateway\Bootstrap;
use Tds\ApiGateway\Http\CorsMiddleware;

/**
 * The gateway's own routes (`/`, `/healthz`) must answer a cross-origin read,
 * and the catch-all must NOT gain a gateway-added CORS header.
 *
 * Both halves are load-bearing and they pull in opposite directions:
 *   - Without the first, the sites' setup wizard reports "nicht erreichbar" for
 *     a perfectly healthy gateway. `/healthz` is answered by the gateway
 *     itself, so no upstream ever set a header on it, while `/content/*` right
 *     beside it in the same check list answered fine.
 *   - Without the second, every proxied response would carry TWO
 *     `Access-Control-Allow-Origin` headers (the upstream's and the gateway's).
 *     A browser rejects a duplicated header outright, so that would take down
 *     the whole API surface — a far worse failure than the one being fixed.
 */
final class GatewayCorsTest extends TestCase
{
    private const ORIGIN = 'https://blog.tracht-digital.de';

    protected function tearDown(): void
    {
        (new \ReflectionClass(AppFactory::class))->setStaticPropertyValue('container', null);
    }

    private function app(): \Slim\App
    {
        // A rootDir without a .env keeps the app on pure defaults and stops a
        // developer's local .env from leaking into the run.
        $rootDir = sys_get_temp_dir() . '/tds-gateway-cors-test';
        if (!is_dir($rootDir)) {
            mkdir($rootDir, 0777, true);
        }
        return Bootstrap::createApp($rootDir);
    }

    private function get(string $path, string $origin = self::ORIGIN, string $method = 'GET'): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://api.tracht-digital.de' . $path)
            ->withHeader('Origin', $origin);
        return $this->app()->handle($request);
    }

    public function testHealthzAllowsAFirstPartyOrigin(): void
    {
        // No services next to the temp rootDir, so the aggregate reports 503 —
        // and that is the case that matters most: an UNHEALTHY gateway must
        // still answer the wizard cross-origin, or the one check that would
        // name the broken service is the one the operator cannot read.
        $response = $this->get('/healthz');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        // Without this a shared cache can hand one site's allowed response to
        // a different origin.
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    public function testIndexAllowsAFirstPartyOrigin(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightIsAnsweredHereAndNotByTheCatchAll(): void
    {
        // `/healthz` is mapped for OPTIONS purely so routing resolves it before
        // the variable catch-all, which would dispatch the preflight into a
        // backend that has no such route. 204 proves the middleware answered.
        $response = $this->get('/healthz', self::ORIGIN, 'OPTIONS');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testUnknownOriginGetsNoAllowHeader(): void
    {
        $response = $this->get('/healthz', 'https://evil.example');

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCatchAllNeverGainsAGatewayCorsHeader(): void
    {
        // No services next to the temp rootDir, so this fails — which is fine:
        // what matters is that the gateway added no CORS header on its way
        // through. A real upstream sets its own, and a second one breaks it.
        $response = $this->get('/tickets');

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testEnvOnlyAddsToTheFirstPartyBaseline(): void
    {
        // The baseline exists so a stale or empty `.env` on the host cannot
        // lock the wizard out of the health check it exists to run.
        $middleware = CorsMiddleware::fromEnv(
            static fn (string $key, ?string $default = null): string => $key === 'CORS_ALLOWED_ORIGINS'
                ? 'http://localhost:4321'
                : (string) $default,
        );

        $reflected = new \ReflectionProperty($middleware, 'allowedOrigins');
        /** @var list<string> $origins */
        $origins = $reflected->getValue($middleware);

        self::assertContains('http://localhost:4321', $origins);
        foreach (CorsMiddleware::BASELINE as $baseline) {
            self::assertContains($baseline, $origins);
        }
    }
}
