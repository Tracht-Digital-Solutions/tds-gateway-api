#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rehearses what `/install.php` does to a fresh host's databases, against a
 * real MySQL 8 — and fails the assemble if any of it would break.
 *
 * WHY THIS EXISTS. The production host is MySQL 8. Development, every service
 * repo's own CI, and the DB-backed tests all run MariaDB, which is markedly
 * more permissive. So a migration can be green in every place anyone looks and
 * still be impossible to apply on the machine that matters.
 *
 * That is not hypothetical. `app_user_avatar` and `auth_company_policy` both
 * declared a PRIMARY KEY over a column that Phinx — which defaults every
 * addColumn() to nullable — emitted as NULL-able. MariaDB silently coerces
 * such a column to NOT NULL. MySQL 8 refuses:
 *
 *     SQLSTATE[42000] 1171 All parts of a PRIMARY KEY must be NOT NULL
 *
 * The first and only symptom was a fresh /install.php dying at
 * "Migration: auth" with fourteen migrations applied, ten not, and a database
 * in a state no later step could recover from. Static rules (each repo's
 * MigrationDialectTest) name the traps that are already known; this step is
 * what finds the next one, because it proves the actual result rather than
 * inspecting the source.
 *
 * WHAT IT RUNS. The same three migration paths install.php drives, in the same
 * order, each into its own empty database:
 *
 *   - auth, customer  → their bundled Phinx, via a generated config so this
 *     does not depend on how each repo's phinx.php happens to read its env
 *   - frontend        → tds-core-frontend-api's OWN in-process MigrationRunner,
 *     which composes all enabled extensions' migrations into one shared
 *     phinxlog (there is no single db/migrations for it)
 *
 * Usage:
 *   php scripts/check-migrations-mysql8.php <auth-dir> <customer-dir> <frontend-dir>
 *
 * Connection (defaults match the workflow's `mysql8` service container):
 *   MYSQL8_HOST=127.0.0.1  MYSQL8_PORT=33306  MYSQL8_USER=root  MYSQL8_PASS=dev
 */

$dirs = array_slice($argv, 1);
if (count($dirs) !== 3) {
    fwrite(STDERR, "usage: check-migrations-mysql8.php <auth-dir> <customer-dir> <frontend-dir>\n");
    exit(2);
}

[$authDir, $customerDir, $frontendDir] = array_map(
    static fn (string $d): string => rtrim(str_replace('\\', '/', $d), '/'),
    $dirs
);

$host = envOr('MYSQL8_HOST', '127.0.0.1');
$port = envOr('MYSQL8_PORT', '33306');
$user = envOr('MYSQL8_USER', 'root');
$pass = envOr('MYSQL8_PASS', 'dev');

$server = connect($host, $port, $user, $pass, null);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();

if (!str_starts_with($version, '8.')) {
    // A MariaDB here would make the whole step pass vacuously — which is the
    // exact failure mode this exists to prevent.
    fwrite(STDERR, "check-migrations-mysql8: connected server reports '{$version}', expected MySQL 8.x.\n");
    exit(2);
}

echo "check-migrations-mysql8: server {$version} at {$host}:{$port}\n";

$failures = [];

foreach ([['auth', $authDir], ['customer', $customerDir]] as [$name, $dir]) {
    $db = 'tds_' . $name . '_install_check';
    freshDatabase($server, $db);

    $phinx = $dir . '/vendor/bin/phinx';
    if (!is_file($phinx)) {
        fwrite(STDERR, "check-migrations-mysql8: no vendor/bin/phinx in {$dir}\n");
        exit(2);
    }

    $config = writePhinxConfig($dir . '/db/migrations', $host, $port, $db, $user, $pass);

    echo "\n=== {$name} ===\n";
    $exit = 0;
    passthru(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phinx)
        . ' migrate -e mysql8 -c ' . escapeshellarg($config),
        $exit
    );
    @unlink($config);

    if ($exit !== 0) {
        $failures[] = "{$name}: phinx migrate exited {$exit} on MySQL {$version}.";
        continue;
    }

    echo "{$name}: " . appliedCount($host, $port, $db, $user, $pass) . " migration(s) applied.\n";
}

