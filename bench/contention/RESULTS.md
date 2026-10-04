# Contention benchmark results

## 0.14.0 release tree on held clocks, measured 2026-10-04

F1, F2, F4 and F5 were rerun against fbfa1f6 (feat/api-014-rc2), the 0.14.0
release tree. Each table compares base 737b2aa, v0.13.0 at dccddd6, 7a49802
(the tree of the next section) and fbfa1f6, with the change of fbfa1f6 against
base.

- **Trees, server and clients:** as in the next sections, with each tree's
  vendor directory and the scripts patched only to take the port and the
  server's cores from environment variables. Server on cores 2 and 4 held at
  4.5 GHz, broadcast_storm and idle_sse clients on cores 6 to 11, shared_read
  and get_clients on core 2. Tree order rotated in every rep.
- **Gating:** every run started only with the package below 95 °C, the
  sibling cores 3 and 5 under 10% busy and cores 2 and 4 at 4.4 GHz or more,
  and would have been rerun had its median clock fallen below 4.4 GHz: 106
  runs kept, none rerun.
- **Correctness:** every storm converged with no failed action, every
  idle_sse broadcast reached all 5000 connections, shared_read had 0
  mismatched frames and every get_clients flag was true, in every tree.

fbfa1f6 matches 7a49802 within the noise in F1, F4 and F5. In F2 the
broadcast to 5000 idle streams takes 20.5% longer than on 7a49802, ranges
apart, and the cycle collector's 100 ms check no longer adds wakeups. Both
follow from 1bdc2f3, which keeps PHP's own collector runs by default and makes
the growth-based runs opt-in (see the F2 bisect below).

### F4 shared_read, release tree

Defaults (N=2000, S=5, 20 broadcasts), 5 reps:

| Metric | Base | v0.13.0 | 7a49802 | fbfa1f6 | Change |
|---|---|---|---|---|---|
| with store, tab (ms per broadcast) | 48.834 (48.632 to 49.502) | 22.722 (22.388 to 23.304) | 21.69 (21.564 to 21.895) | 21.779 (21.507 to 22.125) | 2.24x lower |
| with store, route (ms per broadcast) | 20.224 (20.033 to 20.79) | 5.319 (5.236 to 5.458) | 3.633 (3.563 to 3.688) | 3.603 (3.591 to 3.675) | 5.61x lower |
| no store, tab (ms per broadcast) | 19.808 (19.722 to 20.102) | 20.279 (20.16 to 20.701) | 19.456 (19.426 to 19.759) | 19.497 (19.332 to 19.634) | -1.6% |
| no store, route (ms per broadcast) | 3.751 (3.727 to 3.84) | 4.556 (4.544 to 4.693) | 2.841 (2.822 to 2.879) | 2.844 (2.81 to 2.898) | -24.2% |
| peak memory (MB) | 78 (78 to 78) | 48 (48 to 48) | 56 (56 to 56) | 56 (56 to 56) | -28.2% |

The F4 regression check passes: without a store, fbfa1f6 costs what base
costs per context in tab mode and 24.2% less in route mode, ranges apart.
Against v0.13.0 it is 3.9% faster in tab mode and 37.6% faster in route mode.

### F5 get_clients, release tree

Default sweep (`--n=100,1000,5000 --fanout-cap=1000 --broadcasts=3
--timeout=300`), 5 reps (base 3):

| Metric | Base | v0.13.0 | 7a49802 | fbfa1f6 | Change |
|---|---|---|---|---|---|
| shared, N=1000, call after one new client (ms) | 4.477 (4.348 to 4.615) | 0.384 (0.367 to 0.386) | 0.373 (0.366 to 0.382) | 0.369 (0.364 to 0.385) | 12.1x lower |
| shared, N=5000, call after one new client (ms) | 22.377 (22.265 to 22.524) | 2.015 (1.962 to 2.101) | 2.053 (2.002 to 2.182) | 2.035 (1.958 to 2.242) | 11.0x lower |
| shared, N=100, broadcast to 100 contexts (ms) | 45.19 (44.34 to 47.66) | 0.24 (0.24 to 0.25) | 0.2 (0.19 to 0.21) | 0.2 (0.19 to 0.2) | 226x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | 4533.4 (4459.3 to 4555.6) | 2.24 (2.17 to 2.33) | 1.7 (1.65 to 1.9) | 1.69 (1.68 to 1.72) | 2682x lower |
| shared, N=5000, broadcast to 1000 contexts (ms) | 23016.2 (22657.1 to 23048.7) | 4.06 (3.77 to 4.18) | 3.28 (3.23 to 3.52) | 3.25 (3.24 to 3.47) | 7082x lower |
| single, N=100, broadcast to 100 contexts (ms) | 0.86 (0.85 to 0.89) | 0.18 (0.18 to 0.18) | 0.13 (0.12 to 0.13) | 0.13 (0.12 to 0.14) | 6.62x lower |
| single, N=1000, broadcast to 1000 contexts (ms) | 108.1 (107.5 to 109.2) | 1.77 (1.68 to 1.79) | 1.24 (1.19 to 1.29) | 1.26 (1.23 to 1.28) | 85.8x lower |
| peak memory (MB) | 38.0 (38.0 to 38.0) | 28.0 (28.0 to 28.0) | 28.0 (28.0 to 28.0) | 28.0 (28.0 to 28.0) | -26.3% |

Broadcasts that read the client list take 17 to 29% less time than on
v0.13.0, ranges apart, and the same as on 7a49802. The rebuild after a new
client is level with v0.13.0.

### F2 idle_sse, release tree

N=5000 with a 15 s window (`--n=5000 --idle=15 --settle=3`), 1 worker, 5 reps:

| Metric | Base | v0.13.0 | 7a49802 | fbfa1f6 | Change |
|---|---|---|---|---|---|
| worker CPU (%) | 7.59 (7.4 to 7.79) | 0.2 (0.13 to 0.2) | 0.13 (0.07 to 0.2) | 0.13 (0.07 to 0.13) | 1 to 2 ticks |
| wakeups/s | 596.7 (595.7 to 623) | 22.1 (21.3 to 22.3) | 33.5 (30.4 to 34.3) | 24.2 (22.6 to 26.6) | 24.7x lower |
| broadcast to all connections (ms) | 42.07 (41.69 to 42.55) | 44.27 (43.73 to 48.18) | 39.88 (37.97 to 42.01) | 48.07 (47.36 to 52.23) | +14.3% |
| shutdown (ms) | 122.9 (120.0 to 124.7) | 124.5 (121.0 to 137.5) | 139.4 (134.2 to 153.4) | 140.4 (138.3 to 152.6) | +14.3% |
| worker RSS (MB) | 204.9 (203 to 207.8) | 231.3 (230.3 to 233) | 230.4 (229.8 to 230.5) | 229.8 (229.6 to 230) | +12.2% |

The broadcast to all 5000 streams takes 48.1 ms, 20.5% longer than on 7a49802
and 14.3% longer than on base, ranges apart. Wakeups fall back to the level of
v0.13.0. Shutdown matches 7a49802, 16 to 18 ms slower than base and v0.13.0.

A bisect between 7a49802 and fbfa1f6, 3 reps per tree in the same gated runs,
puts the broadcast step at 1bdc2f3: 38.6 ms (38.2 to 40.1) on its parent
541b691 and 44.9 ms (44.8 to 46.0) on 1bdc2f3, ranges apart, with the wakeups
falling from 32.5 to 22.9 a second at the same commit. 87c8ff9, the parent of
85b446e, measured 47.5 ms (46.6 to 49.8) and fbfa1f6 51.3 ms (46.3 to 51.3),
ranges overlapping, so the later commits add at most a few ms that this run
could not separate. The likely cause, not isolated: with PHP's runs on, a
collector run can fall inside the fan-out and walk every live context. `withGcIntervalMs(onGrowth: true)` is
the opt-in that turns those runs off.

### F1 broadcast_storm, release tree

Defaults (N=1000, K=200, concurrency 50, 1 worker), 5 reps, and low load with
one actor (`--concurrency=1`, K=1), 7 reps:

| Metric | Base | v0.13.0 | 7a49802 | fbfa1f6 | Change |
|---|---|---|---|---|---|
| storm to converge (ms) | 1418.9 (1417.9 to 1437.9) | 33.68 (32.573 to 34.1) | 34.731 (32.854 to 35.56) | 33.903 (33.712 to 34.803) | 41.9x lower |
| converge after last send (ms) | 357.7 (352.2 to 360.9) | 21.554 (19.551 to 23.652) | 23.367 (21.209 to 24.242) | 22.766 (22.729 to 23.772) | 15.7x lower |
| action latency p50 (ms) | 354.3 (353.0 to 358.8) | 0.503 (0.493 to 0.526) | 0.622 (0.6 to 0.635) | 0.653 (0.632 to 0.665) | 543x lower |
| action latency p99 (ms) | 358.9 (356.3 to 363.1) | 10.649 (8.528 to 11.525) | 9.057 (8.735 to 12.516) | 9.199 (9.091 to 9.231) | 39x lower |
| actions/s | 140.9 (139.1 to 141.1) | 15706.1 (14775.4 to 18995.1) | 17322.2 (13332.4 to 17849.2) | 17049 (16914.6 to 17244.2) | 121x higher |
| converge after last send, K=1 (ms) | 7.591 (7.397 to 8.069) | 8.455 (8.298 to 8.758) | 8.714 (8.582 to 9.173) | 9.143 (8.824 to 9.87) | +20.4% |
| action latency, K=1 (ms) | 7.628 (7.435 to 8.191) | 0.267 (0.256 to 0.292) | 0.432 (0.409 to 0.471) | 0.434 (0.402 to 0.459) | 17.6x lower |

The storm converges as fast as on v0.13.0 and 7a49802. At K=1 fbfa1f6
converges 0.4 ms later than 7a49802, ranges overlapping, 0.7 ms later than
v0.13.0 and 1.6 ms later than base, ranges apart. The K=1 action latency
matches 7a49802 and stays 0.17 ms above v0.13.0, and the action latency p50
at the defaults is 29.8% above v0.13.0, ranges apart, as on 7a49802.

## 7a49802 on held clocks, measured 2026-10-03

**Superseded for 0.14.0 by the section above,** which measures the release
tree fbfa1f6.

F1, F2, F4 and F5 were rerun against 7a49802 (feat/api-014-mw), the 0.14.0
tree with forwarding between workers, the cycle collector and the fan-out
work, which the release candidate in the next section did not have. Each table
compares base 737b2aa, v0.13.0 at dccddd6, 0.14 before that work at 7ea9875
(feat/api-014-final) and 7a49802, with the change of 7a49802 against base.

- **Trees, server and clients:** as in the next section, with each tree's
  vendor directory. Server on cores 2 and 4 held at 4.5 GHz, broadcast_storm
  and idle_sse clients on cores 6 to 11, shared_read and get_clients on core 2.
- **Gating:** other sessions drove the package to 100 °C during the first
  F2, F4 and F5 pass, and the held clock fell to 3.0 to 4.3 GHz, so that pass
  was discarded. Every later run started only with the package below 95 °C,
  the sibling cores 3 and 5 under 10% busy and cores 2 and 4 at 4.4 GHz or
  more, and was rerun when its median clock fell below 4.4 GHz: 161 runs kept,
  11 rerun. The 48 F1 runs were not gated, and all ran at a median clock of
  4,458 MHz or more.
- **Correctness:** every storm converged with no failed action, every
  idle_sse broadcast reached all 5000 connections, shared_read had 0
  mismatched frames and every get_clients flag was true, in every tree.

### F4 shared_read, 7a49802

Defaults (N=2000, S=5, 20 broadcasts), 5 reps:

| Metric | Base | v0.13.0 | 7ea9875 | 7a49802 | Change |
|---|---|---|---|---|---|
| with store, tab (ms per broadcast) | 50.292 (49.394 to 50.848) | 23.084 (22.751 to 23.618) | 24.014 (23.64 to 24.065) | 22.062 (21.778 to 22.262) | 2.28x lower |
| with store, route (ms per broadcast) | 20.764 (20.652 to 20.932) | 5.57 (5.556 to 5.777) | 6.53 (6.462 to 6.606) | 3.848 (3.79 to 3.934) | 5.40x lower |
| no store, tab (ms per broadcast) | 20.346 (19.939 to 20.42) | 20.928 (20.591 to 21.411) | 21.605 (21.443 to 21.761) | 19.846 (19.444 to 20.0) | -2.5%, ranges overlap |
| no store, route (ms per broadcast) | 3.937 (3.894 to 3.987) | 4.915 (4.881 to 5.091) | 5.729 (5.708 to 5.778) | 3.051 (3.042 to 3.114) | -22.5% |
| peak memory (MB) | 78 | 48 | 58 | 56 | -28.2% |

