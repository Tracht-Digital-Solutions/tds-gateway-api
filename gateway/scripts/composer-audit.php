#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runs `composer audit` over the PRODUCTION dependencies of each named
 * directory and fails on a high or critical advisory (tds-gateway-api#7).
 *
 * WHY THIS EXISTS. No backend audited its dependencies, so a vulnerable package
 * was found only by accident: tds-customer-api shipped guzzle 7.11.1 and psr7
 * 2.11.0 with three advisories to production — its tracked lock was simply
 * never moved — and it surfaced only because `composer audit` happened to run
 * beside a version bump. The other services track no lock, so the assemble
 * resolves fresh on every release, and an advisory published yesterday rides
 * along without anyone deciding that it should.
 *
 * Run it in the assemble AFTER the production install: that tree is what the
 * host receives, which is the only one worth judging.
 *
 * POLICY. high/critical fails the run; everything below (and an advisory
 * without a severity) is a warning annotation. A finding in a dev-only package
 * is out of scope by construction (`--no-dev`), so it can never hold up a
 * hotfix. An audit that cannot run at all — Packagist unreachable, broken JSON
 * — warns rather than fails: the check exists to block known risks, not to make
 * a release depend on a third-party API being up.
 *
 * Usage: php scripts/composer-audit.php <dir> [<dir> ...]
 *        COMPOSER_BIN="php composer.phar" overrides the composer command.
 */

require_once __DIR__ . '/lib/composer_audit_policy.php';

$dirs = array_slice($argv, 1);
if ($dirs === []) {
    fwrite(STDERR, "usage: composer-audit.php <dir> [<dir> ...]\n");
    exit(2);
}

$blocking = 0;
foreach ($dirs as $dir) {
    if (!is_file($dir . '/composer.json')) {
        echo "::error title=composer audit::{$dir} has no composer.json\n";
        $blocking++;
        continue;
    }
    // Exit code ignored on purpose: composer exits non-zero for ANY finding
    // (and for abandoned packages); the policy below decides what blocks.
    $cmd = (getenv('COMPOSER_BIN') ?: 'composer') . ' audit --no-dev --format=json --abandoned=report --no-interaction --working-dir=' . escapeshellarg($dir);
    $raw = tds_run_stdout($cmd);
    $result = tds_composer_audit_classify($raw);

    if ($result['unreadable']) {
        echo "::warning title=composer audit::{$dir}: audit output unreadable — not checked this run\n";
        continue;
    }
    foreach ($result['findings'] as $f) {
        $level = $f['blocking'] ? 'error' : 'warning';
        echo "::{$level} title=composer audit ({$dir})::{$f['package']} — {$f['title']} [{$f['id']}, {$f['severity']}]\n";
    }
    $blocking += $result['blocking'];
    echo sprintf("%s: %d advisories, %d blocking\n", $dir, count($result['findings']), $result['blocking']);
}

exit($blocking > 0 ? 1 : 0);

/** stdout of a command, stderr discarded — portable, unlike a `2>/dev/null` suffix. */
function tds_run_stdout(string $cmd): string
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return '';
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out;
}
