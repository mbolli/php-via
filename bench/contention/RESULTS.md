# Contention benchmark results

Before and after measurements for the five contention findings (F1 to F5)
fixed on `perf/contention`. Base (A) is 737b2aa: the v0.13.0 release (5ff9d25)
plus the scripts in this directory. Branch (B) is 5a9850a. The base worktree ran
the branch's copies of the scripts, which differ from 737b2aa only in comments
and code style, so the scripts are byte-identical in both trees and every run
is `php bench/contention/<name>.php` from inside the tree under test. The
lock_contention, idle_sse and shared_read JSON records the commit each run ran
on, with `src/` clean in every run; broadcast_storm and get_clients do not
record it, and their runners pick the tree by working directory. The two branch
commits not covered below (197c8d7, 5a9850a) touch only the changelog and the
benchmark code style.

Host: 20 cores, shared with other projects that run browser tests on it. PHP
8.5.11 CLI (NTS) with opcache and JIT off, ext-openswoole 26.2.0.

Protocol: A and B ran interleaved (B1, A1, B2, A2, ...; one shared_read check
ran A first) with identical arguments, one benchmark at a time. The 1-minute
load average was recorded before every run, and a run waited while it was
above 8. At run start it was between 1.3 and 7.9. The only waits followed
lock_contention runs at 8 and 16 workers, whose own spinning workers pushed the
load to 8.0 to 14.1. Each configuration has 2 to 10 reps per variant. Tables
show the median with min to max in parentheses. The change column is the ratio
of the medians when the two differ by 2x or more, and a percentage otherwise;
"ranges overlap" marks a change whose base and branch min-to-max ranges
overlap. Raw per-run JSON is not committed.

Every run was correct in both trees. All 36 lock_contention invocations
reached the expected final value in every round. All 72 broadcast_storm runs
converged every client to the server value with no failed action. All 30
idle_sse runs delivered their broadcasts to every connection. All 40
shared_read runs had 0 mismatched frames, and all 40 get_clients runs had every
freshness and patch flag true.

## F1: broadcast coalescing (08d6449)

Inside a coroutine, `broadcast()`, scoped signal auto-broadcasts and broker
receives now only mark the scope. The worker's next flush renders each marked
scope once and each context once. It runs at the end of the event-loop turn
when the last flush ended at least one tick ago
(`Config::withBroadcastTickMs()`, default 25 ms), and otherwise once that tick
has passed. Measured with `broadcast_storm.php`: N SSE clients on one page, K
actions fired over `concurrency` actor connections.

Defaults, `php bench/contention/broadcast_storm.php` (N=1000, K=200,
concurrency 50, 1 worker, tab mode, global state), 3 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| renders | 200000 | 2000 | 100x fewer |
| frames per client | 200 | 2 | 100x fewer |
| worker CPU net of idle (s) | 1.511 (1.482 to 1.521) | 0.04 (0.02 to 0.04) | 37.8x lower |
| master CPU (s) | 1.21 (1.16 to 1.21) | 0.02 (0.02 to 0.04) | 60.5x lower |
| action latency p50 (ms) | 379.184 (373.339 to 381.522) | 1.434 (0.448 to 1.434) | 264x lower |
| action latency p99 (ms) | 407.273 (399.672 to 410.552) | 18.287 (13.34 to 24.019) | 22.3x lower |
| actions/s | 127.7 (126.7 to 130.2) | 8711.5 (6912.5 to 13174.3) | 68.2x higher |
| converge after last send (ms) | 383.907 (379.136 to 397.791) | 34.505 (33.929 to 41.521) | 11.1x lower |
| storm to converge (ms) | 1565.677 (1535.714 to 1579.064) | 55.799 (48.617 to 69.021) | 28.1x lower |

CPU comes from `/proc` at 10 ms clock-tick resolution. At the default size the
branch's CPU is 1 to 10 ticks, so the CPU ratios give the order of magnitude,
not a precise factor. The N=5000 runs are the only ones where branch CPU sits
well above resolution.

Larger storm, `--n=5000 --k=500 --concurrency=50 --converge-timeout=60
--watchdog=240`, with 1 worker and with `--workers=4`, 3 reps each:

