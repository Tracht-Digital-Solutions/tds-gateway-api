<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\HealthAction;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Http\ProxyResponse;
use Tds\ApiGateway\Tests\Support\FakeProxyClient;

final class HealthActionTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return ServiceRegistry::fromEnv(
            static fn (string $key, ?string $default = null): string => (string) $default,
        );
    }

    public function testAllHealthyReturns200(): void
    {
        $client = new FakeProxyClient(new ProxyResponse(200, [], 'ok'));
        $action = new HealthAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/healthz');
        $response = $action($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertCount(4, $body['services']);
    }

    public function testOneUnhealthyReturns503(): void
    {
        $client = new FakeProxyClient(new ProxyResponse(200, [], 'ok'));
        // contact reports a 500 on its health check.
        $client->whenUrlContains(':8002', new ProxyResponse(500, [], 'down'));
        $action = new HealthAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/healthz');
        $response = $action($request, new Response());

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertFalse($body['services']['/contact']['ok']);
        self::assertTrue($body['services']['/auth']['ok']);
    }
}
