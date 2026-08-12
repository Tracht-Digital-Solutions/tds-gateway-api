# Agent notes — tds-gateway-api

PHP 8.3 + Slim 4 single entry for `api.tracht-digital.de`; routes by first path
segment to the backends. **By default it runs each backend *in-process*
(`GATEWAY_MODE=inprocess`)** — the whole API surface is one PHP-FPM app, with no
service processes to start (the Plesk "install + start without SSH" model). An
optional **`GATEWAY_MODE=proxy`** relays over HTTP to the loopback `php -S`
services instead (supervisor/nginx/Docker run modes). Same env-helper / CI /
deploy-webhook conventions as the backends — read the root `CLAUDE.md` for the
big picture.

> Status: **required by both architectures — not superseded.** Post frontend-platform
> cutover it fronts **three** backends:
> - `auth` → `tds-auth-api` (`/auth/*`, prefix-stripped)
> - `customer` → `tds-customer-api` (`/customer/*`, prefix-stripped)
> - `frontend` → `tds-core-frontend-api` — the **default (catch-all)** upstream: the
>   composed base + extensions that replaced the archived `content`/`contact` backends.
>   Everything not under `/auth` or `/customer` forwards to it verbatim (its module
>   routes live at root: `/tickets`, `/tools`, `/admin/settings`, `/me/…`, `/wiki.json`).
>
> See the root `MIGRATION-STATUS.md`.
>
> Note: this repo also carries a `CLAUDE.md` — it is a **stale copy** of an older root
> `CLAUDE.md` (pre-frontend, mentions Resend/the ten repos). Trust the root
> `C:\Projects\TDS-LP\CLAUDE.md` + this `AGENTS.md`, not the in-repo copy.

## Mental model

