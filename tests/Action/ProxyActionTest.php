<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\ProxyAction;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Http\ProxyResponse;
use Tds\ApiGateway\Tests\Support\FakeProxyClient;

final class ProxyActionTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return ServiceRegistry::fromEnv(
            static fn (string $key, ?string $default = null): string => (string) $default,
        );
    }

    public function testForwardsToCorrectUpstreamWithBodyAndQuery(): void
    {
        $client = new FakeProxyClient(new ProxyResponse(201, ['Content-Type' => ['application/json']], '{"ok":true}'));
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://api.tracht-digital.de/auth/admin/login?x=1')
            ->withHeader('Authorization', 'Bearer token')
            ->withHeader('Host', 'api.tracht-digital.de');
        $request->getBody()->write('{"a":1}');

        $response = $action($request, new Response());

        self::assertSame('POST', $client->method);
        self::assertSame('http://127.0.0.1:8003/admin/login?x=1', $client->url);
        self::assertSame('{"a":1}', $client->body);
        // Host is stripped; forwarded headers added.
        self::assertArrayNotHasKey('Host', $client->headers);
        self::assertSame(['api.tracht-digital.de'], $client->headers['X-Forwarded-Host']);
        self::assertSame(['/auth'], $client->headers['X-Forwarded-Prefix']);
        self::assertSame(['Bearer token'], $client->headers['Authorization']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testMirrorsStatusAndMultipleSetCookieHeaders(): void
    {
        $client = new FakeProxyClient(new ProxyResponse(
            200,
            ['Set-Cookie' => ['a=1', 'b=2'], 'Content-Length' => ['5']],
            'hello',
        ));
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/content/blog');
        $response = $action($request, new Response());

        self::assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame('hello', (string) $response->getBody());
    }

    public function testUnknownServiceReturns404(): void
    {
        $client = new FakeProxyClient();
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/nope/x');
        $response = $action($request, new Response());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $client->calls);
    }

    public function testUpstreamFailureReturns502(): void
    {
        $client = new FakeProxyClient();
        $client->willThrow();
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/customer/admin/projects');
        $response = $action($request, new Response());

        self::assertSame(502, $response->getStatusCode());
    }

    public function testForwardsOptionsPreflightToUpstream(): void
    {
        $client = new FakeProxyClient(new ProxyResponse(204, ['Access-Control-Allow-Origin' => ['*']], ''));
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', 'https://api.tracht-digital.de/contact');
        $response = $action($request, new Response());

        self::assertSame('OPTIONS', $client->method);
        self::assertSame('http://127.0.0.1:8002/contact', $client->url);
        self::assertSame(204, $response->getStatusCode());
        // CORS headers from the upstream are mirrored, not synthesised here.
        self::assertSame(['*'], $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function testPreservesContentEncodingWhileDroppingLength(): void
    {
        $gz = (string) gzencode('payload');
        $client = new FakeProxyClient(new ProxyResponse(
            200,
            ['Content-Encoding' => ['gzip'], 'Content-Length' => [(string) strlen($gz)]],
            $gz,
        ));
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/content/blog');
        $response = $action($request, new Response());

        // The compressed bytes pass through untouched; Content-Length is dropped
        // so the emitter recomputes it.
        self::assertSame(['gzip'], $response->getHeader('Content-Encoding'));
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame($gz, (string) $response->getBody());
    }

    public function testForwardedForAppendsRemoteToExistingChain(): void
    {
        $client = new FakeProxyClient();
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/auth/me', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('X-Forwarded-For', '198.51.100.1');
        $action($request, new Response());

        self::assertSame(['198.51.100.1, 203.0.113.7'], $client->headers['X-Forwarded-For']);
    }

    public function testForwardedForUsesRemoteWhenNoChain(): void
    {
        $client = new FakeProxyClient();
        $action = new ProxyAction($this->registry(), $client);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.tracht-digital.de/auth/me', ['REMOTE_ADDR' => '203.0.113.7']);
        $action($request, new Response());

        self::assertSame(['203.0.113.7'], $client->headers['X-Forwarded-For']);
    }
}
