#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fails the assemble when a service's configuration surface has moved and
 * `public/install.php` was not moved with it.
 *
 * WHY THIS EXISTS. The installer is the only thing that configures a fresh
 * host, and it lives in a DIFFERENT repository from the services it configures.
 * So a service can gain an env var, ship, and deploy perfectly — while every
 * new installation silently comes up without that setting. Nothing goes red,
 * because nothing compares the two.
 *
 * It had already happened twice by the time this check was written:
 *
 *   - Passkeys and "30 Tage angemeldet bleiben" (tds-auth-api, 2026-08-05)
 *     added six env vars. install.php writes none of them. They survive only
 *     because `Bootstrap` defaults them — and one of those defaults is the
 *     hard-coded production domain `tracht-digital.de`, so passkeys are broken
 *     on any host that is not the live one, with no error anywhere.
 *   - The documents surface put DOCUMENT_ROOT_DIR / DOCUMENT_SIGN_SECRET into
 *     the frontend service's .env via install.php, and the service's own
 *     .env.example never learned about them — so the documented setup and the
 *     generated setup disagreed in the other direction.
 *
 * WHAT IT COMPARES. Three writers describe the same contract and must agree:
 *
 *   1. `public/install.php`            → env_for(), what a fresh host gets
 *   2. `deploy/docker-entrypoint.sh`   → dev defaults for the Docker stack
 *   3. `<service>/.env.example`        → what the service documents
 *
 * The rules are deliberately asymmetric:
 *
 *   - Every key in a service's .env.example must be written by install.php,
 *     OR be listed in DEFAULTED below with a reason. That listing is the whole
 *     point: adding an env var to a service forces a conscious decision here —
 *     "the installer asks for it" or "the code defaults it and here is why".
 *   - Every key install.php writes must appear in the service's .env.example,
 *     so the documented setup and the generated one cannot drift apart.
 *   - The entrypoint may be a SUBSET of install.php (it only needs enough to
 *     boot a dev container), but it may not invent keys of its own.
 *
 * Usage:
 *   php scripts/check-env-parity.php <gateway-dir> <service>=<dir> [...]
 *   php scripts/check-env-parity.php . auth=_src/auth customer=_src/customer frontend=_src/frontend
 */

/**
 * Keys a service documents that the installer deliberately does NOT write.
 *
 * Every entry is a claim that the code has a usable default, or that the value
 * belongs to the runtime SettingsStore (DB-first, admin frontend →
 * „Einstellungen“) rather than to a file on the host. Keep the reason honest —
 * it is the only thing standing between this list and a rubber stamp.
 *
 * @var array<string, array<string, string>> service => key => reason
 */
