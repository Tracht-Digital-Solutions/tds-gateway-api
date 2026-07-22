<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\IndexAction;
use Tds\ApiGateway\Config\ServiceRegistry;

final class IndexActionTest extends TestCase
{
    public function testListsServicePrefixesWithoutLeakingUpstreams(): void
    {
        $registry = ServiceRegistry::fromEnv(
            static fn (string $key, ?string $default = null): string => (string) $default,
        );
        $action = new IndexAction($registry);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/');
        $response = $action($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tds-api-gateway', $body['name']);
        $prefixes = array_column($body['services'], 'prefix');
        self::assertSame(['/auth', '/customer', '/frontend'], $prefixes);
        // No internal host:port leaked.
        self::assertStringNotContainsString('127.0.0.1', (string) $response->getBody());
    }
}
