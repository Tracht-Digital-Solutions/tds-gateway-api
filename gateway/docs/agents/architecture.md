# Architecture

## Two modes, chosen in `Bootstrap` from `GATEWAY_MODE`

Default `inprocess`. Both modes share the routing table and the gateway's only own routes, `/` and
`/healthz`; only the catch-all and the health action differ. The API wiki lives in the frontend API's
`/wiki.json`, reached through the catch-all.

## Routing table

- `Config\ServiceRegistry` maps prefix → `Service`, built from env with baked defaults.
  `match($path)` returns `[Service, $remainder]`: an explicit prefix (`auth`, `customer`) strips the
  segment; **anything else falls through to the default service** (`frontend`) with the whole path.
- `GATEWAY_DEFAULT_SERVICE` (default `frontend`) names the catch-all; `''` restores "unmatched → 404".
- `Service::targetFor($remainder, $query)` builds the upstream URL for proxy mode;
  `Service::pathFor($remainder)` is its host-less twin for in-process sub-requests (`''` → `/`).
  `rewrite` is empty for every current service; `isDefault` marks the catch-all.
- A new prefixed backend: add it to `ServiceRegistry::DEFAULTS` and `Bootstrap::SERVICE_BOOTSTRAPS`.
  New frontend features need no gateway change.

## In-process mode (default)

- `Dispatch\InProcessDispatcher` loads a service's `vendor/autoload.php` on demand and calls
  `Tds\<Name>Api\Bootstrap::createApp($dir)->handle($subReq)` (`Tds\AuthApi\`, `Tds\CustomerApi\`,
  `Tds\CoreFrontendApi\`). Services live under `GATEWAY_SERVICES_DIR` (default `<bundle>/services`).
- **Env isolation is the crux.** Services load `.env` with an immutable Dotenv and read `$_ENV`; a reused
  FPM worker keeps those globals, so a later request could see another service's `DB_NAME`. The
  dispatcher enumerates the service's `.env` keys with `Dotenv::parse`, clears exactly those from
  `$_ENV` / `$_SERVER` / `getenv`, and restores them in a `finally`. That keeps the services unchanged and
  runnable standalone.
- `Action\DispatchAction` (`/{path:.*}`): match (404 only when no default service), add
  `X-Forwarded-*`, dispatch, wrap any failure as 502.
- The dispatcher takes an **injectable app resolver** (`callable(dir, fqcn): App`), so tests use a fake
  app and `.env` without sibling repos.

## Proxy mode (`GATEWAY_MODE=proxy`)

- `Action\ProxyAction` relays via `Http\ProxyClientInterface` (cURL) and mirrors the response.
- `ProxyClientInterface::send()` (single, throws `ProxyException`) and `sendMany()` (concurrent
  curl_multi batch for health, never throws; failures are status 0).
- Two clients: long-timeout `proxy.client` and short-timeout `health.client` (connect 1 s, total 2 s).
- `Http\HeaderFilter` strips hop-by-hop headers, Host and Content-Length.
- Request bodies are buffered in memory (fine for current upload sizes; revisit with streaming for large
  uploads). In-process mode passes the PSR-7 request through.

## Health

- `Action\InProcessHealthAction` runs each service's `/healthz` in-process; `Action\HealthAction` fans
  out concurrently in proxy mode. Same JSON shape. `Action\IndexAction` lists prefixes.
- **`status: 0` means the service threw before answering** (in practice a malformed `.env` or a missing
  `services/<name>/vendor/`), never a migration failure. It is logged with the reason to
  `logs/gateway.log` and `error_log()`, never into the public response (exception messages carry paths).
- **Gating on `db`:** `Support\HealthBody` reads each backend's `db` (`ok` / `no-schema` / `down`); the
  aggregate answers 503 on `down` / `no-schema`. A body without `db` returns `null`, meaning "nothing to
  gate on", distinct from `down`, so one lagging service doesn't 503 the gateway. **Every backend must
  report `db`.**

## Logging (`Support\Logger`)

A dependency-free JSON-lines file logger, because PHP's default `error_log()` sink is effectively
invisible under PHP-FPM on Plesk.

- `ProxyAction` logs cURL errno + target URL on a failed hop (7 refused, 6 DNS, 28 timeout) at `error`,
  successes at `info`; `HealthAction` logs a `warning` listing down upstreams.
- Wired via `Logger::fromEnv($env, $rootDir)`, injected as a nullable constructor argument (tests run
  without a logger).
- `GATEWAY_LOG_FILE` (default `<root>/logs/gateway.log`, `off` disables) and `GATEWAY_LOG_LEVEL`
  (`debug|info|warning|error|off`, default `info`; `.env.example` ships `warning`).
- **Logging never breaks the proxy:** an unwritable path degrades to one `error_log()` and then goes
  silent. `logs/` is gitignored.

## Deindexing

`Http\RobotsTagMiddleware` stamps `X-Robots-Tag: noindex, nofollow` on every response. It is added after
the error middleware (LIFO → outermost), so errors and dispatched responses carry it. `public/robots.txt`
(`Disallow: /`) is the matching crawl block; keep both. It's a class, not a closure (Slim binds closure
middleware to the container, and `bindTo()` on a static closure returns null). The nginx example config
mirrors the header for the zero-hop mode.

## Apache front controller

`public/.htaccess` ships the rewrite so the gateway runs under PHP-FPM with the docroot on
`gateway/public` (see `DEPLOY-PLESK.md`). Without it every route except `/` 404s on Apache.
