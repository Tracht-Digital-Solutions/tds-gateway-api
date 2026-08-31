# tds-api-gateway — assembled bundle (generated)

Do not edit. This branch is force-pushed by CI on every gateway or
API push. It contains the PHP gateway plus the current backends
(`auth`, `customer`, and the composed `frontend` = tds-core-frontend-api
with its extensions) with their `vendor/` directories, ready to run.

Default run mode: point a PHP-FPM docroot at `gateway/public` — the
gateway serves every service in-process (`GATEWAY_MODE=inprocess`),
nothing else to start. `frontend` is the default (catch-all) upstream:
`/auth/*` and `/customer/*` route to those backends, everything else to
the composed frontend API. The `Procfile` / `deploy/supervisor.conf.example`
are for the optional `GATEWAY_MODE=proxy` (loopback `php -S`). Each
service still needs its own `.env` on the host. The internal API wiki
is served by the frontend API (`/wiki.json`), not the gateway. See
`BUILD_INFO.json` for the source commit of each piece.