The F4 regression check passes again. Without a store, 7a49802 costs what
base costs per context in tab mode and 22.5% less in route mode, ranges apart,
where 7ea9875 was 6.2% and 45.5% slower than base. Against v0.13.0, 7a49802
is 5.2% faster in tab mode and 37.9% faster in route mode.

### F5 get_clients, 7a49802

Default sweep (`--n=100,1000,5000 --fanout-cap=1000 --broadcasts=3
--timeout=300`), 5 reps (base 3):

| Metric | Base | v0.13.0 | 7ea9875 | 7a49802 | Change |
|---|---|---|---|---|---|
| shared, N=1000, call after one new client (ms) | 4.586 (4.529 to 4.641) | 0.382 (0.369 to 0.387) | 0.461 (0.452 to 0.471) | 0.379 (0.366 to 0.386) | 12.1x lower |
| shared, N=5000, call after one new client (ms) | 23.122 (22.209 to 23.265) | 2.082 (1.953 to 2.168) | 2.659 (2.568 to 2.73) | 2.234 (2.082 to 2.444) | 10.4x lower |
| shared, N=100, broadcast to 100 contexts (ms) | 46.74 (46.11 to 49.4) | 0.24 (0.24 to 0.25) | 0.27 (0.27 to 0.27) | 0.2 (0.18 to 0.21) | 234x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | 4664.4 (4543.6 to 4763.1) | 2.26 (2.13 to 2.71) | 2.46 (2.41 to 2.55) | 1.73 (1.65 to 1.76) | 2696x lower |
| shared, N=5000, broadcast to 1000 contexts (ms) | 23532.5 (23255.4 to 24925.1) | 4.21 (3.96 to 4.41) | 4.77 (4.65 to 4.8) | 3.57 (3.29 to 3.92) | 6592x lower |
| single, N=100, broadcast to 100 contexts (ms) | 0.89 (0.84 to 0.93) | 0.18 (0.17 to 0.19) | 0.19 (0.19 to 0.19) | 0.13 (0.13 to 0.13) | 6.85x lower |
| single, N=1000, broadcast to 1000 contexts (ms) | 111.3 (110.7 to 111.7) | 1.74 (1.7 to 1.75) | 1.84 (1.78 to 1.86) | 1.26 (1.21 to 1.33) | 88.3x lower |
| peak memory (MB) | 38 | 28 | 28 | 28 | -26.3% |

Broadcasts that read the client list take 25 to 32% less time than on
7ea9875 and 15 to 28% less than on v0.13.0, ranges apart. The rebuild after a
new client is 17.7% faster than on 7ea9875 and level with v0.13.0.

### F2 idle_sse, 7a49802

N=5000 with a 15 s window (`--n=5000 --idle=15 --settle=3`), 1 worker, 5 reps:

| Metric | Base | v0.13.0 | 7ea9875 | 7a49802 | Change |
|---|---|---|---|---|---|
| worker CPU (%) | 8.52 (8.19 to 8.79) | 0.13 (0.13 to 0.27) | 0.13 (0.13 to 0.2) | 0.13 (0.07 to 0.13) | 1 to 2 ticks |
| wakeups/s | 615.9 (580.7 to 637.9) | 22.5 (21.4 to 23) | 24.2 (22 to 25.1) | 33.8 (32.5 to 37.3) | 18.2x lower |
| broadcast to all connections (ms) | 44.44 (43.07 to 48.08) | 45.35 (44.01 to 46.74) | 50.37 (47.58 to 50.72) | 40.14 (38.92 to 40.6) | -9.7% |
| shutdown (ms) | 126.8 (122.9 to 134.1) | 129.4 (124.6 to 142.5) | 132.6 (130.8 to 139.3) | 145.3 (143.7 to 147.7) | +14.6% |
| worker RSS (MB) | 205.3 (204.7 to 206.9) | 232.5 (230.3 to 234.7) | 227.6 (227.4 to 228.2) | 230.4 (229.7 to 230.6) | +12.2% |

The broadcast to all 5000 streams is faster than in every other tree, ranges
apart. Two costs are new. About 10 more wakeups a second match the cycle
collector's 100 ms check, and shutdown takes 13 ms longer than on 7ea9875,
which matches the reconnect signal a stopping worker now sends every stream.
Neither cause was isolated.

### F1 broadcast_storm, 7a49802

Defaults (N=1000, K=200, concurrency 50, 1 worker), 5 reps, and low load with
one actor (`--concurrency=1`, K=1), 7 reps:

| Metric | Base | v0.13.0 | 7ea9875 | 7a49802 | Change |
|---|---|---|---|---|---|
| storm to converge (ms) | 1456.4 (1447.3 to 1472.9) | 33.749 (33.421 to 34.778) | 34.391 (33.963 to 34.857) | 34.058 (33.369 to 35.444) | 42.8x lower |
| converge after last send (ms) | 363.2 (362.1 to 367.7) | 22.44 (20.425 to 24.538) | 22.947 (20.251 to 23.189) | 23.53 (22.537 to 24.434) | 15.4x lower |
| action latency p50 (ms) | 363.5 (361.0 to 367.3) | 0.498 (0.477 to 0.509) | 0.582 (0.578 to 0.606) | 0.622 (0.607 to 0.638) | 584x lower |
| action latency p99 (ms) | 367.2 (364.4 to 370.2) | 9.887 (8.466 to 11.678) | 9.914 (9.69 to 11.983) | 8.929 (8.655 to 9.703) | 41.1x lower |
| actions/s | 137.3 (135.8 to 138.2) | 16821 (14566.6 to 19025.7) | 16310.7 (13962.6 to 16611.1) | 17158.5 (16471.5 to 17938.6) | 125x higher |
| converge after last send, K=1 (ms) | 7.852 (7.651 to 8.089) | 8.548 (8.4 to 9.56) | 9.481 (9.347 to 11.894) | 8.735 (8.571 to 9.025) | +11.2% |
| action latency, K=1 (ms) | 7.895 (7.694 to 8.132) | 0.267 (0.258 to 0.302) | 0.547 (0.53 to 0.557) | 0.418 (0.405 to 0.436) | 18.9x lower |

The storm converges as fast as on v0.13.0 and 7ea9875. At K=1, 7a49802
converges 7.9% earlier than 7ea9875, ranges apart, but still 0.9 ms later than
base and 0.2 ms later than v0.13.0. A gated second set of 7 reps puts the step
at 0.13.1: converge after last send 8.827 ms (8.677 to 9.190) on 7a49802,
8.480 (8.377 to 8.627) on v0.13.0, 8.830 on v0.13.1 (e1742bc) and 8.938 on
718e51c, and the K=1 action latency 0.417, 0.269, 0.483 and 0.431 ms. The
action latency p50 at the defaults is 24.9% above v0.13.0, ranges apart. The
0.13.1 commits were not bisected.

## 0.14.0 release candidate on held clocks, measured 2026-10-03

**Superseded for 0.14.0 by the sections above,** which measure 7a49802 and
the release tree: their F4 regression check passes and their F1, F2 and F5
figures replace these.


Every F section below was rerun against release/0.14 at 718e51c, the 0.14.0
release candidate, with the server's cores held at full clock. Base (A) is
737b2aa, as in the older runs. The rerun checks which recorded figures came
from idle cores clocking down, and whether 0.14.0 keeps what the branch gained.
The tables show held medians for base and 0.14.0, the change between them, and
the recorded base and branch medians from the sections below.

- **Trees:** copies of 718e51c and 737b2aa extracted with `git checkout-index`
  from a temporary index, on btrfs, each with its vendor directory and
  `composer dump-autoload`. Both ran release/0.14's scripts, patched only to
  take the port and the server's cores from environment variables. PHP 8.5.11
  CLI with opcache and JIT off, ext-openswoole 26.2.0.
- **Server:** two physical P-cores of an Intel i5-13500 (cores 2 and 4) with
  their hyperthread siblings idle, held at 4.5 GHz by a busy loop in the idle
  scheduling class on each core (`bench/capacity/README.md` shows how). The
  broadcast_storm and idle_sse servers ran there with their clients unheld on
  cores 6 to 11. lock_contention ran on cores 2, 4, 6 and 8 with four busy
  loops, shared_read and get_clients on core 2. The clock was sampled during
  every run.
- **Protocol:** 0.14.0 then base in every rep, at the sizes and rep counts
  each table below states. Unheld controls used the same pinning without the
  busy loops. Table format as described at the top of this file.
  Every run was correct in both trees: every storm converged with no failed
  action, every idle_sse broadcast reached all 5000 connections, every lock
  run reached its final value, shared_read had 0 mismatched frames and every
  get_clients flag was true.

Held, a core runs at 4.5 GHz; a busy core that is not held reaches up to
4.8 GHz on this host. With five busy loops, or while the host was busy, the
held clock fell to 3.7 to 4.3 GHz. The first low-load and idle_sse runs ran at
3.8 GHz that way and were discarded and rerun at 4.5 GHz. Only idle_sse with 4
workers ran below 4.5 GHz (see F2).

### What the held clock changes

- F1 at K=1 measured 2 to 3 times its held value in both trees: the clock,
  plus the host and the branch's FUSE mount. Held, 0.14.0 converges 1.2 ms
  (15%) later than base with the ranges apart, where the F1 regression checks
  found no measurable regression.
- Base's idle CPU in F2 follows the clock: 8.79% held and 17.78% unheld at 5000
  streams on one worker. The recorded 13.39% lies between.
- Everything else was not clock-inflated. The storms keep the server busy,
  and base takes 12 to 13% longer held than recorded. F1 at K=2 and K=20,
  shared_read and get_clients read the same held and unheld, and
  lock_contention's throughput moves by under 30% (see F3). Every ratio in the
  sections below holds.
- Two verdicts change for 0.14.0, and neither is the clock. The F4 regression
  check fails: the single-worker fan-out costs more per context than base. The
  F2 shutdown regression no longer reproduces.

### F1 broadcast_storm on held clocks

Defaults (N=1000, K=200, concurrency 50, 1 worker) and `--n=5000 --k=500
--concurrency=50`, 3 reps each:

| Metric | Base | 0.14.0 | Change | Recorded, base / 70d23a5 |
|---|---|---|---|---|
| storm to converge (ms) | 1487 (1485.6 to 1518.7) | 34.331 (33.495 to 34.557) | 43.3x lower | 1320.407 / 46.889 |
| converge after last send (ms) | 370.4 (368.6 to 380.5) | 23.648 (22.759 to 23.881) | 15.7x lower | 323.747 / 21.534 |
| action latency p50 (ms) | 371.2 (370 to 378.3) | 0.504 (0.494 to 0.509) | 737x lower | 323.461 / 1.113 |
| action latency p99 (ms) | 376.4 (373.6 to 383.6) | 9.12 (9.109 to 9.154) | 41.3x lower | 333.735 / 20.448 |
| actions/s | 134.5 (131.7 to 134.6) | 17876.2 (17787.5 to 17884.3) | 133x higher | 151.5 / 7748.4 |
| worker CPU net of idle (s) | 1.452 (1.452 to 1.482) | 0.01 (0.01 to 0.03) | 1 to 3 ticks | 1.254 / 0.03 |
| master CPU (s) | 1.24 (1.23 to 1.24) | 0.02 (0.01 to 0.02) | 62x lower | 1.03 / 0.03 |
| storm to converge, N=5000 (ms) | 20290 (20120.2 to 20642.1) | 113.1 (111.4 to 113.6) | 179x lower | 18115.591 / 119.961 |
| converge after last send, N=5000 (ms) | 2033.5 (2002.2 to 2035.5) | 49.197 (48.099 to 49.576) | 41.3x lower | 1891.457 / 49.759 |
| action latency p99, N=5000 (ms) | 2090 (2038.3 to 2101.1) | 59.367 (58.836 to 59.577) | 35.2x lower | 1888.692 / 65.056 |
| worker CPU net of idle, N=5000 (s) | 18.399 (18.357 to 18.823) | 0.1 (0.1 to 0.11) | 184x lower | 13.668 / 0.11 |
| master CPU, N=5000 (s) | 15.97 (15.96 to 15.97) | 0.08 (0.08 to 0.08) | 200x lower | not recorded |

Renders and frames per client match the recorded runs in both trees (200000
against 2000 renders at the defaults). Base takes 12 to 13% longer than
recorded at both sizes. Its worker and its reactor threads are both busy and share the two
pinned cores at 4.5 GHz, where the recorded runs were not pinned. 0.14.0 is
within 10% of the recorded branch or faster, and its CPU at the defaults is 1
to 3 clock ticks.

Low load with one actor (`--concurrency=1`), N=1000 on 1 worker, 5 reps held
and 5 unheld; N=5000 with `--converge-timeout=60 --watchdog=240`, 3 reps held:

