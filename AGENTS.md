# Agent notes — tds-api-gateway

PHP 8.3 + Slim 4 single entry for `api.tracht-digital.de`; routes by first path
segment to the four micro-backends. **By default it runs each backend
*in-process* (`GATEWAY_MODE=inprocess`)** — the whole API surface is one
PHP-FPM app, with no service processes to start (the Plesk "install + start
without SSH" model). An optional **`GATEWAY_MODE=proxy`** relays over HTTP to
the loopback `php -S` services instead (supervisor/nginx/Docker run modes). Same
env-helper / CI / deploy-webhook conventions as the backends — read the root
`CLAUDE.md` for the big picture.

> Status: **required by both architectures — not superseded.** Today it bundles the
> four legacy services (`auth`, `customer`, and the archived `content`/`contact` code,
> still deployed until cutover). After the panel-platform cutover it must still route
> to `tds-core-panel-api` + `tds-auth-api`. See the root `MIGRATION-STATUS.md`.
>
> Note: this repo also carries a `CLAUDE.md` — it is a **stale copy** of an older root
> `CLAUDE.md` (pre-panel, mentions Resend/the ten repos). Trust the root
> `C:\Projects\TDS-LP\CLAUDE.md` + this `AGENTS.md`, not the in-repo copy.

## Mental model

- **Two modes, chosen in `Bootstrap` from `GATEWAY_MODE` (default `inprocess`).**
  Both share the routing table and the `/`, `/healthz`, `/wiki` routes; only the
  catch-all + health action differ.
- `Config\ServiceRegistry` is the routing table (prefix → `Service`), built
  from env with baked defaults for the four known services. `match($path)`
  returns `[Service, $remainder]`.
- `Service::targetFor($remainder, $query)` builds the upstream URL
  (`upstream + rewrite + remainder`) for **proxy** mode; `Service::pathFor($remainder)`
  is its host-less twin (`rewrite + remainder`, `''`→`/`) for the **in-process**
  sub-request path. `rewrite` is empty for root-mounted services and `/contact`
  for contact-api.

### In-process mode (default)

- `Dispatch\InProcessDispatcher` loads a service's `vendor/autoload.php` on
  demand and calls `Tds\<Name>Api\Bootstrap::createApp($dir)->handle($subReq)`.
  The prefix→`[dir, BootstrapFQCN]` map is built in `Bootstrap` from the registry
  + `SERVICE_BOOTSTRAPS`; services live at `GATEWAY_SERVICES_DIR`
  (default `<bundle>/services`).
- **Env isolation is the crux.** Services do `Dotenv::createImmutable()->load()`
  and read `$_ENV`; a reused FPM worker keeps those globals, and an *immutable*
  loader won't overwrite an existing key — so a later `/customer` request would
  see the earlier `/auth` request's `DB_NAME`. The dispatcher wraps each dispatch
  in a surgical env scope: it enumerates the service's `.env` keys with
  `Dotenv::parse` (side-effect free), clears exactly those from
  `$_ENV`/`$_SERVER`/`getenv`, then restores their prior state in a `finally`.
  This is why the four services stay **byte-for-byte unchanged** (still run
  standalone via `composer start`).
- `Action\DispatchAction` is the catch-all (`/{path:.*}`): `match` → 404 if
  unknown, add `X-Forwarded-*`, dispatch in-process, wrap any failure as a 502.
  `Action\InProcessHealthAction` runs each service's `/healthz` in-process and
  aggregates (a boot/dispatch failure = status 0), same JSON shape as the proxy
  `HealthAction`.
- The dispatcher takes an **injectable app-resolver** (`callable(dir, fqcn): App`)
  so the unit tests supply a fake app + fake `.env` without any sibling repo
  checked out.

### Proxy mode (`GATEWAY_MODE=proxy`)

- `Action\ProxyAction` is the catch-all; it relays the request via
  `Http\ProxyClientInterface` (cURL impl) and mirrors the response.
- `Action\HealthAction` fans out to every upstream `/healthz` **concurrently**
  via `ProxyClientInterface::sendMany()` (curl_multi), so one slow/dead upstream
  can't serialise the check; a transport failure comes back as a status-0
  response (reported as down). `Action\IndexAction` (used in both modes) lists
  prefixes for navigation.
- `Http\ProxyClientInterface` has two methods: `send()` (single, used by
  `ProxyAction`, throws `ProxyException` on transport failure) and `sendMany()`
  (concurrent batch, used by `HealthAction`, never throws — failures are
  status-0). Two instances are wired in `Bootstrap`: a long-timeout
  `proxy.client` for proxied traffic and a short-timeout `health.client`
  (connect 1s / total 2s) so `/healthz` stays snappy.
- `Http\HeaderFilter` strips hop-by-hop + Host + Content-Length (recomputed
  from the body we hold).

## Gotchas / don't

- **Don't strip the contact prefix.** contact-api's only route is `/contact`
  and the frontend POSTs to `.../contact` with no sub-path. The registry
  default rewrites `/contact` → `:8002/contact`. The other three strip.
- **Don't add CORS here.** Each upstream emits its own CORS headers and the
  proxy forwards them; injecting gateway CORS would duplicate
  `Access-Control-Allow-Origin`. OPTIONS preflights are forwarded so the
  upstream's CorsMiddleware answers them.
- **Don't statically serve `/content/uploads/`.** Blog cover/body images live
  under `/content/uploads/{slug}/{file}` and are user content — content-api's
  `UploadServeAction` stamps the anti-XSS headers (nosniff, sandbox CSP, and
  `Content-Disposition: attachment` for SVG). Whether the front door is this
  PHP gateway, `deploy/nginx.conf.example`, or the Docker stack, uploads must
  reach that PHP action; a static `alias` shortcut to "offload PHP" drops the
  hardening and re-exposes stored XSS via a crafted SVG. Same header-ownership
  rule as CORS: the upstream owns it, the front door just forwards.
- **Keep Content-Encoding, drop Content-Length** on the response. We forward
  the exact upstream body bytes (gzipped or not); the emitter recomputes
  length. Forwarding the upstream's Content-Length would risk a mismatch.
- **Don't add BodyParsingMiddleware.** The proxy needs the raw body
  (`(string) $request->getBody()`), not a parsed array.
- **Every `php -S` needs `public/router.php`.** The built-in server 404s any
  dotted path that has no file on disk *without ever invoking PHP* — the
  gateway's `/wiki.json` and (in proxy mode) every upstream's
  `/.well-known/jwks.json` silently die. `composer start`,
  `bin/start-stack.sh` and both supervisor confs pass the router; keep it
  when adding a new run mode. The router serves real files (`install.php`,
  `robots.txt`) as-is and routes everything else to `index.php`. Apache
  (.htaccess) and in-process mode are unaffected.
- **Env helper:** never `$_ENV[$key] ?? getenv($key) ?: $default` — `??`
  binds tighter than `?:` and clobbers falsy values. Use explicit `?? false`
  (same bug that bit all four APIs).
- **Bodies are buffered in memory** in *proxy* mode (cURL `POSTFIELDS` with the
  raw string). Fine for current upload sizes (blog covers, customer docs);
  revisit with streaming if large uploads land. In-process mode passes the PSR-7
  request straight through (uploads via `getUploadedFiles()`), so no extra copy.
- **In-process autoloader: keep the per-service `vendor/` versions in lockstep.**
  Each request loads one service's autoloader; shared libs (Slim/php-di/phpdotenv)
  load once and "first loaded wins" for the worker. Because the bundle is
  assembled from all repos at once with identical constraints the copies match —
  don't introduce a service that pins a divergent Slim/php-di/phpdotenv major.
- **Don't make the four services depend on the gateway, or read env outside their
  `Bootstrap`.** The in-process env scope only brackets `createApp`; an action
  reading `getenv()` at request time would escape it.
- **The API surface is deliberately deindexed.** `Http\RobotsTagMiddleware`
  stamps `X-Robots-Tag: noindex, nofollow` on every response — it's `add()`ed
  *after* the error middleware (Slim middleware is LIFO → outermost), so error
  responses and everything the catch-all dispatches to the in-process backends
  carry it too. `public/robots.txt` (`Disallow: /`) is the matching crawl
  block: robots.txt stops crawling, the header removes any URL-only index
  entry — keep both. In the zero-hop nginx mode the middleware never runs, so
  `deploy/nginx.conf.example` mirrors the header (`add_header … always`) and
  serves the robots.txt inline. The middleware is a class, not a closure —
  Slim binds closure middleware to the DI container and `bindTo()` on a
  static closure returns null. The four backends need nothing (the gateway
  fronts them); this exists only at the front door.

## Logging (`Support\Logger`)

- `Support\Logger` is a tiny dependency-free file logger — one JSON object per
  line. It exists because the gateway runs under PHP-FPM on Plesk, where PHP's
  default `error_log()` sink is effectively invisible, so a 502 ("upstream
  unavailable") gave no clue **why**. Now `ProxyAction` logs the cURL
  **errno + target URL** behind every failed hop (7=connection refused,
  6=DNS, 28=timeout) at `error`, every successful proxy at `info`, and
  `HealthAction` logs a `warning` listing the down upstreams.
- Wired in `Bootstrap` via `Logger::fromEnv($env, $rootDir)` and injected as a
  **nullable** ctor arg into `ProxyAction` / `HealthAction` (so the unit tests
  construct them with no logger and stay log-free). Config:
  `GATEWAY_LOG_FILE` (default `<root>/logs/gateway.log`, root-relative or
  absolute, `off` disables) and `GATEWAY_LOG_LEVEL`
  (`debug|info|warning|error|off`, default `info`; `.env.example` ships
  `warning` so prod records only failures).
- **Logging must never break the proxy.** A bad/unwritable path degrades to a
  single `error_log()` fallback and is then silenced — `Logger` never throws.
  `ProxyAction` still calls `error_log()` on a failure too, so the cause is
  visible even when the file sink is misconfigured. `logs/` is gitignored;
  don't commit log output.

## The build pipeline (`dev.yml` / `release.yml` → reusable `_assemble.yml`)

The old single `build.yml`/`build`-branch is gone — there are now two thin
caller workflows over a same-repo reusable `_assemble.yml` (`workflow_call`):
- **`dev.yml`** — triggers: push to `main`, `workflow_dispatch`,
  `repository_dispatch(api-pushed)`. Calls `_assemble` with `channel=dev,
  deploy=false` → force-pushes the bundle to the orphan **`dev`** branch. **Not
  deployed.**
- **`release.yml`** — `workflow_dispatch` only. Calls `_assemble` with
  `channel=release, deploy=true` → force-pushes **`release`** + pings
  `DEPLOY_WEBHOOK_URL`. The host pulls `release`.
- `_assemble.yml`: `check` (validate + install + `php -l` + phpunit) then
  `assemble` — checks out the gateway + all four API repos at `main`, runs
  `composer install --no-dev` for each, **re-adds phinx per service**
  (`composer require robmorgan/phinx:<require-dev constraint> --update-no-dev`)
  so the host can migrate from the bundle without a composer install, assembles
  `dist/` (gateway at root, services under `services/`, plus Procfile /
  services.json / BUILD_INFO.json), and publishes to the `inputs.channel` branch
  (deploy ping gated on `inputs.deploy`). PR merge-gate is the separate `ci.yml`.
- `public/.htaccess` ships the Apache front-controller rewrite (same file as
  the four APIs) so the gateway can run straight under PHP-FPM with the
  docroot on `gateway/public` — the Plesk model in `DEPLOY-PLESK.md`. Without
  it every route except `/` 404s on Apache hosts.

### End-to-end wiring (gateway ↔ the four API repos)

The auto-reassembly loop spans two halves; both are live and verified.

1. **API side** — each API repo (`tds-auth-api`, `tds-contact-api`,
   `tds-content-api`, `tds-customer-api`) has its own
   `dev.yml` / `release.yml` (+ `ci.yml` for PRs). Its **manual Release**
   (`release.yml`) pings `DEPLOY_WEBHOOK_URL` **and** POSTs an `api-pushed`
   `repository_dispatch` to this repo (`.../tds-api-gateway/dispatches`) using
   `GATEWAY_DISPATCH_TOKEN`. The dispatch step skips quietly (logs + `exit 0`)
   when the token is unset, so a missing secret never reds the API's CI. The
   four repos' workflows are identical except the PHP `extensions:` list
   (auth: `openssl`; customer: `openssl, fileinfo`).
2. **Gateway side** — the `repository_dispatch(api-pushed)` trigger fires
   `dev.yml`, which rebuilds the **`dev`** bundle from all five repos at `main`.
   So an API release ⇒ dispatch ⇒ gateway `dev` reassembles. The gateway's own
   `release` is a separate manual button.

To test the chain without an API push:
`gh api -X POST repos/Tracht-Digital-Solutions/tds-api-gateway/dispatches
-f event_type=api-pushed` — then confirm a `repository_dispatch`-triggered
`dev` run lands and the `dev` branch SHA advances.

**The deploy-webhook ping is deliberately non-fatal — don't "fix" it back to
`curl -fsS`.** By the time that step runs, the bundle is already force-pushed
to the channel branch, so a wrong/expired/unreachable `DEPLOY_WEBHOOK_URL` (404, timeout,
DNS) must not red the job and mask a good assembly. The step captures the HTTP
status (`-w '%{http_code}'`, `|| echo 000` for a connect failure) and emits a
`::warning::` annotation on a non-2xx instead of exiting non-zero. So a broken
webhook shows as a **yellow warning on a green run**, never a red build — check
the run annotations, not the job status, to catch a dead deploy hook. The same
softening is mirrored in all four API repos' `ci.yml`.

### Required secrets

| Secret | Where | Purpose | Status |
|---|---|---|---|
| `ASSEMBLE_TOKEN` | this repo | org PAT (`repo` scope, SSO-authorized): checks out the private API repos **and** pushes the `dev`/`release` branch (the peaceiris `github_token:` field — despite the name, *not* the default `GITHUB_TOKEN`). | set |
| `GATEWAY_DISPATCH_TOKEN` | each of the 4 API repos | PAT that can POST `repository_dispatch` to this repo (the same org PAT as `ASSEMBLE_TOKEN` works). | set in all 4 |
| `DEPLOY_WEBHOOK_URL` | this repo + each API repo | deploy hook the host pulls on; carries its own token. Optional — the step skips when unset and is non-fatal when set (see above). | Wire to the host's deploy hook once its URL is known; while unset or misconfigured the ping just skips / warns and never reds the job. |

Gotcha: `actions/checkout` errors `Input required and not supplied: token`
when `token:` is given but the secret resolves empty — it does **not** fall
back to `GITHUB_TOKEN`. A missing `ASSEMBLE_TOKEN` therefore fails `assemble`
at the first private-repo checkout while `check` stays green (no secrets
needed). That asymmetric failure is the tell for an unset/expired token.

## API wiki (`/wiki`)

- `bin/gen-api-wiki.php` parses each service's `src/Bootstrap.php` (gateway +
  the four APIs), extracting every Slim route — including grouped routes and
  the auth middleware on them (`->add($admin)` → Admin-Token, the JWT group →
  JWT). It writes `API-WIKI.md` + `wiki/index.html`. It auto-discovers the
  Bootstrap files across the dev (`../tds-*-api`), bundle (`services/<name>`)
  and CI (`_src/<name>`) layouts. **New routes appear automatically** — there
  is no hand-maintained route list.
- CI runs it in the `assemble` job (`php _src/gateway/bin/gen-api-wiki.php
  dist`) so the bundle always ships a current `wiki/index.html` at its root.
- `Action\WikiAction` serves `/wiki`, gated by `ADMIN_TOKEN` (the same shared
  secret the backends use): accepts a Bearer header, a `?token=` query (which
  it converts to a `tds_wiki` cookie + clean redirect), or the cookie. **Unset
  `ADMIN_TOKEN` ⇒ the route is 404 (disabled)** — the wiki is never public.
  It reads `wiki/index.html` from the gateway root (dev) or the bundle root
  (prod). `/wiki` is registered before the catch-all so it isn't proxied.
- `API-WIKI.md` is a committed snapshot for reading on GitHub; the live
  `wiki/index.html` is a build artifact (gitignored, regenerated by CI).

## Web installer (`public/install.php`)

- Self-contained first-run setup wizard served as a plain file at
  `/install.php` (the `.htaccess` serves real files before the Slim
  front-controller, so it isn't proxied). It is **not** a Slim route — no
  autoloader of its own; it shells out to each bundled `services/<name>/vendor/bin/phinx`
  for migrations (via `proc_open`, with a manual-fallback message when that's
  disabled) and uses `ext-openssl` directly for the auth keypair.
- Path model: it lives at `<bundle>/gateway/public/install.php` and resolves
  `<bundle>/services/<name>` two levels up; shows a "bundle not assembled"
  guard when run outside the assembled bundle (e.g. the dev repo).
- Writes `services/<name>/.env` (+ gateway `.env`) from the same templates as
  the `.env.example`s / `deploy/docker-entrypoint.sh`; keep all three in sync
  when a service gains an env var.
- **Only installation-relevant secrets are set here.** Step 3 no longer collects
  third-party service keys (Stripe, Resend email, GitHub blog-rebuild) — those are
  configured at runtime in the admin panel (Einrichtungsassistent / Einstellungen)
  and stored encrypted in each service's `app_setting` table. The installer only
  provisions a per-service `SETTINGS_ENCRYPTION_KEY` (auto-generated, like
  `document_sign_secret`) that protects those runtime secrets — written into the
  content/contact/customer `.env` blocks in `env_for()`.
- **Apply phase is a per-task AJAX driver, not one blocking POST.** Step 4 runs
  each install step (`install_tasks()` / `run_task()`: env writes → keypair →
  dirs → the four phinx migrations → **create_admin** → finalize) as its own small
  JSON request while a progress bar advances. This replaced the old "do everything in one
  request" apply that appeared to hang (four serial migrations blew past
  `max_execution_time`; `run_migration` blocked forever on `stream_get_contents`).
  Now: tasks run with `set_time_limit(0)`, `run_migration` reads non-blocking
  against a 120s deadline (`proc_terminate` on timeout), and the per-task guard
  keys on the **`.tds-installed` lock only** (NOT `services/auth/.env`) — the
  first task writes that `.env`, so an `$alreadyInstalled`-style guard there would
  abort every later task. A `<noscript>` form keeps the single-request fallback.
- **Admin login (step 3 config + `create_admin` task).** Step 3 collects the
  first admin's e-mail + password (defaults `admin@tracht-digital.de` /
  `tds-setup-admin`). The `create_admin` task runs *after* `migrate_auth` and
  writes the row straight into `app_user` via PDO (idempotent: promotes an
  existing e-mail to admin instead of duplicating), with `must_change_password=1`.
  This is deliberately **not** left to the auth `seed_bootstrap_admin` migration:
  that seed reads `ADMIN_BOOTSTRAP_*` from the *process* env, which the in-process
  migrator never populates from `services/auth/.env`, so it would always fall back
  to the public default and the operator would never see working credentials. The
  chosen e-mail is still mirrored into auth's `.env` as `ADMIN_BOOTSTRAP_EMAIL`
  (identity only — the password is not persisted in plaintext). Both done screens
  (JS `donePanel` + no-JS step 5) print the login and the admin-panel URL
  (`management.tracht-digital.de`).
