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
            'auth' => '200', 'contact' => '200', 'content' => '200', 'customer' => '200',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertCount(4, $body['services']);
    }

    public function testOneUnhealthyReturns503(): void
    {
        $dispatcher = $this->dispatcherWith([
            'auth' => '200', 'contact' => '500', 'content' => '200', 'customer' => '200',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertFalse($body['services']['/contact']['ok']);
        self::assertSame(500, $body['services']['/contact']['status']);
        self::assertTrue($body['services']['/auth']['ok']);
    }

    public function testBootFailureCountsAsStatusZero(): void
    {
        $dispatcher = $this->dispatcherWith([
            'auth' => '200', 'contact' => 'THROW', 'content' => '200', 'customer' => '200',
        ]);
        $action = new InProcessHealthAction($this->registry(), $dispatcher);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/healthz');
        $response = $action($request, new Response());

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(0, $body['services']['/contact']['status']);
        self::assertCount(4, $body['services']);
    }
}