| Metric | Base | 0.14.0 | Change | Unheld, base / 0.14.0 | Recorded, base / 70d23a5 |
|---|---|---|---|---|---|
| converge after last send, K=1 (ms) | 7.975 (7.755 to 8.23) | 9.181 (9.032 to 9.953) | +15.1% | 9.048 / 12.291 | 18.545 / 26.326 |
| action latency, K=1 (ms) | 8.025 (7.802 to 8.281) | 0.532 (0.429 to 0.639) | 15.1x lower | 9.099 / 0.936 | 18.612 / 3.706 |
| converge after last send, K=2 (ms) | 7.943 (7.591 to 8.151) | 33.898 (33.725 to 34.898) | 4.27x higher | 7.034 / 33.56 | 6.984 / 46.155 |
| storm to converge, K=2 (ms) | 16.077 (15.494 to 16.404) | 34.425 (34.174 to 35.357) | 2.14x higher | 15.159 / 34.286 | 19.159 / 50.125 |
| action latency p99, K=2 (ms) | 8.089 (7.891 to 8.329) | 9.733 (8.826 to 13.611) | +20.3% | 7.794 / 8.083 | 12.425 / 21.285 |
| converge after last send, K=20 (ms) | 7.561 (7.415 to 7.825) | 24.153 (23.443 to 25.303) | 3.19x higher | 7.496 / 23.832 | 6.614 / 30.69 |
| storm to converge, K=20 (ms) | 153.3 (150.8 to 154.5) | 33.937 (33.765 to 34.882) | 4.52x lower | 149.4 / 34.061 | 166.784 / 56.014 |
| action latency p50, K=20 (ms) | 7.522 (7.476 to 7.693) | 0.025 (0.025 to 0.038) | 301x lower | 7.173 / 0.023 | 6.839 / 0.024 |
| action latency p99, K=20 (ms) | 8.256 (8.035 to 9.635) | 8.689 (8.593 to 9.478) | +5.2%, ranges overlap | 8.942 / 9.096 | 26.725 / 22.465 |
| converge after last send, K=2, N=5000 (ms) | 37.981 (37.75 to 39.732) | 110.3 (102.8 to 110.5) | 2.90x higher | not run | 35.339 / 117.484 |
| storm to converge, K=2, N=5000 (ms) | 81.487 (81.275 to 84.689) | 118.5 (110.5 to 119.1) | +45.4% | not run | 91.291 / 121.035 |
| action latency p99, K=2, N=5000 (ms) | 43.518 (41.748 to 46.7) | 54.218 (50.723 to 55.702) | +24.6% | not run | 53.356 / 62.689 |

K=1 is the one row the held clock changes in kind. The recorded figures were
2 to 3 times the held ones in both trees, and their ranges overlapped. Held,
0.14.0 converges 1.2 ms later than base, every 0.14.0 rep above every base
rep; unheld it is 36% later. The 1.2 ms is close to what the slower
per-context fan-out (F4 below) adds to a 1000-context flush, but that link is
an inference, not measured.

K=2 and K=20 are tick-bound and read the same held and unheld, so their
regression holds. It is 34 ms at K=2, against the recorded 46 ms: a
1000-context flush takes 9 to 10 ms on 0.14.0, and the follow-up update waits
for the rest of the first flush, half a tick (12.5 ms), and its own flush. The
recorded p99s at K=20 (26.725 and 22.465 ms) came from host noise; held, both
trees sit at 8 to 9 ms. At N=5000 the regression holds at 2.90x.

The 4-worker storms (default size and N=5000), K=2 with 4 workers and the
route and signal variants were not rerun.

### F2 idle_sse on held clocks

N=5000 with a 15 s window (`--n=5000 --idle=15 --settle=3`), 1 worker with 5
reps held and 3 unheld, 4 workers with 3 reps held:

| Metric | Base | 0.14.0 | Change | Unheld, base / 0.14.0 | Recorded, base / 5a9850a |
|---|---|---|---|---|---|
| worker CPU, 1 worker (%) | 8.79 (8.79 to 8.99) | 0.2 (0.13 to 0.27) | 44.0x lower | 17.78 / 0.33 | 13.39 / 0.33 |
| wakeups/s, 1 worker | 621.4 (601.1 to 637) | 22.1 (20.5 to 22.1) | 28.1x lower | 602.9 / 22.7 | 594.7 / 42.5 |
| broadcast to all connections, 1 worker (ms) | 45.22 (44.32 to 46.32) | 48.69 (47.68 to 49.57) | +7.7% | 50.26 / 53.58 | 60.9 / 55.02 |
| shutdown, 1 worker (ms) | 131.2 (128.3 to 133.8) | 131.8 (129.6 to 137.4) | +0.5%, ranges overlap | 133 / 132.6 | 155.55 / 181.09 |
| worker RSS, 1 worker (MB) | 205.1 (204.1 to 210.8) | 224.9 (224.3 to 225) | +9.7% | 205.5 / 224.6 | 210.1 / 230.3 |
| master CPU, 1 worker (%) | 0 (0 to 0) | 0.13 (0.13 to 0.2) | new cost | 0 / 0.27 | 0 / 0.33 |
| worker CPU, 4 workers summed (%) | 15.45 (14.71 to 15.65) | 0.07 (0.07 to 0.2) | 1 to 3 ticks | not run | 25.5 / 0.47 |
| wakeups/s, 4 workers | 2070.4 (2048.7 to 2075.8) | 62.4 (62 to 63.3) | 33.2x lower | not run | 2007 / 102.6 |
| broadcast to all connections, 4 workers (ms) | 52.96 (49.19 to 54.16) | 33.74 (30.11 to 42.63) | -36.3% | not run | 60.45 / 45.8 |
| shutdown, 4 workers (ms) | 127.5 (113.9 to 132) | 132.3 (125.2 to 167.5) | +3.8%, ranges overlap | not run | 127.76 / 141.43 |
| worker RSS, 4 workers summed (MB) | 420.9 (417.6 to 423.4) | 428.6 (428 to 429.2) | +1.8% | not run | 420.3 / 431.5 |

Base's idle CPU follows the clock, and its recorded figures lie between the
held and unheld ones, so the recorded base CPU in F2 is superseded by this
table. The ratio holds: 44x held and 54x unheld with 1 worker. 0.14.0's 0.20%
is 3 clock ticks per window, and with 4 workers it is 1 tick, so no factor is
given there. The shutdown regression from the F2 regression checks does not
reproduce: with 1 worker the two trees are 0.6 ms apart, and with 4 workers
the ranges overlap. The broadcast to all connections with 1 worker went from
9.7% faster than base to 7.7% slower, ranges apart, held and unheld alike: the
per-context fan-out cost of F4 below.

The 4-worker servers ran on cores 2, 4, 6 and 8 with four busy loops, which
held them at a median of only 4.2 to 4.3 GHz (3.8 GHz at the 10th percentile),
with the client unheld on core 10. N=2000 was not rerun.

### F3 lock_contention on held clocks

W=4, default sweep (`--reps=1 --timeout=300`), 5 reps held on cores 2, 4, 6
and 8, and 2 reps unheld:

| Metric | Base | 0.14.0 | Change | Recorded, base / 5a9850a |
|---|---|---|---|---|
| ops/s, global, C=1 | 138554 (126030 to 148992) | 293307 (254456 to 314672) | 2.12x higher | 113183 / 244100 |
| ops/s, global, C=8 | 91148 (87059 to 96120) | 287749 (235543 to 296131) | 3.16x higher | 72258 / 261949 |
| ops/s, global, C=32 | 52597 (51567 to 55258) | 304278 (257053 to 333111) | 5.79x higher | 42494 / 265524 |
| ops/s, signal, C=1 | 151826 (149527 to 167884) | 258442 (238540 to 305492) | +70.2% | 142530 / 239609 |
| ops/s, signal, C=32 | 54827 (51143 to 56828) | 311201 (269999 to 325834) | 5.68x higher | 43377 / 273093 |
| CPU per op, global, C=32 (us) | 74.12 (70.39 to 75.84) | 13.03 (11.69 to 14.16) | 5.69x lower | 94.06 / 14.77 |
| CPU per op, signal, C=32 (us) | 71.4 (68.97 to 76.67) | 12.58 (12.09 to 14.38) | 5.68x lower | 92.18 / 13.71 |
| handoff, global, C=32 (us) | 16.67 (15.92 to 17.02) | 1.56 (1.38 to 2.07) | 10.7x lower | 20.11 / 1.44 |
| handoff, signal, C=32 (us) | 15.59 (15 to 16.6) | 1.5 (1.41 to 1.88) | 10.4x lower | 19.48 / 1.2 |
| latency p99, global, C=32 (us) | 5884.8 (5731.1 to 5965.1) | 530.4 (474.5 to 546.3) | 11.1x lower | 4567.4 / 684 |
| ops/s ratio C=32 to C=1, global | 0.38 (0.367 to 0.426) | 1.022 (0.861 to 1.309) | | 0.375 / 1.088 |

Spinning waiters keep all four cores busy, so the clock moves little: unheld,
from 2 reps, throughput reads within 12% of these figures for 0.14.0 and
within 29% for base. Base's p99 latency is the exception, 2594.5 us unheld
against 5884.8 us held; the cause was not isolated. Both trees are faster than
recorded, and the ratios hold. W=8, W=16 and `--ops=100000` were not rerun.

### F4 shared_read on held clocks

Defaults (N=2000, S=5, 20 broadcasts), 5 reps held on core 2 and 2 unheld:

| Metric | Base | 0.14.0 | Change | Recorded, base / 70d23a5 |
|---|---|---|---|---|
| store reads per broadcast, tab | 20000 | 5 | 4000x fewer | 20000 / 5 |
| store reads per broadcast, route | 10005 | 5 | 2001x fewer | 10005 / 5 |
| with store, tab (ms per broadcast) | 50.158 (49.881 to 51.064) | 24.202 (24.013 to 24.687) | 2.07x lower | 48.8 / 21.64 |
| with store, route (ms per broadcast) | 21.442 (21.091 to 22.028) | 6.464 (6.319 to 6.781) | 3.32x lower | 20 / 4.466 |
| no store, tab (ms per broadcast) | 20.556 (20.406 to 21.044) | 21.812 (21.788 to 22.441) | +6.1% | 19.67 / 19.35 |
| no store, route (ms per broadcast) | 4.094 (4.006 to 4.22) | 5.809 (5.657 to 6.04) | +41.9% | 3.767 / 3.726 |
| peak memory (MB) | 78 | 54 | -30.8% | 78 / 44 |

The F4 regression check fails on 0.14.0. Without a store, the single-worker
fan-out is 6.1% slower than base in tab mode and 41.9% slower in route mode,
ranges apart, where 70d23a5 ran at base speed. Unheld runs read the same. In
the same session, without a store, 70d23a5 measured 20.61 ms (tab) and
4.05 ms (route), v0.13.1 21.51 and 5.13 ms, and 718e51c 22.08 and 5.72 ms. A
bisect on unheld efficiency cores, which gives relative numbers only, puts the
route slowdown on 147b473 (about +18%, measured together with a6765a2, whose
code shared_read does not run), 0b9aa92 (+9%), 3a965e6 (+4%) and 30218af
(+8%). The store path pays the same per-context cost: 24.202 and 6.464 ms
against the recorded 21.64 and 4.466 ms.

The larger configurations (`--contexts=5000`, `--view-reads=3
--array-items=100`) were not rerun.

### F5 get_clients on held clocks

Default sweep (`--n=100,1000,5000 --fanout-cap=1000 --broadcasts=3
--timeout=300`), 3 reps held on core 2, and 3 unheld reps of 0.14.0:

| Metric | Base | 0.14.0 | Change | Recorded, base / 5a9850a |
|---|---|---|---|---|
| shared, N=1000, call after one new client (ms) | 4.533 (4.36 to 4.767) | 0.377 (0.372 to 0.384) | 12.0x lower | 4.5207 / 0.3762 |
| shared, N=5000, call after one new client (ms) | 23.049 (22.893 to 23.125) | 2.225 (2.174 to 2.307) | 10.4x lower | 22.4998 / 2.0677 |
| shared, N=100, broadcast to 100 contexts (ms) | 47.3 (45.8 to 47.51) | 0.26 (0.26 to 0.28) | 182x lower | 47.07 / 0.23 |
| shared, N=1000, broadcast to 1000 contexts (ms) | 4598 (4590.8 to 4602.6) | 2.5 (2.41 to 2.63) | 1839x lower | 4619.9 / 2.17 |
| shared, N=5000, broadcast to 1000 contexts (ms) | 23422.8 (23400.1 to 23427.1) | 4.35 (4.05 to 4.43) | 5385x lower | 22844.46 / 4.13 |
| single, N=1000, broadcast to 1000 contexts (ms) | 108.2 (106.9 to 110.3) | 1.92 (1.92 to 2.11) | 56.4x lower | 105.43 / 1.66 |
| peak memory (MB) | 38 | 28 | -26.3% | 38 / 26 |