| Metric | Base | Branch | Change |
|---|---|---|---|
| worker CPU net of idle, 1 worker (s) | 18.903 (18.298 to 19.626) | 0.11 (0.11 to 0.13) | 172x lower |
| worker CPU net of idle, 4 workers (s) | 30.614 (28.467 to 30.953) | 0.19 (0.18 to 0.27) | 161x lower |
| action latency p99, 1 worker (ms) | 2299.158 (2278.22 to 3693.051) | 50.551 (50.357 to 62.149) | 45.5x lower |
| action latency p99, 4 workers (ms) | 1766.782 (1340.012 to 2514.202) | 27.685 (26.208 to 32.952) | 63.8x lower |
| converge after last send, 1 worker (ms) | 2107.047 (2077.247 to 2185.607) | 69.766 (61.118 to 73.65) | 30.2x lower |
| converge after last send, 4 workers (ms) | 1737.181 (1208.017 to 1844.744) | 51.674 (40.371 to 59.786) | 33.6x lower |
| storm to converge, 1 worker (ms) | 21506.763 (20671.569 to 23175.319) | 124.089 (121.401 to 143.908) | 173x lower |
| storm to converge, 4 workers (ms) | 8381.587 (7350.136 to 8458.367) | 74.369 (64.373 to 80.877) | 113x lower |

Other variants at the default size, 3 reps each:

| Metric | Base | Branch | Change |
|---|---|---|---|
| storm to converge, `--mode=route` (ms) | 1340.617 (1321.162 to 1356.431) | 39.635 (39.457 to 62.588) | 33.8x lower |
| storm to converge, `--state=signal` (ms) | 2263.321 (2098.057 to 2327.768) | 51.973 (50.308 to 56.193) | 43.5x lower |
| storm to converge, `--workers=4` (ms) | 611.223 (606.981 to 717.08) | 35.279 (35.229 to 37.587) | 17.3x lower |
| storm to converge, `--workers=4 --state=signal` (ms) | 952.286 (816.698 to 961.857) | 43.405 (43.093 to 43.698) | 21.9x lower |
| worker CPU net of idle, `--mode=route` (s) | 1.298 (1.27 to 1.326) | 0.02 (0.01 to 0.04) | 64.9x lower |
| worker CPU net of idle, `--workers=4 --state=signal` (s) | 3.051 (3.042 to 3.673) | 0.07 (0.07 to 0.1) | 43.6x lower |

Route mode renders once per action on base (200 renders against 200000 in tab
mode) and still gains about as much, because most of the base cost is per
context (patch queue, SSE resume, response write), not per render. With 4
workers the branch sends 2.75 to 3 frames per client instead of 2.

On the branch all K actions are sent within 7 to 72 ms, 1 to 3 ticks, because
an action no longer waits for its fan-out. Two frames per client therefore
means the whole storm fit into two flushes. It does not show how the branch
handles a paced stream of updates over several seconds, which this script
cannot generate.

### F1 regression checks

Low load with one actor (`--concurrency=1`), N=1000, 5 reps each:

| Metric | Base | Branch | Change |
|---|---|---|---|
| converge after last send, K=1 (ms) | 9.95 (8.243 to 17.733) | 10.448 (8.685 to 20.194) | +5.0%, ranges overlap |
| converge after last send, K=2 (ms) | 9.229 (7.446 to 19.151) | 43.452 (40.929 to 49.442) | 4.71x higher |
| storm to converge, K=2 (ms) | 17.183 (15.854 to 39.2) | 44.425 (41.686 to 50.624) | 2.59x higher |
| converge after last send, K=20 (ms) | 8.015 (7.249 to 9.424) | 45.474 (31.597 to 47.212) | 5.67x higher |
| storm to converge, K=20 (ms) | 167.769 (153.101 to 226.522) | 58.062 (40.883 to 63.846) | 2.89x lower |
| action latency p50, K=20 (ms) | 7.813 (7.364 to 8.734) | 0.074 (0.023 to 0.079) | 106x lower |
| action latency p99, K=20 (ms) | 15.156 (7.986 to 19.865) | 8.705 (7.982 to 15.108) | -42.6%, ranges overlap |

This check fails. An isolated update (K=1) arrives as fast as on base, because
the first flush runs at the end of the event-loop turn. An update that lands
during or shortly after a flush waits: at K=20 every branch run converged later
than every base run, at load 3.3 to 5.4. `runTickFlush()` in `src/Via.php` sets
`lastFlushEdgeNs` when a flush starts and again when it ends, and
`msUntilNextTick()` counts the tick from that edge. The 08d6449 commit message
says a flush runs when the last one "started or ended" a tick ago, but once a
flush ends its end overwrites the start, so after a flush the tick counts from
the end. The shortest gap from one flush start to the next is therefore the
flush duration plus the tick. At N=1000 on one worker a flush takes about 8 ms
(the second action at K=2 waits for it: median 8.599 ms), which predicts
8 + 25 + 8 = 41 ms against 43.452 ms measured at K=2. At K=20 the last send
comes about 11 ms after the first, so the same model predicts about 31 ms after
the last send. One run matched (31.597 ms); the other four took 42.881 to
47.212 ms, and in those four the gap between the median and the p99 client
convergence was about 10 ms against about 4 ms at K=2, which suggests their
second flush ran longer. That was not investigated.