- **Two modes, chosen in `Bootstrap` from `GATEWAY_MODE` (default `inprocess`).**
  Both share the routing table and the `/`, `/healthz` routes; only the catch-all
  + health action differ. The gateway owns **no** routes of its own beyond those
  two — it is a pure transparent router (the API wiki now lives in the frontend
  API's `/wiki.json`, not here).
  - **The Docker stack must not start service processes in the default mode.**
    `inprocess` means one `php -S` serves everything, but supervisord started all
    four unconditionally until 0.4.10 — three PHP processes that were never
    dialled, yet answered on 8003/8004/8100 and so read as the live request path
    while debugging. The three backends now key off `TDS_BACKEND_AUTOSTART`,
    which `deploy/docker-entrypoint.sh` derives from `GATEWAY_MODE` and logs at
    startup. **Do not map `GATEWAY_MODE` into `docker-compose.yml`'s
    `environment:`** — Compose substitutes from the project `.env`, which in this
    repo is the gateway *app's* local `composer start` config and still carries
    `GATEWAY_MODE=proxy` plus the pre-cutover `GATEWAY_SERVICES`; mapping it let
    that stale file silently flip the container. Override via `.env.docker`.
- `Config\ServiceRegistry` is the routing table (prefix → `Service`), built
  from env with baked defaults for the current backends. `match($path)` returns
  `[Service, $remainder]`: an explicit prefix (`auth`/`customer`) strips the
  segment; **anything else falls through to the default service** (`frontend`)
  with the *whole* path preserved. `GATEWAY_DEFAULT_SERVICE` (default `frontend`)
  names that catch-all; set it to `''` to restore the old "unmatched → 404".
- `Service::targetFor($remainder, $query)` builds the upstream URL
  (`upstream + rewrite + remainder`) for **proxy** mode; `Service::pathFor($remainder)`
  is its host-less twin (`rewrite + remainder`, `''`→`/`) for the **in-process**
  sub-request path. `rewrite` is empty for every current service (all mount at
  root); the `isDefault` flag marks the catch-all.

### In-process mode (default)

- `Dispatch\InProcessDispatcher` loads a service's `vendor/autoload.php` on
  demand and calls `Tds\<Name>Api\Bootstrap::createApp($dir)->handle($subReq)`
  (`Tds\AuthApi\`, `Tds\CustomerApi\`, `Tds\CoreFrontendApi\`). The
  prefix→`[dir, BootstrapFQCN]` map is built in `Bootstrap` from the registry +
  `SERVICE_BOOTSTRAPS`; services live at `GATEWAY_SERVICES_DIR`
  (default `<bundle>/services`).
- **Env isolation is the crux.** Services do `Dotenv::createImmutable()->load()`
  and read `$_ENV`; a reused FPM worker keeps those globals, and an *immutable*
  loader won't overwrite an existing key — so a later `/customer` request would
  see the earlier `/auth` request's `DB_NAME`. The dispatcher wraps each dispatch
  in a surgical env scope: it enumerates the service's `.env` keys with
  `Dotenv::parse` (side-effect free), clears exactly those from
  `$_ENV`/`$_SERVER`/`getenv`, then restores their prior state in a `finally`.
  This is why the services stay **byte-for-byte unchanged** (still run standalone
  via `composer start`).
- `Action\DispatchAction` is the catch-all (`/{path:.*}`): `match` (→ 404 only if
  the default service is disabled and no prefix matched), add `X-Forwarded-*`,
  dispatch in-process, wrap any failure as a 502.
  `Action\InProcessHealthAction` runs each service's `/healthz` in-process and
  aggregates (a boot/dispatch failure = status 0), same JSON shape as the proxy
  `HealthAction`.
  **A status-0 service logs WHY** — to `logs/gateway.log` *and* `error_log()`,
  never into the response, because `/healthz` is public and an exception message
  carries absolute paths. Before that, the aggregate said `status: 0` and nothing
  else, and this path never reaches `DispatchAction` (the only other place that
  logged a dispatch failure), so a service down in production was indistinguishable
  between "directory missing", "vendor/ unreadable" and "fatal during boot". If you
  are looking at a red `/healthz`, read the log line — it names the cause directly.
  **`status: 0` always means the service threw before answering** — in practice a
  malformed `.env` (see the installer section) or a missing `services/<name>/vendor/`.
  It is NOT a migration failure: `MigrationRunner::ensureMigrated()` catches
  everything internally, so the auto-migrator can never take a service down.
- **Gating on the self-reported `db` only works if the backend reports it.**
  `Support\HealthBody` reads `db` and the aggregate flips to 503 on
  `down`/`no-schema`; a body without the key means "nothing to gate on".
  `tds-core-frontend-api` never sent one, so a frontend pointed at a dead or
  un-migrated database reported `{"ok":true,"status":200}` and the whole API
  looked healthy while every route 500'd — the exact hole this gating exists to
  close (gateway#4), left open for the one service that carries all the module
  routes. Fixed in core-frontend-api 0.11.2. Any new backend must answer
  `/healthz` with `db` = `ok`/`no-schema`/`down`.
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

- **`frontend` is the default catch-all — don't strip anything for it.** Only
  `/auth` and `/customer` are prefix-stripped; everything else is forwarded to
  `tds-core-frontend-api` verbatim (its module routes live at root). Adding a new
  prefixed backend means adding it to `ServiceRegistry::DEFAULTS` +
  `Bootstrap::SERVICE_BOOTSTRAPS`; new frontend features need no gateway change
  (they're just more root routes on the catch-all).
- **Don't add CORS here.** Each upstream emits its own CORS headers and the
  proxy forwards them; injecting gateway CORS would duplicate
  `Access-Control-Allow-Origin`. OPTIONS preflights are forwarded so the
  upstream's CorsMiddleware answers them.
- **Don't statically serve upload routes.** Any file-serving route (blog/CMS
  cover/body images, customer/document files) is user content — the owning API
  stamps the anti-XSS headers (nosniff, sandbox CSP, and `Content-Disposition:
  attachment` for SVG). Whether the front door is this PHP gateway,
  `deploy/nginx.conf.example`, or the Docker stack, uploads must reach that PHP
  action; a static `alias` shortcut to "offload PHP" drops the hardening and
  re-exposes stored XSS via a crafted SVG. Same header-ownership rule as CORS:
  the upstream owns it, the front door just forwards.
- **Keep Content-Encoding, drop Content-Length** on the response. We forward
  the exact upstream body bytes (gzipped or not); the emitter recomputes
  length. Forwarding the upstream's Content-Length would risk a mismatch.
- **Don't add BodyParsingMiddleware.** The proxy needs the raw body
  (`(string) $request->getBody()`), not a parsed array.
- **Every `php -S` needs `public/router.php`.** The built-in server 404s any
  dotted path that has no file on disk *without ever invoking PHP* — in proxy
  mode every upstream's `/.well-known/jwks.json` (JWT verification!) silently
  dies. `composer start`, `bin/start-stack.sh` and both supervisor confs pass the
  router; keep it when adding a new run mode. The router serves real files
  (`install.php`, `robots.txt`) as-is and routes everything else to `index.php`.
  Apache (.htaccess) and in-process mode are unaffected.
- **Env helper:** never `$_ENV[$key] ?? getenv($key) ?: $default` — `??`
  binds tighter than `?:` and clobbers falsy values. Use explicit `?? false`
  (same bug that bit all the APIs).
- **Bodies are buffered in memory** in *proxy* mode (cURL `POSTFIELDS` with the
  raw string). Fine for current upload sizes (blog covers, customer docs);
  revisit with streaming if large uploads land. In-process mode passes the PSR-7
  request straight through (uploads via `getUploadedFiles()`), so no extra copy.
- **In-process autoloader: shared packages are decided by DISPATCH ORDER, and
  `scripts/check-shared-deps.php` is what keeps that honest.**
  Each request loads one service's autoloader; shared libs (Slim/php-di/
  phpdotenv/symfony) load once and "first loaded wins" for the worker. The
  aggregate `/healthz` dispatches auth → customer → frontend in one process, so
  the winner is picked by registry order — something no service author can see
  from their own repo.
  > This bullet used to claim the copies match "because the bundle is assembled
  > from all repos at once with identical constraints". **They were not
  > identical.** Diffing the three assembled locks found **33** shared packages
  > at differing versions, and `symfony/mailer` a whole MAJOR apart — the
  > frontend requiring `^6.4` while customer resolved `^7.4`, so the frontend
  > ran against customer's Symfony 7 classes (reproduced in the Docker stack).
  > It survived only because the few methods it calls did not change between the
  > majors. Aligned in tds-core-frontend-api 0.11.0; see tds-gateway-api#8.
  The assemble now runs `scripts/check-shared-deps.php _src/auth _src/customer
  _src/frontend`, which **fails the build on a major mismatch** and merely
  reports minor/patch drift — the services are locked independently by design,
  so exact equality is not reachable without one shared lock. Pass the service
  directories explicitly; the checkout also holds the contract and all 14
  extensions, several with their own `composer.lock`, and those are mirrored
  into the frontend's vendor rather than loaded as separate processes.
  `frontend` composes its extensions via Composer `path` repos; the assemble
  step mirrors (copies) them into its `vendor/` (`COMPOSER_MIRROR_PATH_REPOS=1`)
  so the bundle ships no dangling symlinks.
- **Don't make the services depend on the gateway, or read env outside their
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
  static closure returns null. The backends need nothing (the gateway fronts
  them); this exists only at the front door.

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
  `assemble` — checks out the gateway + `auth` + `customer` + `frontend`
  (`tds-core-frontend-api`) **and the frontend's extension packages** (the
  `tds-ext-*` repos its composer.json `path`-repos + `tds-frontend-contract-pkg`)
  at `main`. It runs `composer install --no-dev` for gateway/auth/customer and
  **re-adds phinx** for auth+customer (`composer require
  robmorgan/phinx:<require-dev constraint> --update-no-dev`) so the host can
  migrate from the bundle without a composer install. For `frontend` it runs
  `COMPOSER_MIRROR_PATH_REPOS=1 composer update --no-dev` so the sibling
  extension checkouts are **copied** (not symlinked) into `vendor/` — the bundle
  stays self-contained — and it already carries phinx in `require`. Then it
  assembles `dist/` (gateway at root, services under `services/{auth,customer,frontend}`,
  plus Procfile / services.json / BUILD_INFO.json — the latter records the source
  commit of each backend **and** each frontend extension) and publishes to the
  `inputs.channel` branch (deploy ping gated on `inputs.deploy`). PR merge-gate is
  the separate `ci.yml`.
- `public/.htaccess` ships the Apache front-controller rewrite (same file as
  the APIs) so the gateway can run straight under PHP-FPM with the docroot on
  `gateway/public` — the Plesk model in `DEPLOY-PLESK.md`. Without it every route
  except `/` 404s on Apache hosts.

### End-to-end wiring (gateway ↔ the backend repos)

The auto-reassembly loop spans two halves; both are live and verified.

1. **API side** — each backend repo (`tds-auth-api`, `tds-customer-api`) has its
   own `dev.yml` / `release.yml` (+ `ci.yml` for PRs). Its **manual Release**
   (`release.yml`) pings `DEPLOY_WEBHOOK_URL` **and** POSTs an `api-pushed`
   `repository_dispatch` to this repo (`.../tds-api-gateway/dispatches`) using
   `GATEWAY_DISPATCH_TOKEN`. The dispatch step skips quietly (logs + `exit 0`)
   when the token is unset, so a missing secret never reds the API's CI.
   (`tds-core-frontend-api` and its extension packages have their own release
   flows; wiring an `api-pushed` dispatch from them into the gateway `dev`
   reassembly is a follow-up — for now bump the gateway or press its Release to
   pick up a new frontend/extension version.)
2. **Gateway side** — the `repository_dispatch(api-pushed)` trigger fires
   `dev.yml`, which rebuilds the **`dev`** bundle from every source repo at `main`.
   So a backend release ⇒ dispatch ⇒ gateway `dev` reassembles. The gateway's own
   `release` is a separate manual button.

To test the chain without an API push:
`gh api -X POST repos/Tracht-Digital-Solutions/tds-gateway-api/dispatches
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
softening is mirrored in the backend repos' `ci.yml`.

### Required secrets

| Secret | Where | Purpose | Status |
|---|---|---|---|
| `ASSEMBLE_TOKEN` | this repo | org PAT (`repo` scope, SSO-authorized): checks out the backend + extension repos **and** pushes the `dev`/`release` branch (the peaceiris `github_token:` field — despite the name, *not* the default `GITHUB_TOKEN`). | set |
| `GATEWAY_DISPATCH_TOKEN` | each backend repo | PAT that can POST `repository_dispatch` to this repo (the same org PAT as `ASSEMBLE_TOKEN` works). | set |
| `DEPLOY_WEBHOOK_URL` | this repo + each backend repo | deploy hook the host pulls on; carries its own token. Optional — the step skips when unset and is non-fatal when set (see above). | Wire to the host's deploy hook once its URL is known; while unset or misconfigured the ping just skips / warns and never reds the job. |

Gotcha: `actions/checkout` errors `Input required and not supplied: token`
when `token:` is given but the secret resolves empty — it does **not** fall
back to `GITHUB_TOKEN`. A missing `ASSEMBLE_TOKEN` therefore fails `assemble`
at the first cross-repo checkout while `check` stays green (no secrets needed).
That asymmetric failure is the tell for an unset/expired token.

## API wiki (moved out of the gateway)

- The gateway **no longer serves the API wiki.** It is owned by the composed
  frontend API — `tds-core-frontend-api`'s `GET /wiki.json` introspects every
  enabled module's live Slim routes at request time (admin-gated via the JWT) —
  and rendered by the admin frontend's Wiki page. `/wiki.json` reaches it through
  the gateway's catch-all like any other frontend route; there is no
  gateway-owned wiki route, generator (`gen-api-wiki.php`), or `wiki/` artifact
  anymore. Adding a module route surfaces it automatically, no gateway change.

## Web installer (`public/install.php`)

- Self-contained first-run setup wizard served as a plain file at
  `/install.php` (the `.htaccess` serves real files before the Slim
  front-controller, so it isn't proxied). It is **not** a Slim route — no
  autoloader of its own; it drives the in-process Phinx migrator from each
  bundled `services/<name>/vendor/` (subprocess phinx fallback when `proc_open`
  is available) and uses `ext-openssl` directly for the auth keypair.
- Path model: it lives at `<bundle>/gateway/public/install.php` and resolves
  `<bundle>/services/<name>` two levels up; shows a "bundle not assembled"
  guard when run outside the assembled bundle (e.g. the dev repo).
- Writes `services/<name>/.env` (+ gateway `.env`) for **auth, customer, frontend**
  from the same templates as the `.env.example`s / `deploy/docker-entrypoint.sh`;
  keep all three in sync when a service gains an env var.
- **Every generated line goes through `env_line()`, which QUOTES and escapes the
  value — never string-interpolate a value into the `.env` body.** phpdotenv
  refuses a bare unquoted value containing a space, and each service's
  `Bootstrap::createApp()` loads the `.env` *before anything else*, so one such
  line takes the WHOLE service down at boot rather than just breaking its own
  setting. Until 0.4.8 `env_for()` emitted `MAIL_FROM_NAME=Tracht Digital
  Solutions` unquoted, so **every fresh install left the frontend dead**:
  `/healthz` reported `"/frontend": {"status": 0}`, every catch-all route
  answered `500 Slim Application Error`, and nothing appeared in the app log
  (the failure precedes the error handler). auth and customer stayed green only
  because their values happen to contain no spaces — which is exactly what made
  it read as a frontend bug. `deploy/docker-entrypoint.sh` had already been
  fixed for this; the installer was missed. Escaping is `\` → `\\`, `"` → `\"`,
  `$` → `\$`; the `$` escape matters beyond parsing, since phpdotenv
  interpolates `${VAR}` inside double quotes and would otherwise silently
  rewrite a generated password. `read_env_kv()` is the exact INVERSE and must
  stay so — it feeds the DB credentials to the migration steps, so if it stopped
  unescaping, a password containing `$`/`"`/`\` would migrate against the wrong
  credentials while the services themselves connected fine.
  `tests/Support/InstallEnvFileTest.php` pins all of this (it extracts the
  installer's helpers via the tokenizer, since `install.php` is a single file
  that `session_start()`s at top level and cannot be included).
- **Only installation-relevant secrets are set here.** Step 3 no longer collects
  third-party service keys (Stripe, DeepL, Lexware, GitHub blog-rebuild) — those
  are configured at runtime in the admin frontend („Einstellungen“) and stored
  encrypted per service in the `app_setting` table. The installer only provisions
  a per-service `SETTINGS_ENCRYPTION_KEY` (auto-generated, like `document_sign_secret`)
  that protects those runtime secrets — written into the customer/frontend `.env`
  blocks in `env_for()`.
- **Frontend migration is different.** auth + customer migrate via their bundled
  phinx (`run_migration`). `frontend` (`tds-core-frontend-api`) has no single
  `db/migrations` + `phinx.php` — it composes every enabled extension's migration
  paths and applies them through its OWN in-process migrator (`migrate_frontend()`
  requires the frontend `vendor/`, builds `Bootstrap::migrationPaths()` +
  `Support\MigrationRunner`, and verifies via a `phinxlog` count). It also
  auto-migrates on the first request (`AUTO_MIGRATE=1`), so the installer step is
  a head start, not the only path. All frontend extensions share one DB
  (`tds_frontend`) and one `phinxlog`.
- **Apply phase is a per-task AJAX driver, not one blocking POST.** Step 4 runs
  each install step (`install_tasks()` / `run_task()`: env writes → keypair →
  dir → the migrations (auth, customer, frontend) → **create_admin** → finalize)
  as its own small JSON request while a progress bar advances. This replaced the
  old "do everything in one request" apply that appeared to hang (serial
  migrations blew past `max_execution_time`; `run_migration` blocked forever on
  `stream_get_contents`). Now: tasks run with `set_time_limit(0)`, `run_migration`
  reads non-blocking against a 120s deadline (`proc_terminate` on timeout), and
  the per-task guard keys on the **`.tds-installed` lock only** (NOT
  `services/auth/.env`) — the first task writes that `.env`, so an
  `$alreadyInstalled`-style guard there would abort every later task. A
  `<noscript>` form keeps the single-request fallback.
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
  (JS `donePanel` + no-JS step 5) print the login and the admin-frontend URL
  (`management.tracht-digital.de`).
- **CORS default** lists `management.tracht-digital.de` (the admin frontend's current
  address) alongside `app.`/blog/landing — keep it in sync with the backends'
  `CORS_ALLOWED_ORIGINS` (the gateway itself emits no CORS).
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
  applies each service's pending migrations, then marks done. **Only `auth` +
  `customer`** are driven here (`Bootstrap::AUTO_MIGRATE_SERVICES`): `frontend`
  has no single `db/migrations` + `phinx.php`, so it self-migrates through
  `tds-core-frontend-api`'s own in-process migrator when its app is first built
  (the dispatcher constructs its `Bootstrap::createApp`, which runs its
  `AUTO_MIGRATE`).
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
  class/filename with the service/extension. Defense: `MigrationRunner`
  text-scans each service's declared migration classes before running and
  **skips a colliding service with a logged error** (no marker → health shows
  `db:no-schema`) instead of fataling. (The same rule holds *within* `frontend`:
  its extensions share one `phinxlog`, enforced by `tds-core-frontend-api`'s own
  runner — see that repo.)
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
  hiding behind a bare `SELECT 1` (tds-gateway-api#4).

## Tests

PHPUnit 10, no DB, no network. Proxy mode fakes `Http\ProxyClientInterface`
(`tests/Support/FakeProxyClient`); in-process mode injects a fake app-resolver +
temp `.env` into `InProcessDispatcher` (`tests/Dispatch/InProcessDispatcherTest`
covers env isolation, path rewrite, 404, 502; `DispatchActionTest` /
`InProcessHealthActionTest` cover the actions). `ServiceRegistryTest` /
`ProxyActionTest` / `DispatchActionTest` cover the **default (catch-all)
routing** — an unmatched path routing to `frontend` verbatim, and a 404 only when
`GATEWAY_DEFAULT_SERVICE` is disabled. `composer test` runs the suite. The
installer is a standalone script (no unit tests); validate it with `php -l` + a
built-in-server smoke.

## Tests — routing arithmetic and health parsing

`tests/Config/ServiceTest.php` pins the gateway's routing arithmetic. A mistake
in those three small methods sends one backend's request to another —
`/auth/admin/login` arriving at the customer API, or a bare prefix hit arriving
as an **empty** path the upstream router 404s (hence `pathFor('')` → `/`).
Covered in both shapes: prefixed services (segment stripped) and the default
catch-all (forwarded verbatim, since the composed frontend API owns the root
namespace). Also that `healthUrl()` ignores the rewrite — every backend exposes
`/healthz` at its ROOT — and that a dotted path like `/.well-known/jwks.json`
survives intact, since that is how every consumer verifies a JWT.

`tests/Support/HealthBodyTest.php` pins how the gateway spots a backend that is
UP but not usable. Every probe returns HTTP 200 by contract, so the aggregate
cannot gate on status alone: a reachable but un-migrated backend answers 200
while every real query fails, and reports `db: "no-schema"`.

> The `null` return is deliberately **distinct from `"down"`**: it means "this
> body carries nothing to gate on" — an older backend, or a service with no
> database — and callers must treat it as *not a failure*. Collapsing the two
> would 503 the whole gateway the moment one service lags a release. That
> distinction is asserted from both sides.

Verified by mutation: 17 deliberate breakages introduced, 17 caught.
