#!/usr/bin/env bash
# Profiler overhead: the same load with and without Excimer, alternating, ROUNDS rounds.
#
#   EXCIMER=/path/excimer.so overhead.sh
#
# Prints one JSON line per run; compare cpu_ms_per_op (pages) and core_pct (counter).
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
export OUT=${OUT:-$repo/tmp/prof}
export PORT=${PORT:-4711}
HARNESS_CORES=${HARNESS_CORES:-6-11}
ROUNDS=${ROUNDS:-3}
SECS=${SECS:-15}
run="$here/run.sh"

one() {
    local mode=$1 label=$2
    shift 2
    "$run" stop
    "$run" start $mode
    for r in /docs/api /docs/signals /; do
        for _ in 1 2 3; do curl -s -o /dev/null -H 'Accept-Encoding: br' "http://127.0.0.1:$PORT$r"; done
    done
    local ctl=()
    [ "$mode" = profile ] && ctl=(ctl="$OUT/ctl")
    taskset -c "$HARNESS_CORES" /usr/bin/php "$here/load.php" "$@" port="$PORT" name="$label" "${ctl[@]}" \
        pids="$("$run" pids)" secs="$SECS" | jq -c --arg m "${mode:-off}" '{name, profiler: $m, ops, cpu_s, cpu_ms_per_op, core_pct}'
    "$run" stop
}

for r in $(seq "$ROUNDS"); do
    for mode in "" profile; do
        one "$mode" overhead-api pages routes=/docs/api
        one "$mode" overhead-signals pages routes=/docs/signals
        one "$mode" overhead-counter tabs route=/ n=500 act=counter rate=20
    done
done
