# Build pipeline

Two thin caller workflows over a reusable `_assemble.yml` (`workflow_call`); `ci.yml` is the PR gate and
`_check.yml` the gateway check.

| Workflow | Triggers | Result |
|---|---|---|
| `dev.yml` | push to `main`, `workflow_dispatch`, `repository_dispatch(api-pushed)` | `_assemble` with `channel=dev, deploy=false` → orphan **`dev`** branch, not deployed |
| `release.yml` | `workflow_dispatch` | `_assemble` with `channel=release, deploy=true` → **`release`** + `DEPLOY_WEBHOOK_URL` ping; the host pulls `release` |

## `_assemble.yml`

1. `check`: validate, install, `php -l`, phpunit.
2. `assemble`: check out the gateway, `auth`, `customer`, `frontend` (`tds-core-frontend-api`) and the
   frontend's extension packages plus `tds-frontend-contract-pkg`, all at `main`.
   - gateway / auth / customer: `composer install --no-dev`, then **re-add phinx** for auth and customer
     (`composer require robmorgan/phinx:<constraint> --update-no-dev`) so the host can migrate from the
     bundle.
   - frontend: `COMPOSER_MIRROR_PATH_REPOS=1 composer update --no-dev`, so extension checkouts are
     **copied** into `vendor/` (no dangling symlinks). It already requires phinx.
   - Assemble `dist/`: gateway at root, services under `services/{auth,customer,frontend}`, plus
     `Procfile`, `services.json` and `BUILD_INFO.json` (source commit of each backend and extension).
3. Checks during the assemble:
   - `scripts/check-shared-deps.php` — shared package majors (see [pitfalls.md](pitfalls.md)).
   - `scripts/check-migrations-mysql8.php` — fresh install on MySQL 8 (see [migrations.md](migrations.md)).
   - `scripts/check-env-parity.php` — installer ↔ `.env.example` parity (see [installer.md](installer.md)).
   - `scripts/composer-audit.php` — `composer audit --no-dev` over gateway, auth, customer and frontend;
     fails on `high` / `critical`, warns otherwise, and only warns if the audit can't run. Policy in
     `scripts/lib/composer_audit_policy.php` (pinned by `ComposerAuditPolicyTest`). It is the only audit
     of the frontend bundle. Locally:
     `COMPOSER_BIN="php composer.phar" php scripts/composer-audit.php . ../tds-auth-api`.

## End-to-end wiring

1. Each backend repo's manual **Release** pings its `DEPLOY_WEBHOOK_URL` **and** POSTs an `api-pushed`
   `repository_dispatch` here with `GATEWAY_DISPATCH_TOKEN`. Without the token the step skips quietly.
   `tds-core-frontend-api` and the extensions don't dispatch here yet; press the gateway's Release to pick
   up a new frontend or extension version.
2. `api-pushed` fires `dev.yml`, rebuilding the `dev` bundle. The gateway's `release` stays manual.

Test the chain without an API push:
`gh api -X POST repos/Tracht-Digital-Solutions/tds-gateway-api/dispatches -f event_type=api-pushed`, then
confirm a `repository_dispatch` run lands and the `dev` branch SHA advances.

## The deploy-webhook ping is non-fatal

By the time it runs, the bundle is already pushed. The step captures the HTTP status
(`-w '%{http_code}'`, `|| echo 000`) and emits a `::warning::` on non-2xx. A broken webhook shows as a
**yellow warning on a green run**; check annotations, not the job status. Don't change it back to
`curl -fsS`. Backend repos mirror this.

## Secrets

| Secret | Where | Purpose |
|---|---|---|
| `ASSEMBLE_TOKEN` | this repo | Org PAT (`repo`, SSO-authorised): checks out backend and extension repos and pushes `dev` / `release` |
| `GATEWAY_DISPATCH_TOKEN` | each backend repo | PAT that may POST `repository_dispatch` here (the same org PAT works) |
| `DEPLOY_WEBHOOK_URL` | this repo + backends | Deploy hook the host pulls on; optional and non-fatal |

`actions/checkout` fails with `Input required and not supplied: token` when `token:` resolves empty; it
doesn't fall back to `GITHUB_TOKEN`. A failing `assemble` with a green `check` is the tell for a missing
or expired `ASSEMBLE_TOKEN`.
