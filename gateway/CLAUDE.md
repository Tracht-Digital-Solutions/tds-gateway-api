# CLAUDE.md — tds-gateway-api

> This repo used to carry a full copy of the workspace-level `CLAUDE.md`. That copy
> drifted (it described the pre-frontend "ten repos" era, Resend, install-time content
> editing). **The authoritative guidance now lives at the workspace root:**
> `C:\Projects\TDS-LP\CLAUDE.md`.

For this repo specifically, read **`AGENTS.md`** (architecture + gotchas), and the
`INSTALL*.md` / `DEPLOY-PLESK.md` files for setup and deployment.

## Quick orientation

`tds-gateway-api` is the single public entry for `api.tracht-digital.de`. It routes by
first path segment to the backend services. By default (`GATEWAY_MODE=inprocess`) it runs
each backend **in-process** in one PHP-FPM app; `GATEWAY_MODE=proxy` relays over HTTP to
loopback `php -S` services instead.

- **It fronts three backends (post frontend-platform cutover):** `auth` →
  `tds-auth-api` (`/auth/*`), `customer` → `tds-customer-api` (`/customer/*`), and
  `frontend` → `tds-core-frontend-api` as the **default catch-all** (everything else) —
  the composed base + extensions that replaced the archived `content`/`contact` backends.
- **CORS is owned by each upstream** and must never be added to the catch-all (a second
  `Access-Control-Allow-Origin` makes the browser reject the response outright). The
  gateway's OWN two routes — `/` and `/healthz` — are the exception: they have no
  upstream, so since 0.5.0 they carry CORS via per-route middleware. The internal API
  wiki lives in the frontend API (`/wiki.json`), not the gateway.
- **The `env()` `?? false` precedence rule and the "CORS after `addRoutingMiddleware()`"
  LIFO rule apply here too** — see the root `CLAUDE.md` "Cross-cutting conventions".

See `MIGRATION-STATUS.md` at the root for the legacy→frontend replacement map.
