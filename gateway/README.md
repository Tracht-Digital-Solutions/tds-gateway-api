# tds-gateway-api

Single public entry point for the Tracht Digital Solutions backends. It serves
`api.tracht-digital.de` and routes by path prefix to the PHP services, so the
whole API surface lives behind one host:

| Public path | Upstream | Owns |
|---|---|---|
| `api.tracht-digital.de/auth/*`     | `:8003` (tds-auth-api)          | JWT issuance, JWKS, sessions |
| `api.tracht-digital.de/customer/*` | `:8004` (tds-customer-api)      | customers, invoices, docs, time |
| everything else (default) | `:8100` (tds-core-frontend-api) | the composed base + extensions (tickets, tools, blog/website CMS, contact, billing, lexware, messages, projects, documents, …) |

`tds-core-frontend-api` is the **default (catch-all)** upstream — the composed
frontend API that replaced the archived `content` + `contact` backends. Its
module routes live at root (`/tickets`, `/tools`, `/admin/settings`, `/me/…`,
`/wiki.json`), so anything not under `/auth` or `/customer` is forwarded to it.

PHP 8.3 + Slim 4 — same stack, env-helper, CI and deploy-webhook pattern as
the backends it fronts. It is a **transparent reverse proxy**: it forwards
method, query, headers, body and cookies, adds `X-Forwarded-*`, and mirrors
the upstream response back unchanged. CORS stays owned by each upstream.

## Routing

The first path segment selects the service; the remainder is forwarded.
auth/customer mount their routes at root, so the prefix is stripped
(`/auth/admin/login` → `:8003/admin/login`). Anything that doesn't match a
prefix falls through to the **default** service (`frontend`, `:8100`) with the
whole path preserved (`/tools/catalog` → `:8100/tools/catalog`). All of this is
configurable via `GATEWAY_SERVICES`, `GATEWAY_DEFAULT_SERVICE` and per-service
`{NAME}_UPSTREAM` / `{NAME}_REWRITE` (see `.env.example`).

## Gateway endpoints

- `GET /` — navigation: lists the public service prefixes.
- `GET /healthz` — aggregated health; pings every upstream's `/healthz`
  with a short timeout. `200` when all healthy, `503` otherwise — including
  when a service is reachable but un-migrated (its `db` field reports
  `ok | no-schema | down`, and `no-schema`/`down` flip the aggregate to `503`).
- `GET /install.php` — the **web install wizard** (first-run setup); see below.
- everything else — routed to the matching prefix or the default upstream
  (`502` if the upstream is unreachable; `404` only if the catch-all is disabled
  and no prefix matched). The internal API wiki is served by the frontend API at
  `/wiki.json` (admin-gated), reached through this catch-all.

## Web installer (`/install.php`)

A self-contained setup assistant that ships in the bundle
(`gateway/public/install.php`). Open it once after the first deploy and it
walks you through: requirements check → **database connection** (tests the
connection, can create the per-service databases: `tds_auth`, `tds_customer`,
`tds_frontend`) → secrets (admin token, CORS, the settings-encryption key) →
then writes every `services/<name>/.env` (+ the gateway `.env`), generates the
auth RS256 keypair, creates the storage dir and runs the migrations (auth +
customer via phinx; frontend via its own in-process migrator over its composed
extensions). Third-party keys (Stripe, DeepL, Lexware, GitHub rebuild) are set
later at runtime in the admin frontend, not here. The blog/website page-cache
tokens follow the same rule: the service `.env` names are optional fallbacks,
while a fresh install configures the encrypted values in the CMS settings UI.

**Security:** it refuses to run once configured (a `.tds-installed` lock or
an existing `services/auth/.env`), and the final screen offers to delete
itself. **Delete `gateway/public/install.php` once you're live** (and ideally
IP-restrict `/install.php` during setup) — before the first install it is an
open setup endpoint.

## Automatic migrations

Once each service's `.env` + database exist, **migrations apply themselves** —
no manual step. On the first request after a deploy, `Bootstrap::autoMigrate()`
brings `auth` + `customer` up to date **in-process** (Phinx's `Manager` API — no
`proc_open`, no CLI php, which is what makes it work on locked-down Plesk hosts
where the installer's shell-out silently applied nothing). `frontend`
(`tds-core-frontend-api`) self-migrates its composed extensions through its own
in-process migrator when its app is first built. Both are guarded to run at most
once per migration-set (a marker + single-flight `flock`) and are best-effort —
a failure is logged and surfaced as `db:no-schema` in `/healthz` rather than
taking the gateway down. In-process mode only; disable with
`GATEWAY_AUTO_MIGRATE=0`. See `AGENTS.md` → *Auto-migration* for the full contract.

## API wiki

The gateway no longer serves the wiki. The internal API route reference is
introspected live by the frontend API (`tds-core-frontend-api`'s `GET /wiki.json`,
admin-gated) over every enabled module's routes, and rendered by the admin
frontend's Wiki page. New module routes appear automatically — no build step.

## Commands

```bash
composer install
cp .env.example .env     # defaults already target localhost:800x
composer start           # php -S localhost:8000 -t public public/router.php
composer test            # phpunit
```

## Build & deploy — `dev` / `release` branches

CI assembles a **self-contained deployable bundle** (gateway + auth + customer +
the composed frontend, each with `vendor/`). Two tracks (the old `build` branch
is gone):

- **`dev`** — [`dev.yml`](.github/workflows/dev.yml) → reusable
  [`_assemble.yml`](.github/workflows/_assemble.yml): assembles to the orphan
  **`dev`** branch on every push or an `api-pushed` dispatch. **Not deployed.**
- **`release`** — [`release.yml`](.github/workflows/release.yml): assembles to
  the **`release`** branch **only on the manual Actions button**, then pings
  `DEPLOY_WEBHOOK_URL`. The host pulls **`release`**.

The bundle layout (same for both branches):

```
release/
  gateway/            # this repo, with vendor/
  services/{auth,customer,frontend}/   # each backend, with vendor/
  Procfile            # one process per service (+ gateway), with ports
  services.json       # machine-readable manifest (frontend flagged default)
  BUILD_INFO.json     # source commit SHA of every piece + each frontend extension
```

The `frontend` service is `tds-core-frontend-api`; its extensions are composed
at assemble time from sibling checkouts of the `tds-ext-*` packages via Composer
`path` repos, **mirrored (copied) into `vendor/`** so the bundle is
self-contained. auth + customer bundle phinx (re-added after the `--no-dev`
install) so the host runs their migrations straight from the bundle; `frontend`
already ships phinx and self-migrates its extensions in-process — no composer on
the host either way.

The assembly runs on a push to this repo's `main` (or an `api-pushed`
`repository_dispatch` from a backend repo).

See `AGENTS.md` for architecture/gotchas and `INSTALL.md` for the required
secrets and host wiring (`deploy/` has nginx + supervisor examples).
**[`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md)** is the complete production release
guide for a Plesk host — domains, subdomains, SSL, the single-project API
deploy and the deploy webhooks.