- **CORS default** lists `management.tracht-digital.de` (the admin panel's current
  address) alongside `admin.`/`app.`/blog/landing — keep it in sync with the four
  services' `.env.example` `CORS_ALLOWED_ORIGINS`.
- **Security:** refuses to run once a `.tds-installed` lock (bundle root) or
  `services/auth/.env` exists (wizard entry guard), and offers self-delete. It
  ships in every bundle and is an open setup endpoint **before** first install —
  docs tell operators to delete it / IP-restrict during setup.

## Auto-migration (`Support\MigrationRunner`, `Bootstrap::autoMigrate`)

- **Why:** the installer migrates *once* then locks itself, so a later release
  that adds a migration would never apply it — the DB drifts behind the code and
  every query on the new table 500s (the outage in tds-content-api#15 /
  tds-auth-api#13). Auto-migration closes that gap for the no-SSH Plesk model.
- **What:** `public/index.php` calls `Bootstrap::autoMigrate()` **after**
  `createApp()` (never *from* createApp — keeps the app pure for unit tests).
  In-process mode only (`GATEWAY_MODE=proxy` services own their migrations);
  toggle with `GATEWAY_AUTO_MIGRATE=0`. On the first request after a deploy it
  applies each `services/<name>`'s pending migrations, then marks done.
- **Migrations run *in-process* via Phinx's `Manager` API — not a subprocess.**
  Shared Plesk hosting routinely disables `proc_open`, which is exactly why the
  installer's shell-out to phinx silently applied nothing and left prod empty.
  In-process needs no `proc_open` and no CLI php. `MigrationRunner` `require`s
  the service's own `vendor/autoload.php` (each ships Phinx post `--no-dev`),
  reads DB creds straight from that service's `.env` (never the process env, so
  a warm FPM worker can't leak another service's `DB_NAME`), and builds a Phinx
  `Config` mirroring the service's phinx.php (`phinx_migration` table, mysql,
  utf8mb4). The old `proc_open` shell-out remains only as a fallback.
