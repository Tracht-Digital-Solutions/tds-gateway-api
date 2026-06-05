<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Config;

/**
 * One upstream micro-backend behind the gateway.
 *
 * The public contract is path-prefixed: `api.tracht-digital.de/{prefix}/...`.
 * `upstream` is the internal base URL (host:port) the service listens on.
 * `rewrite` is prepended to the remainder after the prefix is stripped — it
 * is empty for services that mount their routes at root (auth, content,
 * customer) and `/contact` for contact-api, whose own route is literally
 * `/contact` (the frontend POSTs to `.../contact` with no sub-path).
 */
final class Service
{
    public function __construct(
        public readonly string $name,
        public readonly string $prefix,
        public readonly string $upstream,
        public readonly string $rewrite = '',
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

    /** Each micro-backend exposes `/healthz` at its root. */
    public function healthUrl(): string
    {
        return $this->upstream . '/healthz';
    }
}
