<?php
declare(strict_types=1);

namespace Tds\ApiGateway;

use DI\Container;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\ApiGateway\Action\DispatchAction;
use Tds\ApiGateway\Action\HealthAction;
use Tds\ApiGateway\Action\IndexAction;
use Tds\ApiGateway\Action\InProcessHealthAction;
use Tds\ApiGateway\Action\ProxyAction;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;
use Tds\ApiGateway\Http\CurlProxyClient;
use Tds\ApiGateway\Http\RobotsTagMiddleware;
use Tds\ApiGateway\Support\Logger;
use Tds\ApiGateway\Support\MigrationRunner;

final class Bootstrap
{
    /**
     * prefix => the service's Bootstrap FQCN, used by in-process mode to build
     * each service's Slim app from its bundled vendor/ (services/<name>/).
     *
     * `frontend` is `tds-core-frontend-api` — the composed base + extensions
     * that replaced the archived content/contact backends. It is the default
     * (catch-all) upstream; its module routes live at root.
     */
    private const SERVICE_BOOTSTRAPS = [
        'auth' => 'Tds\\AuthApi\\Bootstrap',
        'customer' => 'Tds\\CustomerApi\\Bootstrap',
        'frontend' => 'Tds\\CoreFrontendApi\\Bootstrap',
    ];

    /**
     * Services the gateway auto-migrates on the first request after a deploy.
     * `frontend` is intentionally excluded: `tds-core-frontend-api` runs its
     * OWN in-process migrator (over its composed extensions' migration paths)
     * when its app is first built — the gateway can't drive it via a single
     * per-service `db/migrations` dir + `phinx.php`.
     */
    private const AUTO_MIGRATE_SERVICES = ['auth', 'customer'];

    public static function createApp(string $rootDir): App
    {
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $env = static fn (string $key, ?string $default = null): string => self::env($key, $default);

        // inprocess (default): run each service's Slim app inside this process —
        // a single PHP-FPM app, nothing to start. proxy: relay over HTTP to the
        // loopback `php -S` services (the supervisor/nginx/Docker run modes).
        $inProcess = strtolower(trim($env('GATEWAY_MODE', 'inprocess'))) !== 'proxy';

        $container = new Container();

        $container->set(ServiceRegistry::class, static fn () => ServiceRegistry::fromEnv($env));

        // Structured file logger (default <root>/logs/gateway.log). Makes the
        // reason behind a 502 actually readable on the Plesk host, where PHP's
        // default error_log sink is effectively invisible.
        $container->set(Logger::class, static fn () => Logger::fromEnv($env, $rootDir));

        $container->set(IndexAction::class, static fn (Container $c) => new IndexAction(
            $c->get(ServiceRegistry::class),
        ));

        // NB: the internal API wiki is no longer served by the gateway. It now
        // lives in the composed frontend API (`/wiki.json`, introspected from
        // every enabled module's live routes) and is rendered by the admin
        // frontend's Wiki page. `/wiki` + `/wiki.json` fall through the
        // catch-all to `frontend`, so no gateway-owned route intercepts them.

        if ($inProcess) {
            // Services live next to the gateway in the assembled bundle:
            // <bundle>/gateway (rootDir) + <bundle>/services/<name>.
            $servicesDir = rtrim(
                $env('GATEWAY_SERVICES_DIR', \dirname($rootDir) . '/services'),
                '/\\',
            );

            $container->set(InProcessDispatcher::class, static function (Container $c) use ($servicesDir) {
                $services = [];
                foreach ($c->get(ServiceRegistry::class)->all() as $service) {
                    $bootstrap = self::SERVICE_BOOTSTRAPS[$service->name] ?? null;
                    if ($bootstrap === null) {
                        continue;
                    }
                    $services[$service->prefix] = [$servicesDir . '/' . $service->name, $bootstrap];
                }
                return new InProcessDispatcher($services);
            });

            $container->set(DispatchAction::class, static fn (Container $c) => new DispatchAction(
                $c->get(ServiceRegistry::class),
                $c->get(InProcessDispatcher::class),
                $c->get(Logger::class),
            ));
            $container->set(InProcessHealthAction::class, static fn (Container $c) => new InProcessHealthAction(
                $c->get(ServiceRegistry::class),
                $c->get(InProcessDispatcher::class),
                $c->get(Logger::class),
            ));

            $catchAll = DispatchAction::class;
            $healthAction = InProcessHealthAction::class;
        } else {
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

            $catchAll = ProxyAction::class;
            $healthAction = HealthAction::class;
        }

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($env('APP_ENV', 'production') !== 'production', true, true);

        // API responses are machine-to-machine — never index them. Added *after*
        // the error middleware (Slim middleware is LIFO, so this runs outermost)
        // so the header also lands on error responses and on everything the
        // catch-all dispatches to the in-process backends. Pairs with
        // public/robots.txt (crawl block) as belt-and-suspenders; proxy/nginx
        // prefix-routing mode sets the same header via deploy/nginx.conf.example.
        $app->add(new RobotsTagMiddleware());

        $app->get('/', IndexAction::class);
        $app->get('/healthz', $healthAction);
        // Everything else goes to the active backend (in-process dispatch or
        // HTTP proxy). FastRoute prefers the static routes above over this
        // variable catch-all.
        $app->map(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            '/{path:.*}',
            $catchAll,
        );

        return $app;
    }

    /**
     * Best-effort auto-migration, called from the entry point (public/index.php)
     * — NOT from createApp(), so unit tests that build the app never shell out.
     *
     * Only runs in the default in-process mode (proxy mode's services own their
     * own migrations) and can be disabled with GATEWAY_AUTO_MIGRATE=0. Guarded
     * so it applies pending migrations at most once per deployed migration-set;
     * see {@see MigrationRunner}. Assumes createApp() already loaded the .env.
     */
    public static function autoMigrate(string $rootDir): void
    {
        $env = static fn (string $key, ?string $default = null): string => self::env($key, $default);

        $inProcess = strtolower(trim($env('GATEWAY_MODE', 'inprocess'))) !== 'proxy';
        if (!$inProcess || $env('GATEWAY_AUTO_MIGRATE', '1') === '0') {
            return;
        }

        $servicesDir = rtrim($env('GATEWAY_SERVICES_DIR', \dirname($rootDir) . '/services'), '/\\');

        $runner = new MigrationRunner(
            servicesDir: $servicesDir,
            serviceNames: self::AUTO_MIGRATE_SERVICES,
            stateDir: $rootDir . '/var',
            logger: Logger::fromEnv($env, $rootDir),
        );
        $runner->ensureMigrated();
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
