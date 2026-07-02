<?php
declare(strict_types=1);

/**
 * TDS API — web install wizard.
 *
 * A self-contained setup assistant that ships inside the gateway bundle
 * (gateway/public/install.php → reachable at /install.php). It walks the
 * operator through:
 *
 *   1. Requirements — PHP version, extensions, writable service dirs, the
 *      ability to run migrations.
 *   2. Database — host/port/user/pass + one db name per service; tests the
 *      connection and can create the databases if they don't exist yet.
 *   3. Secrets — shared admin token, document-sign secret, CORS, plus the
 *      optional third-party keys (Resend, Stripe, GitHub).
 *   4. Install — writes each services/<name>/.env (+ the gateway .env),
 *      generates the auth RS256 keypair, creates the storage dirs, and runs
 *      every service's phinx migrations.
 *
 * SECURITY: this script writes config + connects to your database. It
 * refuses to run once the stack is configured (a .tds-installed lock or an
 * existing services/auth/.env), and the final screen tells you to delete it.
 * Delete gateway/public/install.php once you're live.
 *
 * No framework, no autoloader of its own — it shells out to each service's
 * bundled phinx for migrations and uses ext-openssl directly for the keypair.
 */

session_start();

$GATEWAY_DIR = dirname(__DIR__);                 // <bundle>/gateway
$BUNDLE_DIR  = dirname($GATEWAY_DIR);            // <bundle>
$SERVICES_DIR = $BUNDLE_DIR . '/services';
$LOCK_FILE = $BUNDLE_DIR . '/.tds-installed';

/** name => [label, default db name, .env builder key]. */
$SERVICES = [
    'auth'     => ['Auth-API', 'tds_auth'],
    'contact'  => ['Contact-API', 'tds_contact_ratelimit'],
    'content'  => ['Content-API', 'tds_content'],
    'customer' => ['Customer-API', 'tds_customer'],
];

// --- small helpers ----------------------------------------------------------
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function token(int $bytes = 24): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
function post(string $k, string $default = ''): string { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : $default; }
function cfg(string $k, string $default = ''): string { return (string) ($_SESSION['tds_install'][$k] ?? $default); }
function set_cfg(array $kv): void { $_SESSION['tds_install'] = array_merge($_SESSION['tds_install'] ?? [], $kv); }

function dsn_server(string $host, string $port): string { return "mysql:host={$host};port={$port};charset=utf8mb4"; }
function dsn_db(string $host, string $port, string $db): string { return "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4"; }

function migrations_available(): bool
{
    if (!function_exists('proc_open')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

/**
 * Resolve a CLI php binary. Under PHP-FPM, PHP_BINARY is the FPM binary, which
 * cannot run phinx — a prime suspect for "installer said OK but the DB is empty".
 * Prefer an explicit override, then the CLI next to the running binary, then a
 * clearly-CLI PHP_BINARY, then PATH. Mirrors MigrationRunner::phpCliBinary().
 */
function php_cli_binary(): string
{
    $override = getenv('GATEWAY_PHP_BINARY');
    if (is_string($override) && $override !== '') return $override;
    if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
        $candidate = PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (is_file($candidate)) return $candidate;
    }
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && !str_contains(strtolower(PHP_BINARY), 'fpm')) {
        return PHP_BINARY;
    }
    return 'php';
}

/** Build a services/<name>/.env file body from the collected config. */
function env_for(string $name, array $c): string
{
    $base = "APP_ENV=production\n"
        . "DB_HOST={$c['db_host']}\n"
        . "DB_PORT={$c['db_port']}\n"
        . "DB_USER={$c['db_user']}\n"
        . "DB_PASS={$c['db_pass']}\n";

    switch ($name) {
        case 'auth':
            return $base
                . "DB_NAME={$c['db_auth']}\n"
                . "ADMIN_TOKEN={$c['admin_token']}\n"
                . "JWT_PRIVATE_KEY=\n"
                . "JWT_KEY_ID={$c['jwt_key_id']}\n"
                . "JWT_ISSUER={$c['jwt_issuer']}\n"
                . "JWT_TTL_SECONDS=3600\n"
                . "JWT_REFRESH_TTL_SECONDS=2592000\n"
                . "COOKIE_DOMAIN={$c['cookie_domain']}\n"
                . "COOKIE_NAME=tds_session\n"
                . "LOGIN_RATE_LIMIT=10\n"
                . "LOGIN_RATE_WINDOW_SECONDS=900\n"
                . "CORS_ALLOWED_ORIGINS={$c['cors']}\n";
        case 'contact':
            return $base
                . "DB_NAME={$c['db_contact']}\n"
                . "RESEND_API_KEY={$c['resend_api_key']}\n"
                . "RESEND_FROM={$c['resend_from']}\n"
                . "CONTACT_EMAIL={$c['contact_email']}\n"
                . "RATE_LIMIT_PER_HOUR=3\n"
                . "CORS_ALLOWED_ORIGINS={$c['cors']}\n";
        case 'content':
            return $base
                . "DB_NAME={$c['db_content']}\n"
                . "ADMIN_TOKEN={$c['admin_token']}\n"
                . "AUTH_API_URL={$c['auth_api_url']}\n"
                . "BLOG_UPLOAD_DIR={$c['blog_upload_dir']}\n"
                . "GITHUB_DISPATCH_TOKEN={$c['github_token']}\n"
                . "BLOG_REBUILD_REPO=Tracht-Digital-Solutions/tds-blog\n"
                . "BLOG_REBUILD_WORKFLOW=build.yml\n"
                . "BLOG_REBUILD_REF=main\n"
                . "CORS_ALLOWED_ORIGINS={$c['cors']}\n";
        case 'customer':
            return $base
                . "DB_NAME={$c['db_customer']}\n"
                . "AUTH_API_URL={$c['auth_api_url']}\n"
                . "JWKS_CACHE_TTL=600\n"
                . "ADMIN_TOKEN={$c['admin_token']}\n"
                . "STRIPE_SECRET_KEY={$c['stripe_secret']}\n"
                . "STRIPE_WEBHOOK_SECRET={$c['stripe_webhook']}\n"
                . "STRIPE_PUBLIC_KEY={$c['stripe_public']}\n"
                . "STRIPE_RETURN_URL={$c['stripe_return_url']}\n"
                . "DOCUMENT_ROOT_DIR={$c['document_root_dir']}\n"
                . "DOCUMENT_SIGN_SECRET={$c['document_sign_secret']}\n"
                . "CORS_ALLOWED_ORIGINS={$c['cors']}\n";
    }
    return $base;
}

