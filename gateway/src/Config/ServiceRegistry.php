<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Config;

/**
 * The routing table: public prefix -> upstream service.
 *
 * Built from env so dev (localhost:800x) and prod (127.0.0.1 / sockets) can
 * differ, but ships with working defaults for the current backends:
 *
 *  - **`auth`** -> `tds-auth-api`      (prefix-stripped)
 *  - **`customer`** -> `tds-customer-api`  (prefix-stripped)
 *  - **`frontend`** -> `tds-core-frontend-api` (DEFAULT / catch-all, no strip)
 *
 * The composed frontend API owns the whole root namespace except the two
 * prefixed legacy backends: its module routes (`/tickets`, `/tools`,
 * `/admin/settings`, `/me/…`, `/wiki.json`, …) live at root. So any request
 * that doesn't match a prefix falls through to the default service verbatim.
 *
 * The archived `content` + `contact` backends are gone — their behaviour is now
 * served by the frontend API's blog-cms / website-cms / contact-tickets
 * extensions, reached through the default route.
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
        'customer' => ['http://127.0.0.1:8004', ''],
        'frontend' => ['http://127.0.0.1:8100', ''],
    ];

    /** @var array<string, Service> keyed by prefix (excludes the default service) */
    private array $byPrefix = [];

    /** @var Service[] every service in declared order (incl. the default) */
    private array $ordered = [];

    /** The catch-all upstream for unmatched paths, or null when none is configured. */
    private ?Service $default = null;

    /** @param Service[] $services */
    public function __construct(array $services)
    {
        foreach ($services as $service) {
            $this->ordered[] = $service;
            if ($service->isDefault) {
                $this->default ??= $service;
            } else {
                $this->byPrefix[$service->prefix] = $service;
            }
        }
    }

    /** @return Service[] */
    public function all(): array
    {
        return $this->ordered;
    }

    public function default(): ?Service
    {
        return $this->default;
    }

    public function get(string $prefix): ?Service
    {
        if (isset($this->byPrefix[$prefix])) {
            return $this->byPrefix[$prefix];
        }
        return $this->default?->prefix === $prefix ? $this->default : null;
    }

    /**
     * Resolve a request path to its service plus the remainder to forward.
     *
     * `/auth/admin/login` -> [auth, '/admin/login']  (prefix stripped)
     * `/tools/catalog`    -> [frontend, '/tools/catalog']  (default, verbatim)
     * `/`                 -> [frontend, '/']  (default root)
     *
     * With no default service configured an unmatched prefix returns null (the
     * legacy behaviour — a 404 from the catch-all action).
     *
     * @return array{0: Service, 1: string}|null
     */
    public function match(string $path): ?array
    {
        $trimmed = ltrim($path, '/');
        if ($trimmed === '') {
            return $this->default === null ? null : [$this->default, '/'];
        }
        $slash = strpos($trimmed, '/');
        $first = $slash === false ? $trimmed : substr($trimmed, 0, $slash);
        $remainder = $slash === false ? '' : substr($trimmed, $slash);

        $service = $this->byPrefix[$first] ?? null;
        if ($service !== null) {
            return [$service, $remainder];
        }
        // No prefix matched: hand the WHOLE path to the default upstream (its
        // routes live at root — nothing is stripped).
        return $this->default === null ? null : [$this->default, '/' . $trimmed];
    }

    /**
     * Construct from an env accessor. `$env(key, default)` must return a
     * string and never throw for a key with a default (so the gateway boots
     * with no .env in dev).
     *
     * `GATEWAY_DEFAULT_SERVICE` (default `frontend`) names the catch-all
     * upstream; set it to '' to disable the catch-all (unmatched paths 404).
     *
     * @param callable(string, ?string): string $env
     */
    public static function fromEnv(callable $env): self
    {
        $names = array_values(array_filter(array_map(
            static fn (string $n): string => strtolower(trim($n)),
            explode(',', $env('GATEWAY_SERVICES', 'auth,customer,frontend')),
        )));

        $defaultName = strtolower(trim($env('GATEWAY_DEFAULT_SERVICE', 'frontend')));

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
            $services[] = new Service($name, $name, $upstream, $rewrite, $name === $defaultName);
        }

        return new self($services);
    }
}