Base is within 3% of its recorded values and unheld 0.14.0 reads the same, so
nothing here was clock-inflated, and the rebuild ratios hold. The broadcasts
to 100 and 1000 contexts take 13 to 16% longer on 0.14.0 than on the recorded
branch, the per-context cost from F4. The single-mode full fan-out and the
cold reads were not rerun.

## perf/contention, measured 2026-09-29 and 2026-09-30 (partly superseded)

**Partly superseded by the two 0.14.0 sections above.** These runs did not hold the
clock. Cores that idled between requests clocked down, so the low-rate
figures read high: F1 at K=1 by 2 to 3 times in both trees, and base's idle
CPU in F2. The held reruns also find a small K=1 regression where the F1
regression checks below find none, no F2 shutdown regression, and an F4
regression check that fails on 0.14.0. The storm, contention, shared-read and
getClients figures and every ratio still hold.

Before and after measurements for the five contention findings (F1 to F5)
fixed on `perf/contention`. Base (A) is 737b2aa: the v0.13.0 release (5ff9d25)
plus the scripts in this directory. Branch (B) is 70d23a5 in the F1 and F4
tables marked so and in both their regression checks, and 5a9850a everywhere
else. The base worktree ran the branch's copies of the scripts, which differ
from 737b2aa only in comments and code style, so the scripts are byte-identical
in both trees and every run is `php bench/contention/<name>.php` from inside
the tree under test. The lock_contention, idle_sse and shared_read JSON records
the commit each run ran on, with `src/` clean in every run; broadcast_storm and
get_clients do not record it, and their runners pick the tree by working
directory. The two branch commits not covered below (197c8d7, 5a9850a) touch
only the changelog and the benchmark code style. Two later commits change
`src/`: 5e38da9 (when a broadcast flush starts) and 70d23a5 (the fan-out loop).
F2, F3 and F5 were not rerun on them.

Host: 20 cores, shared with other projects that run browser tests on it. PHP
8.5.11 CLI (NTS) with opcache and JIT off except in Opcache and JIT,
ext-openswoole 26.2.0.

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

The 70d23a5 runs come from one later session. Unless a table says otherwise, B
and A ran interleaved as B1, A1, B2, A2, ..., with identical arguments. The
load before each run was 0.68 to 5.96, so no run waited. The broadcast_storm
runner wrote each run's commit and `src/` state to a metadata file. Numbers
from different sessions are not comparable. The host runs the `powersave`
governor on a CPU with performance and efficiency cores, and the same
configuration (K=2 in the F1 regression checks) measured 9.229 ms on base and
43.452 ms on 5a9850a in the first session, against 6.984 ms on base and 59.846
and 65.997 ms on 5a9850a in two batches of the later one. Every comparison in
this file is within one session.

The branch always ran from /develop/php-via, a FUSE mount (`fuse.shfs`), and
base from a worktree on btrfs, with opcache off. In the later session 70d23a5
run from a btrfs worktree acknowledged the single action at K=1 in 1.24 ms
against 3.416 ms from /develop/php-via, and converged in 15.308 against
25.674 ms (medians of 5). This penalizes only the branch. It changes no
verdict below, but it inflates small branch latencies, K=1 in particular. Its
size was measured only with broadcast_storm.

Every run was correct in both trees. All 36 lock_contention invocations
reached the expected final value in every round. All 72 broadcast_storm runs
converged every client to the server value with no failed action. All 30
idle_sse runs delivered their broadcasts to every connection. All 40
shared_read runs had 0 mismatched frames, and all 40 get_clients runs had every
freshness and patch flag true. In the later session the same held for all 60
broadcast_storm and 30 shared_read runs against base and for all 129 runs of
the comparison with 5a9850a in the F1 regression checks.

## F1: broadcast coalescing (08d6449)

Inside a coroutine, `broadcast()`, scoped signal auto-broadcasts and broker
receives now only mark the scope. The worker's next flush renders each marked
scope once and each context once. Since 5e38da9 it starts one tick
(`Config::withBroadcastTickMs()`, default 25 ms) after the last flush started
and at least half a tick after that flush ended, or at the end of the
event-loop turn when both have passed; 08d6449 counted the tick from the end
of the last flush. Measured with `broadcast_storm.php`: N SSE clients on one
page, K actions fired over `concurrency` actor connections.

Defaults, `php bench/contention/broadcast_storm.php` (N=1000, K=200,
concurrency 50, 1 worker, tab mode, global state), 70d23a5, 3 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| renders | 200000 | 2000 | 100x fewer |
| frames per client | 200 | 2 | 100x fewer |
| worker CPU net of idle (s) | 1.254 (1.253 to 1.338) | 0.03 (0.03 to 0.04) | 41.8x lower |
| master CPU (s) | 1.03 (1.02 to 1.14) | 0.03 (0.02 to 0.04) | 34.3x lower |
| action latency p50 (ms) | 323.461 (322.549 to 339.56) | 1.113 (0.426 to 1.381) | 291x lower |
| action latency p99 (ms) | 333.735 (332.749 to 359.186) | 20.448 (14.373 to 23.811) | 16.3x lower |
| actions/s | 151.5 (143.7 to 152.5) | 7748.4 (7719.6 to 11277.1) | 51.1x higher |
| converge after last send (ms) | 323.747 (322.484 to 354.722) | 21.534 (17.905 to 23.076) | 15.0x lower |
| storm to converge (ms) | 1320.407 (1311.08 to 1391.819) | 46.889 (35.038 to 47.592) | 28.2x lower |

CPU comes from `/proc` at 10 ms clock-tick resolution. At the default size the
branch's CPU is 1 to 10 ticks, so the CPU ratios give the order of magnitude,
not a precise factor. The N=5000 runs are the only ones where branch CPU sits
well above resolution.

Larger storm, `--n=5000 --k=500 --concurrency=50 --converge-timeout=60
--watchdog=240`, with 1 worker and with `--workers=4`, 70d23a5, 3 reps each:

| Metric | Base | Branch | Change |
|---|---|---|---|
| frames per client, 1 worker | 500 | 2 | 250x fewer |
| frames per client, 4 workers | 500 | 2.75 (2.75 to 3.25) | 182x fewer |
| worker CPU net of idle, 1 worker (s) | 13.668 (13.429 to 13.85) | 0.11 (0.1 to 0.14) | 124x lower |
| worker CPU net of idle, 4 workers (s) | 22.227 (19.459 to 22.661) | 0.29 (0.26 to 0.5) | 76.6x lower |
| action latency p99, 1 worker (ms) | 1888.692 (1852.223 to 1944.149) | 65.056 (59.871 to 77.278) | 29.0x lower |
| action latency p99, 4 workers (ms) | 1728.628 (1095.086 to 2086.508) | 55.887 (28.88 to 87.874) | 30.9x lower |
| converge after last send, 1 worker (ms) | 1891.457 (1781.402 to 1894.742) | 49.759 (47.162 to 49.949) | 38.0x lower |
| converge after last send, 4 workers (ms) | 908.656 (893.373 to 1699.84) | 49.984 (39.448 to 71.476) | 18.2x lower |
| storm to converge, 1 worker (ms) | 18115.591 (17779.905 to 19094.633) | 119.961 (111.422 to 135.588) | 151x lower |
| storm to converge, 4 workers (ms) | 6114.461 (5965.124 to 6572.856) | 96.39 (91.188 to 135.968) | 63.4x lower |

The 4-worker runs had the highest load of the session, 2.83 to 5.96. A later
batch in the same session, at a load of 1.7 to 2.49, measured storm to
converge at 62.004 (60.135 to 79.908) ms on 70d23a5 against 125.954 (105.435
to 163.261) ms on 5a9850a, so the 96.39 ms median above does not show a
regression from 5e38da9.

Other variants at the default size, 5a9850a in the first session, 3 reps each:

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
workers the branch sends 2.75 to 3.25 frames per client instead of 2.

On the branch all K actions are sent within 7 to 90 ms, 1 to 4 ticks, because
an action no longer waits for its fan-out. Two frames per client therefore
means the whole storm fit into two flushes. It does not show how the branch
handles a paced stream of updates over several seconds, which this script
cannot generate.

### F1 regression checks

Low load with one actor (`--concurrency=1`), N=1000 on 1 worker unless marked,
70d23a5, 5 reps (3 at N=5000 and with 4 workers). The N=5000 runs used
`--converge-timeout=60 --watchdog=240`.

| Metric | Base | Branch | Change |
|---|---|---|---|
| converge after last send, K=1 (ms) | 18.545 (12.383 to 34.316) | 26.326 (16.63 to 31.156) | +42.0%, ranges overlap |
| action latency, K=1 (ms) | 18.612 (12.435 to 34.403) | 3.706 (2.878 to 4.834) | 5.02x lower |
| converge after last send, K=2 (ms) | 6.984 (6.728 to 8.74) | 46.155 (45.549 to 56.083) | 6.61x higher |
| storm to converge, K=2 (ms) | 19.159 (14.375 to 40.857) | 50.125 (49.256 to 59.777) | 2.62x higher |
| action latency p99, K=2 (ms) | 12.425 (7.385 to 32.104) | 21.285 (18.529 to 32.3) | +71.3%, ranges overlap |
| converge after last send, K=20 (ms) | 6.614 (6.549 to 7.015) | 30.69 (24.642 to 32.446) | 4.64x higher |
| storm to converge, K=20 (ms) | 166.784 (144.069 to 176.722) | 56.014 (50.5 to 71.144) | 2.98x lower |
| action latency p50, K=20 (ms) | 6.839 (6.556 to 7.075) | 0.024 (0.022 to 0.065) | 285x lower |
| action latency p99, K=20 (ms) | 26.725 (13.924 to 28.342) | 22.465 (21.855 to 34.126) | -15.9%, ranges overlap |
| converge after last send, K=2, N=5000 (ms) | 35.339 (34.785 to 37.928) | 117.484 (116.072 to 126.695) | 3.32x higher |
| storm to converge, K=2, N=5000 (ms) | 91.291 (74.647 to 97.79) | 121.035 (120.764 to 127.538) | +32.6% |
| action latency p99, K=2, N=5000 (ms) | 53.356 (39.301 to 62.995) | 62.689 (62.572 to 71.972) | +17.5%, ranges overlap |
| converge after last send, K=2, 4 workers (ms) | 12.134 (6.966 to 16.821) | 33.332 (29.494 to 37.623) | 2.75x higher |
| storm to converge, K=2, 4 workers (ms) | 22.474 (12.601 to 26.696) | 34.211 (30.504 to 41.447) | +52.2% |

This check fails at K=2, at both sizes and with 4 workers, and at K=20. At
5a9850a, in the first session, K=2 and K=20 converged 43.452 and 45.474 ms
after the last send against 9.229 and 8.015 ms on base, and 5e38da9, which was
meant to fix that, does not make the check pass. K=1 shows no measurable
regression but is not a confident pass: the ranges overlap, and run from btrfs
like base (see Protocol) the branch took 15.308 (8.35 to 22.675) ms against
12.958 (7.347 to 16.307) ms, still overlapping. On held clocks 0.14.0 converges
1.2 ms (15%) later than base at K=1, with the ranges apart (see the 0.14.0
section). At K=20 the branch converges 4.64x later after the last send, while
the whole storm still converges 2.98x sooner, because base renders every
action (20000 renders against 2000).

Since 5e38da9, `msUntilNextTick()` in `src/Via.php` starts the next flush one
tick after the last one started and at least half a tick (12.5 ms) after it
ended. At K=2 the second update lands while the first flush runs, so it waits
for the rest of that flush, then half a tick, then its own flush. The worker
cannot handle the second action before the first flush ends, so its latency,
the branch action p99 at K=2 (21.285 ms), is about the length of that flush.
Whenever a flush lasts longer than half a tick, the half-tick floor and not
the tick sets the next start, so the delay still grows with N: 3.32x base at
N=5000, where the second action waited 62.689 ms. With 4 workers each worker
flushes about 250 contexts, the tick from the last start decides, and what
remains is about one tick: 33.332 ms against 12.134 ms on base. The existing
knobs are `withBroadcastTickMs()` and `withBroadcastCoalescing(false)`.

Same-session comparison with 5a9850a (P), the commit before both fixes:
converge after last send in ms, 5 reps (3 at N=5000), interleaved B, H, P, A
at a load of 0.68 to 2.47. H is 70d23a5 run from a btrfs worktree, like P; B
ran from /develop/php-via as everywhere else.