/** Generate the auth RS256 keypair (no-op if it already exists). */
function generate_keypair(string $authDir): array
{
    $keysDir = $authDir . '/keys';
    $priv = $keysDir . '/private.pem';
    $pub = $keysDir . '/public.pem';
    if (is_file($priv)) {
        return [true, 'keys/private.pem existierte bereits — unverändert gelassen.'];
    }
    if (!is_dir($keysDir) && !@mkdir($keysDir, 0700, true)) {
        return [false, 'Konnte services/auth/keys/ nicht anlegen.'];
    }
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($res === false || !openssl_pkey_export($res, $privPem)) {
        return [false, 'openssl konnte kein Schlüsselpaar erzeugen.'];
    }
    $details = openssl_pkey_get_details($res);
    if ($details === false || !isset($details['key'])) {
        return [false, 'openssl_pkey_get_details fehlgeschlagen.'];
    }
    file_put_contents($priv, $privPem);
    @chmod($priv, 0600);
    file_put_contents($pub, $details['key']);
    @chmod($pub, 0644);
    return [true, 'RS256-Schlüsselpaar erzeugt (keys/private.pem + public.pem).'];
}

/**
 * Run `php vendor/bin/phinx migrate -e production` in a service dir.
 *
 * Reads the child's pipes non-blocking against a wall-clock deadline so a stalled
 * migration (e.g. a DB connect that never answers) can't hang the request forever —
 * the old blocking `stream_get_contents()` was the prime suspect for "installer hangs".
 */
function run_migration(string $serviceDir, int $timeout = 120): array
{
    // Prefer the gateway's in-process migrator (Phinx's Manager API): it needs no
    // proc_open and no CLI php — which is exactly why the old shell-out silently
    // applied nothing and left the prod DBs empty on this host. Fall back to the
    // subprocess below only if the gateway autoloader / Phinx can't be loaded.
    $gatewayAutoload = dirname(__DIR__) . '/vendor/autoload.php'; // <bundle>/gateway/vendor
    if (is_file($gatewayAutoload)) {
        require_once $gatewayAutoload;
        if (class_exists(\Tds\ApiGateway\Support\MigrationRunner::class)) {
            return \Tds\ApiGateway\Support\MigrationRunner::migrateServiceDir($serviceDir, $timeout);
        }
    }

    $phinx = $serviceDir . '/vendor/bin/phinx';
    if (!is_file($phinx)) {
        return [false, 'phinx nicht gefunden (vendor/ fehlt im Bundle).'];
    }
    if (!migrations_available()) {
        return [false, 'proc_open ist deaktiviert und In-Process-Migration nicht verfügbar — Migration bitte manuell ausführen (siehe unten).'];
    }
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = [php_cli_binary(), 'vendor/bin/phinx', 'migrate', '-e', 'production'];
    $proc = @proc_open($cmd, $descriptors, $pipes, $serviceDir);
    if (!is_resource($proc)) {
        return [false, 'Konnte den Migrationsprozess nicht starten.'];
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
            return [false, "Zeitüberschreitung nach {$timeout}s — Migration abgebrochen "
                . "(DB erreichbar? Zugangsdaten korrekt?).\n" . trim($out)];
        }
        usleep(100_000);
    }
    // Drain anything buffered after the process exited.
    $out .= (string) stream_get_contents($pipes[1]);
    $out .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code === 0, trim($out)];
}

/** Ordered list of install steps the apply phase runs, as [id, label] pairs. */
function install_tasks(): array
{
    return [
        ['env_gateway',  'gateway/.env'],
        ['env_auth',     'services/auth/.env'],
        ['env_contact',  'services/contact/.env'],
        ['env_content',  'services/content/.env'],
        ['env_customer', 'services/customer/.env'],
        ['keys',         'Auth RS256-Schlüsselpaar'],
        ['dir_documents','Dokument-Verzeichnis'],
        ['dir_blog',     'Blog-Upload-Verzeichnis'],
        ['migrate_auth',    'Migration: auth'],
        ['migrate_contact', 'Migration: contact'],
        ['migrate_content', 'Migration: content'],
        ['migrate_customer','Migration: customer'],
        ['finalize',     'Abschluss'],
    ];
}

/**
 * Execute one install task by id. Returns [ok, human-readable detail].
 * Each task is small and idempotent so the wizard can drive them one-by-one
 * over AJAX with a live progress bar (no single multi-minute blocking request).
 */