The delay grows with the number of contexts: judging by the branch action p99
at N=5000 on one worker (50.551 ms), a flush there takes about 50 ms. A K=2 run
at that size was not made. Counting the tick from the start of the last flush,
and flushing at once when the tick is already overdue at the end of a flush,
removes the flush duration from the gap, so the delay stops growing with N once
a flush takes longer than a tick. It does not remove the tick itself: at N=1000
an update that lands during a flush would still arrive about 25 + 8 ms after it
was sent, against about 9 ms on base. Both are untested estimates. The existing
knobs are `withBroadcastTickMs()` and `withBroadcastCoalescing(false)`.

A flush does not yield between contexts, so a request that lands on its worker
while it runs waits for it. Branch action p99 is 50.551 ms at N=5000 on one
worker and 27.685 ms at N=5000 on 4 workers (1250 contexts each). Base ran the
same non-yielding fan-out inside the broadcasting action and was at 2299.158 ms
and 1766.782 ms, so this is not a regression: the cost moved from the action
that broadcasts to whichever request arrives during the flush.

CPU at K=1 and K=2 is 1 to 5 clock ticks in both trees, so the two cannot be
told apart there. Branch idle worker CPU is 0 in every configuration against
0.03 to 0.255 s/s on base (that is F2). `worker_cpu_net_s` subtracts the idle
rate measured in the same run, so F2 does not inflate the F1 CPU numbers. F4
cannot be isolated with this script, since coalescing already cuts a storm to 2
or 3 flushes per worker.

## F2: idle SSE wakeups (0b56613)

A page SSE stream now parks until a patch is queued, its channel closes, the
server's close event wakes it, or the keep-alive interval passes
(`Config::withSseKeepAliveMs()`, default 15 s), when a silent stream writes an
SSE comment. Base wakes every stream every 100 ms and, with more than one
worker, writes the context directory on each wake. Measured with
`idle_sse.php`: N idle SSE connections, CPU and voluntary context switches
(wakeups) over a fixed idle window, then one broadcast and a SIGTERM with every
stream open. CPU is in percent of one core.

N=5000 with a 15 s window (`--n=5000 --workers=1,4 --idle=15 --settle=3`), 5
reps. The window holds exactly one keep-alive pass per stream, so the branch
figures are its steady state.

| Metric | Base | Branch | Change |
|---|---|---|---|
| worker CPU, 1 worker (%) | 13.39 (12.52 to 14.19) | 0.33 (0.27 to 0.33) | 40.6x lower |
| worker CPU per 1k connections, 1 worker (%) | 2.68 (2.5 to 2.84) | 0.07 (0.05 to 0.07) | 38.3x lower |
| wakeups/s, 1 worker | 594.7 (567.5 to 628.4) | 42.5 (33.9 to 45.5) | 14.0x lower |
| worker CPU, 4 workers summed (%) | 25.5 (24.31 to 28.58) | 0.47 (0.34 to 0.8) | 54.3x lower |
| worker CPU per 1k connections, 4 workers (%) | 5.1 (4.86 to 5.72) | 0.09 (0.07 to 0.16) | 56.7x lower |
| wakeups/s, 4 workers | 2007 (1966.7 to 2110.2) | 102.6 (85.2 to 109.6) | 19.6x lower |
| broadcast to all connections, 1 worker (ms) | 60.9 (50.53 to 123.59) | 55.02 (48.99 to 87.61) | -9.7%, ranges overlap |
| broadcast to all connections, 4 workers (ms) | 60.45 (55.74 to 71.78) | 45.8 (36.02 to 55.26) | -24.2% |
| shutdown, 1 worker (ms) | 155.55 (144.9 to 167.22) | 181.09 (156.46 to 196.42) | +16.4%, ranges overlap |
| shutdown, 4 workers (ms) | 127.76 (124.76 to 154.1) | 141.43 (129.2 to 153.98) | +10.7%, ranges overlap |
| worker RSS, 1 worker (MB) | 210.1 (209.5 to 211.1) | 230.3 (229.4 to 231.6) | +9.6% |
| worker RSS, 4 workers summed (MB) | 420.3 (420 to 420.6) | 431.5 (430.3 to 432.2) | +2.7% |
| master CPU, 1 worker (%) | 0 (0 to 0) | 0.33 (0.27 to 0.33) | new cost |
| master CPU, 4 workers (%) | 0 (0 to 0.07) | 0.4 (0.33 to 0.47) | new cost |

N=2000 with a 30 s window (`--n=2000 --workers=1,4 --idle=30 --settle=3`), 5
reps, two keep-alive passes per stream:

| Metric | Base | Branch | Change |
|---|---|---|---|
| worker CPU, 1 worker (%) | 5.66 (5.23 to 5.86) | 0.13 (0.07 to 0.13) | 43.5x lower |
| wakeups/s, 1 worker | 512.4 (492.9 to 517.7) | 16.1 (15.2 to 17.9) | 31.8x lower |
| worker CPU, 4 workers summed (%) | 12.19 (10.98 to 12.75) | 0.16 (0.13 to 0.21) | 76.2x lower |
| wakeups/s, 4 workers | 1503.1 (1410.9 to 1591.6) | 37.6 (32.7 to 40.9) | 40.0x lower |
| broadcast to all connections, 4 workers (ms) | 15.76 (15.33 to 26.44) | 17.69 (16.57 to 24.71) | +12.2%, ranges overlap |
| shutdown, 1 worker (ms) | 81.15 (68.52 to 93.44) | 74.34 (69.35 to 103.92) | -8.4%, ranges overlap |
| worker RSS, 1 worker (MB) | 101.9 (100.6 to 102.4) | 108 (107.7 to 108.6) | +6.0% |

The same N=2000 run with a 10 s window (`--idle=10`) ends before the first
keep-alive. There the branch shows 0% worker CPU and 1 wakeup/s per worker,
which is a lower bound, not its steady state; base showed 5.79% (1 worker) and
12% (4 workers).

`fire_http_ms` is not comparable between the trees: `broadcast()` on the
branch returns before the flush runs, so it takes about 0.6 ms against 27.14 ms
(N=2000) and 61.01 ms (N=5000) on base with 1 worker. The tables use
`broadcast_all_ms`, from sending the request to delivery on every connection.

### F2 regression checks

Broadcast after idle: 0 missing connections in all 60 broadcasts (30 per
variant). The broadcast latency median is lower on the branch in 5 of 6
configuration and worker-count cells and higher in N=2000, idle 30 s, 4
workers (above). The base and branch ranges overlap in every cell except
N=5000 with 4 workers, so this shows no regression rather than a gain.

Shutdown with every stream open never timed out and stayed under 200 ms in both
trees, against OpenSwoole's 3 s `max_wait_time`. At N=2000 there is no
difference. At N=5000 the branch is slower: +16.4% with 1 worker, where B was
slower in all 5 interleaved pairs by 8 to 37 ms, and +10.7% with 4 workers,
where B was slower in 4 of 5 pairs. The ranges overlap in both, so the 4-worker
figure in particular is weak evidence. The slowest stream end with 1 worker
went from 39.43 to 58.84 ms. That is about 5 us more per connection at
shutdown.

Worker RSS with 1 worker grows by 3 to 4 KB per connection (+6.1 MB at N=2000,
+20.2 MB at N=5000). With 4 workers it is +2.7% at N=5000 and flat at N=2000
(227.8 against 227.2 MB).

The keep-alive is new behaviour: base writes nothing to an idle stream. It
costs 0.1 to 0.4% of a core in the master (reactor threads) and 0.13 to 0.9% in
the client, where base shows about 0, and it is why branch worker CPU and
wakeups are not 0 at steady state.

## F3: mutate lock contention (8001b97)

The ticket lock that `SharedTable::mutate()` and `SharedSignalStore::mutate()`
each carried is now `TicketLock`. Coroutines of one worker queue on a local
gate, so a worker holds one ticket and runs one poller per key. A poll reads
the integer `serving` and `lease` columns, where base copied the whole row,
serialized value included, on every poll and every release. Measured with
`lock_contention.php`: W forked workers with C coroutines each mutate one hot
key, through `Via::mutateGlobalState()` (global) or `Signal::mutate()` on a
ROUTE-scoped signal (signal). Each worker does the same number of mutations at
every C.

W=4, default sweep (`--reps=1 --timeout=300`: 20000 ops per worker, C=1, 8,
32), 5 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| ops/s, global, C=1 | 113183 (108498 to 132767) | 244100 (195147 to 273204) | 2.16x higher |
| ops/s, global, C=8 | 72258 (58694 to 75422) | 261949 (219950 to 275107) | 3.63x higher |
| ops/s, global, C=32 | 42494 (39523 to 48881) | 265524 (214658 to 291347) | 6.25x higher |
| ops/s, signal, C=1 | 142530 (135081 to 146268) | 239609 (204645 to 286022) | +68.1% |
| ops/s, signal, C=32 | 43377 (41242 to 51312) | 273093 (225607 to 284498) | 6.30x higher |
| CPU per op, global, C=32 (us) | 94.06 (81.76 to 101.08) | 14.77 (13.3 to 17.73) | 6.37x lower |
| CPU per op, signal, C=32 (us) | 92.18 (77.92 to 96.92) | 13.71 (13.38 to 15.63) | 6.72x lower |
| handoff, global, C=32 (us) | 20.11 (17.2 to 21.33) | 1.44 (1.34 to 1.58) | 14.0x lower |
| handoff, signal, C=32 (us) | 19.48 (16.4 to 20.31) | 1.2 (1.15 to 1.48) | 16.2x lower |
| latency p99, global, C=32 (us) | 4567.4 (4060.2 to 4835.8) | 684 (626.6 to 841.1) | 6.68x lower |
| ops/s ratio C=32 to C=1, global | 0.375 (0.298 to 0.439) | 1.088 (1.035 to 1.18) | |
| ops/s ratio C=32 to C=1, signal | 0.321 (0.285 to 0.36) | 1.056 (0.995 to 1.229) | |
| CPU per op ratio C=32 to C=1, global | 2.671 (2.279 to 3.357) | 0.915 (0.83 to 0.96) | |
| CPU per op ratio C=32 to C=1, signal | 3.125 (2.784 to 3.519) | 0.904 (0.789 to 0.992) | |