| Config | Base | P (5a9850a) | B (70d23a5) | H (70d23a5, btrfs) |
|---|---|---|---|---|
| K=1 | 12.958 (7.347 to 16.307) | 24.054 (23.418 to 27.219) | 25.674 (17.307 to 26.99) | 15.308 (8.35 to 22.675) |
| K=2 | 6.955 (6.774 to 21.722) | 65.997 (61.566 to 71.617) | 49.094 (39.248 to 50.911) | 46.411 (40.833 to 54.927) |
| K=20 | 6.88 (6.659 to 10.194) | 44.208 (32.826 to 52.955) | 31.494 (25.512 to 35.469) | 22.68 (18.339 to 27.15) |
| K=2, N=5000 | 35.982 (35.911 to 36.62) | 111.068 (110.477 to 123.382) | 108.493 (98.66 to 118.445) | 100.204 (96.822 to 121.836) |

An earlier batch without H (B, P, A) gave B 45.999 against P 59.846 ms at K=2,
25.532 against 41.777 ms at K=20, and 104.622 against 134.629 ms at N=5000
(3 reps, no base). So the fixes shorten the follow-up delay against P, by 14
to 20 ms at K=2 and 13 to 22 ms at K=20 across both batches and both B and H.
At N=5000 the gain was 30 ms without overlap in the first batch and 3 to 11 ms
with overlapping ranges in the second. At K=1 all variants overlapped in both
batches.

A flush does not yield between contexts, so a request that lands on its worker
while it runs waits for it. Branch action p99 is 65.056 ms at N=5000 on one
worker and 55.887 ms at N=5000 on 4 workers (1250 contexts each). Base ran the
same non-yielding fan-out inside the broadcasting action and was at 1888.692 ms
and 1728.628 ms, so this is not a regression: the cost moved from the action
that broadcasts to whichever request arrives during the flush.

CPU at K=1 and K=2 with N=1000 on one worker is 1 to 5 clock ticks in both
trees, so the two cannot be told apart there. Branch idle worker CPU is 0 in
every configuration against 0.04 to 0.534 s/s on base (that is F2).
`worker_cpu_net_s` subtracts the idle rate measured in the same run, so F2 does
not inflate the F1 CPU numbers. F4 cannot be isolated with this script, since
coalescing already cuts a storm to 2 or 3 flushes per worker.

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
shutdown. On held clocks 0.14.0 shuts down as fast as base (see the 0.14.0
section).

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
tab and route modes, remote writer), 70d23a5, 5 reps:

| Metric | Base | Branch | Change |
|---|---|---|---|
| store reads per broadcast, tab | 20000 | 5 | 4000x fewer |
| store reads per broadcast, route | 10005 | 5 | 2001x fewer |
| with store, tab (ms per broadcast) | 48.8 (48.26 to 48.84) | 21.64 (21.29 to 22.82) | 2.26x lower |
| with store, route (ms per broadcast) | 20 (19.93 to 20.68) | 4.466 (4.349 to 4.564) | 4.48x lower |
| store overhead over no store, tab (%) | 147.1 (146.1 to 148.3) | 11.73 (11.16 to 12.29) | |
| store overhead over no store, route (%) | 429.9 (425.7 to 447.8) | 19.24 (17.66 to 21.03) | |
| no store, tab (ms per broadcast) | 19.67 (19.51 to 19.84) | 19.35 (19.16 to 20.42) | -1.6%, ranges overlap |
| no store, route (ms per broadcast) | 3.767 (3.637 to 3.933) | 3.726 (3.648 to 3.805) | -1.1%, ranges overlap |
| peak memory (MB) | 78 | 44 | -43.6% |

Larger configurations, 70d23a5, 5 reps each. The
`--view-reads=3 --array-items=100` rows come from a separate A/B run shortly
before the others, with the order alternating per rep, at a load of 1.41 to
1.73:

| Metric | Base | Branch | Change |
|---|---|---|---|
| with store, route, `--modes=route --contexts=5000 --broadcasts=30` (ms) | 44.28 (43.75 to 46.79) | 11.24 (11.11 to 11.9) | 3.94x lower |
| store reads, route, N=5000 | 25005 | 5 | 5001x fewer |
| peak memory, N=5000 (MB) | 146 | 54 | 2.70x lower |
| with store, tab, `--view-reads=3 --array-items=100` (ms) | 397.9 (396.1 to 400.9) | 203.6 (202.2 to 205) | -48.8% |
| with store, route, `--view-reads=3 --array-items=100` (ms) | 73.61 (73.4 to 74.23) | 15.55 (15.51 to 16.12) | 4.73x lower |
| store reads, tab, `--view-reads=3 --array-items=100` | 40000 | 5 | 8000x fewer |
| peak memory, `--view-reads=3 --array-items=100` (MB) | 318 | 108 | 2.94x lower |

`ns_per_store_read_est` is meaningless on the branch: it divides the overhead by
the base read count, which the branch no longer performs.

### F4 regression checks

At 5a9850a the fan-out without a store, the single-worker path, was 4.7 to
20.7% slower than base in route mode and up to 4.5% slower in tab mode, and
70d23a5 fixed that. Every context of a fan-out paid for the frame-ordering
check, with or without a store: a read epoch lookup, and a read and a write of
a `WeakMap`. 70d23a5 keeps the newest frame epoch on each `Context` as an int,
and a fan-out pass looks its read epoch up once, and again only after
`ReadEpochs::renew()` moved it. The check still runs without a store, since it
also orders the frames of single-worker coroutines whose views yield.

Without a store, from the runs above:

| Metric | Base | Branch | Change |
|---|---|---|---|
| no store, tab, defaults (ms) | 19.67 (19.51 to 19.84) | 19.35 (19.16 to 20.42) | -1.6%, ranges overlap |
| no store, route, defaults (ms) | 3.767 (3.637 to 3.933) | 3.726 (3.648 to 3.805) | -1.1%, ranges overlap |
| no store, route, N=5000 (ms) | 9.345 (9.246 to 9.784) | 9.257 (9.177 to 9.907) | -0.9%, ranges overlap |
| no store, tab, `--view-reads=3 --array-items=100` (ms) | 198.5 (198.2 to 200.9) | 197.2 (195.3 to 198.8) | -0.7%, ranges overlap |
| no store, route, `--view-reads=3 --array-items=100` (ms) | 14.72 (14.55 to 14.81) | 14.6 (14.48 to 15.2) | -0.8%, ranges overlap |

N=5000 is `--modes=route --contexts=5000 --broadcasts=30`. This check passes:
without a store the branch is 0.7 to 1.6% faster than base, and the ranges
overlap in every configuration. Store reads stay at S=5 per broadcast, and
`mismatched_frames` was 0 in every run, so no stale value reached a frame. On
0.14.0 the check fails: 6.1% slower in tab mode and 41.9% in route mode (see
the 0.14.0 section).

The store path on the branch still costs more than no store: +11.73% in tab
mode (2.286 ms at N=2000), +19.24% in route mode (0.702 ms), +20.8% in route
mode at N=5000 (1.957 ms), and +3.12% (tab) and +6.82% (route) with three view
reads. Divided by the accessor calls (the base read count), the residual is 70
to 114 ns per call in every configuration but one, tab mode with three view
reads and 100-item arrays, where it is about 153 ns. It does not follow the
store reads, which are S=5 per broadcast in every run. That the per-call cost
is the `readEpoch()` check in `getValue()` is an inference, not measured.
`SharedSignalStore::get()` micro timings are within noise of base, with
overlapping ranges.

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

- F1: an update that lands during a flush waits for the rest of it, then half
  a tick, then its own flush. At N=1000 on one worker it reached every client
  46.155 ms (K=2) and 30.69 ms (K=20) after the last send, against 6.984 and
  6.614 ms on base; at N=5000, 117.484 against 35.339 ms; with 4 workers,
  33.332 against 12.134 ms. The delay still grows with the contexts per
  worker.
- F2: shutdown with 5000 open streams takes 10.7 to 16.4% longer (the ranges
  overlap), worker RSS grows by 3 to 4 KB per connection with one worker, and
  the 15 s keep-alive adds master and client CPU that base does not have.
- F5: a cold read of the client list is 3.5 to 4.1% slower at N=100 and
  N=1000 (at N=1000 the ranges overlap), and each worker keeps its client
  snapshot in memory.

Moved, not worse:

- F1: a running flush blocks the requests that land on its worker (branch
  action p99 65.056 ms at N=5000 on one worker). Base paid the same fan-out
  inside the broadcasting action (1888.692 ms).

Unchanged:

- F3: waiters still spin, so one hot key costs W cores, and its throughput
  still falls as W grows (branch C=1 global: 28526 ops/s at W=16).
- F4: the fan-out without a store, the single-worker path, runs at base speed:
  0.7 to 1.6% faster, with overlapping ranges in every configuration.

Not measured:

- All benchmarks: every view was a cheap PHP closure (broadcast_storm: 512
  bytes, `--render-us=0`). Heavier views make a flush longer, which lengthens
  both F1 effects above. Opcache and JIT were off in the F sections; Opcache
  and JIT below covers them for one or two configurations per benchmark.
- F1: a paced stream of updates over several seconds. The script sends all K
  actions at once.
- The F sections, except the H runs in the F1 regression checks: the branch on
  the same filesystem as base. See Protocol. Opcache and JIT ran both trees on
  btrfs.
- F2, F3 and F5 on 5e38da9 and 70d23a5. Those sections are from 5a9850a.
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

## Opcache and JIT

The F sections ran with opcache and JIT off. This section repeats one or two
configurations of each benchmark under four opcache and JIT settings. It comes
from a later session (2026-09-30, 06:44 to 08:53 UTC) on other commits in both
trees, with both trees on btrfs, so its absolute numbers are not comparable
with any table above. Base idle worker CPU at 5000 connections on one worker
with opcache off is 25.11% here and 13.39% in F2. Compare within this section
only.

### Trees, settings and protocol for the opcache and JIT runs

Base (A) is 8405dbc, the v0.13.0 release commit on master and the branch's
merge base. It adds d286954 (shared signal rows keyed by scope) to the 5ff9d25
base used above. Branch (B) is 3c1070d plus the lock_contention read-back fix
from b4c4ad0, which changes only `bench/contention/lock_contention.php`. Both
trees are worktrees on the same btrfs filesystem with byte-identical copies of
the scripts, and every run is `php bench/contention/<name>.php` from inside the
tree. lock_contention, idle_sse and shared_read record the commit in their
JSON, with `src/` clean in every run.

Each setting is one extra ini directory in `PHP_INI_SCAN_DIR`, which every
forked worker, server and client process inherits:

| Setting | ini values |
|---|---|
| off | `opcache.enable_cli=0`, `opcache.jit=disable` |
| opcache | `opcache.enable=1`, `opcache.enable_cli=1`, `opcache.jit=disable`, `opcache.validate_timestamps=0`, `opcache.memory_consumption=256` |
| jit-tracing | as opcache, with `opcache.jit=tracing` and `opcache.jit_buffer_size=128M` |
| jit-function | as opcache, with `opcache.jit=function` and `opcache.jit_buffer_size=128M` |

A probe per benchmark read `opcache_get_status()` in the processes that do the
work, started the way the script starts them: the forked lock_contention
workers, the server workers of broadcast_storm and idle_sse, and the single
process of shared_read and get_clients. Each setting was in effect in both
trees. With off, opcache was disabled. With opcache, the project's `src/`
files were cached and JIT was off. Under jit-tracing the JIT buffer held
compiled traces after the work, and under jit-function it held 3.2 to 3.9 MB
of compiled code. broadcast_storm and shared_read also report the opcache and
JIT state in their JSON, get_clients reports the opcache state, and every run
matched its setting.

Host, PHP and OpenSwoole are the same as above. Each configuration ran 3 reps,
except the broadcast_storm low-load check, which ran 5. The broadcast_storm
4-worker, N=5000 and low-load configurations ran last, 08:42 to 08:53 UTC,
with the same runner and order, at a load of 2.04 to 5.37 before each run.
Within a rep the settings ran in the order off, opcache, jit-tracing,
jit-function, each as B then A with identical arguments, one run at a time.
The 1-minute load before a run was 0.78 to 5.80, except at lock_contention
W=16: each of those runs pushed the load to 8.71 to 12.47 with its own 16
spinning workers, so 23 of the 24 waited 30 s (one waited 60 s) and started at
5.15 to 7.47. The tables use the format described at the top of this file.

All 244 invocations exited 0 and were correct in both trees under every
setting, both JIT modes included, and none crashed. All 48 lock_contention
invocations reached the expected final value in all 192 rounds, with no error
or timeout. All 112 broadcast_storm runs (24 each for the default, 4-worker
and N=5000 storms, 40 for the low-load check) converged every client to the
server value with no failed action. All 36 idle_sse invocations (72 server
runs) connected all 5000 streams, delivered the broadcast to every one and shut
down without a timeout. All 24 shared_read runs had 0 mismatched frames, and
all 24 get_clients runs had every freshness and patch flag true.

### lock_contention under opcache and JIT

