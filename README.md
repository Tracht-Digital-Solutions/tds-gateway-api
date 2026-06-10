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
- everything else — proxied to the matching upstream (`404` if no prefix
  matches, `502` if the upstream is unreachable).

## Commands

```bash
composer install
cp .env.example .env     # defaults already target localhost:800x
composer start           # php -S localhost:8000 -t public
composer test            # phpunit
```

## Build & deploy — the `build` branch

CI assembles a **self-contained deployable bundle** and force-pushes it to the
orphan `build` branch (one commit per run), then pings `DEPLOY_WEBHOOK_URL` —
mirroring the frontend pipeline. The bundle is the gateway plus all four
services (each with `vendor/`) and a process manifest:

```
build/
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
