# INSTALL — tds-api-gateway

## 1. Local dev

```bash
composer install
cp .env.example .env          # defaults target localhost:800x
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
| `ASSEMBLE_TOKEN` | Classic PAT, `repo` scope, SSO-authorized for the org. Used to (a) checkout the four private API repos and (b) force-push the `build` branch. |
| `DEPLOY_WEBHOOK_URL` | Optional. Pinged after the `build` branch is updated so the host pulls and goes live. |

### Each API repo (`tds-auth-api`, `tds-contact-api`, `tds-content-api`, `tds-customer-api`)

| Secret | Purpose |
|---|---|
| `GATEWAY_DISPATCH_TOKEN` | PAT that can POST `repository_dispatch` to `tds-api-gateway`. The new CI step skips quietly when it is unset. |

## 3. Production host

Deploy is host-agnostic, like the other repos: on a successful build the
`build` branch is force-pushed and `DEPLOY_WEBHOOK_URL` is pinged. The host
checks out `build` and runs the bundle.

> **Plesk:** the full click-by-click release guide (domains, subdomains,
> SSL, the single-project API deploy, webhooks) is in
> [`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md).

Three ways to run the surface — pick one:

1. **Gateway under PHP-FPM** (Plesk-style). Point the webserver docroot at
   `gateway/public` (the bundled `.htaccess` does the front-controller
   rewrite on Apache) and run only the four service processes. No gateway
   process needed.
2. **PHP gateway as a process.** Run all five processes from the bundle's
   `Procfile` (see `deploy/supervisor.conf.example`) and point the host's
   webserver at the gateway on `:8000`. The gateway proxies to the services.
3. **nginx only** (no PHP hop). Run just the four services and use
   `deploy/nginx.conf.example` to prefix-route straight to their ports.

Migrations run **from the bundle**: the assemble workflow re-adds phinx
after the `--no-dev` install, so
`php vendor/bin/phinx migrate -e production` works inside each
`services/<name>/` without a composer install on the host.

Either way, each service still needs its own `.env` on the host (DB creds,
JWT keys, Resend/Stripe secrets — see each service's INSTALL.md). The gateway
itself needs no secrets; only the `*_UPSTREAM` values if the ports differ
from the defaults.

## 4. Verifying the pipeline

- Push to this repo's `main` → `build` branch updates.
- Push to any API repo → its CI sends `repository_dispatch` → this repo's
  Build workflow runs `assemble` → `build` branch updates with the new API.
- Check `BUILD_INFO.json` on the `build` branch for the source commit SHAs.
