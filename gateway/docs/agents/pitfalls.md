# Pitfalls

## CORS: upstreams own it, except on `/` and `/healthz`

Each upstream emits its own CORS headers and the proxy forwards them; OPTIONS preflights on proxied
paths go to the upstream. **Adding gateway CORS to the catch-all sends `Access-Control-Allow-Origin`
twice, and browsers reject the response outright.**

`/` and `/healthz` have no upstream, so the gateway answers them itself. Without CORS there, the four
sites' `/install` wizard reported a healthy gateway as unreachable. `Http\CorsMiddleware` is attached
**per route** with `->add()`, never `$app->add()`. `tests/Http/GatewayCorsTest.php` asserts both halves.
Origins are the first-party baseline plus `CORS_ALLOWED_ORIGINS`, union only. `/healthz` answers 503
when a service is down and still needs the header then.

## Headers and bodies

- **Never serve upload routes statically.** File-serving routes (CMS images, customer documents) are
  user content; the owning API stamps `nosniff`, a sandbox CSP and `Content-Disposition: attachment` for
  SVG. A static nginx `alias` shortcut drops that and re-exposes stored XSS.
- **Keep `Content-Encoding`, drop `Content-Length`** on responses; the emitter recomputes the length of
  the exact upstream bytes.
- **No `BodyParsingMiddleware`.** The proxy needs the raw body.

## `php -S` needs `public/router.php`

The built-in server 404s any dotted path without a file, without invoking PHP. In proxy mode every
upstream's `/.well-known/jwks.json` dies and JWT verification fails. `composer start`,
`bin/start-stack.sh` and both supervisor confs pass the router; keep it in any new run mode.

## Env

- Never `$_ENV[$key] ?? getenv($key) ?: $default` (`??` binds tighter than `?:` and clobbers falsy
  values). Use explicit `?? false`.
- Services must not read env outside their `Bootstrap`: the in-process env scope only brackets
  `createApp`, so a request-time `getenv()` escapes it. Services must not depend on the gateway.

## Shared packages in-process: dispatch order decides

Each request loads one service's autoloader; shared libraries (Slim, php-di, phpdotenv, symfony) load
once and the first loaded wins for the worker. The aggregate `/healthz` dispatches auth → customer →
frontend, so registry order picks the winner.

- The three services are locked independently, and shared packages drift (a `symfony/mailer` major
  mismatch once ran the frontend against Symfony 7 classes).
- The assemble runs `scripts/check-shared-deps.php _src/auth _src/customer _src/frontend`: it **fails on a
  major mismatch** and reports minor/patch drift. Pass the service directories explicitly; extensions
  with their own locks are mirrored into the frontend's `vendor/`.

## Docker stack

- **Don't start service processes in the default mode.** In `inprocess`, one `php -S` serves everything.
  The three backends key off `TDS_BACKEND_AUTOSTART`, derived by `deploy/docker-entrypoint.sh` from
  `GATEWAY_MODE` and logged at startup.
- **Don't map `GATEWAY_MODE` into `docker-compose.yml`'s `environment:`.** Compose substitutes from the
  project `.env`, which here is the app's local `composer start` config (still `proxy` and pre-cutover
  services). Override via `.env.docker`.
- **`PHP_CLI_SERVER_WORKERS` is required** (the entrypoint sets 8). With one worker, `php -S` can't answer
  the request it makes to itself: the in-process frontend fetches the auth JWKS over HTTP on every
  authenticated call, times out after 5 s, and answers 401 while nothing failed (`status=401 time=5.0s`).
  Production is PHP-FPM with a pool.
- **`API_PORT` is also the container port.** The frontends run with `network_mode: "service:api"`, so
  `localhost:8080` must mean the API inside those containers too. `deploy/supervisord.docker.conf` takes
  the port from `TDS_GATEWAY_PORT`, derived from `API_PORT`. Otherwise the public sites silently served
  placeholder content with 200.
