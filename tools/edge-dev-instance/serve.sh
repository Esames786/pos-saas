#!/usr/bin/env bash
# DEV EDGE INSTANCE — serve THIS worktree as a branch_server on 127.0.0.1:8095 against the dev-seeded local DB.
# Uses PHP's built-in server exactly like the appliance's edge:local:serve (docroot public/, router public/index.php);
# `artisan serve` is refused on a branch_server by EdgeConsoleBoundary on purpose. Laravel loads .env.edgedev because
# APP_ENV=edgedev is exported for the process. Never the LAB appliance, never a tenant.
# Usage: tools/edge-dev-instance/serve.sh [start|stop|status]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${EDGE_DEV_PHP:-/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64/php.exe}"
PORT="${EDGE_DEV_PORT:-8095}"
ENVFILE="$ROOT/.env.edgedev"
LOG="$ROOT/storage/logs/edgedev-serve.log"
PIDFILE="$ROOT/storage/logs/edgedev-serve.pid"
cmd="${1:-start}"

case "$cmd" in
  start)
    if [ ! -f "$ENVFILE" ]; then
      cp "$ROOT/.env.edgedev.example" "$ENVFILE"
      KEY="$("$PHP" -r 'echo "base64:".base64_encode(random_bytes(32));')"
      sed -i "s|^EDGE_LOCAL_APP_KEY=.*|EDGE_LOCAL_APP_KEY=${KEY}|" "$ENVFILE"
      echo "created $ENVFILE with a fresh machine-local dev key"
    fi
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then echo "already running (pid $(cat "$PIDFILE"))"; exit 0; fi
    cd "$ROOT"
    APP_ENV=edgedev nohup "$PHP" -d variables_order=EGPCS -S "127.0.0.1:$PORT" -t public public/index.php > "$LOG" 2>&1 &
    echo $! > "$PIDFILE"
    for i in $(seq 1 20); do
      code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "http://127.0.0.1:$PORT/edge/local/health" || true)"
      [ "$code" = "200" ] && break
      sleep 1
    done
    echo "dev Edge instance → http://127.0.0.1:$PORT/edge/local/login  (health http=${code:-000}, log: $LOG, pid $(cat "$PIDFILE"))"
    ;;
  stop)
    if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null || true; rm -f "$PIDFILE"; echo "stopped"; else echo "not running"; fi
    ;;
  status)
    curl -s -o /dev/null -w "health http=%{http_code}\n" --max-time 5 "http://127.0.0.1:$PORT/edge/local/health" || true
    ;;
  *) echo "usage: $0 [start|stop|status]"; exit 2 ;;
esac
