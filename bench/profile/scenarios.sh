#!/usr/bin/env bash
# Run the profiling scenarios, each on a fresh server, and fold every window. See README.md.
#
#   EXCIMER=/path/excimer.so FLAMEGRAPH=/path/flamegraph.pl scenarios.sh [scenario...]
#
# Scenarios: pages-api pages-signals pages-home pages-faq pages-mixed spreadsheet chat
# leaderboard counter idle. No argument runs them all. Output goes to $OUT (<repo>/tmp/prof).
# The server cores' clocks must be held (run.sh spin-start) and the harness runs on HARNESS_CORES.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
export OUT=${OUT:-$repo/tmp/prof}
export PORT=${PORT:-4711}
HARNESS_CORES=${HARNESS_CORES:-6-11}
SECS=${SECS:-30}
run="$here/run.sh"
twig="$OUT/sys/php-via-twig-cache"

load() { taskset -c "$HARNESS_CORES" /usr/bin/php "$here/load.php" "$@"; }

scenario() {
    local name=$1 title=$2 warm=$3
    shift 3
    "$run" stop
    rm -f "$OUT/ctl/$name".w*.stacks
    "$run" start profile
    for r in ${warm//,/ }; do
        for _ in 1 2 3; do curl -s -o /dev/null -H 'Accept-Encoding: br' "http://127.0.0.1:$PORT$r"; done
    done
    load "$@" port="$PORT" ctl="$OUT/ctl" name="$name" pids="$("$run" pids)" | tee "$OUT/$name.json"
    "$run" stop
    /usr/bin/php "$here/fold.php" out="$OUT/$name" twig="$twig" flamegraph="${FLAMEGRAPH:-}" \
        title="$title" meta="$OUT/$name.json" "$OUT/ctl/$name".w*.stacks | tee "$OUT/$name.txt"
}

all=(pages-api pages-signals pages-home pages-faq pages-mixed spreadsheet chat leaderboard counter idle)
for s in "${@:-${all[@]}}"; do
    case $s in
    pages-api) scenario "$s" '/docs/api page views, 16 keep-alive clients, Brotli' /docs/api pages secs="$SECS" routes=/docs/api ;;
    pages-signals) scenario "$s" '/docs/signals page views, 16 keep-alive clients, Brotli' /docs/signals pages secs="$SECS" routes=/docs/signals ;;
    pages-home) scenario "$s" 'Home page views, 16 keep-alive clients, Brotli' / pages secs="$SECS" routes=/ ;;
    pages-faq) scenario "$s" '/docs/faq page views (no code blocks), 16 keep-alive clients, Brotli' /docs/faq pages secs="$SECS" routes=/docs/faq ;;
    pages-mixed) scenario "$s" 'Mixed page views (/, /docs/api, /docs/signals, /docs/faq, /examples)' /,/docs/api,/docs/signals,/docs/faq,/examples \
        pages secs="$SECS" routes=/,/docs/api,/docs/signals,/docs/faq,/examples ;;
    spreadsheet) scenario "$s" 'Spreadsheet: 300 tabs, 4 editors, 5 edits/s (3 actions each)' /examples/spreadsheet \
        tabs secs="$SECS" route=/examples/spreadsheet n=300 act=spreadsheet rate=5 editors=4 ;;
    chat) scenario "$s" 'Chat Room: 300 tabs in the lobby, 4 messages/s' /examples/chat-room \
        tabs secs="$SECS" route=/examples/chat-room n=300 act=chat rate=4 settle=5 ;;
    leaderboard) scenario "$s" 'Leaderboard: 1,000 tabs, 10 votes/s, 400 ms broadcast throttle' /examples/leaderboard \
        tabs secs="$SECS" route=/examples/leaderboard n=1000 act=leaderboard rate=10 ;;
    counter) scenario "$s" 'Home shared counter: 500 tabs, 20 clicks/s' / \
        tabs secs="$SECS" route=/ n=500 act=counter rate=20 ;;
    idle) scenario "$s" 'Idle: 1,000 home tabs, no activity' / tabs secs=$((SECS * 4)) route=/ n=1000 act=none ;;
    *) echo "unknown scenario $s" >&2; exit 2 ;;
    esac
done
