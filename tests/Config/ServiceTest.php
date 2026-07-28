<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Config;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Config\Service;

/**
 * The gateway's routing arithmetic.
 *
 * `api.tracht-digital.de` is the single public front door: the first path
 * segment selects a prefixed backend and is stripped before forwarding, while
 * everything else falls through to the default catch-all (the composed frontend
 * API, whose module routes live at root).
 *
 * A mistake in these three tiny methods sends one backend's request to another
 * — `/auth/admin/login` arriving at the customer API, or a bare prefix hit
 * arriving as an empty path that the upstream router 404s.
 */
final class ServiceTest extends TestCase
{
    private function auth(): Service
    {
        return new Service('auth', 'auth', 'http://127.0.0.1:8003');
    }

    private function frontend(): Service
    {
        return new Service('frontend', '', 'http://127.0.0.1:8005', '', true);
    }

    // --- targetFor(): the proxy-mode URL ----------------------------------

    public function test_builds_the_upstream_url_from_the_remainder(): void
    {
        self::assertSame('http://127.0.0.1:8003/admin/login', $this->auth()->targetFor('/admin/login'));
    }

    public function test_appends_the_query_string_when_there_is_one(): void
    {
        self::assertSame(
            'http://127.0.0.1:8003/admin/users?page=2',
            $this->auth()->targetFor('/admin/users', 'page=2'),
        );
    }

    public function test_adds_NO_question_mark_for_an_empty_query(): void
    {
        // A trailing "?" is not the same URL to every upstream router.
        self::assertSame('http://127.0.0.1:8003/admin/users', $this->auth()->targetFor('/admin/users', ''));
    }

    public function test_a_bare_prefix_hit_targets_the_service_root(): void
    {
        self::assertSame('http://127.0.0.1:8003', $this->auth()->targetFor(''));
    }

    public function test_prepends_the_rewrite_when_a_service_declares_one(): void
    {
        $svc = new Service('legacy', 'legacy', 'http://127.0.0.1:8001', '/v1');
        self::assertSame('http://127.0.0.1:8001/v1/posts', $svc->targetFor('/posts'));
    }

    public function test_forwards_the_default_service_verbatim(): void
    {
        // The catch-all owns the root namespace; nothing may be stripped.
        self::assertSame('http://127.0.0.1:8005/tickets', $this->frontend()->targetFor('/tickets'));
        self::assertSame('http://127.0.0.1:8005/wiki.json', $this->frontend()->targetFor('/wiki.json'));
    }

    public function test_keeps_a_dotted_path_intact(): void
    {
        // `/.well-known/jwks.json` is how every consumer verifies a JWT.
        self::assertSame(
            'http://127.0.0.1:8003/.well-known/jwks.json',
            $this->auth()->targetFor('/.well-known/jwks.json'),
        );
    }

    // --- pathFor(): the in-process dispatch path --------------------------

    public function test_maps_the_remainder_to_the_services_own_path(): void
    {
        self::assertSame('/admin/login', $this->auth()->pathFor('/admin/login'));
    }

    public function test_a_bare_prefix_hit_maps_to_the_service_ROOT_not_an_empty_path(): void
    {
        // An empty path is not routable; Slim needs "/".
        self::assertSame('/', $this->auth()->pathFor(''));
    }

    public function test_pathFor_applies_the_rewrite(): void
    {
        $svc = new Service('legacy', 'legacy', 'http://127.0.0.1:8001', '/v1');
        self::assertSame('/v1/posts', $svc->pathFor('/posts'));
    }

    public function test_pathFor_carries_no_host_and_no_query(): void
    {
        // In-process dispatch hands the path to the service's own router; a
        // host here would be routed as a literal path segment.
        $path = $this->auth()->pathFor('/admin/users');
        self::assertStringNotContainsString('127.0.0.1', $path);
        self::assertStringNotContainsString('?', $path);
    }

    public function test_pathFor_leaves_the_default_service_at_root(): void
    {
        self::assertSame('/tools/catalog', $this->frontend()->pathFor('/tools/catalog'));
    }

    // --- healthUrl() ------------------------------------------------------

    public function test_probes_healthz_at_the_service_root(): void
    {
        self::assertSame('http://127.0.0.1:8003/healthz', $this->auth()->healthUrl());
    }

    public function test_healthz_ignores_the_rewrite(): void
    {
        // Every backend exposes /healthz at its ROOT, not behind its rewrite.
        $svc = new Service('legacy', 'legacy', 'http://127.0.0.1:8001', '/v1');
        self::assertSame('http://127.0.0.1:8001/healthz', $svc->healthUrl());
    }

    // --- the value object itself ------------------------------------------

    public function test_defaults_to_no_rewrite_and_not_default(): void
    {
        $svc = $this->auth();
        self::assertSame('', $svc->rewrite);
        self::assertFalse($svc->isDefault);
    }

    public function test_carries_its_name_and_prefix(): void
    {
        $svc = $this->auth();
        self::assertSame('auth', $svc->name);
        self::assertSame('auth', $svc->prefix);
    }

    public function test_the_catch_all_declares_itself_default_with_no_prefix(): void
    {
        $svc = $this->frontend();
        self::assertTrue($svc->isDefault);
        self::assertSame('', $svc->prefix);
    }
}