Branch walls at this size are only 0.25 to 0.4 s, so a second W=4 sweep with
`--ops=100000` (3 reps, 1.3 to 1.5 s walls) checks the same numbers with tighter
spreads. At C=32, global went from 52039 (48540 to 57950) to 272547 (272392 to
293609) ops/s, 5.24x, and signal from 55021 (51639 to 58700) to 290147 (278941
to 296800) ops/s, 5.27x.

W=8 (`--workers=8 --coroutines=1,32 --ops=40000 --reps=1 --timeout=300`), 5
reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| ops/s, global, C=1 | 42170 (39318 to 42821) | 68434 (61577 to 71541) | +62.3% |
| ops/s, global, C=32 | 15900 (15505 to 17378) | 69159 (60183 to 69958) | 4.35x higher |
| ops/s, signal, C=32 | 16473 (15983 to 16732) | 67502 (57146 to 71177) | 4.10x higher |
| CPU per op, global, C=32 (us) | 502.45 (459.87 to 515.48) | 115.11 (113.91 to 132.15) | 4.36x lower |
| handoff, global, C=32 (us) | 52.23 (47.58 to 53.7) | 9.24 (8.88 to 10) | 5.65x lower |
| latency p99, global, C=32 (us) | 20280.6 (18559.6 to 21209.8) | 4737.5 (4401.8 to 6443.4) | 4.28x lower |

The p99 latency is mostly FIFO queueing of W times C waiters and improves in
proportion to throughput. Throughput, CPU per op and handoff measure the lock
directly.

### F3 regression checks

C=1 is not slower at any worker count. W=16, C=1 (`--workers=16 --coroutines=1
--ops=20000 --reps=1 --timeout=300`), 5 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| ops/s, global | 16969 (15625 to 18382) | 28526 (27145 to 29513) | +68.1% |
| ops/s, signal | 15644 (14482 to 16252) | 26751 (24907 to 27029) | +71.0% |
| CPU per op, global (us) | 915.88 (848.59 to 985.4) | 545.44 (525.6 to 558.78) | -40.4% |
| hold time mean, global (us) | 24.6 (23.06 to 26.66) | 11.9 (11.41 to 12.14) | 2.07x lower |
| latency p99, global (us) | 3049.7 (2492.2 to 3652.9) | 2097.1 (2042.5 to 2254.7) | -31.2% |

All 5 branch reps beat all 5 base reps, and the ranges do not overlap. C=1
still pays for the gate: `TicketLock::enterGate()` runs on every call inside a
coroutine and creates and drops a `Channel(1)`, so the C=1 numbers include the
uncontended gate cost. The C=1 gain cannot come from the gate, since a single
coroutine never queues on it. Reading integer columns instead of copying the
whole row (the benchmark stores a small serialized map) is the likely source,
and it fits the halved hold time, since base's release copied the row too. But
737b2aa..5a9850a has other changes in the same path, and which one is
responsible was not isolated.

`cpu_cores` stays near W in both trees. At W=4, signal, C=32 it is 4 (4 to 4) on
base and 3.65 (3.53 to 3.9) on the branch, about 7.96 at W=8 and about 15.5 at
W=16. Waiters still spin; the branch value sits slightly under W at W=4 only
because the runs are short and include startup.

`finish_spread_s`, the gap between the first and the last worker finishing, rose
at W=4 from 0 to 5 ms on base to 3 to 71 ms on the branch, on a 0.25 to 0.41 s
wall. With `--ops=100000` it stayed at 0.1 to 4.8% of a 1.3 to 1.5 s wall, so
it looks like a fixed offset at start or end, not ongoing unfairness. At W=16
the branch spread is lower than base (medians 0.009 and 0.011 s against 0.050
and 0.079 s, global and signal), though in signal mode the ranges overlap.

