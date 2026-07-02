# INSTALL — tds-api-gateway

> **Deploying the whole stack (gateway + the four APIs)?** Pick a guide:
> - [`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md) — **the** production release guide for a
>   Plesk host: all five properties (four frontends + the API bundle), DNS/SSL,
>   the single-project API deploy, the deploy webhooks. Plesk's built-in PHP
>   Composer covered.
> - [`INSTALL-STACK.md`](./INSTALL-STACK.md) — bare-metal/VPS: deploy the
>   bundle, then `bin/start-stack.sh` brings the four services up.
> - [`INSTALL-DOCKER.md`](./INSTALL-DOCKER.md) — one command (`docker compose up`)
>   for MariaDB + gateway + all four services.

## 1. Local dev

The gateway has two modes (`GATEWAY_MODE`): **`inprocess`** (default) runs each
service's Slim app *inside* the gateway process — that's how the assembled bundle
serves the whole surface as one PHP-FPM app in production, with nothing to start.
**`proxy`** relays over HTTP to separately-running services. Locally the four APIs
live in sibling repos (not a `services/` bundle), so use **proxy** mode:

```bash
composer install
cp .env.example .env          # then set: GATEWAY_MODE=proxy
composer start                # http://localhost:8000
```

Start the upstreams you want to exercise (each in its own repo, e.g.
`composer start` in tds-auth-api → :8003). Then:

```bash
curl http://localhost:8000/                       # navigation
curl http://localhost:8000/healthz                # aggregated health
curl http://localhost:8000/content/blog           # → :8001/blog
curl -X POST http://localhost:8000/contact -d ... # → :8002/contact
```

Run the tests (no DB, no network needed):

```bash
composer test
```

## 2. CI secrets

### This repo (`tds-api-gateway`)

| Secret | Purpose |
|---|---|
| `ASSEMBLE_TOKEN` | Classic PAT, `repo` scope, SSO-authorized for the org. Used to (a) checkout the four private API repos and (b) force-push the `dev`/`release` branch. |
| `DEPLOY_WEBHOOK_URL` | Optional. Pinged after the `release` branch is updated (only on a manual Release run) so the host pulls and goes live. |

### Each API repo (`tds-auth-api`, `tds-contact-api`, `tds-content-api`, `tds-customer-api`)

| Secret | Purpose |
|---|---|
| `GATEWAY_DISPATCH_TOKEN` | PAT that can POST `repository_dispatch` to `tds-api-gateway`. The new CI step skips quietly when it is unset. |

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
   request — **there are no service processes to start or keep alive.** The four
   apps are read from `<bundle>/services/<name>` (override with
   `GATEWAY_SERVICES_DIR`). This is what makes "install + start on Plesk without
   SSH" possible: FPM serves everything on request.
2. **Proxy — gateway as a process** (`GATEWAY_MODE=proxy`). Run all five
   processes from the bundle's `Procfile` (see `deploy/supervisor.conf.example`,
   needs a long-lived shell / `@reboot` + watchdog) and point the host's
   webserver at the gateway on `:8000`. The gateway proxies over HTTP to the
   four services on their loopback ports.
3. **Proxy — nginx only** (no PHP gateway process). Run just the four services
   and use `deploy/nginx.conf.example` to prefix-route straight to their ports.

**Migrations apply automatically** (in-process mode). Once each service's `.env`
+ database exist, the gateway brings every schema up to date on the first request
after a deploy — **in-process via Phinx's `Manager` API** (no `proc_open`, no CLI
php), guarded to run once per migration-set, best-effort. So on Plesk there's no
manual migration step; a failure surfaces as `db:no-schema` in `/healthz` (aggregate
`503`) instead of a silent empty DB. Disable with `GATEWAY_AUTO_MIGRATE=0`.

If you'd rather migrate by hand (or auto-migration is off / fell back), migrations
also run **from the bundle**: the assemble workflow re-adds phinx after the
`--no-dev` install, so `php vendor/bin/phinx migrate -e production` works inside each
`services/<name>/` without a composer install on the host (the web installer at
`/install.php` and `bin/migrate-stack.sh` both do this for you).

Each service still needs its own `.env` on the host (DB creds, JWT keys,
Resend/Stripe secrets — see each service's INSTALL.md; the `/install.php` wizard
writes them all). The gateway itself needs only `ADMIN_TOKEN` if you want the
internal `/wiki` (set it to the same shared admin token; unset leaves the wiki
disabled/404), plus — in **proxy** mode only — the `*_UPSTREAM` values if the
ports differ from the defaults.

## 4. Verifying the pipeline

- Push to this repo's `main` → `dev.yml` runs `assemble` → `dev` branch updates
  (not deployed).
- Push to any API repo (manual Release) → its CI sends `repository_dispatch` →
  this repo's `dev.yml` runs `assemble` → `dev` branch updates with the new API.
- Press *Actions → Release* here → `release.yml` runs `assemble` → `release`
  branch updates + `DEPLOY_WEBHOOK_URL` is pinged.
- Check `BUILD_INFO.json` on the `dev`/`release` branch for the source commit SHAs.
