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
 * **Migrations run in-process** through Phinx's PHP API (Manager), not by
 * shelling out. Shared Plesk hosting very commonly disables `proc_open` — which
 * is precisely why the installer's subprocess migration silently applied nothing
 * and left prod empty. In-process needs no `proc_open` and no CLI php, so it
 * works where the subprocess path can't; that path is kept only as a fallback.
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
 *  - **Collision-guarded.** Phinx `include`s every migration file (applied or
 *    not), and in-process all services share ONE PHP process — so two services
 *    declaring the same migration class name would be an *uncatchable* fatal
 *    redeclaration error on every request (the outage: three services shipped
 *    an identical `CreateAppSetting`). Class names are scanned up front and a
 *    colliding service is skipped + logged instead of fataling. Convention:
 *    migration class names are service-prefixed / globally unique.
 */
final class MigrationRunner
{
    /** @var callable(string $serviceDir): array{0: bool, 1: string} */
    private $migrate;

    /**
     * @param string   $servicesDir  <bundle>/services (each holds <name>/vendor + phinx.php + db/migrations).
     * @param string[] $serviceNames Service dir names to migrate, e.g. ['auth','contact','content','customer'].
     * @param string   $stateDir     Preferred dir for the marker + lock (falls back to the system temp dir when unwritable).
     * @param (callable(string $serviceDir): array{0: bool, 1: string})|null $migrate
     *        Runs one service's migrations, returns [ok, output]. Defaults to
     *        in-process Phinx (with a subprocess fallback); tests inject a fake.
     * @param int $timeout Per-service subprocess-fallback wall-clock budget in seconds.
     */
    public function __construct(
        private readonly string $servicesDir,
        private readonly array $serviceNames,
        private readonly string $stateDir,
        private readonly ?Logger $logger = null,
        ?callable $migrate = null,
        private readonly int $timeout = 90,
    ) {
        $this->migrate = $migrate ?? fn (string $dir): array => self::migrateServiceDir($dir, $this->timeout);
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
        $stateDir = $this->resolveStateDir();
        if ($stateDir === null) {
            $this->logger?->warning('auto-migrate skipped: no writable state dir');
            return;
        }

        $marker = $stateDir . '/.migrated-' . $this->signature();
        if (is_file($marker)) {
            return; // already migrated for this exact migration-set — hot path
        }

        $lock = @fopen($stateDir . '/.migrate.lock', 'c');
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
            $declared = []; // migration class name => service that declared it
            foreach ($this->serviceNames as $name) {
                $dir = $this->servicesDir . '/' . $name;
                if (!is_dir($dir)) {
                    continue; // service not in this bundle — skip, don't fail
                }
                $classes = $this->declaredMigrationClasses($dir);
                $collisions = array_intersect_key($declared, array_flip($classes));
                if ($collisions !== []) {
                    $allOk = false;
                    $this->logger?->error(
                        "auto-migrate: {$name} skipped — migration class name collision "
                            . '(would fatal when included into the shared process)',
                        ['collisions' => array_map(
                            fn (string $class): string => "{$class} already declared by '{$collisions[$class]}'",
                            array_keys($collisions),
                        )],
                    );
                    continue;
                }
                foreach ($classes as $class) {
                    $declared[$class] = $name;
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

    /**
     * Class names a service's migration files declare, scanned as text without
     * including them (an actual include of a duplicate would be the very fatal
     * this guards against). Regex over `class X` is enough: Phinx migrations
     * are conventionally one un-namespaced class per file.
     *
     * @return string[]
     */
    private function declaredMigrationClasses(string $serviceDir): array
    {
        $classes = [];
        foreach ((array) glob($serviceDir . '/db/migrations/*.php') as $file) {
            $src = (string) @file_get_contents((string) $file);
            if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/mi', $src, $m) > 0) {
                foreach ($m[1] as $class) {
                    $classes[] = $class;
                }
            }
        }
        return $classes;
    }

    /** Preferred state dir if writable, else a per-host temp dir, else null. */
    private function resolveStateDir(): ?string
    {
        foreach ([$this->stateDir, sys_get_temp_dir() . '/tds-gw-migrate'] as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
            if (!is_dir($dir) && @mkdir($dir, 0775, true) && is_writable($dir)) {
                return $dir;
            }
        }
        return null;
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

    /**
     * Migrate one service dir — in-process Phinx first, subprocess as a fallback.
     * Public + static so the web installer (install.php) can reuse the exact same
     * in-process path (it has no proc_open on this host).
     *
     * @return array{0: bool, 1: string} [ok, output]
     */
    public static function migrateServiceDir(string $serviceDir, int $timeout = 90): array
    {
        $autoload = $serviceDir . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload; // service vendor ships Phinx (added post --no-dev)
            if (class_exists(\Phinx\Migration\Manager::class)) {
                return self::phinxInProcess($serviceDir);
            }
        }
        return self::phinxSubprocess($serviceDir, $timeout);
    }

    /**
     * Run pending migrations via Phinx's PHP API — no proc_open, no CLI php.
     *
     * The DB credentials are read straight from the service's own `.env` (not the
     * process env) so a warm FPM worker that already handled a different service
     * can't leak its DB_NAME in here. The migration table + adapter mirror every
     * service's phinx.php (`phinx_migration`, mysql, utf8mb4) — kept in sync with
     * those configs.
     */
    private static function phinxInProcess(string $serviceDir): array
    {
        $env = self::readEnvFile($serviceDir . '/.env');
        if (($env['DB_NAME'] ?? '') === '') {
            return [false, "in-process: DB_NAME missing from {$serviceDir}/.env"];
        }

        $config = new \Phinx\Config\Config([
            'paths' => [
                'migrations' => $serviceDir . '/db/migrations',
                'seeds' => $serviceDir . '/db/seeds',
            ],
            'environments' => [
                'default_migration_table' => 'phinx_migration',
                'production' => [
                    'adapter' => 'mysql',
                    'host' => $env['DB_HOST'] ?? '127.0.0.1',
                    'port' => $env['DB_PORT'] ?? '3306',
                    'name' => $env['DB_NAME'],
                    'user' => $env['DB_USER'] ?? 'root',
                    'pass' => $env['DB_PASS'] ?? '',
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ],
            ],
        ], $serviceDir . '/phinx.php');

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        try {
            $manager = new \Phinx\Migration\Manager(
                $config,
                new \Symfony\Component\Console\Input\ArrayInput([]),
                $output,
            );
            $manager->migrate('production');
            return [true, trim($output->fetch())];
        } catch (\Throwable $e) {
            return [false, 'in-process migrate failed: ' . $e->getMessage() . "\n" . trim($output->fetch())];
        }
    }

    /**
     * Minimal `.env` reader — KEY=VALUE, ignores comments; never touches globals.
     *
     * Supplies the DB credentials for the auth/customer migrations, so it MUST
     * decode a value exactly as the services' own phpdotenv does. It previously
     * stripped the quotes but left the escaping in place, which was invisible
     * while the installer wrote values raw. Once `install.php` began quoting and
     * escaping (`\` → `\\`, `"` → `\"`, `$` → `\$`), a DB password containing any
     * of those came back here with a stray backslash and Phinx failed with
     * `SQLSTATE[HY000] [1045] Access denied` — while the service itself, reading
     * the same file with real phpdotenv, connected fine. The frontend migrated
     * happily throughout, because its installer path uses install.php's
     * `read_env_kv()`, which had already been made the exact inverse.
     *
     * Mirrors phpdotenv's double-quote semantics; a single-quoted value is
     * literal. Keep this in lockstep with `install.php`'s `read_env_kv()` —
     * `tests/Support/InstallEnvFileTest.php` asserts both agree with phpdotenv.
     */
    private static function readEnvFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach ((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            $len = strlen($val);
            if ($len >= 2 && $val[0] === '"' && $val[$len - 1] === '"') {
                $val = str_replace(['\\\\', '\\"', '\\$'], ['\\', '"', '$'], substr($val, 1, -1));
            } elseif ($len >= 2 && $val[0] === "'" && $val[$len - 1] === "'") {
                $val = substr($val, 1, -1);
            }
            $out[$key] = $val;
        }
        return $out;
    }

    /**
     * Fallback: `php vendor/bin/phinx migrate -e production` in $serviceDir, for
     * hosts where in-process Phinx isn't available. Needs proc_open + a CLI php.
     */
    private static function phinxSubprocess(string $serviceDir, int $timeout): array
    {
        if (!self::procOpenAvailable()) {
            return [false, 'proc_open disabled and in-process Phinx unavailable — migrate manually'];
        }
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
        $deadline = microtime(true) + $timeout;
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
                return [false, "timed out after {$timeout}s\n" . trim($out)];
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
     * Resolve a **CLI** php binary for the subprocess fallback. Under PHP-FPM,
     * PHP_BINARY is the FPM binary, which cannot run a CLI script. Prefer an
     * explicit override, then a `php` next to the running binary, then a
     * clearly-CLI PHP_BINARY, then PATH.
     */
    public static function phpCliBinary(): string
    {
        $override = getenv('GATEWAY_PHP_BINARY');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidate = PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && !str_contains(strtolower(PHP_BINARY), 'fpm')) {
            return PHP_BINARY;
        }
        return 'php';
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
