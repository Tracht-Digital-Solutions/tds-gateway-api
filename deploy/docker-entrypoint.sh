#!/bin/sh
# Container entrypoint for the all-in-one TDS API image.
#
#   1. Generate a services/<name>/.env for any service that doesn't already
#      have one (a mounted real .env always wins — these are dev defaults).
#   2. Make sure the auth service has an RS256 keypair to sign with.
#   3. Wait for the database, then run every service's migrations.
#   4. Hand off to CMD (supervisord), which runs the five processes.
#
# Secrets come from the container environment (compose `environment:` /
# `env_file:`); anything unset falls back to a dev-safe placeholder so a bare
# `docker compose up` still boots end-to-end.
#
# QUOTE every generated value that can contain a space. phpdotenv refuses a bare
# unquoted spaced value ("Failed to parse dotenv file. Encountered unexpected
# whitespace at [...]") and `Bootstrap::createApp()` calls `Dotenv->load()`
# before anything else — so one such line takes the WHOLE service down at boot,
# not just the setting it belongs to. `MAIL_FROM_NAME=Tracht Digital Solutions`
# did exactly that to the frontend: auth and customer, whose values happen to
# have no spaces, stayed green while every frontend route returned 500 and the
# gateway's aggregate health reported `"/frontend": {"status": 0}`.

set -eu

SERVICES_DIR=/srv/tds/services

# --- shared DB connection (one MariaDB, one database per service) ----------
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-3306}
DB_USER=${DB_USER:-tds}
DB_PASS=${DB_PASS:-tds}

