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

### End-to-end wiring (gateway ↔ the four API repos)

The auto-reassembly loop spans two halves; both are live and verified.

1. **API side** — each API repo (`tds-auth-api`, `tds-contact-api`,
   `tds-content-api`, `tds-customer-api`) has its own
   `.github/workflows/ci.yml`: a `check` job (validate + install + `php -l` +
   phpunit) and, on push to `main`, a `deploy` job that pings
   `DEPLOY_WEBHOOK_URL` **and** POSTs an `api-pushed` `repository_dispatch` to
   this repo (`.../tds-api-gateway/dispatches`) using `GATEWAY_DISPATCH_TOKEN`.
   The dispatch step skips quietly (logs + `exit 0`) when the token is unset,
   so a missing secret never reds the API's CI — it just silently stops
   reassembling the gateway. The four `ci.yml` files are identical except the
   PHP `extensions:` list (auth: `openssl`; customer: `openssl, fileinfo`).
2. **Gateway side** — the `repository_dispatch(api-pushed)` trigger above fires
   the `assemble` job, which rebuilds the bundle from all five repos at `main`
   and force-pushes `build`. So a push to *any* API ⇒ that API's CI ⇒ dispatch
   ⇒ gateway reassembles. A push to the gateway itself reassembles directly.

To test the chain without an API push:
`gh api -X POST repos/Tracht-Digital-Solutions/tds-api-gateway/dispatches
-f event_type=api-pushed` — then confirm a `repository_dispatch`-triggered
run lands and the `build` branch SHA advances.

**The deploy-webhook ping is deliberately non-fatal — don't "fix" it back to
`curl -fsS`.** By the time that step runs, the bundle is already force-pushed
to `build`, so a wrong/expired/unreachable `DEPLOY_WEBHOOK_URL` (404, timeout,
DNS) must not red the job and mask a good assembly. The step captures the HTTP
status (`-w '%{http_code}'`, `|| echo 000` for a connect failure) and emits a
`::warning::` annotation on a non-2xx instead of exiting non-zero. So a broken
webhook shows as a **yellow warning on a green run**, never a red build — check
the run annotations, not the job status, to catch a dead deploy hook. The same
softening is mirrored in all four API repos' `ci.yml`.

### Required secrets

| Secret | Where | Purpose | Status |
|---|---|---|---|
| `ASSEMBLE_TOKEN` | this repo | org PAT (`repo` scope, SSO-authorized): checks out the private API repos **and** pushes the `build` branch (the peaceiris `github_token:` field — despite the name, *not* the default `GITHUB_TOKEN`). | set |
| `GATEWAY_DISPATCH_TOKEN` | each of the 4 API repos | PAT that can POST `repository_dispatch` to this repo (the same org PAT as `ASSEMBLE_TOKEN` works). | set in all 4 |
| `DEPLOY_WEBHOOK_URL` | this repo + each API repo | deploy hook the host pulls on; carries its own token. Optional — the step skips when unset and is non-fatal when set (see above). | set on the gateway but currently returns **404/timeout** (surfaces as a build warning); **unset** on all 4 API repos. Needs a correct host URL. |

Gotcha: `actions/checkout` errors `Input required and not supplied: token`
when `token:` is given but the secret resolves empty — it does **not** fall
back to `GITHUB_TOKEN`. A missing `ASSEMBLE_TOKEN` therefore fails `assemble`
at the first private-repo checkout while `check` stays green (no secrets
needed). That asymmetric failure is the tell for an unset/expired token.

## Tests

PHPUnit 10, no DB, no network — `Http\ProxyClientInterface` is faked
(`tests/Support/FakeProxyClient`). `composer test` runs the suite.