const DEFAULTED = [
    'auth' => [
        'ADMIN_BOOTSTRAP_PASSWORD' => 'The installer creates the admin account directly (create_admin_account); the password is deliberately never written to a file.',
        'SERVICE_TOKEN' => 'Bootstrap falls back to ADMIN_TOKEN, which the installer does write.',
        'REMEMBER_COOKIE_NAME' => 'Defaults to tds_remember in Bootstrap; not host-dependent.',
        'REMEMBER_TTL_SECONDS' => 'Defaults to JWT_REFRESH_TTL_SECONDS, which the installer writes.',
        'WEBAUTHN_CHALLENGE_SECRET' => 'Derived from the RS256 private key when unset — the challenge is not a secret, it only must not be attacker-chosen.',
        'WEBAUTHN_COOKIE_NAME' => 'Defaults to tds_wa_challenge in Bootstrap; not host-dependent.',
    ],
    'customer' => [
        // Third-party credentials moved to the runtime SettingsStore
        // (AES-256-GCM at rest, edited under „Einstellungen“). The env vars
        // survive only as a fallback, so an installer that asked for them
        // would be asking for the wrong thing.
        'STRIPE_SECRET_KEY' => 'Runtime SettingsStore (Einstellungen → Stripe).',
        'STRIPE_PUBLIC_KEY' => 'Runtime SettingsStore (Einstellungen → Stripe).',
        'STRIPE_WEBHOOK_SECRET' => 'Runtime SettingsStore (Einstellungen → Stripe).',
        'STRIPE_RETURN_URL' => 'Runtime SettingsStore (Einstellungen → Stripe).',
        'LEXWARE_API_KEY' => 'Runtime SettingsStore (Einstellungen → Lexware).',
        'LEXWARE_API_URL' => 'Runtime SettingsStore (Einstellungen → Lexware).',
        'LEXWARE_TAX_RATE_PERCENT' => 'Runtime SettingsStore (Einstellungen → Lexware).',
        'LEXWARE_DEFAULT_HOURLY_RATE' => 'Runtime SettingsStore (Einstellungen → Lexware).',
        'SMTP_HOST' => 'Runtime SettingsStore (Einstellungen → E-Mail).',
        'SMTP_PORT' => 'Runtime SettingsStore (Einstellungen → E-Mail).',
        'SMTP_USER' => 'Runtime SettingsStore (Einstellungen → E-Mail).',
        'SMTP_PASSWORD' => 'Runtime SettingsStore (Einstellungen → E-Mail).',
        'SMTP_SECURITY' => 'Runtime SettingsStore (Einstellungen → E-Mail).',
        'SMTP_FROM' => 'Runtime SettingsStore (Einstellungen → E-Mail).',
        'IMAP_HOST' => 'Optional email→ticket ingest; configured after setup.',
        'IMAP_PORT' => 'Optional email→ticket ingest; configured after setup.',
        'IMAP_USER' => 'Optional email→ticket ingest; configured after setup.',
        'IMAP_PASSWORD' => 'Optional email→ticket ingest; configured after setup.',
        'IMAP_SECURITY' => 'Optional email→ticket ingest; configured after setup.',
        'IMAP_FOLDER' => 'Optional email→ticket ingest; configured after setup.',
        'INGEST_TOKEN' => 'Optional contact-form→ticket ingest; set together with the ingest source.',
        'TICKET_ADMIN_EMAIL' => 'Notification target, edited in the admin frontend.',
        'TICKET_INBOX_ADDRESS' => 'Notification target, edited in the admin frontend.',
        'ADMIN_APP_URL' => 'Link building only; defaults to the production frontend host.',
        'CUSTOMER_APP_URL' => 'Link building only; defaults to the production portal host.',
        'SERVICE_TOKEN' => 'Falls back to ADMIN_TOKEN, which the installer does write.',
    ],
    'frontend' => [
        // The support-tickets mailbox is configured in the panel (runtime
        // SettingsStore, namespace `support-tickets`, AES-256-GCM at rest). The
        // env vars survive only as a fallback for a host that was set up
        // through the file, so an installer that asked for them at setup time —
        // before there is an inbox to point at — would be asking too early.
        'TICKET_ADMIN_EMAIL' => 'Notification target, edited in the admin frontend.',
        'TICKET_UPLOAD_DIR' => 'Optional attachment storage; unset leaves uploads answering 503.',
        'INGEST_TOKEN' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang); only needed for an external scheduler.',
        'IMAP_HOST' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang).',
        'IMAP_PORT' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang).',
        'IMAP_USER' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang).',
        'IMAP_PASSWORD' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang).',
        'IMAP_SECURITY' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang).',
        'IMAP_FOLDER' => 'Runtime SettingsStore (Einstellungen → Support-Tickets → E-Mail-Eingang).',
        'TICKET_INGEST_MODE' => 'Runtime SettingsStore; defaults to "reply" (thread replies only), which is the safe rule.',
        'TICKET_INGEST_MATCH_COMPANY' => 'Runtime SettingsStore; defaults to on.',
        // Site keys. The keys themselves live in app_site_key as hashes and are
        // issued in the panel, so there is deliberately no env var holding one.
        // Only the POLICY has a fallback, and its default is the pre-feature
        // behaviour — asking at install time would mean asking somebody to
        // choose an enforcement level before a single site has a key, i.e. to
        // choose wrong.
        'SITE_KEY_ENFORCEMENT' => 'Runtime SettingsStore (Einstellungen → Site-Verbindungen); defaults to "off", which is the behaviour of every installation predating site keys.',
        // The CMS panels store page-cache tokens encrypted in their own
        // SettingsStore namespaces. These env keys exist only so an already
        // file-configured host keeps working; a fresh installer must not ask
        // for runtime content credentials.
        'BLOG_CACHE_TOKEN' => 'Runtime SettingsStore (Einstellungen → Blog → Cache-Token); optional env fallback for existing hosts.',
        'WEBSITE_CACHE_TOKEN' => 'Runtime SettingsStore (Einstellungen → Website → Cache-Token); optional env fallback for existing hosts.',
    ],
];

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "usage: check-env-parity.php <gateway-dir> <service>=<dir> [<service>=<dir> ...]\n");
    exit(2);
}

$gatewayDir = rtrim(array_shift($args), '/\\');
$installFile = $gatewayDir . '/public/install.php';
$entrypointFile = $gatewayDir . '/deploy/docker-entrypoint.sh';

foreach ([$installFile, $entrypointFile] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "check-env-parity: missing {$required}\n");
        exit(2);
    }
}

