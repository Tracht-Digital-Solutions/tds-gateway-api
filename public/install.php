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

/** Run `php vendor/bin/phinx migrate -e production` in a service dir. */
function run_migration(string $serviceDir): array
{
    $phinx = $serviceDir . '/vendor/bin/phinx';
    if (!is_file($phinx)) {
        return [false, 'phinx nicht gefunden (vendor/ fehlt im Bundle).'];
    }
    if (!migrations_available()) {
        return [false, 'proc_open ist deaktiviert — Migration bitte manuell ausführen (siehe unten).'];
    }
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = [PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-e', 'production'];
    $proc = @proc_open($cmd, $descriptors, $pipes, $serviceDir);
    if (!is_resource($proc)) {
        return [false, 'Konnte den Migrationsprozess nicht starten.'];
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code === 0, trim((string) $out)];
}

// --- guard: already installed? ----------------------------------------------
$alreadyInstalled = file_exists($LOCK_FILE) || is_file($SERVICES_DIR . '/auth/.env');
$bundleOk = is_dir($SERVICES_DIR . '/auth') && is_dir($SERVICES_DIR . '/customer');

// --- routing -----------------------------------------------------------------
$step = (int) ($_POST['__step'] ?? $_GET['step'] ?? 1);
$errors = [];
$applyResults = null;

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
        // APPLY: write env files, keypair, dirs, migrations.
        $c = $_SESSION['tds_install'];
        $applyResults = ['env' => [], 'keys' => null, 'dirs' => [], 'migrations' => []];

        // gateway .env (ADMIN_TOKEN gates /wiki; upstream defaults are baked).
        @file_put_contents($GATEWAY_DIR . '/.env', "APP_ENV=production\nADMIN_TOKEN={$c['admin_token']}\n");

        foreach (['auth', 'contact', 'content', 'customer'] as $name) {
            $dir = $SERVICES_DIR . '/' . $name;
            $ok = @file_put_contents($dir . '/.env', env_for($name, $c)) !== false;
            $applyResults['env'][$name] = $ok;
        }

        $applyResults['keys'] = generate_keypair($SERVICES_DIR . '/auth');

        foreach ([$c['document_root_dir'] => 0700, $c['blog_upload_dir'] => 0775] as $dir => $mode) {
            $applyResults['dirs'][$dir] = is_dir($dir) || @mkdir($dir, $mode, true);
        }

        foreach (['auth', 'contact', 'content', 'customer'] as $name) {
            $applyResults['migrations'][$name] = run_migration($SERVICES_DIR . '/' . $name);
        }

        @file_put_contents($LOCK_FILE, gmdate('c') . "\n");
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
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<style>
  :root{--haupt:#050f68;--akzent:#820933;--paper:#fafaf7;--soft:#f1efe8;--line:#e8e6df;
    --ink:#1a1a17;--muted:#6b6b66;--card:#fff;--ok:#146c43;--okbg:#e6f4ea;--err:#a51d1d;--errbg:#fbeaea;
    --fd:"Hanken Grotesk",system-ui,sans-serif;--fb:"Plus Jakarta Sans",system-ui,sans-serif;--fm:"JetBrains Mono",monospace;
    --ease:cubic-bezier(.22,1,.36,1);}
  *{box-sizing:border-box}
  body{margin:0;font-family:var(--fb);background:var(--paper);color:var(--ink);line-height:1.55;
    min-height:100vh;position:relative;overflow-x:hidden}

  /* Animated brand aurora behind the card */
  .bg{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
  .blob{position:absolute;border-radius:50%;filter:blur(72px);opacity:.45;will-change:transform}
  .blob.b1{width:46vmax;height:46vmax;left:-12vmax;top:-16vmax;
    background:radial-gradient(circle,color-mix(in srgb,var(--haupt) 60%,transparent),transparent 70%);
    animation:drift1 26s ease-in-out infinite}
  .blob.b2{width:40vmax;height:40vmax;right:-14vmax;top:6vmax;
    background:radial-gradient(circle,color-mix(in srgb,var(--akzent) 55%,transparent),transparent 70%);
    animation:drift2 31s ease-in-out infinite}
  .blob.b3{width:36vmax;height:36vmax;left:24vmax;bottom:-22vmax;
    background:radial-gradient(circle,color-mix(in srgb,var(--haupt) 40%,transparent),transparent 70%);
    animation:drift1 37s ease-in-out infinite reverse}
  @keyframes drift1{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(6vmax,4vmax) scale(1.12)}}
  @keyframes drift2{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(-5vmax,6vmax) scale(1.08)}}

  .wrap{max-width:740px;margin:0 auto;padding:clamp(28px,6vw,64px) 20px 80px}
  .card{position:relative;background:color-mix(in srgb,var(--card) 82%,transparent);
    backdrop-filter:blur(22px) saturate(160%);-webkit-backdrop-filter:blur(22px) saturate(160%);
    border:1px solid color-mix(in srgb,var(--haupt) 12%,var(--line));border-radius:24px;
    padding:clamp(24px,4vw,44px);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.6),0 30px 70px -30px rgba(5,15,104,.45);
    animation:rise .6s var(--ease) both}
  @keyframes rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
  @keyframes pop{0%{transform:scale(.92)}60%{transform:scale(1.04)}100%{transform:scale(1)}}
  @keyframes slidein{from{opacity:0;transform:translateX(-8px)}to{opacity:1;transform:none}}
  @keyframes draw{to{width:68px}}

  .brandmark{display:inline-flex;align-items:center;gap:10px;margin-bottom:14px}
  .brandmark .mk{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;color:#fff;
    font-family:var(--fd);font-weight:800;font-size:16px;
    background:linear-gradient(135deg,var(--haupt),color-mix(in srgb,var(--haupt) 55%,var(--akzent)));
    box-shadow:0 8px 20px -8px var(--haupt)}
  .brandmark .wd{font-family:var(--fd);font-weight:700;letter-spacing:-.01em;font-size:15px}
  .brandmark .wd i{font-style:italic;color:var(--akzent)}

  h1{font-family:var(--fd);font-weight:800;letter-spacing:-.03em;font-size:clamp(28px,5vw,40px);
    margin:0 0 6px;position:relative;display:inline-block}
  h1::after{content:"";position:absolute;left:0;bottom:-7px;height:4px;width:0;border-radius:2px;
    background:linear-gradient(90deg,var(--haupt),var(--akzent));animation:draw .8s .35s var(--ease) forwards}
  .eyebrow{font-family:var(--fd);font-weight:700;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--akzent);margin:0 0 8px}
  h2{font-family:var(--fd);font-weight:700;letter-spacing:-.02em;font-size:22px;margin:30px 0 6px}
  p{margin:0 0 14px;color:var(--muted)}
  code{font-family:var(--fm);font-size:.85em;background:var(--soft);padding:.1em .4em;border-radius:4px}
  .steps{display:flex;flex-wrap:wrap;gap:8px;margin:22px 0 30px;font-size:13px}
  .steps span{display:inline-flex;align-items:center;padding:6px 13px;border-radius:999px;border:1px solid var(--line);
    color:var(--muted);background:color-mix(in srgb,var(--card) 55%,transparent);transition:all .3s var(--ease)}
  .steps span.on{background:var(--haupt);color:#fff;border-color:var(--haupt);font-weight:600;
    box-shadow:0 8px 20px -10px var(--haupt);animation:pop .45s var(--ease)}
  .steps span.done{border-color:color-mix(in srgb,var(--ok) 45%,var(--line));color:var(--ok);
    background:color-mix(in srgb,var(--okbg) 70%,transparent)}
  label{display:block;font-weight:600;font-size:14px;margin:14px 0 5px}
  label .opt{font-weight:400;color:var(--muted);font-size:12px}
  input[type=text],input[type=password]{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:9px;
    font-family:var(--fm);font-size:14px;background:#fff;transition:border-color .2s ease,box-shadow .2s ease}
  input:focus{outline:none;border-color:var(--haupt);box-shadow:0 0 0 3px rgba(5,15,104,.16)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .grid4{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  fieldset{border:1px solid var(--line);border-radius:14px;padding:18px;margin:20px 0;
    background:color-mix(in srgb,var(--card) 50%,transparent)}
  legend{font-family:var(--fd);font-weight:700;padding:0 8px;font-size:14px}
  .btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:11px;color:#fff;
    background:linear-gradient(135deg,var(--haupt),color-mix(in srgb,var(--haupt) 78%,var(--akzent)));
    font-family:var(--fd);font-weight:600;font-size:15px;padding:12px 24px;cursor:pointer;text-decoration:none;margin-top:20px;
    box-shadow:0 10px 24px -12px var(--haupt);transition:transform .18s var(--ease),box-shadow .18s var(--ease)}
  .btn:hover{transform:translateY(-2px);box-shadow:0 16px 32px -12px var(--haupt)}
  .btn:active{transform:translateY(0)}
  .btn.ghost{background:transparent;color:var(--muted);border:1px solid var(--line);box-shadow:none}
  .check{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line);font-size:14px;
    animation:slidein .5s var(--ease) both}
  .badge{font-family:var(--fm);font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;white-space:nowrap}
  .b-ok{background:var(--okbg);color:var(--ok)} .b-err{background:var(--errbg);color:var(--err)} .b-warn{background:#fff4d6;color:#8a5a00}
  .note{padding:13px 16px;border-radius:11px;font-size:14px;margin:14px 0;animation:rise .5s var(--ease) both}
  .note.err{background:var(--errbg);color:var(--err)} .note.ok{background:var(--okbg);color:var(--ok)} .note.warn{background:#fff4d6;color:#8a5a00}
  pre{background:var(--ink);color:#f5f3ec;padding:12px 14px;border-radius:8px;overflow:auto;font-family:var(--fm);font-size:12px;max-height:220px}
  .cb{display:flex;gap:8px;align-items:center;margin-top:14px;font-size:14px;color:var(--ink)}
  .muted-line{font-size:13px;color:var(--muted);margin-top:6px}
  @media (prefers-reduced-motion:reduce){
    .blob{animation:none}
    .card,.note,.check,.steps span.on{animation:none}
    h1::after{animation:none;width:68px}
  }
</style>
</head>
<body>
<div class="bg" aria-hidden="true">
  <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>
</div>
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
      $checks[] = ['Migrationen ausführbar (proc_open)', migrations_available(), 'sonst manuell nötig'];
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
    <form method="post">
      <input type="hidden" name="__step" value="4" />
      <div class="check"><span class="badge b-ok">DB</span><span><?= h(cfg('db_user')) ?>@<?= h(cfg('db_host')) ?>:<?= h(cfg('db_port')) ?></span></div>
      <div class="check"><span class="badge b-ok">.env</span><span>auth, contact, content, customer + gateway</span></div>
      <div class="check"><span class="badge b-ok">Keys</span><span>Auth RS256-Schlüsselpaar</span></div>
      <div class="check"><span class="badge <?= migrations_available() ? 'b-ok' : 'b-warn' ?>">Migrationen</span><span><?= migrations_available() ? 'phinx je Service' : 'proc_open deaktiviert — manuell nötig' ?></span></div>
      <button class="btn" type="submit">Jetzt installieren →</button>
    </form>

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
