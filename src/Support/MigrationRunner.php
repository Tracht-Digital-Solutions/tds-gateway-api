<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Support;

/**
 * Applies each bundled service's pending Phinx migrations automatically, once
 * per deployed migration-set, from inside the gateway process.
 *
 * Why this exists: the stack installs + runs on Plesk *without SSH*. The web
 * installer (install.php) runs migrations once, but it locks itself afterwards,
 * so a later release that adds a migration would never apply it — the DB drifts
 * behind the code and every query against the new table 500s (the outage that
 * motivated this: tds-content-api#15 / tds-auth-api#13). This closes that gap:
 * on the first request after a deploy the gateway brings every service's schema
 * up to date, then never touches the DB again until the next deploy.
 *
 * Safety properties:
 *  - **Idempotent & cheap steady-state.** A marker file keyed to the *set of
 *    migration files* short-circuits every request once applied — the hot path
 *    is a single `is_file()`. The marker name changes only when a migration is
 *    added/removed, which is exactly when we want to re-run.
 *  - **Single-flight.** An exclusive, non-blocking `flock` means only the first
 *    worker after a deploy migrates; concurrent workers skip and serve normally.
 *  - **Never fatal.** Any failure is logged and swallowed — a migration hiccup
 *    must not take the whole gateway down. Health then reports `db:no-schema`
 *    (see {@see HealthBody}) so the problem is visible instead of silent.
 *  - **Only pending work.** Phinx applies just the migrations not yet in
 *    `phinxlog`; a fully-migrated DB is a no-op.
 */
final class MigrationRunner
{
    /** @var callable(string $serviceDir): array{0: bool, 1: string} */
    private $migrate;

    /**
     * @param string   $servicesDir  <bundle>/services (each holds <name>/vendor/bin/phinx + phinx.php).
     * @param string[] $serviceNames Service dir names to migrate, e.g. ['auth','contact','content','customer'].
     * @param string   $stateDir     Writable dir for the marker + lock (gitignored, survives deploys).
     * @param (callable(string $serviceDir): array{0: bool, 1: string})|null $migrate
     *        Runs one service's migrations, returns [ok, output]. Defaults to
     *        `phinx migrate -e production` via a resolved CLI php; tests inject a fake.
     * @param int $timeout Per-service migration wall-clock budget in seconds.
     */
    public function __construct(
        private readonly string $servicesDir,
        private readonly array $serviceNames,
        private readonly string $stateDir,
        private readonly ?Logger $logger = null,
        ?callable $migrate = null,
        private readonly int $timeout = 90,
    ) {
        $this->migrate = $migrate ?? fn (string $dir): array => $this->phinxMigrate($dir);
    }

    /** Best-effort entry point — brings every service up to date, never throws. */
    public function ensureMigrated(): void
    {
        try {
            $this->run();
        } catch (\Throwable $e) {
            $this->logger?->error('auto-migrate: unexpected failure', ['error' => $e->getMessage()]);
        }
    }

    private function run(): void
    {
        $marker = $this->markerPath();
        if (is_file($marker)) {
            return; // already migrated for this exact migration-set — hot path
        }

        if (!self::procOpenAvailable()) {
            $this->logger?->warning('auto-migrate skipped: proc_open disabled — run migrations manually');
            return;
        }

        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0775, true) && !is_dir($this->stateDir)) {
            $this->logger?->warning('auto-migrate skipped: state dir not writable', ['dir' => $this->stateDir]);
            return;
        }

        $lock = @fopen($this->stateDir . '/.migrate.lock', 'c');
        if ($lock === false) {
            $this->logger?->warning('auto-migrate skipped: could not open lock file');
            return;
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return; // another worker is already migrating — let it finish
            }
            if (is_file($marker)) {
                return; // won the race, but the winner already finished
            }

            $allOk = true;
            foreach ($this->serviceNames as $name) {
                $dir = $this->servicesDir . '/' . $name;
                if (!is_dir($dir)) {
                    continue; // service not in this bundle — skip, don't fail
                }
                [$ok, $out] = ($this->migrate)($dir);
                if ($ok) {
                    $this->logger?->info("auto-migrate: {$name} up to date");
                } else {
                    $allOk = false;
                    $this->logger?->error("auto-migrate: {$name} failed", ['output' => $out]);
                }
            }

            // Only mark done when *everything* succeeded, so a partial failure
            // retries on the next request instead of being latched as "migrated".
            if ($allOk) {
                @file_put_contents($marker, gmdate('c') . "\n");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Marker path keyed to a signature of the current migration files. */
    private function markerPath(): string
    {
        return $this->stateDir . '/.migrated-' . $this->signature();
    }

    /**
     * Signature over every service's migration filenames. Adding or removing a
     * migration changes it (→ re-run on next deploy); a plain redeploy of the
     * same schema keeps it (→ stays a no-op).
     */
    private function signature(): string
    {
        $names = [];
        foreach ($this->serviceNames as $name) {
            $dir = $this->servicesDir . '/' . $name . '/db/migrations';
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '/*.php') as $file) {
                $names[] = $name . '/' . basename((string) $file);
            }
        }
        sort($names);
        return substr(hash('sha256', implode('|', $names)), 0, 16);
    }

    /** Run `php vendor/bin/phinx migrate -e production` in $serviceDir. */
    private function phinxMigrate(string $serviceDir): array
    {
        if (!is_file($serviceDir . '/vendor/bin/phinx')) {
            return [false, 'phinx not found (vendor/ missing from bundle)'];
        }

        $cmd = [self::phpCliBinary(), 'vendor/bin/phinx', 'migrate', '-e', 'production'];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $serviceDir);
        if (!is_resource($proc)) {
            return [false, 'could not start the migration process'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return [false, "timed out after {$this->timeout}s\n" . trim($out)];
            }
            usleep(100_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code === 0, trim($out)];
    }

    /**
     * Resolve a **CLI** php binary. Under PHP-FPM, PHP_BINARY is the FPM binary,
     * which cannot run a CLI script — a prime suspect for "install ran but the
     * DB is empty". Prefer an explicit override, then the CLI next to the FPM
     * binary, then PATH, then PHP_BINARY as a last resort.
     */
    public static function phpCliBinary(): string
    {
        $override = getenv('GATEWAY_PHP_BINARY');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        // A sibling `php` next to the running binary's dir (Plesk ships php-cli
        // alongside php-fpm as e.g. /opt/plesk/php/8.3/bin/php).
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidate = PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // PHP_BINARY only if it's clearly the CLI (not php-fpm / apache module).
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && !str_contains(strtolower(PHP_BINARY), 'fpm')) {
            return PHP_BINARY;
        }

        return 'php'; // trust PATH
    }

    private static function procOpenAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }
}
