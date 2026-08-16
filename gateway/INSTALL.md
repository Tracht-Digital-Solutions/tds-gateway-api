# INSTALL — tds-gateway-api

> **Deploying the whole stack (gateway + the backends)?** Pick a guide:
> - [`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md) — **the** production release guide for a
>   Plesk host: all properties (the frontends + the API bundle), DNS/SSL,
>   the single-project API deploy, the deploy webhooks. Plesk's built-in PHP
>   Composer covered.
> - [`INSTALL-STACK.md`](./INSTALL-STACK.md) — bare-metal/VPS: deploy the
>   bundle, then `bin/start-stack.sh` brings the services up.
> - [`INSTALL-DOCKER.md`](./INSTALL-DOCKER.md) — one command (`docker compose up`)
>   for MariaDB + gateway + the backends.

The gateway fronts three backends: `auth` (`tds-auth-api`, `/auth/*`), `customer`
(`tds-customer-api`, `/customer/*`), and `frontend` (`tds-core-frontend-api`) as
the **default catch-all** for everything else — the composed base + extensions
that replaced the archived `content`/`contact` backends.

## 1. Local dev

The gateway has two modes (`GATEWAY_MODE`): **`inprocess`** (default) runs each
service's Slim app *inside* the gateway process — that's how the assembled bundle
serves the whole surface as one PHP-FPM app in production, with nothing to start.
**`proxy`** relays over HTTP to separately-running services. Locally the APIs
live in sibling repos (not a `services/` bundle), so use **proxy** mode:

```bash
composer install
cp .env.example .env          # then set: GATEWAY_MODE=proxy
composer start                # http://localhost:8000
```

Start the upstreams you want to exercise (each in its own repo, e.g.
`composer start` in tds-auth-api → :8003, tds-core-frontend-api → :8100). Then:

```bash
curl http://localhost:8000/                       # navigation
curl http://localhost:8000/healthz                # aggregated health
curl http://localhost:8000/auth/.well-known/jwks.json  # → :8003 (prefix stripped)
curl http://localhost:8000/tools/catalog          # → :8100/tools/catalog (default route)
```

Run the tests (no DB, no network needed):

```bash
composer test
```

## 2. CI secrets

### This repo (`tds-gateway-api`)

| Secret | Purpose |
|---|---|
| `ASSEMBLE_TOKEN` | Classic PAT, `repo` scope, SSO-authorized for the org. Used to (a) checkout the backend + extension repos (`tds-auth-api`, `tds-customer-api`, `tds-core-frontend-api`, `tds-frontend-contract-pkg`, the enabled `tds-ext-*`) and (b) force-push the `dev`/`release` branch. |
| `DEPLOY_WEBHOOK_URL` | Optional. Pinged after the `release` branch is updated (only on a manual Release run) so the host pulls and goes live. |

### Each backend repo (`tds-auth-api`, `tds-customer-api`)

| Secret | Purpose |
|---|---|
| `GATEWAY_DISPATCH_TOKEN` | PAT that can POST `repository_dispatch` to `tds-gateway-api`. The new CI step skips quietly when it is unset. |

## 3. Production host

Deploy is host-agnostic, like the other repos. Two branches (the old `build`
branch is gone): every push to `main` (or an `api-pushed` dispatch) auto-builds
the **`dev`** branch (not deployed); the manual *Actions → Release* button builds
the **`release`** branch and pings `DEPLOY_WEBHOOK_URL`. The host checks out
**`release`** and runs the bundle.

> **Plesk:** the full click-by-click release guide (domains, subdomains,
> SSL, the single-project API deploy, webhooks) is in
> [`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md).

Ways to run the surface — pick one:

1. **In-process under PHP-FPM** (`GATEWAY_MODE=inprocess`, the default —
   **recommended, the Plesk model**). Point the webserver docroot at
   `gateway/public` (the bundled `.htaccess` does the Apache front-controller
   rewrite). The gateway loads each service's app inside its own PHP-FPM
   request — **there are no service processes to start or keep alive.** The
   apps are read from `<bundle>/services/<name>` (override with
   `GATEWAY_SERVICES_DIR`). This is what makes "install + start on Plesk without
   SSH" possible: FPM serves everything on request.
2. **Proxy — gateway as a process** (`GATEWAY_MODE=proxy`). Run every process
   from the bundle's `Procfile` (see `deploy/supervisor.conf.example`, needs a
   long-lived shell / `@reboot` + watchdog) and point the host's webserver at the
   gateway on `:8000`. The gateway proxies over HTTP to the services on their
   loopback ports (auth :8003, customer :8004, frontend :8100).
3. **Proxy — nginx only** (no PHP gateway process). Run just the services and use
   `deploy/nginx.conf.example` to prefix-route straight to their ports.

**Migrations apply automatically** (in-process mode). Once each service's `.env`
+ database exist, `auth` + `customer` are brought up to date on the first request
after a deploy — **in-process via Phinx's `Manager` API** (no `proc_open`, no CLI
php), guarded to run once per migration-set, best-effort. `frontend`
(`tds-core-frontend-api`) self-migrates its composed extensions through its own
in-process migrator when its app is first built. So on Plesk there's no manual
migration step; a failure surfaces as `db:no-schema` in `/healthz` (aggregate
`503`) instead of a silent empty DB. Disable with `GATEWAY_AUTO_MIGRATE=0`.

If you'd rather migrate auth/customer by hand (or auto-migration is off / fell
back), their migrations also run **from the bundle**: the assemble workflow
re-adds phinx after the `--no-dev` install, so `php vendor/bin/phinx migrate -e
production` works inside `services/auth/` and `services/customer/` without a
composer install on the host (the web installer at `/install.php` and
`bin/migrate-stack.sh` both do this for you). `frontend` has no single phinx
config — it always migrates through its own composed runner.

Each service still needs its own `.env` on the host (DB creds, JWT keys, plus a
`SETTINGS_ENCRYPTION_KEY` on customer/frontend — see each service's docs; the
`/install.php` wizard writes them all). Third-party service secrets (Stripe,
DeepL, Lexware, the GitHub blog-rebuild token) are **not** in `.env` anymore —
they're configured at runtime in the admin frontend („Einstellungen“) and stored
encrypted in each service's `app_setting` table. The gateway itself needs no
secrets — only, in **proxy** mode, the `*_UPSTREAM` values if the ports differ
from the defaults.

## 4. Verifying the pipeline

- Push to this repo's `main` → `dev.yml` runs `assemble` → `dev` branch updates
  (not deployed).
- Push to a backend repo (manual Release) → its CI sends `repository_dispatch` →
  this repo's `dev.yml` runs `assemble` → `dev` branch updates with the new API.
- Press *Actions → Release* here → `release.yml` runs `assemble` → `release`
  branch updates + `DEPLOY_WEBHOOK_URL` is pinged.
- Check `BUILD_INFO.json` on the `dev`/`release` branch for the source commit SHAs.