function run_task(string $id, array $c, string $gatewayDir, string $servicesDir, string $lockFile): array
{
    switch ($id) {
        case 'env_gateway':
            $ok = @file_put_contents($gatewayDir . '/.env',
                "APP_ENV=production\nADMIN_TOKEN={$c['admin_token']}\n") !== false;
            return [$ok, $ok ? 'gateway/.env geschrieben.' : 'Konnte gateway/.env nicht schreiben.'];

        case 'env_auth':
        case 'env_contact':
        case 'env_content':
        case 'env_customer':
            $name = substr($id, 4); // strip "env_"
            $ok = @file_put_contents($servicesDir . '/' . $name . '/.env', env_for($name, $c)) !== false;
            return [$ok, $ok ? "services/{$name}/.env geschrieben." : "Konnte services/{$name}/.env nicht schreiben."];

        case 'keys':
            return generate_keypair($servicesDir . '/auth');

        case 'dir_documents':
            $dir = $c['document_root_dir'];
            $ok = is_dir($dir) || @mkdir($dir, 0700, true);
            return [$ok, $ok ? $dir : "Konnte {$dir} nicht anlegen."];

        case 'dir_blog':
            $dir = $c['blog_upload_dir'];
            $ok = is_dir($dir) || @mkdir($dir, 0775, true);
            return [$ok, $ok ? $dir : "Konnte {$dir} nicht anlegen."];

        case 'migrate_auth':
        case 'migrate_contact':
        case 'migrate_content':
        case 'migrate_customer':
            $name = substr($id, 8); // strip "migrate_"
            return run_migration($servicesDir . '/' . $name);

        case 'finalize':
            $ok = @file_put_contents($lockFile, gmdate('c') . "\n") !== false;
            return [$ok, $ok ? 'Installation abgeschlossen — Sperre gesetzt.' : 'Konnte .tds-installed nicht schreiben.'];
    }
    return [false, "Unbekannter Schritt: {$id}"];
}

// --- guard: already installed? ----------------------------------------------
$alreadyInstalled = file_exists($LOCK_FILE) || is_file($SERVICES_DIR . '/auth/.env');
$bundleOk = is_dir($SERVICES_DIR . '/auth') && is_dir($SERVICES_DIR . '/customer');

// --- routing -----------------------------------------------------------------
$step = (int) ($_POST['__step'] ?? $_GET['step'] ?? 1);
$errors = [];
$applyResults = null;

