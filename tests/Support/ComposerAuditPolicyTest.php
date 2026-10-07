<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Support;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/lib/composer_audit_policy.php';

/**
 * The policy behind scripts/composer-audit.php (tds-gateway-api#7): a high or
 * critical advisory in a production dependency blocks the assemble; anything
 * below only warns; an audit that could not run blocks nothing.
 */
final class ComposerAuditPolicyTest extends TestCase
{
    public function testACleanTreeBlocksNothing(): void
    {
        // A clean `composer audit --format=json` prints advisories as an empty LIST.
        $r = tds_composer_audit_classify('{"advisories": [], "abandoned": []}');
        self::assertFalse($r['unreadable']);
        self::assertSame(0, $r['blocking']);
        self::assertSame([], $r['findings']);
    }

    public function testHighAndCriticalBlockAndLowerSeveritiesWarn(): void
    {
        $r = tds_composer_audit_classify((string) json_encode(['advisories' => [
            'guzzlehttp/guzzle' => [
                ['advisoryId' => 'PKSA-1', 'cve' => 'CVE-2026-0001', 'title' => 'Header injection', 'severity' => 'high'],
                ['advisoryId' => 'PKSA-2', 'cve' => null, 'title' => 'Minor leak', 'severity' => 'low'],
            ],
            'guzzlehttp/psr7' => [
                ['advisoryId' => 'PKSA-3', 'title' => 'Parser bug', 'severity' => 'CRITICAL'],
                ['advisoryId' => 'PKSA-4', 'title' => 'Unrated', 'severity' => null],
            ],
        ]]));

        self::assertSame(2, $r['blocking']);
        $by = array_column($r['findings'], null, 'title');
        self::assertTrue($by['Header injection']['blocking']);
        self::assertSame('CVE-2026-0001', $by['Header injection']['id']);
        self::assertFalse($by['Minor leak']['blocking']);
        self::assertSame('PKSA-2', $by['Minor leak']['id']);
        self::assertTrue($by['Parser bug']['blocking'], 'severity is compared case-insensitively');
        self::assertFalse($by['Unrated']['blocking']);
        self::assertSame('unknown', $by['Unrated']['severity']);
    }

    public function testAnAuditThatCouldNotRunBlocksNothing(): void
    {
        // Packagist unreachable or composer failing prints no JSON: warn, do not
        // make a release depend on a third-party API being up.
        $r = tds_composer_audit_classify('');
        self::assertTrue($r['unreadable']);
        self::assertSame(0, $r['blocking']);
    }
}
