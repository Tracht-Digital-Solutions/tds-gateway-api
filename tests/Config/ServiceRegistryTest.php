<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Config;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Config\ServiceRegistry;

final class ServiceRegistryTest extends TestCase
{
    private function defaultEnv(): callable
    {
        // Returns the default for every key (no overrides).
        return static fn (string $key, ?string $default = null): string => (string) $default;
    }

    public function testMatchesPrefixAndStripsRemainder(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());

        $match = $registry->match('/auth/admin/login');
        self::assertNotNull($match);
        [$service, $remainder] = $match;
        self::assertSame('auth', $service->prefix);
        self::assertSame('/admin/login', $remainder);
    }

    public function testUnmatchedPathFallsThroughToDefaultVerbatim(): void
    {
        // The composed frontend API (`frontend`) is the default upstream: a path
        // that matches no prefix is forwarded whole, nothing stripped.
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());

        $match = $registry->match('/tools/catalog');
        self::assertNotNull($match);
        [$service, $remainder] = $match;
        self::assertSame('frontend', $service->prefix);
        self::assertTrue($service->isDefault);
        self::assertSame('/tools/catalog', $remainder);
        self::assertSame('http://127.0.0.1:8100/tools/catalog', $service->targetFor($remainder));
    }

    public function testRootPathMapsToDefaultRoot(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
        $match = $registry->match('/');
        self::assertNotNull($match);
        [$service, $remainder] = $match;
        self::assertSame('frontend', $service->prefix);
        self::assertSame('/', $remainder);
    }

    public function testCustomerRootServiceTargetStripsPrefix(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
        [$service, $remainder] = $registry->match('/customer/projects');

        self::assertSame('http://127.0.0.1:8004/projects', $service->targetFor($remainder));
    }

    public function testQueryStringIsAppended(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
        [$service, $remainder] = $registry->match('/customer/projects');

        self::assertSame(
            'http://127.0.0.1:8004/projects?tag=astro',
            $service->targetFor($remainder, 'tag=astro'),
        );
    }

    public function testUnknownPrefixReturnsNullWhenDefaultDisabled(): void
    {
        $env = static fn (string $key, ?string $default = null): string =>
            $key === 'GATEWAY_DEFAULT_SERVICE' ? '' : (string) $default;
        $registry = ServiceRegistry::fromEnv($env);
        self::assertNull($registry->match('/nope/here'));
        self::assertNull($registry->match('/'));
    }

    public function testEnvOverridesUpstreamAndTrimsTrailingSlash(): void
    {
        $env = static function (string $key, ?string $default = null): string {
            return match ($key) {
                'AUTH_UPSTREAM' => 'http://auth.internal:9000/',
                default => (string) $default,
            };
        };
        $registry = ServiceRegistry::fromEnv($env);
        [$service, $remainder] = $registry->match('/auth/refresh');

        self::assertSame('http://auth.internal:9000/refresh', $service->targetFor($remainder));
    }

    public function testHealthUrlAlwaysHitsUpstreamRoot(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
        self::assertSame('http://127.0.0.1:8004/healthz', $registry->get('customer')->healthUrl());
        // get() resolves the default service by name too.
        self::assertSame('http://127.0.0.1:8100/healthz', $registry->get('frontend')->healthUrl());
    }

    public function testCustomServiceWithoutUpstreamThrows(): void
    {
        $env = static fn (string $key, ?string $default = null): string => match ($key) {
            'GATEWAY_SERVICES' => 'auth,billing',
            default => (string) $default,
        };

        $this->expectException(\RuntimeException::class);
        ServiceRegistry::fromEnv($env);
    }

    public function testCustomServiceWithUpstreamRoutes(): void
    {
        $env = static fn (string $key, ?string $default = null): string => match ($key) {
            'GATEWAY_SERVICES' => 'auth,reports',
            'REPORTS_UPSTREAM' => 'http://127.0.0.1:9100',
            default => (string) $default,
        };

        $registry = ServiceRegistry::fromEnv($env);
        $match = $registry->match('/reports/daily');
        self::assertNotNull($match);
        [$service, $remainder] = $match;
        self::assertSame('reports', $service->prefix);
        self::assertSame('/daily', $remainder);
        self::assertSame(
            'http://127.0.0.1:9100/daily?range=7d',
            $service->targetFor($remainder, 'range=7d'),
        );
    }
}