// --- AJAX: run a single install task and return JSON -------------------------
// The apply phase (step 4) is driven one task at a time from the browser so the
// progress bar can advance and no request runs for minutes. Gate on the lock file
// only (NOT $alreadyInstalled) — the first task writes services/auth/.env, which
// would otherwise flip $alreadyInstalled and abort every following task.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__task'])) {
    // This endpoint MUST return JSON no matter what. A stray PHP warning/notice
    // rendered as HTML (`<br /><b>Warning</b>…`) would make the browser's
    // r.json() choke with "Unexpected token '<'". So: suppress HTML error output
    // and install a shutdown guard that still emits a JSON error if a fatal kills
    // the script before we responded.
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);
    @set_time_limit(0);
    ignore_user_abort(true);

    $responded = false;
    $respond = static function (bool $ok, string $detail) use (&$responded): void {
        if ($responded) {
            return;
        }
        $responded = true;
        echo json_encode(['ok' => $ok, 'detail' => $detail], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
    register_shutdown_function(static function () use ($respond): void {
        $e = error_get_last();
        if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $respond(false, 'PHP-Fatal: ' . $e['message'] . ' @ ' . basename((string) $e['file']) . ':' . $e['line']);
        }
    });
    $fail = static function (string $msg) use ($respond): never {
        $respond(false, $msg);
        exit;
    };

    if (!$bundleOk) {
        $fail('Service-Verzeichnisse nicht gefunden — Bundle nicht assembliert.');
    }
    if (file_exists($LOCK_FILE)) {
        $fail('Bereits installiert (.tds-installed vorhanden).');
    }
    $c = $_SESSION['tds_install'] ?? null;
    if (!is_array($c) || ($c['db_user'] ?? '') === '') {
        $fail('Sitzung abgelaufen — bitte den Assistenten von vorne starten.');
    }

    try {
        [$ok, $detail] = run_task((string) $_POST['__task'], $c, $GATEWAY_DIR, $SERVICES_DIR, $LOCK_FILE);
        $respond((bool) $ok, (string) $detail);
    } catch (\Throwable $e) {
        $respond(false, 'Ausnahme: ' . $e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled && $bundleOk) {
    if ($step === 2) {
        // Save DB form, test connection, optionally create databases.
        set_cfg([
            'db_host' => post('db_host', '127.0.0.1'),
            'db_port' => post('db_port', '3306'),
            'db_user' => post('db_user'),
            'db_pass' => post('db_pass'),
            'db_auth' => post('db_auth', 'tds_auth'),
            'db_contact' => post('db_contact', 'tds_contact_ratelimit'),
            'db_content' => post('db_content', 'tds_content'),
            'db_customer' => post('db_customer', 'tds_customer'),
        ]);
        $c = $_SESSION['tds_install'];
        try {
            $pdo = new PDO(dsn_server($c['db_host'], $c['db_port']), $c['db_user'], $c['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $dbs = [$c['db_auth'], $c['db_contact'], $c['db_content'], $c['db_customer']];
            if (post('create_dbs') === '1') {
                foreach ($dbs as $db) {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $db)
                        . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                }
            }
            foreach ($dbs as $db) {
                new PDO(dsn_db($c['db_host'], $c['db_port'], $db), $c['db_user'], $c['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
            }
            $step = 3; // success → secrets
        } catch (Throwable $e) {
            $errors[] = 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage();
            $step = 2;
        }
    } elseif ($step === 3) {
        set_cfg([
            'admin_token' => post('admin_token') ?: token(),
            'cors' => post('cors'),
            'cookie_domain' => post('cookie_domain', '.tracht-digital.de'),
            'jwt_issuer' => post('jwt_issuer', 'https://api.tracht-digital.de/auth'),
            'jwt_key_id' => post('jwt_key_id', 'tds-auth-2026-1'),
            'auth_api_url' => post('auth_api_url', 'https://api.tracht-digital.de/auth'),
            'resend_api_key' => post('resend_api_key'),
            'resend_from' => post('resend_from', 'noreply@tracht-digital.de'),
            'contact_email' => post('contact_email', 'hallo@tracht-digital.de'),
            'github_token' => post('github_token'),
            'stripe_secret' => post('stripe_secret'),
            'stripe_webhook' => post('stripe_webhook'),
            'stripe_public' => post('stripe_public'),
            'stripe_return_url' => post('stripe_return_url', 'https://app.tracht-digital.de/invoices'),
            'document_sign_secret' => post('document_sign_secret') ?: token(32),
            'document_root_dir' => post('document_root_dir', $BUNDLE_DIR . '/var/customer-files'),
            'blog_upload_dir' => post('blog_upload_dir', $BUNDLE_DIR . '/var/blog-uploads'),
        ]);
        $step = 4; // → review/apply
    } elseif ($step === 4) {
        // APPLY (no-JS fallback): run every task in one request. The JS path drives
        // run_task() one-at-a-time over AJAX with a progress bar; this branch only
        // fires when JavaScript is off, so lift the time limit and reuse run_task().
        @set_time_limit(0);
        ignore_user_abort(true);
        $c = $_SESSION['tds_install'];
        $applyResults = ['env' => [], 'keys' => null, 'dirs' => [], 'migrations' => []];

        foreach (['gateway', 'auth', 'contact', 'content', 'customer'] as $name) {
            $id = $name === 'gateway' ? 'env_gateway' : 'env_' . $name;
            [$ok] = run_task($id, $c, $GATEWAY_DIR, $SERVICES_DIR, $LOCK_FILE);
            $applyResults['env'][$name] = $ok;
        }

        $applyResults['keys'] = run_task('keys', $c, $GATEWAY_DIR, $SERVICES_DIR, $LOCK_FILE);

        foreach (['dir_documents' => 'document_root_dir', 'dir_blog' => 'blog_upload_dir'] as $id => $key) {
            [$ok, $detail] = run_task($id, $c, $GATEWAY_DIR, $SERVICES_DIR, $LOCK_FILE);
            $applyResults['dirs'][$c[$key]] = $ok;
        }

        foreach (['auth', 'contact', 'content', 'customer'] as $name) {
            $applyResults['migrations'][$name] = run_task('migrate_' . $name, $c, $GATEWAY_DIR, $SERVICES_DIR, $LOCK_FILE);
        }

        run_task('finalize', $c, $GATEWAY_DIR, $SERVICES_DIR, $LOCK_FILE);
        $step = 5; // done
    }
}

// Apply the "delete installer" action from the done screen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('__action') === 'self_delete') {
    @unlink(__FILE__);
    $selfDeleted = !file_exists(__FILE__);
    $step = 5;
}

// =============================================================================
// RENDER
// =============================================================================
$steps = [1 => 'Voraussetzungen', 2 => 'Datenbank', 3 => 'Konfiguration', 4 => 'Installieren', 5 => 'Fertig'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex" />
<title>TDS API — Installation</title>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&family=Instrument+Serif&family=Plus+Jakarta+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<style>
  /* Flat design — matches the admin/customer panels: solid surfaces, hairlines
     + accent bars, one navy accent, 0.75rem radius. No gradients, no blur, no
     lifted shadows, no decorative motion. */
  :root{--haupt:#050f68;--akzent:#820933;--paper:#fafaf7;--soft:#f1efe8;--line:#e4e2da;
    --ink:#1a1a17;--muted:#6b6b66;--card:#fff;
    --ok:#146c43;--okbg:#e6f4ea;--err:#a51d1d;--errbg:#fbeaea;--warn:#8a5a00;--warnbg:#fff4d6;
    --tint:color-mix(in srgb,var(--haupt) 7%,var(--paper));
    --serif:"Instrument Serif",Georgia,serif;
    --fd:"Hanken Grotesk",system-ui,sans-serif;--fb:"Plus Jakarta Sans",system-ui,sans-serif;
    --fm:"JetBrains Mono",ui-monospace,monospace;}
  *{box-sizing:border-box}
  body{margin:0;font-family:var(--fb);background:var(--paper);color:var(--ink);line-height:1.55;min-height:100vh}

  .wrap{max-width:720px;margin:0 auto;padding:clamp(28px,6vw,56px) 20px 80px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:clamp(24px,4vw,40px)}

  .brandmark{display:inline-flex;align-items:center;gap:10px;margin-bottom:14px}
  .brandmark .mk{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;color:#fff;
    font-family:var(--fd);font-weight:800;font-size:16px;background:var(--haupt)}
  .brandmark .wd{font-family:var(--serif);font-weight:400;font-size:18px;letter-spacing:.01em}
  .brandmark .wd i{font-style:italic;color:var(--akzent)}

  h1{font-family:var(--fd);font-weight:800;letter-spacing:-.03em;font-size:clamp(28px,5vw,38px);
    margin:0 0 12px;position:relative;display:inline-block}
  h1::after{content:"";position:absolute;left:0;bottom:-6px;height:3px;width:52px;background:var(--haupt)}
  .eyebrow{font-family:var(--fd);font-weight:700;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--akzent);margin:0 0 8px}
  h2{font-family:var(--fd);font-weight:700;letter-spacing:-.02em;font-size:21px;margin:30px 0 6px}
  p{margin:0 0 14px;color:var(--muted)}
  code{font-family:var(--fm);font-size:.85em;background:var(--soft);padding:.1em .4em;border-radius:4px}

  .steps{display:flex;flex-wrap:wrap;gap:8px;margin:22px 0 30px;font-size:13px}
  .steps span{display:inline-flex;align-items:center;padding:6px 12px;border-radius:8px;border:1px solid var(--line);
    color:var(--muted);background:var(--paper)}
  .steps span.on{background:var(--haupt);color:#fff;border-color:var(--haupt);font-weight:600}
  .steps span.done{border-color:color-mix(in srgb,var(--ok) 45%,var(--line));color:var(--ok);background:var(--okbg)}

  label{display:block;font-weight:600;font-size:14px;margin:14px 0 5px}
  label .opt{font-weight:400;color:var(--muted);font-size:12px}
  input[type=text],input[type=password]{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:8px;
    font-family:var(--fm);font-size:14px;background:#fff;color:var(--ink);transition:border-color .15s ease,box-shadow .15s ease}
  input:focus{outline:none;border-color:var(--haupt);box-shadow:0 0 0 2px color-mix(in srgb,var(--haupt) 18%,transparent)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .grid4{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  fieldset{border:1px solid var(--line);border-radius:12px;padding:18px;margin:20px 0;background:var(--tint)}
  legend{font-family:var(--fd);font-weight:700;padding:0 8px;font-size:14px}

  .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--haupt);border-radius:10px;color:#fff;
    background:var(--haupt);font-family:var(--fd);font-weight:600;font-size:15px;padding:11px 22px;cursor:pointer;
    text-decoration:none;margin-top:20px;transition:background .15s ease}
  .btn:hover{background:color-mix(in srgb,var(--haupt) 88%,#000)}
  .btn:disabled{opacity:.55;cursor:default}
  .btn.ghost{background:transparent;color:var(--haupt);border-color:var(--line)}
  .btn.ghost:hover{background:var(--tint)}

  .check{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line);font-size:14px}
  .badge{font-family:var(--fm);font-size:11px;font-weight:600;padding:3px 9px;border-radius:6px;white-space:nowrap}
  .b-ok{background:var(--okbg);color:var(--ok)} .b-err{background:var(--errbg);color:var(--err)} .b-warn{background:var(--warnbg);color:var(--warn)}
  .note{padding:12px 15px;border-radius:10px;font-size:14px;margin:14px 0;border-left:3px solid transparent}
  .note.err{background:var(--errbg);color:var(--err);border-left-color:var(--err)}
  .note.ok{background:var(--okbg);color:var(--ok);border-left-color:var(--ok)}
  .note.warn{background:var(--warnbg);color:var(--warn);border-left-color:var(--warn)}
  pre{background:var(--ink);color:#f5f3ec;padding:12px 14px;border-radius:8px;overflow:auto;font-family:var(--fm);font-size:12px;max-height:220px}
  .cb{display:flex;gap:8px;align-items:center;margin-top:14px;font-size:14px;color:var(--ink)}
  .muted-line{font-size:13px;color:var(--muted);margin-top:6px}

  #progress{margin-top:24px}
  .bar{height:10px;border-radius:6px;overflow:hidden;background:var(--soft);border:1px solid var(--line)}
  .bar>i{display:block;height:100%;width:0;background:var(--haupt);transition:width .35s ease}
  #progressLabel{font-family:var(--fd);font-weight:600;color:var(--ink);margin:12px 0 4px}
  #log{margin-top:6px}
  @media (prefers-reduced-motion:reduce){.bar>i,input{transition:none}}
</style>
</head>
<body>
<main class="wrap">
<div class="card">
  <div class="brandmark"><span class="mk">T</span><span class="wd">Tracht <i>Digital</i></span></div>
  <p class="eyebrow">API-Gateway · Setup</p>
  <h1>Installation</h1>
  <p>Assistent zum Einrichten der API-Plattform und Verbinden der Datenbank.</p>

  <div class="steps">
    <?php foreach ($steps as $n => $label): ?>
      <span class="<?= $n === $step ? 'on' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= h($label) ?></span>
    <?php endforeach; ?>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="note err"><?= h($e) ?></div>
  <?php endforeach; ?>

  <?php if (!$bundleOk): ?>
    <div class="note err">
      Die Service-Verzeichnisse wurden nicht gefunden (<code><?= h($SERVICES_DIR) ?></code>).
      Diesen Assistenten bitte aus dem assemblierten <code>build</code>-Bundle heraus aufrufen
      (Gateway-Docroot zeigt auf <code>gateway/public</code>, die Services liegen unter <code>services/</code>).
    </div>
  <?php elseif ($alreadyInstalled && $step !== 5): ?>
    <div class="note warn">
      <strong>Bereits installiert.</strong> Es existiert eine <code>.tds-installed</code>-Sperre oder eine
      <code>services/auth/.env</code>. Aus Sicherheitsgründen läuft der Assistent nicht erneut.
      Zum Neu-Konfigurieren die Sperre und die <code>.env</code>-Dateien löschen.
      <strong>Bitte löschen Sie jetzt <code>gateway/public/install.php</code>.</strong>
    </div>

  <?php elseif ($step === 1): ?>
    <?php
      $checks = [];
      $checks[] = ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), 'mind. PHP 8.1 (empfohlen 8.3)'];
      foreach (['pdo_mysql', 'openssl', 'mbstring', 'curl', 'json'] as $ext) {
          $checks[] = ['Erweiterung ' . $ext, extension_loaded($ext), ''];
      }
      $writable = is_writable($SERVICES_DIR . '/auth') && is_writable($SERVICES_DIR . '/customer');
      $checks[] = ['Service-Verzeichnisse beschreibbar', $writable, 'für .env + Schlüssel'];
      // Migrations run in-process via the gateway's MigrationRunner (Phinx
      // Manager API) — no proc_open needed. Available whenever the gateway
      // vendor/ is present; proc_open is only the legacy fallback.
      $inProcessMig = is_file($GATEWAY_DIR . '/vendor/autoload.php');
      $checks[] = ['Migrationen ausführbar', $inProcessMig || migrations_available(),
          $inProcessMig ? 'in-process (Phinx) — kein proc_open nötig' : 'via proc_open (Fallback)'];
      $hardFail = !version_compare(PHP_VERSION, '8.1.0', '>=')
        || !extension_loaded('pdo_mysql') || !extension_loaded('openssl') || !$writable;
    ?>
    <h2>Voraussetzungen</h2>
    <?php foreach ($checks as [$label, $ok, $hint]): ?>
      <div class="check">
        <span class="badge <?= $ok ? 'b-ok' : 'b-err' ?>"><?= $ok ? 'OK' : 'Fehlt' ?></span>
        <span><?= h($label) ?><?php if ($hint): ?> <span style="color:var(--muted)">— <?= h($hint) ?></span><?php endif; ?></span>
      </div>
    <?php endforeach; ?>
    <?php if ($hardFail): ?>
      <div class="note err">Bitte zuerst die rot markierten Punkte beheben.</div>
    <?php else: ?>
      <form method="get"><input type="hidden" name="step" value="2" /><button class="btn" type="submit">Weiter zur Datenbank →</button></form>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <h2>Datenbank verbinden</h2>
    <p>MariaDB/MySQL-Zugangsdaten. Je Service eine eigene Datenbank (verhindert Kollisionen der Migrations-Tabellen).</p>
    <form method="post">
      <input type="hidden" name="__step" value="2" />
      <div class="row">
        <div><label>Host</label><input type="text" name="db_host" value="<?= h(cfg('db_host', '127.0.0.1')) ?>" /></div>
        <div><label>Port</label><input type="text" name="db_port" value="<?= h(cfg('db_port', '3306')) ?>" /></div>
      </div>
      <div class="row">
        <div><label>Benutzer</label><input type="text" name="db_user" value="<?= h(cfg('db_user')) ?>" autocomplete="off" /></div>
        <div><label>Passwort</label><input type="password" name="db_pass" value="<?= h(cfg('db_pass')) ?>" autocomplete="off" /></div>
      </div>
      <fieldset>
        <legend>Datenbanknamen</legend>
        <div class="grid4">
          <div><label>Auth</label><input type="text" name="db_auth" value="<?= h(cfg('db_auth', 'tds_auth')) ?>" /></div>
          <div><label>Contact</label><input type="text" name="db_contact" value="<?= h(cfg('db_contact', 'tds_contact_ratelimit')) ?>" /></div>
          <div><label>Content</label><input type="text" name="db_content" value="<?= h(cfg('db_content', 'tds_content')) ?>" /></div>
          <div><label>Customer</label><input type="text" name="db_customer" value="<?= h(cfg('db_customer', 'tds_customer')) ?>" /></div>
        </div>
        <label class="cb"><input type="checkbox" name="create_dbs" value="1" checked /> Fehlende Datenbanken anlegen (Benutzer braucht das <code>CREATE</code>-Recht)</label>
      </fieldset>
      <button class="btn" type="submit">Verbindung testen &amp; weiter →</button>
    </form>

  <?php elseif ($step === 3): ?>
    <h2>Konfiguration</h2>
    <p>Geheimnisse + Dienst-URLs. Vorgaben sind sinnvoll voreingestellt; Drittanbieter-Schlüssel können leer bleiben und später ergänzt werden.</p>
    <form method="post">
      <input type="hidden" name="__step" value="3" />
      <fieldset>
        <legend>Kern</legend>
        <label>Admin-Token <span class="opt">(geteilt von auth/content/customer + /wiki)</span></label>
        <input type="text" name="admin_token" value="<?= h(cfg('admin_token', token())) ?>" />
        <label>CORS-Origins <span class="opt">(kommagetrennt)</span></label>
        <input type="text" name="cors" value="<?= h(cfg('cors', 'https://tracht-digital.de,https://blog.tracht-digital.de,https://admin.tracht-digital.de,https://app.tracht-digital.de')) ?>" />
        <div class="row">
          <div><label>Cookie-Domain</label><input type="text" name="cookie_domain" value="<?= h(cfg('cookie_domain', '.tracht-digital.de')) ?>" /></div>
          <div><label>Auth-API-URL</label><input type="text" name="auth_api_url" value="<?= h(cfg('auth_api_url', 'https://api.tracht-digital.de/auth')) ?>" /></div>
        </div>
        <label>Document-Sign-Secret <span class="opt">(leer = automatisch)</span></label>
        <input type="text" name="document_sign_secret" value="<?= h(cfg('document_sign_secret', token(32))) ?>" />
      </fieldset>
      <fieldset>
        <legend>Drittanbieter (optional)</legend>
        <label>Resend API-Key <span class="opt">(Kontaktformular-Mail)</span></label>
        <input type="text" name="resend_api_key" value="<?= h(cfg('resend_api_key')) ?>" />
        <div class="row">
          <div><label>Stripe Secret Key</label><input type="text" name="stripe_secret" value="<?= h(cfg('stripe_secret')) ?>" /></div>
          <div><label>Stripe Webhook Secret</label><input type="text" name="stripe_webhook" value="<?= h(cfg('stripe_webhook')) ?>" /></div>
        </div>
        <div class="row">
          <div><label>Stripe Public Key</label><input type="text" name="stripe_public" value="<?= h(cfg('stripe_public')) ?>" /></div>
          <div><label>GitHub Dispatch Token <span class="opt">(Blog-Rebuild)</span></label><input type="text" name="github_token" value="<?= h(cfg('github_token')) ?>" /></div>
        </div>
      </fieldset>
      <fieldset>
        <legend>Speicherpfade</legend>
        <label>Dokument-Verzeichnis <span class="opt">(außerhalb des Webroots, 700)</span></label>
        <input type="text" name="document_root_dir" value="<?= h(cfg('document_root_dir', $BUNDLE_DIR . '/var/customer-files')) ?>" />
        <label>Blog-Upload-Verzeichnis</label>
        <input type="text" name="blog_upload_dir" value="<?= h(cfg('blog_upload_dir', $BUNDLE_DIR . '/var/blog-uploads')) ?>" />
      </fieldset>
      <button class="btn" type="submit">Weiter zur Übersicht →</button>
    </form>

  <?php elseif ($step === 4): ?>
    <h2>Übersicht &amp; Installation</h2>
    <p>Es werden je Service eine <code>.env</code> geschrieben, das Auth-Schlüsselpaar erzeugt, die Speicherpfade angelegt und die Datenbank-Migrationen ausgeführt.</p>
    <div class="note warn">Vorhandene <code>.env</code>-Dateien werden überschrieben. Secrets liegen danach im Klartext in den Service-Verzeichnissen.</div>

    <div id="review">
      <div class="check"><span class="badge b-ok">DB</span><span><?= h(cfg('db_user')) ?>@<?= h(cfg('db_host')) ?>:<?= h(cfg('db_port')) ?></span></div>
      <div class="check"><span class="badge b-ok">.env</span><span>auth, contact, content, customer + gateway</span></div>
      <div class="check"><span class="badge b-ok">Keys</span><span>Auth RS256-Schlüsselpaar</span></div>
      <div class="check"><span class="badge <?= migrations_available() ? 'b-ok' : 'b-warn' ?>">Migrationen</span><span><?= migrations_available() ? 'phinx je Service' : 'proc_open deaktiviert — manuell nötig' ?></span></div>
    </div>

    <button class="btn" type="button" id="startBtn" data-tasks='<?= h(json_encode(install_tasks(), JSON_UNESCAPED_UNICODE)) ?>'>Jetzt installieren →</button>

    <!-- Live progress (revealed by JS once the install starts) -->
    <div id="progress" hidden>
      <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <i id="barFill"></i>
      </div>
      <p class="muted-line" id="progressLabel">Installation läuft …</p>
      <div id="log"></div>
    </div>

    <!-- Completion panel (revealed by JS when every task is done) -->
    <div id="donePanel" hidden>
      <h2 id="doneHeading">Installation abgeschlossen</h2>
      <div class="note ok">
        Fertig — es müssen <strong>keine Dienst-Prozesse gestartet</strong> werden: Das Gateway bedient die
        vier APIs im selben PHP-FPM-Prozess (<code>GATEWAY_MODE=inprocess</code>). Prüfen Sie die Plattform
        direkt über <code>/healthz</code> und das interne Wiki unter <code>/wiki</code>.
      </div>
      <div class="note err">
        <strong>Sicherheit:</strong> Bitte löschen Sie jetzt <code>gateway/public/install.php</code>.
      </div>
      <form method="post" onsubmit="return confirm('install.php endgültig löschen?');">
        <input type="hidden" name="__action" value="self_delete" />
        <button class="btn" type="submit">install.php jetzt löschen</button>
      </form>
    </div>

    <noscript>
      <div class="note warn">JavaScript ist deaktiviert — die Installation läuft als ein einzelner Vorgang (kann ein bis zwei Minuten dauern, kein Fortschrittsbalken).</div>
      <form method="post">
        <input type="hidden" name="__step" value="4" />
        <button class="btn" type="submit">Jetzt installieren →</button>
      </form>
    </noscript>

    <script>
    (function () {
      var btn = document.getElementById('startBtn');
      if (!btn) return;
      var tasks = JSON.parse(btn.getAttribute('data-tasks'));
      var progress = document.getElementById('progress');
      var fill = document.getElementById('barFill');
      var bar = progress.querySelector('.bar');
      var label = document.getElementById('progressLabel');
      var log = document.getElementById('log');
      var review = document.getElementById('review');

      function badge(ok) {
        var s = document.createElement('span');
        s.className = 'badge ' + (ok ? 'b-ok' : 'b-err');
        s.textContent = ok ? 'OK' : 'Fehler';
        return s;
      }
      function row(taskLabel) {
        var d = document.createElement('div');
        d.className = 'check';
        var b = document.createElement('span');
        b.className = 'badge b-warn';
        b.textContent = '…';
        var t = document.createElement('span');
        t.textContent = taskLabel;
        d.appendChild(b); d.appendChild(t);
        log.appendChild(d);
        return { node: d, badge: b, text: t };
      }
      function setProgress(done, total) {
        var pct = Math.round((done / total) * 100);
        fill.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
      }

      function runTask(id) {
        var body = new URLSearchParams();
        body.set('__task', id);
        return fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
          body: body.toString(),
          credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
          .catch(function (e) { return { ok: false, detail: 'Netzwerkfehler: ' + e }; });
      }

      btn.addEventListener('click', function () {
        btn.disabled = true;
        if (review) review.hidden = true;
        progress.hidden = false;
        var total = tasks.length, i = 0, anyFail = false;

        function next() {
          if (i >= total) {
            label.textContent = anyFail
              ? 'Abgeschlossen — einige Schritte sind fehlgeschlagen (siehe oben).'
              : 'Alle Schritte erfolgreich abgeschlossen.';
            var heading = document.getElementById('doneHeading');
            if (anyFail) heading.textContent = 'Installation mit Fehlern abgeschlossen';
            // Reflect completion in the step indicator.
            var spans = document.querySelectorAll('.steps span');
            if (spans[3]) { spans[3].className = 'done'; }
            if (spans[4]) { spans[4].className = 'on'; }
            document.getElementById('donePanel').hidden = false;
            return;
          }
          var task = tasks[i];
          label.textContent = 'Schritt ' + (i + 1) + ' von ' + total + ': ' + task[1];
          var r = row(task[1]);
          runTask(task[0]).then(function (res) {
            r.badge.className = 'badge ' + (res.ok ? 'b-ok' : 'b-err');
            r.badge.textContent = res.ok ? 'OK' : 'Fehler';
            if (res.detail) {
              if (res.ok) {
                var hint = document.createElement('span');
                hint.style.color = 'var(--muted)';
                hint.textContent = ' — ' + res.detail;
                r.text.appendChild(hint);
              } else {
                anyFail = true;
                var pre = document.createElement('pre');
                pre.textContent = res.detail;
                r.node.insertAdjacentElement('afterend', pre);
              }
            }
            i++;
            setProgress(i, total);
            next();
          });
        }
        next();
      });
    })();
    </script>

  <?php elseif ($step === 5): ?>
    <?php $res = $applyResults; ?>
    <h2>Installation abgeschlossen</h2>
    <?php if ($res): ?>
      <h2>.env-Dateien</h2>
      <?php foreach ($res['env'] as $name => $ok): ?>
        <div class="check"><span class="badge <?= $ok ? 'b-ok' : 'b-err' ?>"><?= $ok ? 'OK' : 'Fehler' ?></span><span>services/<?= h($name) ?>/.env</span></div>
      <?php endforeach; ?>
      <h2>Schlüssel &amp; Pfade</h2>
      <div class="check"><span class="badge <?= $res['keys'][0] ? 'b-ok' : 'b-err' ?>"><?= $res['keys'][0] ? 'OK' : 'Fehler' ?></span><span><?= h($res['keys'][1]) ?></span></div>
      <?php foreach ($res['dirs'] as $dir => $ok): ?>
        <div class="check"><span class="badge <?= $ok ? 'b-ok' : 'b-err' ?>"><?= $ok ? 'OK' : 'Fehler' ?></span><span><?= h($dir) ?></span></div>
      <?php endforeach; ?>
      <h2>Migrationen</h2>
      <?php foreach ($res['migrations'] as $name => [$ok, $out]): ?>
        <div class="check"><span class="badge <?= $ok ? 'b-ok' : 'b-err' ?>"><?= $ok ? 'OK' : 'Fehler' ?></span><span><?= h($name) ?></span></div>
        <?php if (!$ok && $out): ?><pre><?= h($out) ?></pre><?php endif; ?>
      <?php endforeach; ?>
    <?php elseif (!empty($selfDeleted)): ?>
      <div class="note ok">install.php wurde gelöscht. Der Assistent ist nicht mehr erreichbar.</div>
    <?php endif; ?>

    <div class="note ok">
      Fertig — es müssen <strong>keine Dienst-Prozesse gestartet</strong> werden: Das Gateway bedient die
      vier APIs im selben PHP-FPM-Prozess (<code>GATEWAY_MODE=inprocess</code>). Prüfen Sie die Plattform
      direkt über <code>/healthz</code> und das interne Wiki unter <code>/wiki</code>.
    </div>
    <div class="note err">
      <strong>Sicherheit:</strong> Bitte löschen Sie jetzt <code>gateway/public/install.php</code>.
    </div>
    <form method="post" onsubmit="return confirm('install.php endgültig löschen?');">
      <input type="hidden" name="__action" value="self_delete" />
      <button class="btn" type="submit">install.php jetzt löschen</button>
    </form>
  <?php endif; ?>
</div>
</main>
</body>
</html>
