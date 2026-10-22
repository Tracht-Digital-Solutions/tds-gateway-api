#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fails the assemble when two services that run in ONE process resolve
 * incompatible versions of the same package.
 *
 * WHY THIS EXISTS. In `GATEWAY_MODE=inprocess` the gateway loads every
 * service's `vendor/autoload.php` into a single PHP process. Composer
 * autoloaders are first-come-first-served per class name, so whichever service
 * is dispatched first wins for every package they share — and the aggregate
 * `/healthz` dispatches them in registry order, so the winner is decided by
 * something no service author can see.
 *
 * That is normally invisible: the services shared 33 packages at differing
 * versions for months, and `symfony/mailer` was a whole MAJOR apart (the
 * frontend requiring ^6.4 while customer resolved ^7.4). The frontend really
 * did run against customer's Symfony 7 classes — it survived only because the
 * handful of methods it calls did not change between the two majors. Nothing
 * would have caught the day that stopped being true: the mailer is bound
 * lazily, so no health probe resolves it (tds-gateway-api#8).
 *
 * A MAJOR mismatch fails the build. Minor/patch drift only warns: services are
 * locked independently by design, so exact equality is not achievable without
 * one shared lock, and failing on it would make the assemble unrunnable.
 *
 * Usage: php scripts/check-shared-deps.php <service-dir> [<service-dir> ...]
 *
 * Take the service directories EXPLICITLY, never a parent to scan: the assemble
 * checkout also holds the contract and all 14 extension packages, several of
 * which commit a `composer.lock` of their own. Those are mirrored into the
 * frontend's vendor rather than loaded as separate processes, so folding them
 * in would compare things that cannot conflict and bury the real signal.
 */

$dirs = array_slice($argv, 1);
if ($dirs === []) {
    fwrite(STDERR, "usage: check-shared-deps.php <service-dir> [<service-dir> ...]\n");
    exit(2);
}

/** @var array<string, array<string, string>> package => service => version */
$seen = [];
$services = [];
foreach ($dirs as $dir) {
    $lock = rtrim($dir, '/\\') . '/composer.lock';
    if (!is_file($lock)) {
        fwrite(STDERR, "check-shared-deps: no composer.lock in {$dir}\n");
        exit(2);
    }
    $entry = basename(rtrim($dir, '/\\'));
    $services[] = $entry;
    $data = json_decode((string) file_get_contents($lock), true);
    foreach ($data['packages'] ?? [] as $pkg) {
        // Path-mirrored first-party packages are copies of one checkout, not
        // independently resolved dependencies — they cannot disagree.
        if (str_starts_with((string) $pkg['name'], 'tracht-digital-solutions/')) {
            continue;
        }
        $seen[$pkg['name']][$entry] = (string) $pkg['version'];
    }
}

if (count($services) < 2) {
    echo "check-shared-deps: fewer than two services given — nothing to compare\n";
    exit(0);
}

/** Leading integer of a version, ignoring a `v` prefix. `v7.4.15` => 7. */
$major = static function (string $v): string {
    $v = ltrim($v, 'vV');
    return explode('.', $v)[0] ?? $v;
};

$fatal = [];
$drift = [];
foreach ($seen as $name => $byService) {
    if (count($byService) < 2) {
        continue;
    }
    $versions = array_unique(array_values($byService));
    if (count($versions) === 1) {
        continue;
    }
    $majors = array_unique(array_map($major, array_values($byService)));
    $row = $name . '  ' . implode('  ', array_map(
        static fn (string $s, string $v): string => "{$s}={$v}",
        array_keys($byService),
        array_values($byService),
    ));
    if (count($majors) > 1) {
        $fatal[] = $row;
    } else {
        $drift[] = $row;
    }
}

echo 'check-shared-deps: ' . count($services) . ' services (' . implode(', ', $services) . "), "
    . count($drift) . " package(s) drifting, " . count($fatal) . " incompatible\n";

if ($drift !== []) {
    echo "\nShared packages at different minor/patch versions (allowed — the winner is\n"
        . "still decided by dispatch order, so keep the list short):\n";
    foreach ($drift as $row) {
        echo "  {$row}\n";
    }
}

if ($fatal !== []) {
    fwrite(STDERR, "\nShared packages at different MAJOR versions — these run in ONE process\n"
        . "and the first service dispatched wins the class name for all of them:\n\n");
    foreach ($fatal as $row) {
        fwrite(STDERR, "  {$row}\n");
    }
    fwrite(STDERR, "\nAlign the constraint in the services' composer.json (see tds-gateway-api#8).\n");
    exit(1);
}

echo "\nNo major-version conflicts.\n";