// The frontend has no single migration set — it composes every enabled
// extension's through its own runner into one shared phinxlog, so it must be
// driven exactly the way install.php's migrate_frontend() drives it.
echo "\n=== frontend (composed extensions) ===\n";

$autoload = $frontendDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "check-migrations-mysql8: no vendor/autoload.php in {$frontendDir}\n");
    exit(2);
}
require_once $autoload;

if (!class_exists(\Tds\CoreFrontendApi\Bootstrap::class)
    || !class_exists(\Tds\CoreFrontendApi\Support\MigrationRunner::class)) {
    fwrite(STDERR, "check-migrations-mysql8: tds-core-frontend-api classes not autoloadable.\n");
    exit(2);
}

$frontendDb = 'tds_frontend_install_check';
freshDatabase($server, $frontendDb);

$paths = \Tds\CoreFrontendApi\Bootstrap::migrationPaths();
echo 'composed migration paths: ' . count($paths) . "\n";

if ($paths === []) {
    $failures[] = 'frontend: Bootstrap::migrationPaths() is empty — no extension migrations would ever run.';
} else {
    try {
        (new \Tds\CoreFrontendApi\Support\MigrationRunner(
            $paths,
            ['host' => $host, 'port' => $port, 'name' => $frontendDb, 'user' => $user, 'pass' => $pass],
            sys_get_temp_dir() . '/tds-migrate-mysql8-check',
        ))->ensureMigrated();

        $applied = appliedCount($host, $port, $frontendDb, $user, $pass, 'phinxlog');
        echo "frontend: {$applied} migration(s) applied.\n";

        if ($applied === 0) {
            $failures[] = 'frontend: the runner completed but applied nothing — the phinxlog is empty.';
        }
    } catch (\Throwable $e) {
        $failures[] = 'frontend: ' . get_class($e) . ': ' . $e->getMessage();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\ncheck-migrations-mysql8: a fresh install would FAIL on the production host:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    fwrite(
        STDERR,
        "\nError 1171 means a PRIMARY KEY column is missing 'null' => false — Phinx defaults\n"
        . "every addColumn() to nullable and only MySQL 8 objects. Fix the migration in its own\n"
        . "repo; do NOT edit an already-released migration's version.\n\n"
    );
    exit(1);
}

echo "\ncheck-migrations-mysql8: a fresh install applies cleanly on MySQL {$version}.\n";
exit(0);

function envOr(string $key, string $default): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}

function connect(string $host, string $port, string $user, string $pass, ?string $db): PDO
{
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($db !== null) {
        $dsn .= ";dbname={$db}";
    }

    try {
        return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "check-migrations-mysql8: cannot connect ({$dsn}): {$e->getMessage()}\n");
        exit(2);
    }
}

/** Drop and recreate, so every run starts from the state a fresh host is in. */
function freshDatabase(PDO $server, string $db): void
{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
    $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function appliedCount(
    string $host,
    string $port,
    string $db,
    string $user,
    string $pass,
    string $table = 'phinx_migration'
): int {
    $pdo = connect($host, $port, $user, $pass, $db);

    return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

/** A throwaway Phinx config, so each repo's own phinx.php env handling is irrelevant here. */
function writePhinxConfig(
    string $migrationsDir,
    string $host,
    string $port,
    string $db,
    string $user,
    string $pass
): string {
    $config = tempnam(sys_get_temp_dir(), 'phinx') . '.php';

    file_put_contents($config, "<?php\nreturn " . var_export([
        'paths' => ['migrations' => $migrationsDir],
        'environments' => [
            'default_migration_table' => 'phinx_migration',
            'default_environment' => 'mysql8',
            'mysql8' => [
                'adapter' => 'mysql',
                'host' => $host,
                'port' => $port,
                'name' => $db,
                'user' => $user,
                'pass' => $pass,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ],
    ], true) . ";\n");

    return $config;
}
