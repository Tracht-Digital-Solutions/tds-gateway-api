<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Support;

/**
 * Parses an upstream /healthz JSON body to extract its self-reported `db`
 * state. Every backend probe returns HTTP 200 by contract (never-5xx), so the
 * aggregate health can't gate on status code alone — a reachable-but-un-migrated
 * backend answers 200 while every real query fails. The backends expose that as
 * `db: "no-schema"` (reachable, tables missing) / `"down"` (unreachable) /
 * `"ok"`; this reads that field so the gateway can flip the aggregate to 503.
 */
final class HealthBody
{
    /**
     * @return 'ok'|'down'|'no-schema'|string|null The `db` field verbatim, or
     *         null when the body isn't JSON or carries no `db` key (an older
     *         backend, or a non-DB service) — callers treat null as "nothing to
     *         gate on" so mixed-version fleets stay backward-compatible.
     */
    public static function dbState(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !array_key_exists('db', $data) || !is_string($data['db'])) {
            return null;
        }
        return $data['db'];
    }
}