# --- shared cross-service settings -----------------------------------------
ADMIN_TOKEN=${ADMIN_TOKEN:-dev-admin-token-change-me}
AUTH_API_URL=${AUTH_API_URL:-http://127.0.0.1:8000/auth}
CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS:-http://localhost:4321}
# Exported, not just defaulted: besides landing in each service's .env below,
# the GATEWAY process reads it for its own two routes (`/` and `/healthz`) —
# and the gateway is configured from the container environment, not from a
# written .env file. Without the export, a dev origin reaches the services but
# not the health check the /install wizard runs first.
export CORS_ALLOWED_ORIGINS

write_env_if_absent() {
  # $1 = service name, remaining stdin = file body
  file="$SERVICES_DIR/$1/.env"
  if [ -f "$file" ]; then
    echo "[entrypoint] $1: keeping existing .env"
    return
  fi
  cat > "$file"
  echo "[entrypoint] $1: wrote dev .env"
}

write_env_if_absent auth <<EOF
APP_ENV=production
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=${AUTH_DB_NAME:-tds_auth}
DB_USER=$DB_USER
DB_PASS=$DB_PASS
ADMIN_TOKEN=$ADMIN_TOKEN
JWT_KEY_ID=${JWT_KEY_ID:-tds-auth-dev-1}
JWT_ISSUER="${JWT_ISSUER:-http://127.0.0.1:8000/auth}"
JWT_TTL_SECONDS=${JWT_TTL_SECONDS:-3600}
JWT_REFRESH_TTL_SECONDS=${JWT_REFRESH_TTL_SECONDS:-2592000}
COOKIE_DOMAIN=${COOKIE_DOMAIN:-localhost}
COOKIE_NAME=${COOKIE_NAME:-tds_session}
LOGIN_RATE_LIMIT=${LOGIN_RATE_LIMIT:-10}
LOGIN_RATE_WINDOW_SECONDS=${LOGIN_RATE_WINDOW_SECONDS:-900}
CORS_ALLOWED_ORIGINS="$CORS_ALLOWED_ORIGINS"
EOF
# JWT_PRIVATE_KEY only when provided; otherwise auth falls back to keys/private.pem (below).
if [ -n "${JWT_PRIVATE_KEY:-}" ] && ! grep -q '^JWT_PRIVATE_KEY=' "$SERVICES_DIR/auth/.env" 2>/dev/null; then
  printf 'JWT_PRIVATE_KEY=%s\n' "$JWT_PRIVATE_KEY" >> "$SERVICES_DIR/auth/.env"
fi

write_env_if_absent customer <<EOF
APP_ENV=production
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=${CUSTOMER_DB_NAME:-tds_customer}
DB_USER=$DB_USER
DB_PASS=$DB_PASS
AUTH_API_URL=$AUTH_API_URL
JWKS_CACHE_TTL=${JWKS_CACHE_TTL:-600}
ADMIN_TOKEN=$ADMIN_TOKEN
SETTINGS_ENCRYPTION_KEY=${SETTINGS_ENCRYPTION_KEY:-}
# Stripe keys are optional env fallbacks — normally set at runtime in the admin
# panel (Einrichtungsassistent / Einstellungen), stored encrypted in app_setting.
STRIPE_SECRET_KEY=${STRIPE_SECRET_KEY:-}
STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET:-}
STRIPE_PUBLIC_KEY=${STRIPE_PUBLIC_KEY:-}
STRIPE_RETURN_URL=${STRIPE_RETURN_URL:-http://localhost:4321/invoices}
DOCUMENT_ROOT_DIR=${DOCUMENT_ROOT_DIR:-/srv/tds/var/customer-files}
DOCUMENT_SIGN_SECRET=${DOCUMENT_SIGN_SECRET:-dev-document-sign-secret-change-me}
CORS_ALLOWED_ORIGINS="$CORS_ALLOWED_ORIGINS"
EOF

# frontend = tds-core-frontend-api (composed base + extensions; the gateway's
# default catch-all route). All extensions share one DB and auto-migrate
# in-process on the first request (AUTO_MIGRATE=1). Third-party keys (Stripe,
# DeepL, Lexware, GitHub rebuild) are set at runtime in the admin frontend.
write_env_if_absent frontend <<EOF
APP_ENV=production
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=${FRONTEND_DB_NAME:-tds_frontend}
DB_USER=$DB_USER
DB_PASS=$DB_PASS
AUTH_API_URL=$AUTH_API_URL
JWKS_CACHE_TTL=${JWKS_CACHE_TTL:-600}
SETTINGS_ENCRYPTION_KEY=${SETTINGS_ENCRYPTION_KEY:-}
MAIL_DSN=${MAIL_DSN:-}
MAIL_FROM="${MAIL_FROM:-no-reply@tracht-digital.de}"
MAIL_FROM_NAME="${MAIL_FROM_NAME:-Tracht Digital Solutions}"
DOCUMENT_ROOT_DIR=${DOCUMENT_ROOT_DIR:-/srv/tds/var/customer-files}
DOCUMENT_SIGN_SECRET=${DOCUMENT_SIGN_SECRET:-dev-document-sign-secret-change-me}
CORS_ALLOWED_ORIGINS="$CORS_ALLOWED_ORIGINS"
EOF

# Storage dirs the services expect to be writable.
mkdir -p /srv/tds/var/customer-files

# auth: ensure a signing keypair exists (keygen writes keys/{private,public}.pem).
if [ -z "${JWT_PRIVATE_KEY:-}" ] && [ ! -f "$SERVICES_DIR/auth/keys/private.pem" ]; then
  echo "[entrypoint] auth: generating a dev RS256 keypair"
  php "$SERVICES_DIR/auth/bin/keygen.php" || echo "[entrypoint] WARN: keygen failed"
fi

# Wait for the database before migrating.
echo "[entrypoint] waiting for database at $DB_HOST:$DB_PORT…"
i=0
until mysqladmin ping -h"$DB_HOST" -P"$DB_PORT" --silent 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "[entrypoint] WARN: database not reachable after 60s — starting anyway."
    break
  fi
  sleep 1
done

# Migrations: reuse the bundled migrate-stack.sh (same loop, one source).
echo "[entrypoint] running migrations…"
TDS_SERVICES_DIR="$SERVICES_DIR" PHP_BIN=php sh /srv/tds/gateway/bin/migrate-stack.sh \
  || echo "[entrypoint] WARN: some migrations failed — starting anyway."

# --- run mode --------------------------------------------------------------
# One instance by default: `inprocess` makes the gateway load each service's
# Slim app inside its own process, so the loopback servers are dead weight —
# supervisor only starts them for GATEWAY_MODE=proxy (see
# deploy/supervisord.docker.conf, which reads TDS_BACKEND_AUTOSTART).
#
# Exported, not merely defaulted in the app: supervisor fails to start if a
# %(ENV_…)s referenced in its config is unset, and an explicit GATEWAY_MODE in
# the container environment is visible to `docker exec … printenv`, which is
# where you look when the request path is in doubt.
GATEWAY_MODE="${GATEWAY_MODE:-inprocess}"
export GATEWAY_MODE
if [ "$GATEWAY_MODE" = "proxy" ]; then
  TDS_BACKEND_AUTOSTART=true
else
  TDS_BACKEND_AUTOSTART=false
fi
export TDS_BACKEND_AUTOSTART
echo "[entrypoint] GATEWAY_MODE=$GATEWAY_MODE (loopback backends autostart: $TDS_BACKEND_AUTOSTART)"

echo "[entrypoint] starting processes…"
exec "$@"
