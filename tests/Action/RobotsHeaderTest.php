<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\ApiGateway\Bootstrap;

/**
 * The gateway must stamp X-Robots-Tag: noindex on every response — the API
 * origin is machine-to-machine and must never end up in a search index. The
 * middleware is added outermost (after the error middleware), so the header
 * has to land on plain routes and on error/404 paths alike.
 */
final class RobotsHeaderTest extends TestCase
{
    protected function tearDown(): void
    {
        // Bootstrap::createApp() wires its container through the *static*
        // AppFactory. Reset it so container-less AppFactory tests elsewhere
        // in the suite don't inherit it.
        (new \ReflectionClass(AppFactory::class))->setStaticPropertyValue('container', null);
    }

    private function app(): \Slim\App
    {
        // A rootDir without a .env: keeps the app on pure defaults and stops
        // Dotenv from leaking a developer's local .env into the test run.
        $rootDir = sys_get_temp_dir() . '/tds-gateway-robots-test';
        if (!is_dir($rootDir)) {
            mkdir($rootDir, 0777, true);
        }
        return Bootstrap::createApp($rootDir);
    }

    public function testIndexResponseCarriesNoindexHeader(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/');
        $response = $this->app()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    public function testDispatchedErrorResponseCarriesNoindexHeader(): void
    {
        // No services are installed next to the temp rootDir, so the catch-all
        // dispatch fails — exactly the error path the outermost middleware
        // must still stamp.
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/auth/whatever');
        $response = $this->app()->handle($request);

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }
}
