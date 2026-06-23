# tds-api-gateway

Single public entry point for the Tracht Digital Solutions micro-backends.
It serves `api.tracht-digital.de` and routes by path prefix to the four PHP
services, so the whole API surface lives behind one host:

| Public path | Upstream | Owns |
|---|---|---|
| `api.tracht-digital.de/auth/*`     | `:8003` (tds-auth-api)     | JWT issuance, JWKS, sessions |
| `api.tracht-digital.de/contact`    | `:8002` (tds-contact-api)  | contact form → email |
| `api.tracht-digital.de/content/*`  | `:8001` (tds-content-api)  | blog CRUD |
| `api.tracht-digital.de/customer/*` | `:8004` (tds-customer-api) | customers, invoices, docs, time |

PHP 8.3 + Slim 4 — same stack, env-helper, CI and deploy-webhook pattern as
the backends it fronts. It is a **transparent reverse proxy**: it forwards
method, query, headers, body and cookies, adds `X-Forwarded-*`, and mirrors
the upstream response back unchanged. CORS stays owned by each upstream.

## Routing

The first path segment selects the service; the remainder is forwarded.
auth/content/customer mount their routes at root, so the prefix is stripped
(`/auth/admin/login` → `:8003/admin/login`). contact-api's own route is
literally `/contact`, so it is mapped with a `/contact` rewrite
(`/contact` → `:8002/contact`). All of this is configurable per service via
`{NAME}_UPSTREAM` / `{NAME}_REWRITE` (see `.env.example`).

## Gateway endpoints

- `GET /` — navigation: lists the public service prefixes.
- `GET /healthz` — aggregated health; pings every upstream's `/healthz`
  with a short timeout. `200` when all healthy, `503` otherwise.
- `GET /wiki` — the internal **API wiki** (auto-generated route reference
  for every service). Login-gated by `ADMIN_TOKEN`; disabled (`404`) when
  that env is unset, so it is never reachable without being logged in.
- `GET /install.php` — the **web install wizard** (first-run setup); see below.
- everything else — proxied to the matching upstream (`404` if no prefix
  matches, `502` if the upstream is unreachable).

## Web installer (`/install.php`)

A self-contained setup assistant that ships in the bundle
(`gateway/public/install.php`). Open it once after the first deploy and it
walks you through: requirements check → **database connection** (tests the
connection, can create the per-service databases) → secrets (admin token,
CORS, optional Resend/Stripe/GitHub keys) → then writes every
`services/<name>/.env` (+ the gateway `.env`), generates the auth RS256
keypair, creates the storage dirs and runs each service's phinx migrations.

**Security:** it refuses to run once configured (a `.tds-installed` lock or
an existing `services/auth/.env`), and the final screen offers to delete
itself. **Delete `gateway/public/install.php` once you're live** (and ideally
IP-restrict `/install.php` during setup) — before the first install it is an
open setup endpoint.

## API wiki

`bin/gen-api-wiki.php` parses every service's `src/Bootstrap.php` and emits
`API-WIKI.md` (committed reference) + `wiki/index.html` (the page served at
`/wiki`). Because it reads the route definitions themselves, **new routes
appear automatically** — CI regenerates the wiki on every build. Run it
locally with `php bin/gen-api-wiki.php`.

## Commands

```bash
composer install
cp .env.example .env     # defaults already target localhost:800x
composer start           # php -S localhost:8000 -t public
composer test            # phpunit
```

## Build & deploy — `dev` / `release` branches

CI assembles a **self-contained deployable bundle** (gateway + all four
services, each with `vendor/`). Two tracks (the old `build` branch is gone):

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
  services/{auth,contact,content,customer}/   # each API, with vendor/
  Procfile            # one process per service (+ gateway), with ports
  services.json       # machine-readable manifest
  BUILD_INFO.json     # source commit SHA of every piece
```

The assembly runs on:
- a push to this repo's `main`, **and**
- a push to **any** of the four API repos — their CI sends a
  `repository_dispatch` (`event_type: api-pushed`) to this repo.

So the merged bundle is always rebuilt as soon as any API changes.

Each service bundles phinx (re-added after the `--no-dev` install), so the
host runs migrations straight from the bundle — no composer on the host.

See `AGENTS.md` for architecture/gotchas and `INSTALL.md` for the required
secrets and host wiring (`deploy/` has nginx + supervisor examples).
**[`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md)** is the complete production release
guide for a Plesk host — domains, subdomains, SSL, the single-project API
deploy and the deploy webhooks.
