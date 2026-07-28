<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Support\HealthBody;

/**
 * How the gateway spots a backend that is UP but not usable.
 *
 * Every backend probe returns HTTP 200 by contract (never-5xx), so the
 * aggregate health cannot gate on the status code alone: a reachable but
 * un-migrated backend answers 200 cheerfully while every real query fails.
 * The backends report that as `db: "no-schema"`, and this parser is what lets
 * the gateway flip its aggregate to 503.
 *
 * The `null` return is deliberately distinct from `"down"`: it means "this
 * body carries nothing to gate on" (an older backend, or a service with no
 * database at all), and callers must treat it as *not a failure* so a
 * mixed-version fleet stays backward-compatible. Collapsing the two would take
 * the whole gateway down the moment one service lags a release.
 */
final class HealthBodyTest extends TestCase
{
    public function test_reads_a_healthy_db_state(): void
    {
        self::assertSame('ok', HealthBody::dbState('{"db":"ok"}'));
    }

    public function test_reads_the_unmigrated_state_the_gateway_gates_on(): void
    {
        // The whole reason this parser exists.
        self::assertSame('no-schema', HealthBody::dbState('{"db":"no-schema"}'));
    }

    public function test_reads_an_unreachable_db_state(): void
    {
        self::assertSame('down', HealthBody::dbState('{"db":"down"}'));
    }

    public function test_returns_the_field_VERBATIM_for_an_unknown_state(): void
    {
        // A future backend may report something this gateway has never heard
        // of; passing it through beats inventing a verdict.
        self::assertSame('degraded', HealthBody::dbState('{"db":"degraded"}'));
    }

    public function test_reads_db_alongside_other_fields(): void
    {
        self::assertSame('ok', HealthBody::dbState('{"service":"auth","db":"ok","version":"1.2.3"}'));
    }

    // --- "nothing to gate on" is NOT a failure ----------------------------

    public function test_an_empty_body_carries_nothing_to_gate_on(): void
    {
        self::assertNull(HealthBody::dbState(''));
    }

    public function test_a_body_without_a_db_key_carries_nothing_to_gate_on(): void
    {
        // An older backend, or a service with no database — must not read as
        // "down", or one lagging service 503s the whole gateway.
        self::assertNull(HealthBody::dbState('{"service":"gateway"}'));
    }

    public function test_a_non_json_body_carries_nothing_to_gate_on(): void
    {
        self::assertNull(HealthBody::dbState('OK'));
        self::assertNull(HealthBody::dbState('<html>502</html>'));
    }

    public function test_malformed_json_carries_nothing_to_gate_on(): void
    {
        self::assertNull(HealthBody::dbState('{"db":'));
    }

    public function test_a_json_scalar_carries_nothing_to_gate_on(): void
    {
        // json_decode("null"/"5") succeeds but is not an object.
        foreach (['null', '5', '"ok"', 'true'] as $body) {
            self::assertNull(HealthBody::dbState($body), $body);
        }
    }

    public function test_a_non_string_db_value_carries_nothing_to_gate_on(): void
    {
        // `db: true` from a mis-typed backend must not be coerced into a
        // state string the caller then compares against "ok".
        foreach (['{"db":true}', '{"db":1}', '{"db":null}', '{"db":{"state":"ok"}}'] as $body) {
            self::assertNull(HealthBody::dbState($body), $body);
        }
    }

    public function test_distinguishes_a_missing_field_from_an_empty_one(): void
    {
        // An explicitly empty string is still a reported value; only a missing
        // or non-string field means "nothing to gate on".
        self::assertSame('', HealthBody::dbState('{"db":""}'));
        self::assertNull(HealthBody::dbState('{}'));
    }

    public function test_ignores_a_db_key_nested_somewhere_else(): void
    {
        self::assertNull(HealthBody::dbState('{"upstream":{"db":"ok"}}'));
    }
}
