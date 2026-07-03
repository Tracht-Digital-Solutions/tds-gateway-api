<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\WikiDataAction;
use Tds\ApiGateway\Support\AdminSessionVerifier;

final class WikiDataActionTest extends TestCase
{
    private const ORIGIN = 'https://management.tracht-digital.de';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tds-wiki-json-' . uniqid();
        mkdir($this->dir . '/wiki', 0775, true);
        file_put_contents($this->dir . '/wiki/data.json', '{"totalRoutes":3,"services":[]}');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/wiki/data.json');
        @rmdir($this->dir . '/wiki');
        @rmdir($this->dir);
    }

    private function action(string $adminToken): WikiDataAction
    {
        return new WikiDataAction(
            new AdminSessionVerifier($adminToken, ''),
            $this->dir,
            [self::ORIGIN],
        );
    }

    private function call(WikiDataAction $action, string $method, array $opts = []): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, 'https://api.tracht-digital.de/wiki.json');
        if (isset($opts['origin'])) {
            $req = $req->withHeader('Origin', $opts['origin']);
        }
        if (isset($opts['bearer'])) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $opts['bearer']);
        }
        /** @var Response $res */
        $res = $action($req, new Response());
        return $res;
    }

    public function testDisabledWhenNoCredentialConfigured(): void
    {
        $res = $this->call($this->action(''), 'GET');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testUnauthorizedWithoutToken(): void
    {
        $res = $this->call($this->action('secret'), 'GET');
        self::assertSame(401, $res->getStatusCode());
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    public function testServesJsonWithBearerToken(): void
    {
        $res = $this->call($this->action('secret'), 'GET', ['bearer' => 'secret', 'origin' => self::ORIGIN]);
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('totalRoutes', (string) $res->getBody());
        self::assertSame(self::ORIGIN, $res->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $res->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testPreflightReturns204WithCorsHeaders(): void
    {
        $res = $this->call($this->action('secret'), 'OPTIONS', ['origin' => self::ORIGIN]);
        self::assertSame(204, $res->getStatusCode());
        self::assertSame(self::ORIGIN, $res->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('GET', $res->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testDisallowedOriginGetsNoCorsHeader(): void
    {
        $res = $this->call($this->action('secret'), 'GET', ['bearer' => 'secret', 'origin' => 'https://evil.example']);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('', $res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testMissingDataFileReturns503(): void
    {
        @unlink($this->dir . '/wiki/data.json');
        @rmdir($this->dir . '/wiki');

        $res = $this->call($this->action('secret'), 'GET', ['bearer' => 'secret']);
        self::assertSame(503, $res->getStatusCode());
        self::assertStringContainsString('gen-api-wiki.php', (string) $res->getBody());
    }
}
