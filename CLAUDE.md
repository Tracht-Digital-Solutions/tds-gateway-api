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

- **Both architectures depend on it.** Today it fronts the legacy services (`auth`,
  `customer`, and the archived `content`/`contact` code still deployed until cutover);
  after the frontend-platform cutover it must route to `tds-core-frontend-api` + `tds-auth-api`.
- **CORS is owned by each upstream**, never added at the gateway (it would duplicate the
  header).
- **The `env()` `?? false` precedence rule and the "CORS after `addRoutingMiddleware()`"
  LIFO rule apply here too** — see the root `CLAUDE.md` "Cross-cutting conventions".

See `MIGRATION-STATUS.md` at the root for the legacy→frontend replacement map.
