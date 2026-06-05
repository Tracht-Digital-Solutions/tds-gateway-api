<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Config;

/**
 * The routing table: public prefix -> upstream service.
 *
 * Built from env so dev (localhost:800x) and prod (127.0.0.1 / sockets)
 * can differ, but ships with working defaults for the four known backends.
 */
final class ServiceRegistry
{
    /**
     * Baked defaults: prefix => [upstream, rewrite]. Overridable per service
     * via {PREFIX}_UPSTREAM / {PREFIX}_REWRITE env vars.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DEFAULTS = [
        'auth' => ['http://127.0.0.1:8003', ''],
        'contact' => ['http://127.0.0.1:8002', '/contact'],
        'content' => ['http://127.0.0.1:8001', ''],
        'customer' => ['http://127.0.0.1:8004', ''],
    ];

    /** @var array<string, Service> keyed by prefix */
    private array $byPrefix = [];

    /** @param Service[] $services */
    public function __construct(array $services)
    {
        foreach ($services as $service) {
            $this->byPrefix[$service->prefix] = $service;
        }
    }

    /** @return Service[] */
    public function all(): array
    {
        return array_values($this->byPrefix);
    }

    public function get(string $prefix): ?Service
    {
        return $this->byPrefix[$prefix] ?? null;
    }

    /**
     * Resolve a request path to its service plus the remainder to forward.
     *
     * `/auth/admin/login` -> [auth, '/admin/login']
     * `/contact`          -> [contact, '']
     * `/nope/...`         -> null
     *
     * @return array{0: Service, 1: string}|null
     */
    public function match(string $path): ?array
    {
        $trimmed = ltrim($path, '/');
        if ($trimmed === '') {
            return null;
        }
        $slash = strpos($trimmed, '/');
        $first = $slash === false ? $trimmed : substr($trimmed, 0, $slash);
        $remainder = $slash === false ? '' : substr($trimmed, $slash);

        $service = $this->byPrefix[$first] ?? null;
        return $service === null ? null : [$service, $remainder];
    }

    /**
     * Construct from an env accessor. `$env(key, default)` must return a
     * string and never throw for a key with a default (so the gateway boots
     * with no .env in dev).
     *
     * @param callable(string, ?string): string $env
     */
    public static function fromEnv(callable $env): self
    {
        $names = array_values(array_filter(array_map(
            static fn (string $n): string => strtolower(trim($n)),
            explode(',', $env('GATEWAY_SERVICES', 'auth,contact,content,customer')),
        )));

        $services = [];
        foreach ($names as $name) {
            [$defaultUpstream, $defaultRewrite] = self::DEFAULTS[$name] ?? ['', ''];
            $key = strtoupper($name);
            $upstream = rtrim($env($key . '_UPSTREAM', $defaultUpstream), '/');
            $rewrite = $env($key . '_REWRITE', $defaultRewrite);
            if ($upstream === '') {
                throw new \RuntimeException(
                    "No upstream configured for gateway service '{$name}'. "
                    . "Set {$key}_UPSTREAM or remove it from GATEWAY_SERVICES.",
                );
            }
            $services[] = new Service($name, $name, $upstream, $rewrite);
        }

        return new self($services);
    }
}