- **Guards (all in `MigrationRunner`):** a marker file under `<root>/var/`
  (gitignored → survives `git pull`, never committed) keyed to a **signature of
  the migration filenames** — hot path is a single `is_file()`; the marker name
  changes only when a migration is added/removed, which is exactly when a re-run
  is wanted. An exclusive non-blocking `flock` makes it single-flight (only the
  first worker migrates; others skip). Failures are logged and swallowed (never
  fatal) and do **not** write the marker, so they retry next request.
- **Migration class names must be unique across ALL services.** Phinx `include`s
  every migration file (applied or not) and in-process all services share one
  PHP process, so two services declaring the same class is an **uncatchable**
  fatal redeclaration error on every request — three services shipping an
  identical `CreateAppSetting` took the whole API down. Convention: prefix the
  class/filename with the service (`CreateContentAppSetting`, …). Defense:
  `MigrationRunner` text-scans each service's declared migration classes before
  running and **skips a colliding service with a logged error** (no marker →
  health shows `db:no-schema`) instead of fataling.
- **CLI-php resolution (subprocess fallback + install.php only):** under PHP-FPM
  `PHP_BINARY` is the FPM binary and can't run phinx (a prime suspect for
  "installer said OK but the DB is empty"). `MigrationRunner::phpCliBinary()`
  prefers `GATEWAY_PHP_BINARY`, then a `php` next to `PHP_BINDIR`, then a non-fpm
  `PHP_BINARY`, then PATH. `install.php`'s `php_cli_binary()` mirrors this (it
  has no autoloader, so it's duplicated). The runtime auto-migrator prefers the
  in-process path and only reaches this when Phinx can't be loaded in-process.
- **Health interplay:** each backend `/healthz` now reports `db: ok|no-schema|
  down` and the gateway's aggregate (`HealthAction` / `InProcessHealthAction` via
  `Support\HealthBody`) flips to **503** when any service is reachable-but-un-
  migrated — so if auto-migration ever fails, the outage is visible instead of
  hiding behind a bare `SELECT 1` (tds-api-gateway#4).

## Tests

PHPUnit 10, no DB, no network. Proxy mode fakes `Http\ProxyClientInterface`
(`tests/Support/FakeProxyClient`); in-process mode injects a fake app-resolver +
temp `.env` into `InProcessDispatcher` (`tests/Dispatch/InProcessDispatcherTest`
covers env isolation, path rewrite, 404, 502; `DispatchActionTest` /
`InProcessHealthActionTest` cover the actions). `composer test` runs the suite
(`WikiActionTest` covers the wiki auth gate). The installer is a standalone
script (no unit tests); validate it with `php -l` + a built-in-server smoke.
