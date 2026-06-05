# Agent notes — tds-api-gateway

PHP 8.3 + Slim 4 transparent reverse proxy. Single entry for
`api.tracht-digital.de`; routes by first path segment to the four
micro-backends. Same env-helper / CI / deploy-webhook conventions as the
backends — read the root `CLAUDE.md` for the big picture.

## Mental model

- `Config\ServiceRegistry` is the routing table (prefix → `Service`), built
  from env with baked defaults for the four known services. `match($path)`
  returns `[Service, $remainder]`.
- `Service::targetFor($remainder, $query)` builds the upstream URL as
  `upstream + rewrite + remainder`. `rewrite` is empty for root-mounted
  services and `/contact` for contact-api.
- `Action\ProxyAction` is a catch-all (`/{path:.*}`). It relays the request
  via `Http\ProxyClientInterface` (cURL impl) and mirrors the response.
- `Action\HealthAction` fans out to every upstream `/healthz`;
  `Action\IndexAction` lists prefixes for navigation.
- `Http\HeaderFilter` strips hop-by-hop + Host + Content-Length (recomputed
  from the body we hold).

## Gotchas / don't

- **Don't strip the contact prefix.** contact-api's only route is `/contact`
  and the frontend POSTs to `.../contact` with no sub-path. The registry
  default rewrites `/contact` → `:8002/contact`. The other three strip.
- **Don't add CORS here.** Each upstream emits its own CORS headers and the
  proxy forwards them; injecting gateway CORS would duplicate
  `Access-Control-Allow-Origin`. OPTIONS preflights are forwarded so the
  upstream's CorsMiddleware answers them.
- **Keep Content-Encoding, drop Content-Length** on the response. We forward
  the exact upstream body bytes (gzipped or not); the emitter recomputes
  length. Forwarding the upstream's Content-Length would risk a mismatch.
- **Don't add BodyParsingMiddleware.** The proxy needs the raw body
  (`(string) $request->getBody()`), not a parsed array.
- **Env helper:** never `$_ENV[$key] ?? getenv($key) ?: $default` — `??`
  binds tighter than `?:` and clobbers falsy values. Use explicit `?? false`
  (same bug that bit all four APIs).
- **Bodies are buffered in memory** (cURL `POSTFIELDS` with the raw string).
  Fine for current upload sizes (blog covers, customer docs); revisit with
  streaming if large uploads land.

## The build pipeline (`.github/workflows/build.yml`)

- `check` job: validate + install + `php -l` + phpunit (runs on PRs too).
- `assemble` job (not on PRs): checks out the gateway + all four API repos at
  `main`, runs `composer install --no-dev` for each, assembles `dist/`
  (gateway at root, services under `services/`, plus Procfile / services.json
  / BUILD_INFO.json), force-pushes to the orphan `build` branch, then pings
  `DEPLOY_WEBHOOK_URL`.
- Triggers: push to this repo's `main`, `workflow_dispatch`, and
  `repository_dispatch` (`api-pushed`) sent by each API repo's CI.

**Required secrets** (this repo): `ASSEMBLE_TOKEN` — org PAT with `repo`
scope, SSO-authorized, used both to checkout the private API repos and to
push the `build` branch (the peaceiris `github_token:` field). Optional:
`DEPLOY_WEBHOOK_URL`. **Each API repo** needs `GATEWAY_DISPATCH_TOKEN` — a
PAT that can POST `repository_dispatch` to this repo.

## Tests

PHPUnit 10, no DB, no network — `Http\ProxyClientInterface` is faked
(`tests/Support/FakeProxyClient`). `composer test` runs the suite.
