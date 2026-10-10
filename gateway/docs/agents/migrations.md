# Auto-migration (`Support\MigrationRunner`, `Bootstrap::autoMigrate`)

## Why

The installer migrates once and locks itself, so a later release adding a migration would never apply
it; every query on the new table would 500. Auto-migration closes that gap for the no-SSH Plesk model.

## What

- `public/index.php` calls `Bootstrap::autoMigrate()` **after** `createApp()` (never from it, keeping the
  app pure for unit tests).
- In-process mode only; `GATEWAY_AUTO_MIGRATE=0` disables it.
- On the first request after a deploy it applies pending migrations for **`auth` and `customer`**
  (`Bootstrap::AUTO_MIGRATE_SERVICES`). `frontend` self-migrates through `tds-core-frontend-api`'s own
  runner when its app is first built.

## In-process via Phinx's `Manager` API

Shared Plesk hosting often disables `proc_open`, which once made the installer's shell-out silently apply
nothing. `MigrationRunner` requires the service's own `vendor/autoload.php`, reads DB credentials from that
service's `.env` (never the process env, so a warm worker can't leak another service's `DB_NAME`) and
builds a Phinx `Config` mirroring the service's `phinx.php` (`phinx_migration` table, mysql, utf8mb4). The
`proc_open` shell-out remains only as a fallback.

## Guards

- A marker file under `<root>/var/` (gitignored), keyed to a **signature of the migration filenames**. The
  hot path is one `is_file()`; the marker changes only when a migration is added or removed.
- An exclusive non-blocking `flock` makes it single-flight.
- Failures are logged and swallowed, and don't write the marker, so they retry on the next request.
- `MigrationRunner::ensureMigrated()` never throws, so auto-migration can't take a service down.

## Migration class names must be unique across all services

Phinx includes every migration file, and in-process all services share one PHP process. Two services
declaring the same class is an **uncatchable fatal** on every request (three services shipping
`CreateAppSetting` took the whole API down). Prefix class and file name with the service or extension.
`MigrationRunner` text-scans declared classes first and **skips a colliding service with a logged error**
(no marker → health shows `db: no-schema`). Within `frontend` the same rule is enforced by
`tds-core-frontend-api`'s own runner.

## CLI PHP resolution (fallback and installer only)

Under PHP-FPM, `PHP_BINARY` is the FPM binary and can't run phinx. `MigrationRunner::phpCliBinary()`
prefers `GATEWAY_PHP_BINARY`, then a `php` next to `PHP_BINDIR`, then a non-FPM `PHP_BINARY`, then PATH.
`install.php`'s `php_cli_binary()` duplicates this (no autoloader there).

## Health interplay

Each backend's `/healthz` reports `db: ok | no-schema | down`, and the aggregate answers 503 when a
reachable service is un-migrated, so a failing auto-migration is visible.

## MySQL 8 rehearsal (`scripts/check-migrations-mysql8.php`, in the assemble)

Production is MySQL 8; development, every service's CI and DB tests run MariaDB, which is more permissive.
Phinx defaults columns to nullable, and a nullable PRIMARY KEY is coerced by MariaDB but rejected by MySQL 8
(`SQLSTATE[42000] 1171`).

- The step drives exactly what the installer drives, each into its own empty database: auth and customer via
  their bundled Phinx (generated config), frontend via `tds-core-frontend-api`'s in-process
  `MigrationRunner`, the only way to reach the composed extensions.
- A `mysql:8` service container backs it (host port 33306). The script refuses to pass if the server isn't
  MySQL 8, which would make the check vacuous.
- **It proves the frontend run by count.** Because the runner swallows failures, it compares applied
  versions with every `NNNNNNNNNNNNNN_*.php` in the composed paths and names the first unapplied files. A
  run once reported "applies cleanly" with 25 of 58 applied.
