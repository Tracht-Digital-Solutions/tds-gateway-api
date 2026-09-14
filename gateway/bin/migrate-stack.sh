#!/bin/sh
# migrate-stack.sh — run phinx migrations for auth + customer from the assembled
# bundle. Phinx ships inside each service's vendor/ (the assemble workflow
# re-adds it after the --no-dev install), so the host never needs a composer
# install. Safe to re-run; phinx skips already-applied migrations.
#
# The `frontend` service (tds-core-frontend-api) is deliberately NOT migrated
# here: it has no single db/migrations + phinx.php — it composes every enabled
# extension's migrations and applies them through its OWN in-process migrator on
# the first request (AUTO_MIGRATE=1). The web installer triggers it once too.
#
# Env knobs mirror start-stack.sh:
#   PHP_BIN, TDS_SERVICES_DIR
#
# Each service reads its DB credentials from its own services/<name>/.env.

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BUNDLE_DIR=$(dirname -- "$(dirname -- "$SCRIPT_DIR")")

PHP_BIN=${PHP_BIN:-php}
TDS_SERVICES_DIR=${TDS_SERVICES_DIR:-"$BUNDLE_DIR/services"}

rc=0
for name in auth customer; do
  dir="$TDS_SERVICES_DIR/$name"
  if [ ! -x "$dir/vendor/bin/phinx" ]; then
    echo "[migrate-stack] WARN: $name has no vendor/bin/phinx — skipping."
    continue
  fi
  echo "[migrate-stack] Migrating $name…"
  if ! ( cd "$dir" && "$PHP_BIN" vendor/bin/phinx migrate -e production ); then
    echo "[migrate-stack] ERROR: migrate $name failed."
    rc=1
  fi
done

exit "$rc"