W=4, default sweep (`--reps=1 --timeout=300`: 20000 ops per worker, C=1, 8,
32), 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| ops/s, global, C=1 | off | 135091 (131255 to 146410) | 304322 (289173 to 309001) | 2.25x higher |
| ops/s, global, C=1 | opcache | 131092 (130582 to 132258) | 277528 (275577 to 320591) | 2.12x higher |
| ops/s, global, C=1 | jit-tracing | 108407 (102057 to 109190) | 226867 (219450 to 231865) | 2.09x higher |
| ops/s, global, C=1 | jit-function | 113504 (111381 to 118992) | 234203 (225754 to 235584) | 2.06x higher |
| ops/s, global, C=32 | off | 53856 (50522 to 54126) | 317559 (314416 to 319970) | 5.90x higher |
| ops/s, global, C=32 | opcache | 55281 (54220 to 59042) | 295217 (258598 to 300439) | 5.34x higher |
| ops/s, global, C=32 | jit-tracing | 59008 (55424 to 61299) | 265073 (256650 to 288775) | 4.49x higher |
| ops/s, global, C=32 | jit-function | 55273 (53860 to 58475) | 287670 (280720 to 288328) | 5.20x higher |
| CPU per op, global, C=32 (us) | off | 74.27 (73.88 to 79.1) | 12.46 (12.4 to 12.7) | 5.96x lower |
| CPU per op, global, C=32 (us) | opcache | 72.35 (67.72 to 73.69) | 13.53 (13.3 to 15.29) | 5.35x lower |
| CPU per op, global, C=32 (us) | jit-tracing | 67.73 (65.25 to 72.14) | 15.04 (13.83 to 15.54) | 4.50x lower |
| CPU per op, global, C=32 (us) | jit-function | 72.36 (68.4 to 74.26) | 13.89 (13.83 to 13.91) | 5.21x lower |
| ops/s, signal, C=1 | off | 163014 (149116 to 165100) | 241947 (226077 to 271523) | +48.4% |
| ops/s, signal, C=1 | opcache | 148341 (147603 to 152390) | 258537 (245424 to 282620) | +74.3% |
| ops/s, signal, C=1 | jit-tracing | 121741 (120831 to 121743) | 212239 (210999 to 219933) | +74.3% |
| ops/s, signal, C=1 | jit-function | 127183 (120619 to 128045) | 199981 (195488 to 207654) | +57.2% |
| ops/s, signal, C=32 | off | 57317 (54590 to 66607) | 307569 (270138 to 320946) | 5.37x higher |
| ops/s, signal, C=32 | opcache | 57745 (54492 to 60619) | 279382 (270498 to 325778) | 4.84x higher |
| ops/s, signal, C=32 | jit-tracing | 59084 (58235 to 60407) | 311476 (290569 to 316885) | 5.27x higher |
| ops/s, signal, C=32 | jit-function | 55978 (55385 to 60899) | 270228 (263674 to 291979) | 4.83x higher |

Both JIT modes slow the C=1 case in both trees, and no JIT range overlaps the
range with opcache off: ops/s drops by 12.3 to 25.5% and CPU per op rises by 15
to 34%. The time goes mostly between holders, not into the mutation.
Global-mode handoff at C=1 grows from 1.74 us (off) to 2.81 us (jit-tracing)
and 2.48 us (jit-function) on the branch and from 5.14 to 7.03 and 6.63 us on
base, while the median hold time stays within 1.56 to 1.76 us on the branch and
2.25 to 2.41 us on base; only the branch's jit-function hold (1.76 us against
1.56 us) is clear of its off range. The cause was not isolated. Base loses 16.0
to 25.3% of its C=1 throughput and the branch 12.3 to 25.5%, so the branch
still does 2.06x to 2.25x base's global throughput at C=1. At C=32 base gains
up to 9.6% (global, jit-tracing: 55424 to 61299 against 50522 to 54126 ops/s),
and the branch under jit-tracing does 16.5% fewer global ops/s than with
opcache off (256650 to 288775 against 314416 to 319970). With opcache alone it
already does 7.0% fewer (258598 to 300439), so that loss is not specific to
JIT. In signal mode at C=32 no range in either tree is clear of its off range.
`cpu_cores` stays at about 4 (W) in both trees under every setting, so waiters
still spin.

W=16, C=1 (`--workers=16 --coroutines=1 --ops=20000 --reps=1
--timeout=300`), 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| ops/s, global | off | 20179 (19616 to 21199) | 32587 (29791 to 33357) | +61.5% |
| ops/s, global | opcache | 20481 (19071 to 21533) | 33591 (30305 to 34114) | +64.0% |
| ops/s, global | jit-tracing | 20547 (20094 to 20608) | 31812 (30797 to 33730) | +54.8% |
| ops/s, global | jit-function | 20143 (16443 to 20575) | 32547 (31922 to 35692) | +61.6% |
| CPU per op, global (us) | off | 771.99 (752.39 to 802.78) | 480.65 (471.44 to 524.91) | -37.7% |
| CPU per op, global (us) | opcache | 779.39 (740.46 to 831.13) | 475.42 (464.12 to 517.23) | -39.0% |
| CPU per op, global (us) | jit-tracing | 774.78 (773.96 to 790.11) | 496.67 (471.55 to 503.61) | -35.9% |
| CPU per op, global (us) | jit-function | 782.61 (774.14 to 849.3) | 476.52 (445.13 to 488.06) | -39.1% |
| ops/s, signal | off | 20706 (19093 to 23440) | 30358 (28045 to 31992) | +46.6% |
| ops/s, signal | opcache | 21435 (18234 to 21644) | 31402 (26638 to 31590) | +46.5% |
| ops/s, signal | jit-tracing | 19405 (19113 to 20953) | 34576 (29871 to 36471) | +78.2% |
| ops/s, signal | jit-function | 18201 (17598 to 20131) | 34891 (34151 to 36423) | +91.7% |
| CPU per op, signal (us) | off | 770.58 (681.33 to 831.14) | 505.57 (488.47 to 534.64) | -34.4% |
| CPU per op, signal (us) | opcache | 735.42 (733.42 to 846.61) | 498.94 (492.33 to 524.68) | -32.2% |
| CPU per op, signal (us) | jit-tracing | 780.52 (761.08 to 831.27) | 461.77 (436.33 to 503.08) | -40.8% |
| CPU per op, signal (us) | jit-function | 846.74 (789.64 to 905.35) | 456.62 (437.34 to 461.94) | -46.1% |

The branch leads under every setting without overlap, by 54.8 to 64.0% in
global and 46.5 to 91.7% in signal ops/s. In signal mode the lead is largest
under JIT, with base medians lower (18201 and 19405 against 20706 ops/s) and
branch medians higher (34576 and 34891 against 30358). Only the branch's
jit-function range lies clear of its off range; the branch's jit-tracing range
and both base JIT ranges overlap their off ranges, so 3 reps do not show that
JIT widens the gap. Two runs had a worker descheduled (`cpu_cores` below 14 of
16) and are kept in the ranges: base jit-function rep 1 in global mode (16443
ops/s) and branch opcache rep 1 in signal mode (26638 ops/s).

### broadcast_storm under opcache and JIT

Defaults, `php bench/contention/broadcast_storm.php` (N=1000, K=200,
concurrency 50, 1 worker, tab mode, global state), 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| storm to converge (ms) | off | 1426.038 (1381.789 to 1521.236) | 40.153 (38.292 to 75.317) | 35.5x lower |
| storm to converge (ms) | opcache | 1284.946 (1213.331 to 1339.874) | 44.915 (42.587 to 51.802) | 28.6x lower |
| storm to converge (ms) | jit-tracing | 1064.268 (1043.523 to 1156.915) | 59.949 (52.736 to 64.08) | 17.8x lower |
| storm to converge (ms) | jit-function | 1143.299 (1077.496 to 1191.939) | 43.187 (35.357 to 48.613) | 26.5x lower |
| converge after last send (ms) | off | 342.476 (338.031 to 357.894) | 25.043 (16.953 to 26.524) | 13.7x lower |
| converge after last send (ms) | opcache | 336.154 (297.234 to 373.822) | 20.766 (20.665 to 21.82) | 16.2x lower |
| converge after last send (ms) | jit-tracing | 262.962 (255.621 to 280.713) | 19.181 (9.184 to 35.71) | 13.7x lower |
| converge after last send (ms) | jit-function | 279.764 (264.066 to 292.276) | 19.527 (18.51 to 23.067) | 14.3x lower |
| worker CPU net of idle (s) | off | 1.348 (1.303 to 1.463) | 0.02 (0.02 to 0.07) | 67.4x lower |
| worker CPU net of idle (s) | opcache | 1.224 (1.149 to 1.262) | 0.04 (0.03 to 0.04) | 30.6x lower |
| worker CPU net of idle (s) | jit-tracing | 1.021 (1.007 to 1.084) | 0.05 (0.05 to 0.06) | 20.4x lower |
| worker CPU net of idle (s) | jit-function | 1.114 (1.048 to 1.13) | 0.03 (0.02 to 0.04) | 37.1x lower |
| action latency p50 (ms) | off | 350.771 (337.56 to 355.988) | 1.387 (1.385 to 1.823) | 253x lower |
| action latency p50 (ms) | opcache | 309.672 (299.24 to 316.403) | 0.596 (0.499 to 0.896) | 520x lower |
| action latency p50 (ms) | jit-tracing | 258.199 (256.994 to 281.044) | 2.248 (0.904 to 7.387) | 115x lower |
| action latency p50 (ms) | jit-function | 281.108 (265.149 to 292.44) | 0.498 (0.37 to 1.305) | 564x lower |
| action latency p99 (ms) | off | 364.902 (345.471 to 452.058) | 9.878 (8.751 to 53.91) | 36.9x lower |
| action latency p99 (ms) | opcache | 336.838 (302.926 to 373.788) | 22.294 (19.902 to 27.196) | 15.1x lower |
| action latency p99 (ms) | jit-tracing | 273.563 (267.039 to 298.06) | 35.278 (24.386 to 38.106) | 7.75x lower |
| action latency p99 (ms) | jit-function | 289.346 (273.157 to 298.042) | 19.857 (15.74 to 23.88) | 14.6x lower |

Base renders 200000 views and sends 200 frames per client under every setting,
the branch 2000 and 2, except one branch jit-tracing run (rep 3) that flushed a
third time (3000 renders, 3 frames). Branch worker CPU is 2 to 7 clock ticks,
so its CPU ratios give the order of magnitude only. Base converges 9.9% sooner
with opcache, 25.4% with jit-tracing and 19.8% with jit-function. The branch is
slowest under jit-tracing: 59.949 ms storm to converge against 40.153 ms with
opcache off, and 2.248 against 1.387 ms action p50. Its action p99 is 9.878 ms
with opcache off and 19.857 to 35.278 ms with opcache on. All of these ranges
overlap with off, whose storm and p99 ranges reach 75.317 and 53.91 ms through
one slow run (rep 3). In the other direction, the branch's action p50 with
opcache (0.499 to 0.896 ms) and jit-function (0.37 to 1.305 ms) lies below its
off range (1.385 to 1.823 ms). JIT compile time inside a 40 to 60 ms storm is a
possible cause, not a measured one.

4 workers, `--workers=4` (N=1000, K=200, concurrency 50), 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| storm to converge (ms) | off | 591.516 (485.996 to 726.823) | 49.75 (46.349 to 50.495) | 11.9x lower |
| storm to converge (ms) | opcache | 523.787 (476.31 to 772.735) | 31.491 (31.318 to 42.888) | 16.6x lower |
| storm to converge (ms) | jit-tracing | 434.925 (405.749 to 589.258) | 99.299 (82.06 to 105.943) | 4.38x lower |
| storm to converge (ms) | jit-function | 533.489 (443.651 to 547.991) | 48.709 (30.746 to 50.025) | 11x lower |
| converge after last send (ms) | off | 298.142 (243.345 to 430.365) | 30.076 (25.161 to 39.669) | 9.91x lower |
| converge after last send (ms) | opcache | 290.053 (223.475 to 431.362) | 26.637 (25.64 to 35.36) | 10.9x lower |
| converge after last send (ms) | jit-tracing | 188.604 (152.062 to 276.127) | 29.695 (20.901 to 32.419) | 6.35x lower |
| converge after last send (ms) | jit-function | 250.679 (169.877 to 284.031) | 27.569 (25.501 to 33.702) | 9.09x lower |
| worker CPU net of idle (s) | off | 1.925 (1.658 to 2.29) | 0.15 (0.14 to 0.16) | 12.8x lower |
| worker CPU net of idle (s) | opcache | 1.715 (1.604 to 2.484) | 0.04 (0.03 to 0.05) | 42.9x lower |
| worker CPU net of idle (s) | jit-tracing | 1.649 (1.486 to 2.051) | 0.23 (0.168 to 0.25) | 7.17x lower |
| worker CPU net of idle (s) | jit-function | 1.818 (1.725 to 1.84) | 0.15 (0.05 to 0.16) | 12.1x lower |
| action latency p99 (ms) | off | 330.612 (297.466 to 438.329) | 19.69 (9.928 to 25.214) | 16.8x lower |
| action latency p99 (ms) | opcache | 298.539 (268.867 to 445.412) | 7.554 (6.09 to 8.899) | 39.5x lower |
| action latency p99 (ms) | jit-tracing | 232.976 (182.196 to 256.662) | 61.375 (48.23 to 62.876) | 3.8x lower |
| action latency p99 (ms) | jit-function | 307.026 (221.582 to 323.631) | 15.397 (6.51 to 28.131) | 19.9x lower |
| frames per client | off | 200 | 2.75 (2.75 to 3) | 72.7x lower |
| frames per client | opcache | 200 | 3 | 66.7x lower |
| frames per client | jit-tracing | 200 | 3.75 (3.75 to 4) | 53.3x lower |
| frames per client | jit-function | 200 | 3 (2.75 to 3) | 66.7x lower |

