<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Action;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\ApiGateway\Action\WikiAction;
use Tds\ApiGateway\Support\AdminSessionVerifier;

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

    /**
     * Build a WikiAction. `adminToken` gates the legacy path; `me` (when set)
     * is the stubbed auth-api /me body used to verify a session cookie without
     * touching the network.
     */
    private function action(string $adminToken, ?array $me = null): WikiAction
    {
        $verifier = new AdminSessionVerifier(
            $adminToken,
            $me === null ? '' : 'https://auth.example/auth',
            fn (string $cookie): ?array => $me,
        );
        return new WikiAction($verifier, $this->dir);
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

    public function testDisabledWhenNoCredentialConfigured(): void
    {
        $res = $this->get($this->action(''));
        self::assertSame(404, $res->getStatusCode());
    }

    public function testShowsLoginFormWithoutToken(): void
    {
        $res = $this->get($this->action('secret'));
        self::assertSame(401, $res->getStatusCode());
        self::assertStringContainsString('Anmelden', (string) $res->getBody());
        self::assertStringNotContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testRejectsWrongToken(): void
    {
        $res = $this->get($this->action('secret'), ['bearer' => 'nope']);
        self::assertSame(401, $res->getStatusCode());
    }

    public function testServesWikiWithBearerToken(): void
    {
        $res = $this->get($this->action('secret'), ['bearer' => 'secret']);
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testServesWikiWithLegacyCookie(): void
    {
        $res = $this->get($this->action('secret'), ['cookie' => ['tds_wiki' => 'secret']]);
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testServesWikiWithAdminSessionCookie(): void
    {
        // No ADMIN_TOKEN; auth is the tds_session cookie verified via /me.
        $res = $this->get(
            $this->action('', ['isAdmin' => true]),
            ['cookie' => ['tds_session' => 'jwt-value']],
        );
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('WIKI-OK', (string) $res->getBody());
    }

    public function testRejectsNonAdminSessionCookie(): void
    {
        $res = $this->get(
            $this->action('', ['isAdmin' => false]),
            ['cookie' => ['tds_session' => 'jwt-value']],
        );
        self::assertSame(401, $res->getStatusCode());
    }

    public function testQueryTokenSetsCookieAndRedirects(): void
    {
        $res = $this->get($this->action('secret'), ['query' => ['token' => 'secret']]);
        self::assertSame(303, $res->getStatusCode());
        self::assertSame('/wiki', $res->getHeaderLine('Location'));
        self::assertStringContainsString('tds_wiki=', $res->getHeaderLine('Set-Cookie'));
    }

    public function testAuthedButMissingWikiFileReturns503(): void
    {
        // Remove the generated wiki so only the fallback path remains.
        @unlink($this->dir . '/wiki/index.html');
        @rmdir($this->dir . '/wiki');

        $res = $this->get($this->action('secret'), ['bearer' => 'secret']);
        self::assertSame(503, $res->getStatusCode());
        self::assertStringContainsString('bin/gen-api-wiki.php', (string) $res->getBody());
    }
}
