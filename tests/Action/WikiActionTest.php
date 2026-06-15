<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\WikiAction;

final class WikiActionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tds-wiki-' . uniqid();
        mkdir($this->dir . '/wiki', 0775, true);
        file_put_contents($this->dir . '/wiki/index.html', '<!DOCTYPE html><title>WIKI-OK</title>');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/wiki/index.html');
        @rmdir($this->dir . '/wiki');
        @rmdir($this->dir);
    }

    private function get(WikiAction $action, array $opts = []): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.tracht-digital.de/wiki');
        if (isset($opts['bearer'])) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $opts['bearer']);
        }
        if (isset($opts['query'])) {
            $req = $req->withQueryParams($opts['query']);
        }
        if (isset($opts['cookie'])) {
            $req = $req->withCookieParams($opts['cookie']);
        }
        /** @var Response $res */
        $res = $action($req, new Response());
        return $res;
    }

    public function testDisabledWhenNoAdminTokenConfigured(): void
    {
        $res = $this->get(new WikiAction('', $this->dir));
        self::assertSame(404, $res->getStatusCode());
    }

    public function testShowsLoginFormWithoutToken(): void
    {
        $res = $this->get(new WikiAction('secret', $this->dir));
        self::assertSame(401, $res->getStatusCode());
        self::assertStringContainsString('Anmelden', (string) $res->getBody());
        self::assertStringNotContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testRejectsWrongToken(): void
    {
        $res = $this->get(new WikiAction('secret', $this->dir), ['bearer' => 'nope']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testServesWikiWithBearerToken(): void
    {
        $res = $this->get(new WikiAction('secret', $this->dir), ['bearer' => 'secret']);
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testServesWikiWithCookie(): void
    {
        $res = $this->get(new WikiAction('secret', $this->dir), ['cookie' => ['tds_wiki' => 'secret']]);
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testQueryTokenSetsCookieAndRedirects(): void
    {
        $res = $this->get(new WikiAction('secret', $this->dir), ['query' => ['token' => 'secret']]);
        self::assertSame(303, $res->getStatusCode());
        self::assertSame('/wiki', $res->getHeaderLine('Location'));
        self::assertStringContainsString('tds_wiki=', $res->getHeaderLine('Set-Cookie'));
    }
}
