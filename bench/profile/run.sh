#!/usr/bin/env bash
# Start and stop the website for profiling, hold the server cores' clocks, and talk to the
# profiler in the worker. See README.md.
#
#   run.sh spin-start | spin-stop       idle-class busy loops on the server cores
#   run.sh start [profile]              start website/app.php (profile: with Excimer)
#   run.sh stop                         stop the server started by `start`
#   run.sh pids                         master,manager,workers (for capacity.php pids=)
#   run.sh worker                       the first worker's pid
#   run.sh ctl 'reset' | 'dump NAME'    command every worker's profiler, wait for the ack
#
# Environment: PORT (4711), SERVER_CORES (2,4), OUT (<repo>/tmp/prof), EXCIMER (path to excimer.so),
# PERIOD (0.001 s), WORKERS (1).
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
PORT=${PORT:-4711}
SERVER_CORES=${SERVER_CORES:-2,4}
OUT=${OUT:-$repo/tmp/prof}
PERIOD=${PERIOD:-0.001}
PHP=/usr/bin/php
mkdir -p "$OUT/ctl" "$OUT/sys"

case "${1:-}" in
spin-start)
    for c in ${SERVER_CORES//,/ }; do
        taskset -c "$c" chrt -i 0 sh -c 'while :; do :; done' &
        echo $! >>"$OUT/spin.pids"
    done
    ;;
spin-stop)
    [ -f "$OUT/spin.pids" ] && kill $(cat "$OUT/spin.pids") 2>/dev/null || true
    rm -f "$OUT/spin.pids"
    ;;
start)
    ext=()
    envs=(VIA_PORT="$PORT" VIA_ACTION_RATE_LIMIT=100000000 TMPDIR="$OUT/sys")
    if [ "${2:-}" = profile ]; then
        ext=(-d "extension=${EXCIMER:?set EXCIMER to the built excimer.so}")
        envs+=(VIA_PROFILE=1 VIA_PROFILE_DIR="$OUT/ctl" VIA_PROFILE_PERIOD="$PERIOD")
    fi
    rm -f "$OUT"/ctl/pid.w* "$OUT"/ctl/ack.w*
    cd "$repo/website"
    env "${envs[@]}" setsid taskset -c "$SERVER_CORES" "$PHP" "${ext[@]}" -d opcache.enable_cli=1 \
        -d opcache.validate_timestamps=0 app.php >"$OUT/server.log" 2>&1 &
    echo $! >"$OUT/server.pid"
    for _ in $(seq 100); do
        curl -s -o /dev/null "http://127.0.0.1:$PORT/docs/faq" && break
        sleep 0.1
    done
    ;;
stop)
    if [ -f "$OUT/server.pid" ]; then
        pid=$(cat "$OUT/server.pid")
        kill -TERM "$pid" 2>/dev/null || true
        for _ in $(seq 100); do kill -0 "$pid" 2>/dev/null || break; sleep 0.1; done
        kill -KILL "$pid" 2>/dev/null || true
        rm -f "$OUT/server.pid"
    fi
    ;;
pids)
    master=$(cat "$OUT/server.pid")
    manager=$(pgrep -P "$master" | head -1)
    echo "$master,$manager,$(pgrep -P "$manager" | paste -sd,)"
    ;;
worker)
    master=$(cat "$OUT/server.pid")
    pgrep -P "$(pgrep -P "$master" | head -1)" | head -1
    ;;
ctl)
    printf '%s\n' "$2" >"$OUT/ctl/ctl"
    rm -f "$OUT"/ctl/ack.w*
    n=0
    for f in "$OUT"/ctl/pid.w*; do
        kill -USR2 "$(cat "$f")"
        n=$((n + 1))
    done
    for _ in $(seq 300); do
        [ "$(ls "$OUT"/ctl/ack.w* 2>/dev/null | wc -l)" -ge "$n" ] && exit 0
        sleep 0.1
    done
    echo "no ack from the profiler" >&2
    exit 1
    ;;
*)
    sed -n '2,13p' "$0"
    exit 2
    ;;
esac
