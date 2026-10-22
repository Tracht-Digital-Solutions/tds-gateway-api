# Web installer (`public/install.php`)

A self-contained first-run wizard served as a plain file at `/install.php` (`.htaccess` serves real
files before the Slim front controller). Not a Slim route and without its own autoloader. It drives the
in-process Phinx migrator from each bundled `services/<name>/vendor/` (subprocess fallback when
`proc_open` is available) and uses `ext-openssl` for the auth keypair.

## Paths

It lives at `<bundle>/gateway/public/install.php` and resolves `<bundle>/services/<name>` two levels up.
Outside an assembled bundle it shows a "bundle not assembled" guard.

## Generated `.env` files

Writes `services/<name>/.env` for **auth, customer, frontend** plus the gateway `.env`, from the same
templates as the `.env.example`s and `deploy/docker-entrypoint.sh`.

### Env parity is enforced (`scripts/check-env-parity.php`, run in the assemble)

The installer configures services in other repositories, so a new env var can ship while every new
installation silently lacks it. Rules:

- Every `.env.example` key is written by `install.php` **or** listed in the script's `DEFAULTED` table
  with the reason its default is safe.
- Every key `install.php` writes is documented in that service's `.env.example`.
- The Docker entrypoint may write a subset but may not invent keys.

`DEFAULTED` holds runtime-first settings that are configured later in the panel: the customer service's
Stripe, Lexware and SMTP; the frontend's `IMAP_*`, `INGEST_TOKEN`, `TICKET_INGEST_*`,
`SITE_KEY_ENFORCEMENT` (default `off`; keys are issued in the panel), `BLOG_CACHE_TOKEN` and
`WEBSITE_CACHE_TOKEN`.

`install.php` writes `WEBAUTHN_RP_ID` (the cookie domain without its leading dot, i.e. the registrable
domain, so one passkey covers all subdomains) and `WEBAUTHN_RP_NAME`.

### Every line goes through `env_line()`

`env_line()` **quotes and escapes** each value: `\` → `\\`, `"` → `\"`, `$` → `\$`. Never interpolate a
value into the `.env` body.

- phpdotenv refuses an unquoted value containing a space, and each service loads `.env` first in
  `Bootstrap::createApp()`, so one bad line takes the whole service down at boot (health `status: 0`,
  every route 500, nothing in the app log).
- The `$` escape matters beyond parsing: phpdotenv interpolates `${VAR}` inside double quotes and would
  rewrite a generated password.

**There are two hand-rolled readers, and both must invert the writer exactly:**
`install.php`'s `read_env_kv()` (frontend migration) and `Support\MigrationRunner::readEnvFile()` (auth and
customer migrations and the request-time auto-migrate). If one drifts, a password containing `$` / `"` /
`\` migrates against wrong credentials while the services connect fine.
`tests/Support/InstallEnvFileTest.php` extracts the installer helpers via the tokenizer (the file
`session_start()`s at top level) and asserts both readers agree with phpdotenv key by key over a hostile
config.

## Secrets

Only installation-relevant secrets are set here. Third-party keys (Stripe, DeepL, Lexware) are configured
at runtime in the admin frontend and stored encrypted per service. The installer generates a
per-service `SETTINGS_ENCRYPTION_KEY` (like `document_sign_secret`) for the customer and frontend `.env`.

## Frontend migration is different

auth and customer migrate via their bundled Phinx (`run_migration`). `frontend` composes every extension's
migration paths and applies them through its **own** in-process migrator: `migrate_frontend()` requires the
frontend `vendor/`, builds `Bootstrap::migrationPaths()` + `Support\MigrationRunner`, and verifies via a
`phinxlog` count. It also auto-migrates on the first request (`AUTO_MIGRATE=1`). All extensions share one
DB (`tds_frontend`) and one `phinxlog`.

## Apply phase: a per-task AJAX driver

Step 4 runs each task (env writes → keypair → dir → migrations auth, customer, frontend → `create_admin` →
finalize) as its own JSON request with a progress bar.

- Tasks run with `set_time_limit(0)`; `run_migration` reads non-blocking against a 120 s deadline
  (`proc_terminate` on timeout).
- The per-task guard keys on the **`.tds-installed` lock only**, not `services/auth/.env` (the first task
  writes that file).
- A `<noscript>` form keeps the single-request fallback.

## First admin

Step 3 collects the first admin's email and password (defaults `admin@tracht-digital.de` /
`tds-setup-admin`). `create_admin` runs after `migrate_auth` and writes `app_user` via PDO (idempotent:
promotes an existing email) with `must_change_password=1`. It is deliberately not left to auth's
`seed_bootstrap_admin` migration, which reads `ADMIN_BOOTSTRAP_*` from the process env the in-process
migrator never populates. The email is mirrored into auth's `.env` as `ADMIN_BOOTSTRAP_EMAIL` (the
password isn't persisted). Both done screens print the login and the admin frontend URL.

## CORS default

Lists `management.tracht-digital.de` with `app.`, blog and landing page, written to all three services and
the gateway's own `.env`. On a running host, edit origins in the admin frontend under
*Einstellungen → CORS / Freigegebene Origins* (unioned on top of the file).

## Security

The wizard refuses to run once `.tds-installed` (bundle root) or `services/auth/.env` exists, and offers
self-delete. Before first install it is an open setup endpoint; operators should delete or IP-restrict it.

## Validation

No unit tests for the script as a whole; validate with `php -l` and a built-in-server smoke test, plus
`InstallEnvFileTest` for the env helpers.
