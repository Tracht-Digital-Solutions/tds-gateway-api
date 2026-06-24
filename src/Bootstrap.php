<?php
declare(strict_types=1);

namespace Tds\ApiGateway;

use DI\Container;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\ApiGateway\Action\HealthAction;
use Tds\ApiGateway\Action\IndexAction;
use Tds\ApiGateway\Action\ProxyAction;
use Tds\ApiGateway\Action\WikiAction;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Http\CurlProxyClient;
use Tds\ApiGateway\Support\Logger;

final class Bootstrap
{
    public static function createApp(string $rootDir): App
    {
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $env = static fn (string $key, ?string $default = null): string => self::env($key, $default);

        $container = new Container();

        $container->set(ServiceRegistry::class, static fn () => ServiceRegistry::fromEnv($env));

        // Structured file logger (default <root>/logs/gateway.log). Makes the
        // cURL errno behind a 502 actually readable on the Plesk host, where
        // PHP's default error_log sink is effectively invisible.
        $container->set(Logger::class, static fn () => Logger::fromEnv($env, $rootDir));

        // Long-timeout client for proxied traffic (uploads, Stripe, etc.).
        $container->set('proxy.client', static fn () => new CurlProxyClient(
            connectTimeout: (int) $env('GATEWAY_CONNECT_TIMEOUT', '2'),
            timeout: (int) $env('GATEWAY_TIMEOUT', '30'),
        ));

        // Short-timeout client so /healthz can't hang on a dead upstream.
        $container->set('health.client', static fn () => new CurlProxyClient(
            connectTimeout: (int) $env('GATEWAY_HEALTH_CONNECT_TIMEOUT', '1'),
            timeout: (int) $env('GATEWAY_HEALTH_TIMEOUT', '2'),
        ));

        $container->set(ProxyAction::class, static fn (Container $c) => new ProxyAction(
            $c->get(ServiceRegistry::class),
            $c->get('proxy.client'),
            $c->get(Logger::class),
        ));
        $container->set(HealthAction::class, static fn (Container $c) => new HealthAction(
            $c->get(ServiceRegistry::class),
            $c->get('health.client'),
            $c->get(Logger::class),
        ));
        $container->set(IndexAction::class, static fn (Container $c) => new IndexAction(
            $c->get(ServiceRegistry::class),
        ));

        // Internal API wiki, gated by the shared ADMIN_TOKEN (disabled when
        // unset). Serves the generated wiki/index.html from the bundle.
        $container->set(WikiAction::class, static fn () => new WikiAction(
            $env('ADMIN_TOKEN', ''),
            $rootDir,
        ));

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($env('APP_ENV', 'production') !== 'production', true, true);

        $app->get('/', IndexAction::class);
        $app->get('/healthz', HealthAction::class);
        // Internal, login-gated API wiki (not proxied).
        $app->get('/wiki', WikiAction::class);
        // Everything else is proxied. FastRoute prefers the static routes
        // above over this variable catch-all.
        $app->map(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            '/{path:.*}',
            ProxyAction::class,
        );

        return $app;
    }

    /**
     * Env reader. NB: explicit `?? false` checks — never
     * `$_ENV[$key] ?? getenv($key) ?: $default`, which clobbers falsy values
     * because `??` binds tighter than `?:` (the bug that bit all four APIs).
     */
    private static function env(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? false;
        if ($value === false) {
            $value = getenv($key);
        }
        if ($value === false) {
            $value = $default;
        }
        if ($value === null) {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return (string) $value;
    }
}
