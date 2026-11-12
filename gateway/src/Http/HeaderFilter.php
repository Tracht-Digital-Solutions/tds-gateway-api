<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

/**
 * Strips hop-by-hop and length/host headers when relaying through the proxy.
 * Content-Length is dropped both ways and recomputed from the body we hold
 * (correct for gzipped bodies too, since we forward the exact upstream bytes
 * and keep Content-Encoding). CORS is deliberately left to the upstreams.
 */
final class HeaderFilter
{
    /** Per RFC 7230 §6.1 — must not be forwarded by a proxy. */
    public const HOP_BY_HOP = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /**
     * @param array<string, string[]> $headers
     * @return array<string, string[]>
     */
    public static function forRequest(array $headers): array
    {
        return self::drop($headers, [...self::HOP_BY_HOP, 'host', 'content-length']);
    }

    /**
     * @param array<string, string[]> $headers
     * @return array<string, string[]>
     */
    public static function forResponse(array $headers): array
    {
        return self::drop($headers, [...self::HOP_BY_HOP, 'content-length']);
    }

    /**
     * @param array<string, string[]> $headers
     * @param string[] $remove lower-cased header names
     * @return array<string, string[]>
     */
    private static function drop(array $headers, array $remove): array
    {
        $removeSet = array_flip($remove);
        $out = [];
        foreach ($headers as $name => $values) {
            if (isset($removeSet[strtolower((string) $name)])) {
                continue;
            }
            $out[$name] = $values;
        }
        return $out;
    }
}
