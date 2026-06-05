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

    public function testContactRewriteKeepsItsOwnPath(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());

        $match = $registry->match('/contact');
        self::assertNotNull($match);
        [$service, $remainder] = $match;
        self::assertSame('contact', $service->prefix);
        self::assertSame('', $remainder);
        // The frontend POSTs to .../contact and contact-api's route is /contact.
        self::assertSame('http://127.0.0.1:8002/contact', $service->targetFor($remainder));
    }

    public function testRootServiceTargetStripsPrefix(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
        [$service, $remainder] = $registry->match('/content/blog');

        self::assertSame('http://127.0.0.1:8001/blog', $service->targetFor($remainder));
    }

    public function testQueryStringIsAppended(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
        [$service, $remainder] = $registry->match('/content/blog');

        self::assertSame(
            'http://127.0.0.1:8001/blog?tag=astro',
            $service->targetFor($remainder, 'tag=astro'),
        );
    }

    public function testUnknownPrefixReturnsNull(): void
    {
        $registry = ServiceRegistry::fromEnv($this->defaultEnv());
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
        self::assertSame('http://127.0.0.1:8002/healthz', $registry->get('contact')->healthUrl());
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
}