One hot key still loses throughput as W grows, because every worker keeps one
coroutine spinning on the row. Branch C=1 global does 244100 ops/s at W=4,
68434 at W=8 and 28526 at W=16, at 16.06, 116.21 and 545.44 us CPU per op. The
branch's mean hold time goes from 4.6 us at W=8 to 11.9 us at W=16, which points
to the pollers fighting the holder for the row lock, though at W=16 descheduling
(below) adds to it. Base has the same shape at lower throughput. The gate
removes the C multiplier, not the W multiplier; that needs a backoff or a
blocking wait. W=16 oversubscribes this shared host (`cpu_cores` about 15.5,
not 16), so descheduled holders inflate the absolute W=16 handoff and hold
numbers in both trees. The interleaved A/B comparison still holds.

## F4: shared signal reads per broadcast (6a6fc90)

A flush now holds a per-coroutine read epoch (`State\ReadEpochs`) across all
its dirty scopes, and a synchronous fan-out holds one per pass, so each scoped
signal is read from the shared table once per fan-out instead of once per
accessor call. Measured with `shared_read.php`, in one process: N contexts on
two Via instances, one without a store (the single-worker baseline) and one
with a real `SharedSignalStore`. Each view reads S=5 scoped signals, and a
third instance writes them as another worker would. Store reads are counted by
swapping the store's table for a counting subclass.

Defaults, `php bench/contention/shared_read.php` (N=2000, S=5, 20 broadcasts,
tab and route modes, remote writer), 5 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| store reads per broadcast, tab | 20000 | 5 | 4000x fewer |
| store reads per broadcast, route | 10005 | 5 | 2001x fewer |
| with store, tab (ms per broadcast) | 51.51 (51.05 to 52.81) | 23.71 (23.59 to 24.07) | 2.17x lower |
| with store, route (ms per broadcast) | 23.33 (22.62 to 24.81) | 6.001 (5.868 to 6.086) | 3.89x lower |
| store overhead over no store, tab (%) | 146.8 (145.9 to 148.1) | 8.99 (7.24 to 10.66) | |
| store overhead over no store, route (%) | 405.6 (392.1 to 425.9) | 14.08 (12.33 to 15) | |
| no store, tab (ms per broadcast) | 20.85 (20.69 to 21.28) | 21.75 (21.65 to 22.09) | +4.3% |
| no store, route (ms per broadcast) | 4.646 (4.478 to 4.74) | 5.236 (5.136 to 5.335) | +12.7% |
| peak memory (MB) | 78 | 44 | -43.6% |

Larger configurations, 5 reps each:

| Metric | Base | Branch | Change |
|---|---|---|---|
| with store, route, `--modes=route --contexts=5000 --broadcasts=30` (ms) | 47.1 (46.59 to 47.94) | 13.52 (13.51 to 13.61) | 3.48x lower |
| store reads, route, N=5000 | 25005 | 5 | 5001x fewer |
| with store, tab, `--view-reads=3 --array-items=100` (ms) | 418.3 (414.8 to 422.3) | 215 (214 to 219.5) | -48.6% |
| with store, route, `--view-reads=3 --array-items=100` (ms) | 81.24 (80.15 to 91.6) | 17.44 (17.37 to 17.47) | 4.66x lower |
| store reads, tab, `--view-reads=3 --array-items=100` | 40000 | 5 | 8000x fewer |
| peak memory, `--view-reads=3 --array-items=100` (MB) | 318 | 108 | 2.94x lower |

`ns_per_store_read_est` is meaningless on the branch: it divides the overhead by
the base read count, which the branch no longer performs.

### F4 regression checks

This check fails for route mode. The fan-out without a store, which is the
single-worker path, is slower on the branch in every configuration and in both
run orders, and the ranges never overlap:

| Metric | Base | Branch | Change |
|---|---|---|---|
| no store, route, defaults (ms) | 4.646 (4.478 to 4.74) | 5.236 (5.136 to 5.335) | +12.7% |
| no store, route, defaults in A,B order, 2 reps (ms) | 4.015 (3.862 to 4.169) | 4.51 (4.506 to 4.515) | +12.3% |
| no store, route, `--writer=local`, 3 reps (ms) | 3.999 (3.944 to 4.008) | 4.819 (4.52 to 5.108) | +20.5% |
| no store, route, `--view-reads=3 --array-items=100` (ms) | 15.91 (15.84 to 16.49) | 16.65 (16.58 to 16.8) | +4.7% |
| no store, route, N=5000 (ms) | 10.08 (9.958 to 10.31) | 11.84 (11.79 to 11.93) | +17.5% |

Tab mode without a store is marginal: +4.3% (defaults) and +4.5%
(`--writer=local`) with no overlap, but -1.0% in the A,B order runs and -0.4%
with three view reads, where about 208 ms of rendering hides it; both of those
have overlapping ranges.

