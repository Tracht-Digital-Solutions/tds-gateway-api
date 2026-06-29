<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Dispatch;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\ApiGateway\Dispatch\DispatchException;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;

final class InProcessDispatcherTest extends TestCase
{
    /** @var string[] */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            @unlink($dir . '/.env');
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    private function serviceDirWithEnv(string $body): string
    {
        $dir = sys_get_temp_dir() . '/tds-gw-svc-' . bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.env', $body);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /** Resolver mimicking a real service: loads its .env, then echoes a var. */
    private function echoEnvResolver(): callable
    {
        return static function (string $dir, string $bootstrap): App {
            \Dotenv\Dotenv::createImmutable($dir)->load();
            $value = $_ENV['SHARED_KEY'] ?? 'MISSING';
            $app = AppFactory::create();
            $app->map(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                '/{path:.*}',
                static function ($req, $res) use ($value) {
                    $res->getBody()->write($value);
                    return $res;
                },
            );
            return $app;
        };
    }

    public function testDispatchesToServiceApp(): void
    {
        $dir = $this->serviceDirWithEnv("SHARED_KEY=alpha\n");
        $dispatcher = new InProcessDispatcher(['a' => [$dir, 'X']], $this->echoEnvResolver());

        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/x/y');
        $res = $dispatcher->dispatch($req, 'a', '/y');

        self::assertSame('alpha', (string) $res->getBody());
    }

    public function testEnvDoesNotBleedBetweenServices(): void
    {
        $dirA = $this->serviceDirWithEnv("SHARED_KEY=alpha\n");
        $dirB = $this->serviceDirWithEnv("SHARED_KEY=beta\n");
        $dispatcher = new InProcessDispatcher(
            ['a' => [$dirA, 'X'], 'b' => [$dirB, 'X']],
            $this->echoEnvResolver(),
        );
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/x');

        // Without the env scope, the immutable loader would keep 'alpha' for b.
        self::assertSame('alpha', (string) $dispatcher->dispatch($req, 'a', '/')->getBody());
        self::assertSame('beta', (string) $dispatcher->dispatch($req, 'b', '/')->getBody());
        self::assertSame('alpha', (string) $dispatcher->dispatch($req, 'a', '/')->getBody());
    }

    public function testRestoresPriorEnvAndLeavesNoServiceKeys(): void
    {
        $dir = $this->serviceDirWithEnv("SHARED_KEY=alpha\nONLY_IN_SERVICE=1\n");
        $_ENV['SHARED_KEY'] = 'host-value';
        putenv('SHARED_KEY=host-value');

        $dispatcher = new InProcessDispatcher(['a' => [$dir, 'X']], $this->echoEnvResolver());
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/x');
        $dispatcher->dispatch($req, 'a', '/');

        // A pre-existing key is restored to its original value…
        self::assertSame('host-value', $_ENV['SHARED_KEY']);
        self::assertSame('host-value', getenv('SHARED_KEY'));
        // …and a key only the service defined is gone again.
        self::assertArrayNotHasKey('ONLY_IN_SERVICE', $_ENV);
        self::assertFalse(getenv('ONLY_IN_SERVICE'));

        unset($_ENV['SHARED_KEY']);
        putenv('SHARED_KEY');
    }

    public function testRewritesSubRequestPath(): void
    {
        $dir = $this->serviceDirWithEnv("SHARED_KEY=x\n");
        $capturedPath = null;
        $resolver = static function (string $d, string $b) use (&$capturedPath): App {
            $app = AppFactory::create();
            $app->map(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                '/{path:.*}',
                static function ($req, $res) use (&$capturedPath) {
                    $capturedPath = $req->getUri()->getPath();
                    return $res;
                },
            );
            return $app;
        };
        $dispatcher = new InProcessDispatcher(['auth' => [$dir, 'X']], $resolver);
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://api/auth/admin/login');
        $dispatcher->dispatch($req, 'auth', '/admin/login');

        self::assertSame('/admin/login', $capturedPath);
    }

    public function testUnknownPrefixThrows(): void
    {
        $dispatcher = new InProcessDispatcher([], $this->echoEnvResolver());
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/x');

        $this->expectException(DispatchException::class);
        $dispatcher->dispatch($req, 'nope', '/');
    }

    public function testResolverThrowIsWrappedAndEnvRestored(): void
    {
        $dir = $this->serviceDirWithEnv("SHARED_KEY=alpha\n");
        $resolver = static function (): App {
            throw new \RuntimeException('boom');
        };
        $dispatcher = new InProcessDispatcher(['a' => [$dir, 'X']], $resolver);
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://api/x');

        try {
            $dispatcher->dispatch($req, 'a', '/');
            self::fail('expected DispatchException');
        } catch (DispatchException $e) {
            self::assertStringContainsString('boom', $e->getMessage());
        }

        // Even on failure the env scope is unwound.
        self::assertArrayNotHasKey('SHARED_KEY', $_ENV);
        self::assertFalse(getenv('SHARED_KEY'));
    }
}
