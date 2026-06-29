<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Dispatch;

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

/**
 * Runs a TDS micro-backend *inside the gateway's own PHP process* instead of
 * proxying to a loopback `php -S`. This is what lets the whole API surface run
 * as a single PHP-FPM application on Plesk — there are no service processes to
 * start or keep alive.
 *
 * Each service exposes the same contract —
 *   Tds\<Name>Api\Bootstrap::createApp(string $rootDir): Slim\App
 * — and we drive it with Slim's RequestHandlerInterface::handle().
 *
 * Two hazards are handled here so the four services stay byte-for-byte
 * identical to their standalone (`composer start`) form:
 *
 *  1. Autoloading — each service ships its own vendor/. We require the target
 *     service's autoloader on demand; service namespaces are disjoint
 *     (Tds\AuthApi\… vs Tds\CustomerApi\…) and the shared libraries (Slim,
 *     php-di, phpdotenv) load once and are reused. The bundle is assembled from
 *     all repos at once with identical version constraints, so the per-service
 *     copies of the shared libs are the same version — "first loaded wins" is
 *     therefore safe.
 *
 *  2. Env isolation — every service's Bootstrap does
 *     Dotenv::createImmutable($rootDir)->load() and reads $_ENV/getenv. A reused
 *     FPM worker keeps those globals between requests, and an *immutable* loader
 *     will not overwrite a key that is already set, so a later /customer request
 *     would otherwise see the previous /auth request's DB_NAME. Each dispatch is
 *     wrapped in a surgical env scope: clear exactly the keys the target
 *     service's .env declares (so it loads fresh) and restore their prior state
 *     in a finally.
 */
final class InProcessDispatcher
{
    /** @var callable(string $serviceDir, string $bootstrapClass): App */
    private $appResolver;

    /**
     * @param array<string, array{0: string, 1: string}> $services
     *        prefix => [absolute service dir, Bootstrap FQCN]
     * @param (callable(string $serviceDir, string $bootstrapClass): App)|null $appResolver
     *        Override how a service App is built (tests inject a fake). The
     *        default requires the service's vendor/autoload.php and calls its
     *        Bootstrap::createApp().
     */
    public function __construct(
        private readonly array $services,
        ?callable $appResolver = null,
    ) {
        $this->appResolver = $appResolver ?? self::defaultResolver(...);
    }

    public function knows(string $prefix): bool
    {
        return isset($this->services[$prefix]);
    }

    /**
     * Build a sub-request for $prefix at the service-local $path and run it
     * through the service's Slim app in-process.
     *
     * @throws DispatchException when the service is unknown or its app cannot be
     *         built/handled.
     */
    public function dispatch(ServerRequestInterface $request, string $prefix, string $path): ResponseInterface
    {
        $entry = $this->services[$prefix] ?? null;
        if ($entry === null) {
            throw new DispatchException("No in-process service registered for '{$prefix}'.");
        }
        [$dir, $bootstrap] = $entry;

        $sub = $request->withUri($request->getUri()->withPath($path));

        $restore = $this->enterEnvScope($dir);
        try {
            $app = ($this->appResolver)($dir, $bootstrap);
            return $app->handle($sub);
        } catch (DispatchException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DispatchException(
                "In-process dispatch to '{$prefix}' failed: " . $e->getMessage(),
                0,
                $e,
            );
        } finally {
            $restore();
        }
    }

    /**
     * Clear the keys the service's .env declares — capturing each key's prior
     * $_ENV / $_SERVER / getenv() state — and return a closure that restores
     * them exactly. Parsing is side-effect free (Dotenv::parse, not ->load()).
     */
    private function enterEnvScope(string $serviceDir): callable
    {
        $envFile = $serviceDir . '/.env';
        $keys = is_file($envFile)
            ? array_keys(Dotenv::parse((string) file_get_contents($envFile)))
            : [];

        /** @var array<string, array{0: bool, 1: mixed, 2: bool, 3: mixed, 4: string|false}> $prior */
        $prior = [];
        foreach ($keys as $key) {
            $prior[$key] = [
                array_key_exists($key, $_ENV), $_ENV[$key] ?? null,
                array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null,
                getenv($key),
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        return static function () use ($prior): void {
            foreach ($prior as $key => [$hadEnv, $envVal, $hadServer, $serverVal, $getenvVal]) {
                if ($hadEnv) {
                    $_ENV[$key] = $envVal;
                } else {
                    unset($_ENV[$key]);
                }
                if ($hadServer) {
                    $_SERVER[$key] = $serverVal;
                } else {
                    unset($_SERVER[$key]);
                }
                if ($getenvVal === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $getenvVal);
                }
            }
        };
    }

    private static function defaultResolver(string $serviceDir, string $bootstrapClass): App
    {
        $autoload = $serviceDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new DispatchException("Service autoloader missing: {$autoload}");
        }
        require_once $autoload;
        if (!class_exists($bootstrapClass)) {
            throw new DispatchException("Service bootstrap not found: {$bootstrapClass}");
        }
        /** @var App $app */
        $app = $bootstrapClass::createApp($serviceDir);
        return $app;
    }
}
