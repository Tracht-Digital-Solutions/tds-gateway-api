<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Config;

/**
 * One upstream micro-backend behind the gateway.
 *
 * Two routing shapes:
 *  - **Prefixed** (`auth`, `customer`): the public contract is
 *    `api.tracht-digital.de/{prefix}/...`; the first path segment selects the
 *    service and is stripped before forwarding (`/auth/admin/login` →
 *    auth-api's `/admin/login`).
 *  - **Default / catch-all** (`isDefault`): the composed frontend API
 *    (`tds-core-frontend-api`) owns the whole root namespace except the
 *    prefixed services above. Anything that doesn't match a prefix is forwarded
 *    to it verbatim (no segment stripped) — its module routes live at root
 *    (`/tickets`, `/tools`, `/admin/settings`, `/me/…`, `/wiki.json`, …).
 *
 * `upstream` is the internal base URL (host:port) the service listens on.
 * `rewrite` is prepended to the remainder after the prefix is stripped — it is
 * empty for every current service (all mount their routes at root).
 */
final class Service
{
    public function __construct(
        public readonly string $name,
        public readonly string $prefix,
        public readonly string $upstream,
        public readonly string $rewrite = '',
        public readonly bool $isDefault = false,
    ) {
    }

    /**
     * Build the upstream URL for a forwarded request.
     *
     * @param string $remainder Path after the public prefix, with leading
     *                          slash, or '' when the request hit the prefix
     *                          exactly (e.g. `/contact`).
     */
    public function targetFor(string $remainder, string $query = ''): string
    {
        $url = $this->upstream . $this->rewrite . $remainder;
        if ($query !== '') {
            $url .= '?' . $query;
        }
        return $url;
    }

    /**
     * Host-less twin of {@see targetFor()}: the path the service's own router
     * should see in the in-process dispatch mode (no upstream host, no query —
     * the query rides along on the forwarded request's URI).
     *
     * `auth` + `/admin/login` -> `/admin/login`
     * `customer` + `''` -> `/`  (a bare prefix hit maps to the service root)
     * `frontend` (default) + `/tools/catalog` -> `/tools/catalog`
     */
    public function pathFor(string $remainder): string
    {
        $path = $this->rewrite . $remainder;
        return $path === '' ? '/' : $path;
    }

    /** Each micro-backend exposes `/healthz` at its root. */
    public function healthUrl(): string
    {
        return $this->upstream . '/healthz';
    }
}