A scratch driver that builds only the no-store instance, so that a store in the
same process cannot skew the timing, gives the same result: route +20.7% at
N=200, +19.2% at N=2000, +11.8% at N=5000 and +18.9% at N=10000, 0.22 to
0.37 us per context, and tab +3.2% at N=2000. The same driver locates the cost.
Calling `Context::sync()` on every context costs the same in both trees (3.374
against 3.416 ms at N=2000, route). The same loop through
`Via::syncContextSafely()` costs 3.42 ms on base and 4.099 ms on the branch
(+19.9%). The extra cost is the epoch bookkeeping in `syncContextSafely()`:
`spl_object_id`, `ReadEpochs::current()` (which calls `Coroutine::getCid()`),
a read and a write of the `frameEpochs` WeakMap. It runs even without a store,
because Via always builds a `ReadEpochs`. A tight loop of those operations
costs only about 60 ns per context, so the rest is probably cache misses
between contexts in a real fan-out; that is not proven. The frame-ordering
guard also protects single-worker coroutines whose views yield, so skipping it
when there is no store is not a fix on its own.

The store path on the branch still costs more than no store: +8.99% in tab mode
(about 2.0 ms at N=2000), +14.08% in route mode (about 0.7 ms), and about 4%
with three view reads. Divided by the accessor calls (the base read count), the
residual is 66 to 103 ns per call in every configuration but one, tab mode with
three view reads and 100-item arrays, where it is about 213 ns. It does not
follow the store reads, which are down to S. That the per-call cost is the
`readEpoch()` check in `getValue()` is an inference, not measured.
`mismatched_frames` was 0 in every run, so no stale value reached a frame.
`SharedSignalStore::get()` micro timings are unchanged.

Everything runs in one process, so cross-process contention on the store rows
is not measured, and the base numbers are a lower bound for a multi-worker
server.

## F5: getClients() cost (f77b210)

Each worker keeps its last client list behind a shared version counter and
reuses identicons when it rebuilds. A broadcast reads the list once per read
epoch. With one worker, `getClients()` returns the stored array instead of
building a new one. Measured with `get_clients.php`, in one process: "shared"
mode attaches a real `SharedClientRegistry` as `Via::start()` does for more than
one worker, "single" mode has none. Each view calls
`count($via->getClients())`.

Default sweep, `--n=100,1000,5000 --fanout-cap=1000 --broadcasts=3
--timeout=300`, 5 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| shared, N=1000, call (ms) | 4.5949 (4.5298 to 4.7632) | 0.0001 (0.0001 to 0.0001) | below resolution |
| shared, N=1000, call after one new client (ms) | 4.5207 (4.387 to 4.7556) | 0.3762 (0.3672 to 0.3839) | 12.0x lower |
| shared, N=5000, call after one new client (ms) | 22.4998 (21.7583 to 22.9765) | 2.0677 (1.9256 to 2.372) | 10.9x lower |
| shared, N=100, broadcast to 100 contexts (ms) | 47.07 (44.2 to 49.25) | 0.23 (0.23 to 0.24) | 205x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | 4619.9 (4447.95 to 4705.87) | 2.17 (2.06 to 2.23) | 2130x lower |
| shared, N=5000, broadcast to 1000 contexts (ms) | 22844.46 (22578.8 to 23734.03) | 4.13 (3.58 to 4.23) | 5530x lower |
| shared, N=5000, broadcast extrapolated to 5000 contexts (ms) | 113404.9 (112933.9 to 119916) | 10.7 (9.8 to 11) | extrapolated |
| shared, N=5000, size of one call's result (KB) | 11424.4 | 0 | |
| single, N=1000, broadcast to 1000 contexts (ms) | 105.43 (103.21 to 106.8) | 1.66 (1.62 to 1.85) | 63.5x lower |
| peak memory (MB) | 38 | 26 | -31.6% |

The branch's 0.0001 ms call time is the script's rounding floor and includes
hrtime overhead. It means "below 0.1 us" and is not a speedup factor. By design
the call loop measures cache hits on the branch; the rebuild cost is in the
"call after one new client" rows.

Single mode with the full fan-out measured (`--n=1000,5000 --fanout-cap=5000
--broadcasts=3 --timeout=300 --modes=single`), 5 reps: a broadcast to 5000
contexts went from 2928.42 (2892.24 to 2947.23) to 8.33 (8.19 to 8.88) ms, 352x
lower. The 10-rep single-only sweep extrapolated the same case to 2944.5 and
8.2 ms.

### F5 regression checks

Single-worker path: every single-mode metric is faster on the branch in all
three configurations. In the 10-rep single-only sweep
(`--n=100,1000,5000 --fanout-cap=1000 --broadcasts=3 --modes=single`) the
branch's N=100 broadcast spreads widely, 0.36 ms (0.17 to 0.85), most likely
CPU ramp-up in a process that lasts 0.1 s; that is unproven. Its slowest rep is
still below the base median of 0.885 ms.

