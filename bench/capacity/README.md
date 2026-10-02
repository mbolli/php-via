# Capacity benchmark

Measures what one php-via server can carry: page views, open tabs, private actions, shared
clicks and visitors arriving and leaving. It was written against the website (`website/app.php`)
and its defaults point at the homepage widgets, but any app works if you pass its routes,
action paths and signal IDs. Results for the website are in [RESULTS.md](RESULTS.md); the
docs page `/docs/performance` explains them.

## What it measures

- `pages`: keep-alive GETs at a fixed concurrency, without opening SSE streams, as a crawler
  does. Reports requests per second, server CPU per request and worker memory before and after.
- `tabs`: opens `n` tabs (page plus SSE stream, Brotli on unless `br=0`) and then, in order:
  - the server memory each open tab costs;
  - idle CPU over `idle` seconds;
  - `churn`: visitors who open and close a tab on another page one at a time, and the CPU each
    one costs while the `n` tabs stay open;
  - `tabrates`: private actions at fixed open-loop rates, with response time and the time until
    the tab's own stream shows the new value;
  - `bcrates`: shared clicks at fixed rates, with the time until every observed tab shows each
    click, frames and bytes per tab, and server CPU.

Only `observe` tabs decode their Brotli stream; the rest read and discard it, so the harness is
not the bottleneck. Check the `harness CPU` figure in each line anyway.

## Running it

The server and the harness must not share cores. On a machine with enough cores, pin the server
to the cores you want to emulate (two physical cores below) and the harness elsewhere:

```bash
cd website
VIA_PORT=3999 VIA_ACTION_RATE_LIMIT=100000000 setsid taskset -c 2,4 \
  php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 app.php &

master=$(pgrep -f '^php .*app\.php' | sort -n | head -1)
manager=$(pgrep -P "$master")
worker=$(pgrep -P "$manager" | head -1)
pids="$master,$manager,$worker"

cd ../bench/capacity
taskset -c 6-11 php capacity.php pages port=3999 pids=$pids worker=$worker route=/docs/api conc=16 secs=10
taskset -c 6-11 php capacity.php tabs port=3999 pids=$pids worker=$worker route=/ n=500 observe=100 \
  idle=10 tabrates=100,300 bcrates=5,20 phase=10
```

`VIA_ACTION_RATE_LIMIT` lifts the website's per-IP action limit, which would otherwise cap the
harness at 20 actions per second. Restart the server between scenarios: memory figures are
read from the worker's RSS, which does not shrink after a run.

## Scaling the numbers to another machine

The CPU figures depend on the core. `calibrate.php` times Brotli and string work on one core:

```bash
php -d opcache.enable_cli=1 calibrate.php
```

Divide the target machine's figures by the reference machine's (RESULTS.md lists them) and
multiply the CPU costs by that ratio. Memory figures carry over unchanged for the same PHP,
OpenSwoole and Brotli versions.
