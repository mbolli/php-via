#!/usr/bin/env bash
#
# php-via dev watcher
#
# Starts the website server, a PostCSS CSS watcher, and an entr file watcher
# that sends USR1 to the master process whenever a .php file changes.
#
# USR1 triggers OpenSwoole's graceful worker rotation (POOL_MODE): the worker
# finishes in-flight requests, then a fresh worker is forked from master and
# re-includes routes.php — picking up new class definitions from disk.
#
# Twig templates are always live (Twig cache is off) and do NOT trigger a reload.
#
# Usage: composer run dev
#        APP_ENV=dev bash scripts/dev.sh
#

set -euo pipefail

# ─── Prerequisites ────────────────────────────────────────────────────────────

if ! command -v entr >/dev/null 2>&1; then
    echo "❌  'entr' not found. Install it:"
    echo "    Ubuntu/Debian: sudo apt install entr"
    echo "    macOS:         brew install entr"
    exit 1
fi

# ─── Paths ────────────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PID_FILE="$(php -r 'echo sys_get_temp_dir();')/php-via-master.pid"

# ─── Cleanup ──────────────────────────────────────────────────────────────────

SERVER_PID=""
CSS_PID=""

cleanup() {
    echo ""
    echo "⏹  Stopping..."
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    [ -n "$CSS_PID" ]    && kill "$CSS_PID"    2>/dev/null || true
    pkill -f "entr -dn" 2>/dev/null || true
    rm -f "$PID_FILE"
    wait 2>/dev/null || true
    exit 0
}

trap cleanup INT TERM

# ─── Start server ─────────────────────────────────────────────────────────────

cd "$PROJECT_ROOT"

# Kill any stale processes from a previous session (handles the case where
# Composer killed this script via SIGKILL after timeout, leaving orphans).

# 1. Kill by saved master PID (fast path when PID file is fresh).
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        echo "⚠️  Killing stale server (pid $OLD_PID)..."
        kill "$OLD_PID" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
fi

# 2. Belt-and-suspenders: kill by port in case the PID file was absent/stale.
if command -v fuser >/dev/null 2>&1; then
    fuser -k 3000/tcp 2>/dev/null || true
elif command -v lsof >/dev/null 2>&1; then
    lsof -ti:3000 2>/dev/null | xargs -r kill 2>/dev/null || true
fi

# 3. Kill orphaned entr instances from a previous session.
pkill -f "entr -dn" 2>/dev/null || true

APP_ENV=dev php website/app.php &
SERVER_PID=$!

# Wait for Via's onStart callback to write the master PID file (up to 10 s).
# This file is written by the framework from $server->master_pid — authoritative.
echo "⚡ Waiting for server to start..."
for i in $(seq 1 20); do
    [ -f "$PID_FILE" ] && break
    sleep 0.5
done

if [ ! -f "$PID_FILE" ]; then
    echo "❌  Server did not write PID file at $PID_FILE — check for startup errors."
    kill "$SERVER_PID" 2>/dev/null || true
    exit 1
fi

MASTER_PID=$(cat "$PID_FILE")
echo "⚡ Server started (master_pid: $MASTER_PID, bash \$!: $SERVER_PID)"

# ─── Start CSS watcher ────────────────────────────────────────────────────────

if command -v pnpm >/dev/null 2>&1; then
    (cd website && pnpm dev 2>&1 | sed 's/^/[css] /') &
    CSS_PID=$!
    echo "🎨 CSS watcher started"
else
    echo "⚠️  pnpm not found — skipping CSS watch. Run 'composer run css' manually."
fi

# ─── Watch PHP files ──────────────────────────────────────────────────────────

echo ""
echo "👁  Watching .php and .twig files"
echo "    Press Ctrl+C to stop."
echo ""

# Outer loop: entr exits with code 2 when a new file appears in a watched
# directory (-d flag). Restarting the loop re-runs find so new files are picked up.
while kill -0 "$SERVER_PID" 2>/dev/null; do
    find "$PROJECT_ROOT" -type f \( -name '*.php' -o -name '*.twig' \) \
        | grep -v '/vendor/' \
        | grep -v '/node_modules/' \
        | entr -dn sh -c "kill -USR1 $(cat "$PID_FILE") 2>/dev/null && echo '↻  Workers reloading...'" \
        || true  # entr exits 2 on new file (-d); continue the loop
done

echo "Server process exited."