Rebuild after a membership change: the "call after one new client" rows above
are the warm rebuild, 10.9x to 12.0x faster. The script does not time a cold
rebuild, where the worker has never read the list and no identicon can be
reused, so a scratch probe timed `SharedClientRegistry::all()` on fresh
registries (7 per N per run, 5 reps):

| Metric | Base | Branch | Change |
|---|---|---|---|
| cold read, N=100 (ms) | 0.4607 (0.4479 to 0.4737) | 0.4795 (0.4745 to 0.4827) | +4.1% |
| cold read, N=1000 (ms) | 4.3944 (4.317 to 4.4444) | 4.5503 (4.4115 to 4.6066) | +3.5%, ranges overlap |
| cold read, N=5000 (ms) | 22.4058 (22.1313 to 22.7759) | 22.3374 (22.0588 to 23.3972) | -0.3%, ranges overlap |
| rebuild after 10% churn, N=1000 (ms) | 4.3415 (4.2951 to 4.3762) | 0.8241 (0.7977 to 0.8425) | 5.27x lower |
| rebuild after 10% churn, N=5000 (ms) | 22.2065 (21.7528 to 22.7495) | 4.1015 (4.0359 to 4.2706) | 5.41x lower |

The cold read is the one path where the branch is slower. At N=100 every branch
rep is above every base rep; at N=1000 the ranges overlap slightly. The likely
cause is the lookup into the previous snapshot and the duplicate-tab check in
`scan()`. It happens once per worker per entirely new client set.

Memory: one call on the branch returns the shared array (0 KB) where base built
2.2 MB at N=1000 and 11.4 MB at N=5000. In exchange each worker keeps its
snapshot permanently, about 2.2 KB per client, so about 11 MB per worker at
5000 clients. That size is estimated from one base result, not measured on the
branch.

## What got worse or was not measured

Worse on the branch:

- F1: an update that lands during a flush, or less than one tick after a flush
  ends, waits until one tick after that end. It reached every client 43.452 ms
  (K=2) and 45.474 ms (K=20) after the last send at N=1000 on one worker,
  against 9.229 and 8.015 ms on base. The tick counts from the end of the last
  flush, so the delay grows with the contexts per worker.
- F4: the fan-out without a store is 4.7 to 20.7% slower in route mode and up
  to 4.5% slower in tab mode, from the read-epoch bookkeeping in
  `syncContextSafely()`.
- F2: shutdown with 5000 open streams takes 10.7 to 16.4% longer (the ranges
  overlap), worker RSS grows by 3 to 4 KB per connection with one worker, and
  the 15 s keep-alive adds master and client CPU that base does not have.
- F5: a cold read of the client list is 3.5 to 4.1% slower at N=100 and
  N=1000 (at N=1000 the ranges overlap), and each worker keeps its client
  snapshot in memory.

Moved, not worse:

- F1: a running flush blocks the requests that land on its worker (branch
  action p99 50.551 ms at N=5000 on one worker). Base paid the same fan-out
  inside the broadcasting action (2299.158 ms).

Unchanged:

- F3: waiters still spin, so one hot key costs W cores, and its throughput
  still falls as W grows (branch C=1 global: 28526 ops/s at W=16).

Not measured:

- All benchmarks: opcache and JIT were off, and every view was a cheap PHP
  closure (broadcast_storm: 512 bytes, `--render-us=0`). Heavier views make a
  flush longer, which lengthens both F1 effects above.
- F1: a paced stream of updates over several seconds. The script sends all K
  actions at once.
- F1: the follow-up delay (the K=2 case) at N=5000 or with 4 workers. Its
  growth with N is predicted from the flush duration, not measured.
- F2: the HTTP/2 stream-reset check and the per-worker directory refresh timer
  (every 150 s by default, longer than any idle window here), and the
  destroyed-context safety valve.
- F2: how quickly the stream of a disconnected client ends. Base checked
  `isWritable()` on every 100 ms wake; the branch relies on the close event or
  the next keep-alive write, and no benchmark times it.
- F3: how a spinning waiter slows the other connections of its worker. The
  harness has no HTTP.
- F3: a long critical section. Every run used `--hold-us=0` and a map value.
  After 2 ms without progress a branch waiter sleeps 1 ms per poll, where base
  kept yielding, so a mutate callback that holds the lock for more than 2 ms
  can add up to 1 ms to the next handoff. `--hold-us` and `--value=int` were
  not run.
- F4 and F5: cross-process contention, since both scripts run in one process.
  F4 in a real server cannot be separated from F1 with `broadcast_storm.php`.
- F5: a version bump from another process, the stale-row removal in
  `claimWorker` and `removeDeadProcesses`, and the coalesced flush path (the
  script runs outside a coroutine, so `broadcast()` renders synchronously).
- F5 single mode: a caller that keeps the returned array while a client
  registers or unregisters makes PHP copy it (O(N)) at that write. Not timed.
