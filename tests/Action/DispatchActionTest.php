<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\DispatchAction;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;
use Tds\ApiGateway\Support\Logger;

final class DispatchActionTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return ServiceRegistry::fromEnv(
            static fn (string $key, ?string $default = null): string => (string) $default,
        );
    }

    /** Registry with the catch-all disabled, so an unmatched path 404s. */
    private function registryNoDefault(): ServiceRegistry
    {
        return ServiceRegistry::fromEnv(
            static fn (string $key, ?string $default = null): string =>
                $key === 'GATEWAY_DEFAULT_SERVICE' ? '' : (string) $default,
        );
    }

    /**
     * Dispatcher whose services all use the injected resolver. The dirs are
     * bogus (no .env → the env scope is a no-op), which is fine because the
     * resolver is supplied directly.
     */
    private function dispatcher(callable $resolver): InProcessDispatcher
    {
        return new InProcessDispatcher([
            'auth' => ['/no/auth', 'X'],
            'customer' => ['/no/customer', 'X'],
            'frontend' => ['/no/frontend', 'X'],
        ], $resolver);
    }

    public function testDispatchesAuthWithStrippedPathAndForwardedHeaders(): void
    {
        $captured = [];
        $resolver = static function (string $dir, string $b) use (&$captured): App {
            $app = AppFactory::create();
            $app->map(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                '/{path:.*}',
                static function ($req, $res) use (&$captured) {
                    $captured['path'] = $req->getUri()->getPath();
                    $captured['method'] = $req->getMethod();
                    $captured['xfp'] = $req->getHeaderLine('X-Forwarded-Prefix');
                    $captured['xfh'] = $req->getHeaderLine('X-Forwarded-Host');
                    $res->getBody()->write('service-body');
                    return $res->withStatus(201);
                },
            );
            return $app;
        };
        $action = new DispatchAction($this->registry(), $this->dispatcher($resolver));

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://api.tracht-digital.de/auth/admin/login?x=1');
        $response = $action($request, new Response());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('service-body', (string) $response->getBody());
        self::assertSame('/admin/login', $captured['path']);
        self::assertSame('POST', $captured['method']);
        self::assertSame('/auth', $captured['xfp']);
        self::assertSame('api.tracht-digital.de', $captured['xfh']);
    }

    public function testDefaultServiceGetsUnmatchedPathVerbatim(): void
    {
        // A path that matches no prefix falls through to `frontend` (the
        // composed API) unchanged — its module routes live at root.
        $captured = [];
        $resolver = static function (string $dir, string $b) use (&$captured): App {
            $app = AppFactory::create();
            $app->map(
                ['GET', 'POST', 'OPTIONS'],
                '/{path:.*}',
                static function ($req, $res) use (&$captured) {
                    $captured['path'] = $req->getUri()->getPath();
                    $captured['xfp'] = $req->getHeaderLine('X-Forwarded-Prefix');
                    return $res;
                },
            );
            return $app;
        };
        $action = new DispatchAction($this->registry(), $this->dispatcher($resolver));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/tools/catalog');
        $action($request, new Response());

        self::assertSame('/tools/catalog', $captured['path']);
        self::assertSame('/frontend', $captured['xfp']);
    }

    public function testUnknownServiceReturns404WhenNoDefault(): void
    {
        $called = false;
        $resolver = static function () use (&$called): App {
            $called = true;
            return AppFactory::create();
        };
        $action = new DispatchAction($this->registryNoDefault(), $this->dispatcher($resolver));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/nope/x');
        $response = $action($request, new Response());

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($called);
    }

    public function testServiceFailureReturns502(): void
    {
        $resolver = static function (): App {
            throw new \RuntimeException('service boot failed');
        };
        $action = new DispatchAction($this->registry(), $this->dispatcher($resolver));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/customer/projects');
        $response = $action($request, new Response());

        self::assertSame(502, $response->getStatusCode());
    }

    public function testLogsDispatchFailureWithServiceAndPath(): void
    {
        $logPath = sys_get_temp_dir() . '/tds-gw-dispatch-' . bin2hex(random_bytes(4)) . '.log';
        $resolver = static function (): App {
            throw new \RuntimeException('kaboom');
        };
        $action = new DispatchAction(
            $this->registry(),
            $this->dispatcher($resolver),
            new Logger($logPath, 'info'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/tickets/blog');
        $response = $action($request, new Response());

        self::assertSame(502, $response->getStatusCode());
        self::assertFileExists($logPath);
        $entry = json_decode(trim((string) file_get_contents($logPath)), true);
        self::assertSame('error', $entry['level']);
        self::assertSame('/frontend', $entry['service']);
        self::assertSame('/tickets/blog', $entry['path']);

        @unlink($logPath);
    }
}
