# AGENTS.md — tds-gateway-api

The single public entry for `api.tracht-digital.de`: PHP 8.3 + Slim 4, routing by first path
segment. By default (`GATEWAY_MODE=inprocess`) it runs every backend **in-process** in one
PHP-FPM app (the Plesk "install and start without SSH" model); `GATEWAY_MODE=proxy` relays over
HTTP to loopback `php -S` services instead.

| Prefix | Backend | Path handling |
|---|---|---|
| `/auth/*` | `tds-auth-api` | prefix stripped |
| `/customer/*` | `tds-customer-api` | prefix stripped |
| everything else | `tds-core-frontend-api` (default catch-all: base + extensions) | forwarded verbatim |

Its `_assemble.yml` also builds and deploys `tds-core-frontend-api` with all extensions. Not
superseded by anything.

## Commands

```bash
composer install
composer start          # php -S localhost:8000 with public/router.php
composer test           # phpunit, no DB, no network
```

## Hard rules

- Never add CORS to the catch-all; only `/` and `/healthz` carry gateway CORS (per-route `->add()`).
- Only `/auth` and `/customer` are prefix-stripped; the frontend catch-all is forwarded verbatim.
- Never serve upload routes statically; the owning API stamps the anti-XSS headers.
- Every `php -S` run mode uses `public/router.php`.
- Env helpers use explicit `?? false`, never `$_ENV[$k] ?? getenv($k) ?: $d`.
- Services must not read env outside their `Bootstrap` or depend on the gateway.
- Every installer `.env` line goes through `env_line()`; every reader must undo its escaping.
- A new service env var needs a decision in `install.php` or `check-env-parity.php`'s `DEFAULTED`.
- Migration class names are unique across **all** services; a new backend answers `/healthz` with `db`.
- The deploy-webhook ping stays non-fatal.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routing, modes, the dispatcher, health or logging |
| [docs/agents/pitfalls.md](docs/agents/pitfalls.md) | Any change to headers, CORS, bodies, autoloading or the Docker stack |
| [docs/agents/pipeline.md](docs/agents/pipeline.md) | Changing workflows, the assemble, check scripts or secrets |
| [docs/agents/installer.md](docs/agents/installer.md) | Touching `public/install.php` or any service's env vars |
| [docs/agents/migrations.md](docs/agents/migrations.md) | Touching `MigrationRunner`, auto-migration or migration naming |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or running tests |

Setup: [INSTALL.md](INSTALL.md), [INSTALL-STACK.md](INSTALL-STACK.md),
[INSTALL-DOCKER.md](INSTALL-DOCKER.md), [DEPLOY-PLESK.md](DEPLOY-PLESK.md).
Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