Under jit-tracing the branch's 4-worker storm is slower than with opcache off,
with ranges apart on four of the five metrics: 99.299 against 49.75 ms storm
to converge, 0.23 against 0.15 s worker CPU, 61.375 against 19.69 ms action
p99, and 3.75 against 2.75 frames per client. Converge after the last send is
unchanged (29.695 against 30.076 ms, ranges overlap). It is still 4.38x faster than
base under the same setting. Trace compilation in four workers during a storm
of about 100 ms is a possible cause, not a measured one. With opcache and no
JIT the branch uses less worker CPU than with opcache off (0.04 against 0.15 s,
ranges apart), which fits workers that no longer compile the source files
themselves; that is an inference.

Larger storm, `--n=5000 --k=500 --concurrency=50 --converge-timeout=60
--watchdog=240`, 1 worker, 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| storm to converge (ms) | off | 17806.237 (17756.617 to 19467.909) | 100.596 (97.387 to 152.664) | 177x lower |
| storm to converge (ms) | opcache | 16057.823 (15920.414 to 16199.36) | 111.919 (98.09 to 127.455) | 143x lower |
| storm to converge (ms) | jit-tracing | 15107.175 (13698.802 to 16311.573) | 131.827 (79.714 to 145.639) | 115x lower |
| storm to converge (ms) | jit-function | 15712.059 (14742.779 to 15765.171) | 103.529 (98.543 to 106.432) | 152x lower |
| converge after last send (ms) | off | 1801.953 (1774.962 to 1962.651) | 47.242 (45.343 to 95.08) | 38.1x lower |
| converge after last send (ms) | opcache | 1688.496 (1607.52 to 1745.936) | 48.172 (40.273 to 53.752) | 35.1x lower |
| converge after last send (ms) | jit-tracing | 1472.726 (1355.466 to 1543.688) | 35.555 (32.75 to 36.579) | 41.4x lower |
| converge after last send (ms) | jit-function | 1485.998 (1034.544 to 1588.372) | 46.235 (41.425 to 51.432) | 32.1x lower |
| worker CPU net of idle (s) | off | 13.56 (13.216 to 14.84) | 0.1 (0.09 to 0.14) | 136x lower |
| worker CPU net of idle (s) | opcache | 12.697 (12.579 to 13.238) | 0.11 (0.08 to 0.13) | 115x lower |
| worker CPU net of idle (s) | jit-tracing | 11.558 (11.244 to 12.246) | 0.12 (0.08 to 0.14) | 96.3x lower |
| worker CPU net of idle (s) | jit-function | 12.282 (11.956 to 12.78) | 0.09 (0.09 to 0.1) | 136x lower |
| action latency p99 (ms) | off | 1812.43 (1795.373 to 2427.502) | 46.967 (45.991 to 50.881) | 38.6x lower |
| action latency p99 (ms) | opcache | 1726.324 (1607.341 to 1845.836) | 53.796 (45.75 to 79.525) | 32.1x lower |
| action latency p99 (ms) | jit-tracing | 1551.365 (1407.705 to 2564.036) | 51.722 (41.551 to 71.354) | 30x lower |
| action latency p99 (ms) | jit-function | 1612.839 (1508.68 to 2733.745) | 51.302 (45.709 to 52.776) | 31.4x lower |
| frames per client | off | 500 | 2 (2 to 3) | 250x lower |
| frames per client | opcache | 500 | 2 | 250x lower |
| frames per client | jit-tracing | 500 | 3 (2 to 3) | 167x lower |
| frames per client | jit-function | 500 | 2 | 250x lower |

The branch stays 114.6x to 177x faster to converge under every setting. Base
gains 9.8% (opcache) to 15.2% (jit-tracing) on storm to converge and renders
and sends the same 2,500,000 views and 500 frames per client under every
setting.

Low load, `--n=1000 --k=2 --concurrency=1` (one actor, two actions), 1 worker,
5 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| converge after last send (ms) | off | 12.462 (6.886 to 25.8) | 44.304 (37.038 to 68.889) | 3.56x higher |
| converge after last send (ms) | opcache | 11.42 (6.783 to 18.371) | 47.228 (35.948 to 54.183) | 4.14x higher |
| converge after last send (ms) | jit-tracing | 6.928 (5.888 to 17.08) | 37.809 (30.965 to 44.027) | 5.46x higher |
| converge after last send (ms) | jit-function | 8.783 (5.609 to 27.123) | 42.652 (38.544 to 44.986) | 4.86x higher |
| storm to converge (ms) | off | 36.676 (19.152 to 53.567) | 45.974 (37.577 to 69.846) | +25.4%, ranges overlap |
| storm to converge (ms) | opcache | 35.266 (30.441 to 49.694) | 48.831 (37.867 to 55.773) | +38.5%, ranges overlap |
| storm to converge (ms) | jit-tracing | 36.297 (27.85 to 39.479) | 38.969 (31.451 to 45.546) | +7.4%, ranges overlap |
| storm to converge (ms) | jit-function | 31.283 (16.6 to 52.948) | 47.566 (43.66 to 49.186) | 1.52x higher, ranges overlap |
| action latency p99 (ms) | off | 24.428 (12.26 to 27.753) | 22.757 (8.755 to 33.54) | -6.8%, ranges overlap |
| action latency p99 (ms) | opcache | 24.527 (18.021 to 31.294) | 20.922 (12.411 to 33.656) | -14.7%, ranges overlap |
| action latency p99 (ms) | jit-tracing | 22.351 (21.783 to 29.356) | 11.894 (7.584 to 20.29) | 1.88x lower |
| action latency p99 (ms) | jit-function | 23.387 (10.986 to 27.192) | 15.098 (9.487 to 19.103) | 1.55x lower, ranges overlap |
| worker CPU net of idle (s) | off | 0.02 (0.007 to 0.045) | 0.04 (0.02 to 0.05) | 2x higher, ranges overlap |
| worker CPU net of idle (s) | opcache | 0.032 (0.01 to 0.047) | 0.03 (0.02 to 0.04) | -6.3%, ranges overlap |
| worker CPU net of idle (s) | jit-tracing | 0.03 (0.012 to 0.044) | 0.02 (0.02 to 0.04) | 1.5x lower, ranges overlap |
| worker CPU net of idle (s) | jit-function | 0.029 (0.02 to 0.047) | 0.03 (0.03 to 0.04) | +3.4%, ranges overlap |

The tick trade-off from the F1 regression check holds under every setting: the
second update reaches every client 3.56x to 5.46x later on the branch than on
base, with ranges apart, while the whole two-action run converges within
overlapping ranges. Both trees ran from btrfs here, so the FUSE penalty
described under Protocol does not apply to these numbers.

### idle_sse under opcache and JIT

`--n=5000 --workers=1,4 --idle=15 --settle=3`, 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| worker CPU, 1 worker (%) | off | 25.11 (24.93 to 27.04) | 0.47 (0.33 to 0.47) | 53.4x lower |
| worker CPU, 1 worker (%) | opcache | 21.65 (20.71 to 21.92) | 0.4 | 54.1x lower |
| worker CPU, 1 worker (%) | jit-tracing | 19.18 (14.85 to 19.25) | 0.47 (0.33 to 0.47) | 40.8x lower |
| worker CPU, 1 worker (%) | jit-function | 20.18 (19.91 to 20.98) | 0.4 (0.4 to 0.47) | 50.4x lower |
| worker CPU, 4 workers summed (%) | off | 53.08 (51.48 to 53.54) | 0.53 (0.34 to 0.87) | 100x lower |
| worker CPU, 4 workers summed (%) | opcache | 45.82 (40.36 to 47.15) | 0.73 (0.73 to 0.87) | 62.8x lower |
| worker CPU, 4 workers summed (%) | jit-tracing | 38.62 (25.84 to 40.56) | 0.46 (0.4 to 0.52) | 84.0x lower |
| worker CPU, 4 workers summed (%) | jit-function | 43.5 (29.11 to 43.76) | 0.66 (0.53 to 0.73) | 65.9x lower |
| wakeups/s, 4 workers | off | 2276.1 (2259.8 to 2363.6) | 84.8 (58.6 to 91.7) | 26.8x lower |
| wakeups/s, 4 workers | opcache | 2248 (2232.2 to 2311.9) | 89.1 (86.3 to 94) | 25.2x lower |
| wakeups/s, 4 workers | jit-tracing | 2236.1 (2064 to 2375.6) | 76 (69.9 to 78.2) | 29.4x lower |
| wakeups/s, 4 workers | jit-function | 2275.8 (2101.6 to 2325.5) | 79.6 (77.6 to 84.5) | 28.6x lower |
| broadcast to all connections, 4 workers (ms) | off | 62.26 (62.19 to 68.36) | 48.5 (33.61 to 81.64) | -22.1%, ranges overlap |
| broadcast to all connections, 4 workers (ms) | opcache | 68.4 (67.62 to 71.12) | 47.63 (47.41 to 64.63) | -30.4% |
| broadcast to all connections, 4 workers (ms) | jit-tracing | 67.16 (62.26 to 78.03) | 82.39 (73.56 to 83.43) | +22.7%, ranges overlap |
| broadcast to all connections, 4 workers (ms) | jit-function | 56.87 (53.46 to 65.97) | 59.96 (32.3 to 98.25) | +5.4%, ranges overlap |
| worker RSS, 1 worker (MB) | off | 210.4 (209.9 to 210.4) | 229.8 (226.4 to 230.3) | +9.2% |
| worker RSS, 1 worker (MB) | opcache | 170.8 (170.5 to 171.2) | 177.3 (176.5 to 177.3) | +3.8% |
| worker RSS, 1 worker (MB) | jit-tracing | 177.5 (177.3 to 177.6) | 212.2 (211.3 to 212.5) | +19.5% |
| worker RSS, 1 worker (MB) | jit-function | 173.6 (173.1 to 174.1) | 180.3 (180.3 to 181) | +3.9% |

Wakeups do not depend on the setting. At 1 worker the medians are 577.7 to
599.4/s on base and 18.5 to 20.7/s on the branch. Base idle CPU falls with
opcache and JIT because each wake costs less: at 1 worker by 13.8% (opcache),
23.6% (jit-tracing) and 19.6% (jit-function). Branch CPU at 1 worker is 5 to 7
clock ticks per 15 s window (one 10 ms tick is 0.067%), near the measurement
floor, so its ratios are rough. Some base runs came in low, with all workers
lower together while wakeups fell by less than 10%: jit-tracing rep 1 (14.85%
at 1 worker, 25.84% at 4) and jit-function rep 1 at 4 workers (29.11%). The
same happened with opcache off in extra rep 6 (20.78% at 1 worker, 27.71% at
4), so these runs are read as host state and kept in the ranges. That off run
falls inside the opcache and jit-function ranges at 1 worker, so those two
drops rest on 3 reps each; jit-tracing stays clear of all 6 off runs (14.19 to
20.05% against 20.78 to 27.04%).

Reps 4 to 6 ran right after, with off and jit-tracing only, to check the two
differences below. Over all 6 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| broadcast to all connections, 4 workers (ms) | off | 66.68 (62.19 to 75.69) | 45.135 (33.61 to 81.64) | -32.3%, ranges overlap |
| broadcast to all connections, 4 workers (ms) | jit-tracing | 66.215 (62.26 to 142.72) | 82.91 (71.82 to 96.25) | +25.2%, ranges overlap |
| worker RSS, 1 worker (MB) | off | 209.75 (206.8 to 210.4) | 229.55 (226.4 to 230.3) | +9.4% |
| worker RSS, 1 worker (MB) | jit-tracing | 177.55 (176.8 to 192.8) | 212.1 (209.4 to 212.5) | +19.5% |

