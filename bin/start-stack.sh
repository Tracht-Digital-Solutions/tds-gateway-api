#!/bin/sh
# start-stack.sh — bring the four TDS micro-backends up behind the gateway.
#
# Run this once the gateway bundle is deployed/installed on a host: it starts
# the auth/contact/content/customer service processes (and, optionally, the
# gateway itself) on the loopback ports the gateway proxies to. It is
# idempotent — a service that is already listening is left alone — so it is
# safe to wire into a @reboot cron and a 5-minute watchdog cron.
#
# Layout it expects (the assembled `build` bundle):
#
#   <bundle>/gateway/public        ← Slim proxy           (:8000, optional here)
#   <bundle>/services/auth/public  ← tds-auth-api          (:8003)
#   <bundle>/services/contact/...  ← tds-contact-api       (:8002)
#   <bundle>/services/content/...  ← tds-content-api       (:8001)
#   <bundle>/services/customer/... ← tds-customer-api      (:8004)
#
# This script lives at <bundle>/gateway/bin/start-stack.sh, so it derives the
# bundle root from its own location. Override any of the knobs below via env.
#
#   PHP_BIN          php binary to use            (default: php; Plesk: /opt/plesk/php/8.3/bin/php)
#   TDS_SERVICES_DIR where the four services live (default: <bundle>/services)
#   TDS_LOG_DIR      where to write per-service logs (default: <bundle>/logs)
#   START_GATEWAY    "1" to also start the PHP gateway on :8000
#                    (skip it when the webserver serves gateway/public itself,
#                    e.g. the Plesk PHP-FPM model)
#   RUN_MIGRATIONS   "1" to run phinx migrations before starting (default: 0)
#
# Ports are fixed to match the gateway's *_UPSTREAM defaults; change both
# together if you ever move a service.

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
GATEWAY_DIR=$(dirname -- "$SCRIPT_DIR")
BUNDLE_DIR=$(dirname -- "$GATEWAY_DIR")

PHP_BIN=${PHP_BIN:-php}
TDS_SERVICES_DIR=${TDS_SERVICES_DIR:-"$BUNDLE_DIR/services"}
TDS_LOG_DIR=${TDS_LOG_DIR:-"$BUNDLE_DIR/logs"}
START_GATEWAY=${START_GATEWAY:-0}
RUN_MIGRATIONS=${RUN_MIGRATIONS:-0}

mkdir -p "$TDS_LOG_DIR"

# name:port — the contract with the gateway's *_UPSTREAM defaults.
SERVICES="auth:8003 contact:8002 content:8001 customer:8004"

log() { echo "[start-stack] $*"; }

is_up() {
  # 0 if a `php -S 127.0.0.1:<port>` is already running for this port.
  pgrep -f "php -S 127.0.0.1:$1 " >/dev/null 2>&1
}

start_service() {
  name=$1
  port=$2
  docroot="$TDS_SERVICES_DIR/$name/public"

  if [ ! -d "$docroot" ]; then
    log "WARN: $name has no docroot at $docroot — skipping."
    return
  fi
  if is_up "$port"; then
    log "$name already listening on 127.0.0.1:$port — leaving it."
    return
  fi

  if [ "$RUN_MIGRATIONS" = "1" ] && [ -x "$TDS_SERVICES_DIR/$name/vendor/bin/phinx" ]; then
    log "Migrating $name…"
    ( cd "$TDS_SERVICES_DIR/$name" && "$PHP_BIN" vendor/bin/phinx migrate -e production ) \
      || log "WARN: migrate $name failed — starting anyway."
  fi

  log "Starting $name on 127.0.0.1:$port…"
  # No `nohup` dependency (a restricted Plesk shell may lack it): a subshell
  # that ignores SIGHUP and detaches stdin is the portable POSIX equivalent —
  # the exec'd php keeps the ignore-HUP disposition and outlives the parent.
  # public/router.php is REQUIRED for `php -S`: without a router script the
  # built-in server 404s any dotted path that has no file on disk — e.g.
  # /.well-known/jwks.json never reaches Slim, breaking JWT verification.
  ( trap '' HUP; exec "$PHP_BIN" -S "127.0.0.1:$port" -t "$docroot" "$docroot/router.php" \
    >>"$TDS_LOG_DIR/tds-$name.log" 2>&1 </dev/null ) &
}

for svc in $SERVICES; do
  start_service "${svc%%:*}" "${svc##*:}"
done

if [ "$START_GATEWAY" = "1" ]; then
  if is_up 8000; then
    log "gateway already listening on 127.0.0.1:8000 — leaving it."
  else
    log "Starting gateway on 0.0.0.0:8000…"
    # See the notes above: portable nohup-free detach + router.php (the
    # gateway serves /wiki.json, which php -S would otherwise 404).
    ( trap '' HUP; exec "$PHP_BIN" -S "0.0.0.0:8000" -t "$GATEWAY_DIR/public" "$GATEWAY_DIR/public/router.php" \
      >>"$TDS_LOG_DIR/tds-gateway.log" 2>&1 </dev/null ) &
  fi
fi

log "Done. Tail logs in $TDS_LOG_DIR; check health via the gateway's /healthz."