$installSrc = (string) file_get_contents($installFile);
$entrypointSrc = (string) file_get_contents($entrypointFile);

$problems = [];

foreach ($args as $arg) {
    if (!str_contains($arg, '=')) {
        fwrite(STDERR, "check-env-parity: expected <service>=<dir>, got '{$arg}'\n");
        exit(2);
    }
    [$service, $dir] = explode('=', $arg, 2);
    $example = rtrim($dir, '/\\') . '/.env.example';

    if (!is_file($example)) {
        fwrite(STDERR, "check-env-parity: no .env.example in {$dir}\n");
        exit(2);
    }

    $installKeys = installKeys($installSrc, $service);
    if ($installKeys === []) {
        $problems[] = "{$service}: install.php's env_for() has no case for this service.";
        continue;
    }

    $exampleKeys = exampleKeys($example);
    $entrypointKeys = entrypointKeys($entrypointSrc, $service);
    $defaulted = DEFAULTED[$service] ?? [];

    foreach (array_diff($exampleKeys, $installKeys) as $key) {
        if (!isset($defaulted[$key])) {
            $problems[] = "{$service}: {$key} is documented in .env.example but install.php never writes it."
                . " Either add it to env_for() in public/install.php, or add it to DEFAULTED in "
                . basename(__FILE__) . " with the reason its default is safe.";
        }
    }

    foreach (array_diff($installKeys, $exampleKeys) as $key) {
        $problems[] = "{$service}: install.php writes {$key} but {$dir}/.env.example does not document it."
            . ' Add it there so the documented setup matches the generated one.';
    }

    // The entrypoint may write a key the installer leaves to its default (it
    // seeds empty placeholders for the Docker stack), but it may not invent a
    // key that neither the installer nor the service knows about.
    foreach (array_diff($entrypointKeys, $installKeys, array_keys($defaulted)) as $key) {
        $problems[] = "{$service}: deploy/docker-entrypoint.sh writes {$key} but neither install.php"
            . ' nor the service documents it. The Docker stack and a real host must not be'
            . ' configured differently.';
    }

    foreach (array_keys($defaulted) as $key) {
        if (!in_array($key, $exampleKeys, true)) {
            $problems[] = "{$service}: {$key} is listed as defaulted in " . basename(__FILE__)
                . " but no longer appears in {$dir}/.env.example — remove the stale entry.";
        }
    }

    printf(
        "%-9s install.php %2d | .env.example %2d | entrypoint %2d | defaulted %2d\n",
        $service,
        count($installKeys),
        count($exampleKeys),
        count($entrypointKeys),
        count($defaulted)
    );
}

if ($problems !== []) {
    fwrite(STDERR, "\ncheck-env-parity: the installer and the services disagree:\n\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    fwrite(STDERR, "\n");
    exit(1);
}

echo "check-env-parity: installer and services agree.\n";
exit(0);

/**
 * Env keys install.php writes for one service: the shared $base block plus the
 * service's own case in env_for().
 *
 * @return list<string>
 */
function installKeys(string $src, string $service): array
{
    if (!preg_match('#function env_for\(.*?\n\}#s', $src, $fn)) {
        return [];
    }
    $body = $fn[0];

    $keys = [];
    if (preg_match('#\$base = \[(.*?)\];#s', $body, $base)) {
        preg_match_all("#'([A-Z0-9_]+)'\s*=>#", $base[1], $m);
        $keys = $m[1];
    }

    $case = "#case '" . preg_quote($service, '#') . "':(.*?)(?=\n\s*case '|\n\s*\}\n)#s";
    if (!preg_match($case, $body, $c)) {
        return [];
    }
    preg_match_all("#'([A-Z0-9_]+)'\s*=>#", $c[1], $m);

    return array_values(array_unique(array_merge($keys, $m[1])));
}

/** @return list<string> */
function exampleKeys(string $file): array
{
    $keys = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('#^([A-Z0-9_]+)\s*=#', $line, $m)) {
            $keys[] = $m[1];
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Env keys the Docker entrypoint writes for one service — the heredoc body of
 * its `write_env_if_absent <service> <<EOF … EOF` block.
 *
 * @return list<string>
 */
function entrypointKeys(string $src, string $service): array
{
    $pattern = '#write_env_if_absent\s+' . preg_quote($service, '#') . '\s+<<\s*EOF\n(.*?)\nEOF#s';
    if (!preg_match($pattern, $src, $m)) {
        return [];
    }
    preg_match_all('#^([A-Z0-9_]+)=#m', $m[1], $keys);

    return array_values(array_unique($keys[1]));
}