Under jit-tracing all 6 branch runs took 71.82 ms or more to reach every
connection with 4 workers. With opcache off 2 of 6 did (80.42 and 81.64 ms) and
the other 4 took 33.61 to 48.5 ms, and under jit-function 1 of 3 did
(98.25 ms). So under jit-tracing the branch reaches every connection later than
base at the median (82.91 against 66.215 ms), while with opcache off it is
earlier. The ranges overlap and the cause was not investigated; this is a
signal to follow up, not a proven regression. The fire request lands on worker
id 1 with opcache off and on worker id 2 with opcache on, in both trees, and
with opcache but no JIT the branch took 47.41 to 64.63 ms, so the worker
placement alone does not explain it. With 1 worker the branch median is at or
below base under every setting.

Branch worker RSS at 1 worker is 34.7 MB above base under jit-tracing (every
branch run above every base run), against 19.4 MB with opcache off and 6.5 and
6.7 MB with opcache or jit-function. A `/proc` breakdown of separate 1-worker
runs puts the difference in private anonymous memory: RssAnon was 186 to 191
MB for the branch under jit-tracing, 153 to 158 MB for the branch with opcache
and 157 MB for base under jit-tracing, while RssShmem, which holds the opcache
and JIT shared memory, was 6 to 8 MB in all of them. RssAnon stayed
flat through the idle window, so the memory is allocated while the connections
are set up and does not grow while idle. Its cause was not investigated.
Shutdown medians are 106.03 to 145.28 ms in every cell, with no timeout and no
setting effect beyond noise.

### shared_read under opcache and JIT

Defaults, `php bench/contention/shared_read.php` (N=2000, S=5, 20 broadcasts,
tab and route modes, remote writer), 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| with store, tab (ms per broadcast) | off | 49.71 (49.492 to 49.948) | 21.827 (21.524 to 22.496) | 2.28x lower |
| with store, tab (ms per broadcast) | opcache | 47.463 (47.371 to 49.844) | 20.786 (20.776 to 22.06) | 2.28x lower |
| with store, tab (ms per broadcast) | jit-tracing | 42.554 (42.433 to 43.236) | 17.076 (17.033 to 17.335) | 2.49x lower |
| with store, tab (ms per broadcast) | jit-function | 44.103 (43.791 to 45.994) | 18.716 (17.823 to 18.826) | 2.36x lower |
| with store, route (ms per broadcast) | off | 20.296 (20.227 to 20.744) | 4.48 (4.399 to 4.9) | 4.53x lower |
| with store, route (ms per broadcast) | opcache | 19.608 (19.496 to 19.62) | 4.176 (4.058 to 4.45) | 4.70x lower |
| with store, route (ms per broadcast) | jit-tracing | 17.36 (17.274 to 18.269) | 2.476 (2.463 to 2.48) | 7.01x lower |
| with store, route (ms per broadcast) | jit-function | 18.202 (18.175 to 19.309) | 3.153 (3.045 to 3.27) | 5.77x lower |
| no store, route (ms per broadcast) | off | 3.717 (3.713 to 3.758) | 3.721 (3.669 to 4.053) | +0.1%, ranges overlap |
| no store, route (ms per broadcast) | opcache | 3.554 (3.518 to 3.683) | 3.553 (3.489 to 3.775) | 0.0%, ranges overlap |
| no store, route (ms per broadcast) | jit-tracing | 2.364 (2.334 to 2.476) | 2.228 (2.226 to 2.257) | -5.8% |
| no store, route (ms per broadcast) | jit-function | 2.773 (2.706 to 2.946) | 2.755 (2.653 to 2.883) | -0.6%, ranges overlap |
| store overhead over no store, tab (%) | off | 155.41 (155.17 to 158.07) | 11.45 (11.34 to 13.03) |  |
| store overhead over no store, tab (%) | opcache | 146.21 (145.5 to 147.17) | 10.18 (9.9 to 10.71) |  |
| store overhead over no store, tab (%) | jit-tracing | 167.61 (164.52 to 169.66) | 8.41 (7.62 to 8.42) |  |
| store overhead over no store, tab (%) | jit-function | 161.4 (158.6 to 162.85) | 6.99 (6.94 to 8.99) |  |
| store overhead over no store, route (%) | off | 446.67 (444.15 to 451.99) | 20.41 (19.88 to 20.89) |  |
| store overhead over no store, route (%) | opcache | 451.79 (432.75 to 454.24) | 17.54 (16.31 to 17.88) |  |
| store overhead over no store, route (%) | jit-tracing | 637.79 (634.24 to 640.03) | 10.53 (9.9 to 11.25) |  |
| store overhead over no store, route (%) | jit-function | 555.36 (555.36 to 572.74) | 14.42 (13.45 to 14.76) |  |

Store reads per broadcast are the same under every setting: 20000 (tab) and
10005 (route) on base, 5 on the branch. JIT speeds up rendering more than the
store read, which stays at 2.15 to 2.45 us per `SharedSignalStore::get()` of
an array in every run, so on base the store's share of a fan-out grows under
JIT: its overhead goes from 155.41% (off) to 167.61% (jit-tracing) in tab mode
and from 446.67% to 637.79% in route mode. The branch gains from JIT on the
whole path, and its lead with the store grows from 2.28x to 2.49x (tab) and
from 4.53x to 7.01x (route) between opcache off and jit-tracing. Its remaining
store overhead falls from 11.45% to 6.99 to 8.41% (tab) and from 20.41% to
10.53 to 14.42% (route) under JIT. The branch reads the store 5 times per
broadcast, so a residual that shrinks with JIT points to PHP work per context
on the store path rather than to store reads. Without a store the trees are
within 2.3% of each other, except in route mode under jit-tracing, where the
branch is 5.8% faster with non-overlapping ranges over 3 reps.

### get_clients under opcache and JIT

`--n=100,1000 --fanout-cap=1000 --broadcasts=3 --timeout=300` (shared and
single modes; every broadcast covers all N contexts), 3 reps:

| Metric | Setting | Base | Branch | Branch vs base |
|---|---|---|---|---|
| shared, N=100, broadcast to 100 contexts (ms) | off | 44.26 (43.5 to 45.32) | 0.21 (0.2 to 0.21) | 211x lower |
| shared, N=100, broadcast to 100 contexts (ms) | opcache | 35.98 (35.56 to 38.38) | 0.2 (0.19 to 0.2) | 180x lower |
| shared, N=100, broadcast to 100 contexts (ms) | jit-tracing | 31.37 (28.77 to 31.49) | 1.01 (0.98 to 1.18) | 31.1x lower |
| shared, N=100, broadcast to 100 contexts (ms) | jit-function | 31.74 (30.55 to 32.16) | 0.16 (0.15 to 0.16) | 198x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | off | 4409.31 (4378.25 to 4518.73) | 1.89 (1.89 to 1.9) | 2333x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | opcache | 3732.41 (3614.78 to 3736.12) | 1.86 (1.7 to 1.86) | 2007x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | jit-tracing | 3021.75 (2919.57 to 3035.75) | 1.32 (1.29 to 1.37) | 2289x lower |
| shared, N=1000, broadcast to 1000 contexts (ms) | jit-function | 3116.77 (3085.45 to 3150.11) | 1.43 (1.4 to 1.58) | 2180x lower |
| shared, N=1000, call after one new client (ms) | off | 4.268 (4.2473 to 4.3922) | 0.3757 (0.3709 to 0.3777) | 11.4x lower |
| shared, N=1000, call after one new client (ms) | opcache | 3.7196 (3.4989 to 3.7359) | 0.3544 (0.3494 to 0.3626) | 10.5x lower |
| shared, N=1000, call after one new client (ms) | jit-tracing | 3.0275 (2.8151 to 3.0287) | 0.3125 (0.2995 to 0.3135) | 9.69x lower |
| shared, N=1000, call after one new client (ms) | jit-function | 3.0195 (2.9647 to 3.158) | 0.3424 (0.3303 to 0.3605) | 8.82x lower |
| single, N=1000, broadcast to 1000 contexts (ms) | off | 104.6 (103.85 to 106.84) | 1.38 (1.37 to 1.4) | 75.8x lower |
| single, N=1000, broadcast to 1000 contexts (ms) | opcache | 102.8 (100.57 to 107.85) | 1.35 (1.3 to 1.38) | 76.1x lower |
| single, N=1000, broadcast to 1000 contexts (ms) | jit-tracing | 97.99 (94.38 to 99.56) | 0.82 (0.8 to 0.84) | 120x lower |
| single, N=1000, broadcast to 1000 contexts (ms) | jit-function | 98.89 (96.71 to 100.67) | 1.04 (0.99 to 1.13) | 95.1x lower |

Opcache and JIT make base's shared path faster by a constant factor and keep
its quadratic shape: the N=1000 broadcast takes 4409.31 ms with opcache off and
3021.75 ms under jit-tracing. Single mode gains only 6.3% on base (104.6
against 97.99 ms). Branch call times without a membership change stay at the
script's rounding floor under every setting (0 to 0.0002 ms) and are left out.
Under jit-tracing the branch's shared N=100 broadcast, the first case the
script measures, takes 1.01 ms against 0.16 to 0.21 ms under the other
settings. A branch-only run that repeats that case four times in one process
measured 0.91, 0.13, 0.25 and 0.14 ms under jit-tracing and 0.19 to 0.2 ms with
opcache off, which points to trace compilation in the first case; the
compilation itself was not timed. In the N=1000 cases, measured after both
N=100 cases, jit-tracing is the branch's fastest setting (shared 1.32 ms,
single 0.82 ms). In the single N=100 case, measured second, it is slower than
jit-function (0.16 against 0.1 ms in every run).

### What the settings change

- Opcache without JIT makes base at most 18.7% faster (get_clients shared N=100
  broadcast; 15.4% at N=1000) and changes no verdict. Base lock_contention at
  C=1 is up to 9.0% slower with it, with overlapping ranges.
- JIT makes base faster in broadcast_storm, idle_sse, shared_read and the
  get_clients shared path: storm to converge by 25.4% (jit-tracing) and 19.8%
  (jit-function), idle worker CPU at 1 worker by 23.6% and 19.6%, shared_read
  tab with store by 14.4% and 11.3%, and the get_clients shared N=1000
  broadcast by 31.5% and 29.3%. The get_clients single-mode N=1000 broadcast
  gains only 5.5 to 6.3%. Renders, frames, wakeups and store reads stay the
  same, so the work the branch removes is still there.
- Both JIT modes slow the least contended lock case (lock_contention W=4, C=1:
  one coroutine per worker) by 12.3 to 25.5% in both trees. The time is lost
  mostly between holders; the cause was not isolated.
- The branch advantage holds under every setting on the metrics each benchmark
  targets, and base and branch ranges stay apart there. For off, opcache,
  jit-tracing and jit-function: lock_contention W=4 global C=32 ops/s 5.90x,
  5.34x, 4.49x and 5.20x higher; broadcast_storm storm to converge 35.5x,
  28.6x, 17.8x and 26.5x lower; idle_sse worker CPU at 1 worker 53.4x, 54.1x,
  40.8x and 50.4x lower; shared_read route with store 4.53x, 4.70x, 7.01x and
  5.77x lower; get_clients shared N=1000 broadcast 2333x, 2007x, 2289x and
  2180x lower. The advantage shrinks in lock_contention global mode at C=32
  under jit-tracing, with both trees clear of their off ranges: base gains
  9.6% and the branch loses 16.5% (7.0% with opcache alone). In broadcast_storm
  the ratio of medians falls from 35.5x to 17.8x under jit-tracing, but only
  base getting 9.9 to 25.4% faster is clear of noise: every branch storm range
  overlaps its off range. At N=5000 the branch stays 114.6x to 177x faster
  under every setting. With 4 workers the lead falls from 11.9x (off) to 4.38x
  (jit-tracing), because the branch storm takes twice as long under
  jit-tracing, ranges apart. The advantage grows in shared_read under JIT.
- The F1 tick trade-off does not depend on the setting: a follow-up update
  (K=2, one actor) reaches every client 3.56x to 5.46x later on the branch
  than on base under all four settings.
- Under jit-tracing the branch shows one difference clear of noise and two
  within overlapping ranges, none explained yet. Clear: a 1-worker server with
  5000 idle connections holds 34.7 MB more RSS than base (every branch run
  above every base run), against 6.5 to 19.4 MB under the other settings, all
  of it anonymous memory. Within overlapping ranges: the 4-worker broadcast
  after idle reaches every connection later than on base (82.91 against 66.215
  ms over 6 runs), and broadcast_storm converges in 59.949 ms against 40.153 ms
  with opcache off. The first get_clients case takes 1.01 ms against 0.16 to
  0.21 ms and drops to 0.13 to 0.25 ms when it repeats in one process, which
  points to trace compilation.
- No run crashed or returned a wrong result under any setting.
