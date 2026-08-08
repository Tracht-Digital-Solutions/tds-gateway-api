<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\InProcessHealthAction;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;
use Tds\ApiGateway\Support\Logger;

final class InProcessHealthActionTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return ServiceRegistry::fromEnv(
            static fn (string $key, ?string $default = null): string => (string) $default,
        );
    }

    /**
     * The service "dir" encodes the desired probe behaviour: a numeric string
     * is the HTTP status to return, 'THROW' makes the app fail to boot.
     */
    private function dispatcherWith(array $behaviour): InProcessDispatcher
    {
        $resolver = static function (string $dir, string $b): App {
            if ($dir === 'THROW') {
                throw new \RuntimeException('cannot boot');
            }
            $status = (int) $dir;
            $app = AppFactory::create();
            $app->get('/{path:.*}', static fn ($req, $res) => $res->withStatus($status));
            return $app;
        };
        $map = [];
        foreach ($behaviour as $prefix => $dir) {
            $map[$prefix] = [$dir, 'X'];
        }
        return new InProcessDispatcher($map, $resolver);
    }

    public function testAllHealthyReturns200(): void
    {
        $dispatcher = $this->dispatcherWith([
            'auth' => '200', 'customer' => '200', 'frontend' => '200',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertCount(3, $body['services']);
    }

    public function testOneUnhealthyReturns503(): void
    {
        $dispatcher = $this->dispatcherWith([
            'auth' => '200', 'customer' => '200', 'frontend' => '500',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertFalse($body['services']['/frontend']['ok']);
        self::assertSame(500, $body['services']['/frontend']['status']);
        self::assertTrue($body['services']['/auth']['ok']);
    }

    public function testBootFailureCountsAsStatusZero(): void
    {
        $dispatcher = $this->dispatcherWith([
            'auth' => '200', 'customer' => '200', 'frontend' => 'THROW',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(0, $body['services']['/frontend']['status']);
        self::assertCount(3, $body['services']);
    }
    public function testBootFailureLogsWhyButNeverReturnsIt(): void
    {
        // `status: 0` on its own says a service is down and nothing about the
        // cause — and this path never reaches DispatchAction, which is the only
        // other place that logs a dispatch failure. Diagnosing "/frontend is at
        // status 0" in production meant guessing between a missing directory, an
        // unreadable vendor/ and a fatal during boot.
        $logFile = sys_get_temp_dir() . '/gw-health-' . bin2hex(random_bytes(6)) . '.log';
        $logger = new Logger($logFile, 'warning');

        $dispatcher = $this->dispatcherWith([
            'auth' => '200', 'customer' => '200', 'frontend' => 'THROW',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher, $logger);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        $body = (string) $response->getBody();
        // The reason is diagnostic detail with absolute paths in it, and /healthz
        // is public and unauthenticated. It goes to the log, never to the caller.
        self::assertStringNotContainsString('cannot boot', $body);

        $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        @unlink($logFile);
        self::assertStringContainsString('cannot boot', $log, 'the failure reason was not logged');
        self::assertStringContainsString('/frontend', $log);
    }
}
