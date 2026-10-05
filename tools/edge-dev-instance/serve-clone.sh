#!/usr/bin/env bash
# DEV EDGE INSTANCE "edgeclone" — a SECOND dev Edge instance of THIS worktree on 127.0.0.1:8096 that is PAIRED to the
# disposable dev Cloud clone (http://localhost:9704 / tenant edgehomelab.localhost, master pos_devonline_master_edge) and
# BOOTSTRAPPED from it through the product flow (edge:local:pair → bootstrap-pull → enroll), so Edge and Online render the
# IDENTICAL dataset for the Phase 3 geometry / pixel comparisons (docs/status/edge-phase3-samedata-comparison.md).
# Same server pattern as serve.sh (PHP built-in server, docroot public/, router public/index.php, APP_ENV=edgeclone →
# Laravel loads .env.edgeclone). Local DB bingoo_edge_devclone_local (EdgeLocalDatabase accepts bingoo_edge_* only).
# Never the LAB appliance, never the LAB Cloud (:9701), never a real tenant.
#
#   tools/edge-dev-instance/serve-clone.sh init          # create .env.edgeclone (fresh machine-local key, enrolment PUBLIC key from the LAB secrets dir)
#   tools/edge-dev-instance/serve-clone.sh start|stop|status
#   tools/edge-dev-instance/serve-clone.sh artisan edge:local:db-init        # any edge:local:* command with the edgeclone env
#   tools/edge-dev-instance/serve-clone.sh worker start|stop                 # authority heartbeat worker (lease renewal) — RELEASE the clone lease when done
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${EDGE_DEV_PHP:-/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64/php.exe}"
PORT="${EDGE_CLONE_PORT:-8096}"
ENVFILE="$ROOT/.env.edgeclone"
LOG="$ROOT/storage/logs/edgeclone-serve.log"
PIDFILE="$ROOT/storage/logs/edgeclone-serve.pid"
WLOG="$ROOT/storage/logs/edgeclone-authority-worker.log"
WPIDFILE="$ROOT/storage/logs/edgeclone-authority-worker.pid"
# the enrolment PUBLIC key (not a secret) — the clone Cloud signs assertions with the LAB enroll.secret; its public half lives here
ENROLL_PUBLIC_FILE="${EDGE_CLONE_ENROLL_PUBLIC_FILE:-/c/Users/Dell/BingooEdgeLab/secrets/enroll.public}"
cmd="${1:-start}"

init_env() {
  if [ -f "$ENVFILE" ]; then echo "exists: $ENVFILE (delete it to re-create)"; return 0; fi
  [ -f "$ENROLL_PUBLIC_FILE" ] || { echo "enrolment public key file not found: $ENROLL_PUBLIC_FILE"; exit 1; }
  KEY="$("$PHP" -r 'echo "base64:".base64_encode(random_bytes(32));')"
  PUB="$(tr -d '\r\n' < "$ENROLL_PUBLIC_FILE")"
  sed -e 's|^APP_NAME=.*|APP_NAME="Bingoo Edge DEV CLONE"|' \
      -e "s|^APP_URL=.*|APP_URL=http://127.0.0.1:${PORT}|" \
      -e 's|^EDGE_DB_DATABASE=.*|EDGE_DB_DATABASE=bingoo_edge_devclone_local|' \
      -e "s|^EDGE_LOCAL_APP_KEY=.*|EDGE_LOCAL_APP_KEY=${KEY}|" \
      -e 's|^CACHE_STORE=.*|CACHE_STORE=array|' \
      "$ROOT/.env.edgedev.example" > "$ENVFILE"
  cat >> "$ENVFILE" <<EOT

# ── edgeclone: paired to the DISPOSABLE dev Cloud clone (plain HTTP, loopback) ───────────────────────────────────────
# CACHE_STORE=array above: the file cache would be SHARED with the :8095 instance (same storage/), so it is disabled here.
EDGE_CLOUD_ALLOW_HTTP=true
EDGE_ENROLLMENT_PUBLIC_KEY=${PUB}
# edge:local:pair --env-file=.env.edgeclone appends EDGE_CLOUD_BASE_URL / EDGE_INSTALLATION_UUID / EDGE_SYNC_DEVICE_ID /
# EDGE_SYNC_DEVICE_SECRET and the EDGE_SYNC_*_URL / EDGE_STANDBY_*_URL / EDGE_AUTHORITY_*_URL family below this line.
EOT
  echo "created $ENVFILE with a fresh machine-local key (port $PORT, DB bingoo_edge_devclone_local)"
}

case "$cmd" in
  init) init_env ;;
  start)
    [ -f "$ENVFILE" ] || init_env
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then echo "already running (pid $(cat "$PIDFILE"))"; exit 0; fi
    cd "$ROOT"
    APP_ENV=edgeclone nohup "$PHP" -d variables_order=EGPCS -S "127.0.0.1:$PORT" -t public public/index.php > "$LOG" 2>&1 &
    echo $! > "$PIDFILE"
    code=000
    for i in $(seq 1 20); do
      code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "http://127.0.0.1:$PORT/edge/local/health" || true)"
      [ "$code" = "200" ] && break
      sleep 1
    done
    echo "dev Edge CLONE instance → http://127.0.0.1:$PORT/edge/local/login  (health http=${code}, log: $LOG, pid $(cat "$PIDFILE"))"
    ;;
  stop)
    if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null || true; rm -f "$PIDFILE"; echo "stopped"; else echo "not running"; fi
    ;;
  status)
    curl -s -o /dev/null -w "health http=%{http_code}\n" --max-time 5 "http://127.0.0.1:$PORT/edge/local/health" || true
    if [ -f "$WPIDFILE" ] && kill -0 "$(cat "$WPIDFILE")" 2>/dev/null; then echo "authority worker: running (pid $(cat "$WPIDFILE"))"; else echo "authority worker: not running"; fi
    ;;
  artisan)
    shift
    cd "$ROOT"
    APP_ENV=edgeclone exec "$PHP" artisan "$@"
    ;;
  worker)
    sub="${2:-start}"
    cd "$ROOT"
    case "$sub" in
      start)
        if [ -f "$WPIDFILE" ] && kill -0 "$(cat "$WPIDFILE")" 2>/dev/null; then echo "worker already running (pid $(cat "$WPIDFILE"))"; exit 0; fi
        APP_ENV=edgeclone nohup "$PHP" artisan edge:local:authority-worker --no-interaction >> "$WLOG" 2>&1 &
        echo $! > "$WPIDFILE"
        echo "authority worker started (pid $(cat "$WPIDFILE"), log $WLOG) — it RENEWS the clone Cloud's branch lease; release the lease on the clone after stopping it"
        ;;
      stop)
        APP_ENV=edgeclone "$PHP" artisan edge:local:authority-worker --stop --stop-wait=40 --no-interaction || true
        if [ -f "$WPIDFILE" ]; then kill "$(cat "$WPIDFILE")" 2>/dev/null || true; rm -f "$WPIDFILE"; fi
        echo "authority worker stopped"
        ;;
      *) echo "usage: $0 worker start|stop"; exit 2 ;;
    esac
    ;;
  *) echo "usage: $0 [init|start|stop|status|artisan <edge:local:…>|worker start|stop]"; exit 2 ;;
esac
