# php-via Performance Profile

## Current figures (0.14.0)

The figures to plan with are on the website's [Performance](https://via.zweiundeins.gmbh/docs/performance)
page, measured on 0.14.0 with the harness in `bench/capacity` (`bench/capacity/RESULTS.md`), and in
`bench/contention/RESULTS.md`. In short, for one worker on a core held at full clock:

- A page view of 21 to 86 KB of HTML costs 1.3 to 4.2 ms of CPU, and its context 14 to 37 KB until
  the stream connects or the connect timeout (30 s) passes.
- An open tab costs 570 to 650 KB with Brotli level 4, 65 to 125 KB at level 1 and 40 to 75 KB
  without Brotli, mostly the stream encoder's window. A busy stream's encoder grows to about 9 MB at
  level 4 (see the Brotli section below).
- A private action with a `sync()` costs 0.16 to 0.20 ms, a broadcast about 0.05 ms per receiving
  tab. A view renders once per broadcast for all tabs only with `view(..., shareRender: true)`.
- A storm of 200 actions on 1,000 connected tabs converges in 34 ms (base before coalescing: 1.46 s).
- A static file comes from worker memory, a 91 KB stylesheet at 0.054 ms of CPU with Brotli level 11,
  which never runs in a worker.
- With several workers, an action that reaches another worker than its tab's is passed there, at
  about twice the CPU of one that does not. Behind an h2c proxy all clients share one worker until the
  proxy's connection carries 1,280 streams; over HTTP/1.1 they spread, and about (N-1)/N of a tab's
  actions are passed on. See [Same machine](https://via.zweiundeins.gmbh/docs/deployment#tab-worker).

The sections below are the earlier record, each with its date, version and setup. The first ones
were measured in April 2026 on a single-process dev build (`APP_ENV=dev php website/app.php`),
OpenSwoole 22.13.0, PHP 8.4.19, port 3000 (HTTPS/TLS, self-signed cert), and were not re-run on
OpenSwoole 26, which php-via requires since 0.13.0.

---

## Test Scripts

| Script | Purpose |
|--------|---------|
| `tests/Load/action_hammer.php` | Concurrent POST actions, net state increment, broadcast fan-out |
| `tests/Load/sse_connections.php` | SSE connection ramp, failure rate, patch latency under load |

---

## Action Throughput (action_hammer.php)

### Test Setup

| Parameter | Value |
|-----------|-------|
| Route | `/` (homepage, shared ROUTE-scoped counter) |
| Action | `/_action/increment` (no hash, route-scoped) |
| Signal | `route___counter` |
| Observers | 50 live SSE connections watching for patches |
| Actions | 2000 per run |

The test measures three things independently:

- **HTTP OK rate**: did the server *respond* with 2xx?
- **Net signal increment**: did the server *process* the action (state mutation)?
- **Patch delivery rate**: did the 50 SSE observers receive the broadcast?

These can diverge because OpenSwoole may finish processing a request but the client
times out before receiving the response; the mutation has already happened.

Since broadcast coalescing, an observer gets about one frame per broadcast tick instead of one
per action, so "HTTP OK × observers" no longer counts expected patches. The harness now reports
frames per observer and how many observers end on the final value. The delivery figures below
were measured before coalescing.

### Results

#### Concurrency = 200 (clean ceiling)

```
php tests/Load/action_hammer.php \
  --url=https://127.0.0.1:3000 --route=/ \
  --action=increment --signal=route___counter \
  --actions=2000 --concurrency=200 --observers=50
```

| Metric | Value |
|--------|-------|
| Actions sent | 2000 |
| HTTP OK | 1998 (99.9%) |
| Net increment | 2000 (100% of sent) |
| Patches delivered (50 observers) | 99,492 / 99,900 expected (99.6%) |
| Patch drops | 0.4% |
| Throughput | ~46 req/s |
| Wall time | 43.1s |

Near-perfect run. Every action mutated state. 0.4% patch drops are expected and
by design: `PatchManager` uses a non-blocking `Channel(50)` per SSE connection;
state is always consistent even if one frame is dropped.

#### Concurrency = 500 (above OS accept-queue limit)

```
php tests/Load/action_hammer.php \
  --url=https://127.0.0.1:3000 --route=/ \
  --action=increment --signal=route___counter \
  --actions=2000 --concurrency=500 --observers=50
```

| Metric | Value |
|--------|-------|
| Actions sent | 2000 |
| HTTP OK | 357 (17.8%) |
| Net increment | 716 (200.6% of HTTP OK, server processed ~2x more than responded) |
| True drops (never reached handler) | ~1284 (64.2%) |
| Patches observed vs net-increment expected | 40,378 / 35,800 (112.8%) |
| Throughput | ~72 req/s (higher because failures return fast) |
| Wall time | 27.9s |

At concurrency=500 the OS TCP accept queue is saturated. Connections queue at the
kernel level and time out on the client side before OpenSwoole's `accept()` loop
drains them. The gap between HTTP OK (357) and net increment (716) shows ~359
requests were processed server-side but clients had already dropped; this is
TCP-level loss, not application-level corruption. State remained internally
consistent throughout.

**Note:** Results at this concurrency level vary run-to-run (about 15 to 45% OK) depending
on OS scheduler, system load, and whether the backlog has recovered from a prior run.

---

## SSE Connection Scaling (sse_connections.php)

### Test Setup

```
php tests/Load/sse_connections.php \
  --url=https://127.0.0.1:3000 \
  --route=/examples/counter \
  --action=increment \
  --milestones=50,200,500,1000,2000 \
  --ramp-delay=10
```

Each "connection" is an independent coroutine: loads the page, opens a persistent
SSE connection, and holds it open. Ramp delay = 10ms between connection attempts.

### Results

| Milestone | Connected | Failed | Failure rate |
|-----------|-----------|--------|--------------|
| 50 | 50 | 0 | 0% |
| 200 | 200 | 0 | 0% |
| 500 | 500 | 0 | 0% |
| 1,000 | 1,000 | 0 | 0% |
| 2,000 | 2,000 | 0 | 0% |

**Patch latency at 2,000 active SSE connections: 1,188 ms**
(time from action POST to patch received on the observer context)

2,000 concurrent SSE connections, fully coroutine-resident in a single PHP process,
with zero failures and clean shutdown. The latency at 2,000 connections reflects
coroutine scheduling overhead, not dropped frames.

At 5,000 connections (~82% success) the client-side test machine runs out of ephemeral
ports (`EADDRNOTAVAIL`), not the server. The server itself showed no failures up to the
OS client-side limit.

---

## Multi-worker Comparison (16 workers + RedisBroker, localhost Redis)

> **⚠ This section does not measure what its title says.** See
> [Correction](#correction-what-the-16-worker-run-actually-measured) below before
> using these numbers. The results are left in place because they are real; only the
> attribution was wrong.

To test the multi-worker path, the website was configured with:

```php
(new Config())
    ->withBroker(new RedisBroker())          // localhost Redis, pub/sub channel
    ->withSwooleSettings(['worker_num' => \OpenSwoole\Util::getCpuNum()])  // 16 workers
```

Since 0.14.0, `start()` throws for a `worker_num` in `withSwooleSettings()` that differs from
`withWorkerNum()`, so this configuration no longer starts.

Redis was already running locally (`redis-server`, default port 6379).
`\OpenSwoole\Util::getCpuNum()` returns 16 on this machine.

### action_hammer results

| Metric | 1 worker (baseline) | 16 workers + Redis |
|--------|---------------------|-------------------|
| HTTP OK (concurrency=200) | 1998 (99.9%) | 1794 (89.7%) |
| Net increment (concurrency=200) | 2000 (100%) | 1992 (99.6%) |
| Patches delivered (concurrency=200) | 99,492 (99.6%) | 100,308 (111.8%) |
| Throughput (concurrency=200) | ~46 req/s | ~50 req/s |
| HTTP OK (concurrency=500) | 357 (17.8%) | 134 (6.7%) |
| Net increment (concurrency=500) | 716 | 585 |

### SSE connection scaling results

| Milestone | 1 worker | 16 workers + Redis |
|-----------|----------|--------------------|
| 50 | 50/50 (0% fail) | 50/50 (0% fail) |
| 200 | 200/200 (0% fail) | 200/200 (0% fail) |
| 500 | 500/500 (0% fail) | 500/500 (0% fail) |
| 1,000 | 1,000/1,000 (0% fail) | 1,000/1,000 (0% fail) |
| 2,000 | 2,000/2,000 (0% fail) | 1,563/2,000 (22% fail) |
| Patch latency @ peak | 1,188 ms | 760 ms |

### Analysis

**Action throughput regressed at high concurrency.** At concurrency=200, HTTP OK
dropped from 99.9% to 89.7%. At concurrency=500, from 17.8% to 6.7%. The degradation
comes from Redis pub/sub overhead: every action now makes two Redis round-trips (publish
+ receive-loop). At high concurrency, these add latency that pushes responses past
client timeout windows.

**Net state remains correct.** Despite HTTP timeouts, net increment was 1992/2000 (99.6%)
at concurrency=200, nearly identical to single-worker. The broker correctly propagated
mutations across workers.

**Patch delivery exceeded 100% (111.8%).** With 16 workers sharing 50 observer SSE
connections, a single Redis broadcast triggers all 16 workers to sync their local
contexts. Observers distributed across multiple workers each receive the patch from
their own worker's sync, while the "expected" count was calculated assuming 1:1
(HTTP OK × observers). This is correct behaviour: the broker fan-out amplifies
patch delivery.

**SSE connection ceiling dropped at 2,000** (78% vs 100%). With 16 workers sharing
`enable_reuse_port`, the OS distributes incoming connections across all workers. Each
worker's per-connection backlog queue fills faster at burst ramp rates, causing some
connections to be refused before the queue drains.

**Latency improved significantly: 760ms vs 1,188ms.** With 16 workers, each event loop
is less saturated. The SSE push from the action handler to the observer happens on a
different worker's loop, still fast because Redis pub/sub is sub-millisecond on localhost.

### Correction: what the 16-worker run actually measured

`worker_num` was set through `withSwooleSettings()`, which writes the OpenSwoole setting
directly and never touches `Config::getWorkerNum()`. Everything php-via gates on that
accessor therefore stayed switched off:

| gated on `getWorkerNum() > 1` | state during the run |
|---|---|
| session-affinity dispatch (`dispatch_mode`, `dispatch_func`) | never configured |
| `SharedTable` allocation (`Via.php`) | never allocated: `GlobalState` stayed per-worker |
| the multi-worker broker guard | never armed |

So the run measured **16 workers on OpenSwoole's default fd-based dispatch with per-worker
global state**, not php-via's multi-worker path. fd dispatch is per-connection sticky, so
a keep-alive client stays on one worker; that is why the numbers look as good as they do.

This also means the Analysis above misattributes the cause. The 99.9% → 89.7% drop is
consistent with the section's own explanation (broker round-trips pushing responses past
client timeouts) and with the concurrency=500 collapse (17.8% → 6.7%), which is
latency-shaped. It is *not* evidence about session affinity, which never ran.

### Re-measured on the real multi-worker path

Different machine and runtime from the run above: 20 cores, PHP 8.5.9,
**ext-openswoole 26.2.0**, no Redis available, so `SwooleBroker` (the same-machine
broker) instead of `RedisBroker`. Absolute numbers are therefore not comparable with the
tables above; the shape across worker counts is the finding.

`tests/Load/bench_app.php` uses `withWorkerNum($n)`, so this is the path the section
above was meant to exercise. 1,000 actions, concurrency 100, `/bench/counter`:

| workers | HTTP OK (before) | 1/N | HTTP OK (after) |
|---|---|---|---|
| 1 | 1000 (100.0%) | 100% | 100% |
| 2 | 510 (51.0%) | 50% | 100% |
| 4 | 262 (26.2%) | 25% | 100% |
| 8 | 130 (13.0%) | 12.5% | 100% |
| 16 | 69 (6.9%) | 6.25% | 100% |

**Before the fix, action success tracked `1/worker_num` exactly.** Every failure was
`HTTP 400 "Invalid context"`: a context was created on the worker that served the page,
and `ActionHandler`, unlike `SseHandler`, made no attempt to revive one it had not
seen, so an action only succeeded when it happened to land back on the originating worker.

**Fixed.** `ActionHandler` now rebuilds an unknown context by re-running its route handler,
using a shared context directory written at context *creation* (a context alive on another
worker never had a revival record, so sharing those alone would not have been enough), and
scoped signal values are backed by shared memory so the rebuilt context does not mutate a
copy nobody is watching. The "after" column is 1,000 actions at concurrency 100, verified
end to end: 200 actions spread across 4 workers leave the shared counter at exactly 200.

> **Note on the load harness.** `action_hammer` at 16 workers/concurrency 100 reports a
> variable 80 to 100%, while a direct probe at the same concurrency gets 1000/1000. The
> difference is the harness holding 50 SSE observers alongside the action connections, so
> that residual is client-side, not routing.

### Re-measured again after items 6-10 (2026-08-26)

Hardware and runtime as in the section above (20 cores, PHP 8.5.9, ext-openswoole 26.2.0,
`SwooleBroker`). `tests/Load/bench_app.php` with `VIA_BENCH_SCOPE=route` at every worker count,
so all rows measure a shared ROUTE-scoped counter rather than a per-tab one. Worker counts are
verified per row (`procs = workers + 2`): an overlapping server from a previous run silently
makes every row measure the same process tree, which is exactly how an earlier draft of this
table came out flat.

**CPU-bound action** (`/bench/cpu`, 50x50 Mandelbrot, 1,000 actions, concurrency 100):

| workers | throughput | speedup |
|---|---|---|
| 1 | 574 req/s | 1.00x |
| 2 | 1,091 req/s | 1.90x |
| 4 | 1,660 req/s | 2.89x |
| 8 | 2,685 req/s | 4.68x |
| 16 | **4,499 req/s** | **7.84x** |

**Broadcast-bound action** (`/bench/counter`, 2,000 actions, concurrency 200):

| workers | throughput |
|---|---|
| 1 | 19,374 req/s |
| 2 | 18,772 req/s |
| 4 | 13,483 req/s |
| 8 | 13,598 req/s |
| 16 | 13,861 req/s |

**IO-bound action** (`/bench/io`, 2 ms coroutine sleep): flat at 10-16k req/s across all worker
counts, as expected: coroutines already provide IO concurrency inside one worker.

HTTP OK was 100% and net increment exact at every worker count in all three.

**So multi-worker is a win exactly where it should be.** Extra workers buy real parallelism for
CPU-bound handlers (7.8x on 16), buy nothing for IO-bound ones, and cost ~30% for
broadcast-bound ones where each action already fans out to every worker. Pick the worker count
from what the handlers actually do.

> **The benchmark indicted itself first.** With the action written as
> `setValue($sig->int() + 1)`, a read-modify-write, net increment fell below HTTP OK as
> workers rose: 2000 / 1951 / 1922 / 1879 / 1840 at 1 / 2 / 4 / 8 / 16. That is the lost-update
> behaviour `Signal::increment()` exists to avoid; switching the harness to it gives exactly
> 2000 at every worker count. A load test that quietly under-counts is worse than useless, so
> the harness now uses the atomic API.

## Brotli SSE compression: the cost is memory, and it is per connection

Measured 2026-08-26 with ext-brotli 0.21.0 against **real captured Game-of-Life SSE frames**
(2,500 tiles, ~127 KB per frame), 50 concurrent encoders, RSS delta per encoder at steady state.

| level | per encoder | at 2,000 conns | ratio | CPU per frame |
|---|---|---|---|---|
| 1 | 574 KB | **1.1 GB** | 19.0:1 | 90 µs |
| 2 | 8,573 KB | 16.4 GB | 26.9:1 | 190 µs |
| 3 | 8,567 KB | 16.3 GB | 27.8:1 | 218 µs |
| **4** (php-via default) | 8,851 KB | **16.9 GB** | 30.3:1 | 277 µs |
| 5 | 9,677 KB | 18.5 GB | 40.3:1 | 450 µs |
| 8 | 13,577 KB | 25.9 GB | 49.5:1 | 1,094 µs |
| 11 | 31,474 KB | 60.0 GB | 75.3:1 | **94,551 µs** |

**The encoder is lazy but saturating.** At init it costs ~7 KB. It grows as the stream feeds it
and plateaus after roughly 8 MB of traffic (measured identical at 8 MB, 64 MB and 256 MB fed)
and never shrinks. So the cost is driven by traffic, not connection count: an idle stream stays
near 7 KB, and the 2,000-connection column above only applies to 2,000 *busy* streams.

That is why the existing 2,000-connection result in this document does not contradict it: that
run held connections against a low-volume counter, so the ring buffers never grew.

**The window is not tunable.** ext-brotli's `brotli_compress_init()` takes only
`(level, mode, dict)` and hardcodes `BROTLI_DEFAULT_WINDOW` (22 = 4 MB). Neither lowering it to
save memory nor raising it for the compression gains Anders Murphy reports is possible without an
upstream extension change. The lever php-via has is the quality level, and it has a cliff: level 1
costs 15× less memory and 3× less CPU than the default for 37% more bytes on the wire.

**Level 11 must never be used for streaming**: 94 ms of CPU per frame, on the event loop.
php-via uses it only for static assets, and since 0.14.0 never in a worker: files of up to 128 KiB
are compressed in the master process before the port opens and shared by all workers, bigger ones by
a low-priority helper process, and a worker sends a file the helper has not finished at level 4.
See [Static compression](https://via.zweiundeins.gmbh/docs/deployment#static-compression).

---

### When multi-worker helps (and when it doesn't)

At 1,000 to 2,000 SSE connections on a single machine with a localhost Redis broker,
**single-worker is faster and more reliable**. The coroutine scheduler handles thousands
of concurrent SSE connections without process-switch overhead.

Multi-worker with a broker starts winning when:
- CPU-bound work (rendering, computation) saturates a single worker's event loop
- Connections are distributed across machines (where cross-machine Redis adds the same
  latency whether using 1 or 16 workers per machine)
- You need fault isolation (one worker crash doesn't take down all connections)

---

## What This Means for Real Applications

### The bottleneck is TCP accept, not the application

At concurrency ≤ 200 actions/s the application is effectively perfect: 100%
delivery, 0% state drops, correct final state. The constraint is the OS TCP
accept queue depth, not the action handler, signal system, or SSE fan-out.

### SSE broadcast fan-out is not the bottleneck (yet)

`PatchManager` uses a `Channel(50)` per connection and pushes non-blockingly.
At 50 observers × 2,000 actions, 99.6% of patches were delivered. The 0.4% drop
is from momentary channel backpressure when the event loop is busy flushing earlier
frames.

### "HTTP errors" ≠ state corruption

Via's action handler is a coroutine that runs to completion regardless of whether
the HTTP response is delivered. If a client drops the connection mid-response, the
mutation has already happened. This is **safe by design** but means HTTP
response-code monitoring will under-count successful operations under extreme load.

### Real-world headroom

A typical real-world page has:
- 1 to 5 SSE connections per user (one per open tab)
- Bursts of 1 to 10 actions/second per active user
- At 200 concurrent HTTP connections, that supports **thousands of simultaneous users**
  whose actions arrive in a natural Poisson distribution, not all at once

The 200 concurrent action ceiling is an *instantaneous concurrency* ceiling, not a
throughput ceiling. 2,000 sustained SSE connections held with 0% failure means a
single Via instance can serve 2,000+ active browser sessions simultaneously.

---

## Path to 30k Concurrent Requests

Getting from ~200 to 30k concurrent requests requires changes at multiple layers. This plan was
written in April 2026, before 0.13.0 made several workers work; its code samples are updated for
0.14.0.

### 1. OpenSwoole server tuning (easy wins, about 5 to 10×)

```php
(new Config())
    ->withWorkerNum(\OpenSwoole\Util::getCPUNum())  // one worker per core; never worker_num below
    ->withSwooleSettings([
        'max_coroutine'       => 100_000,
        'backlog'             => 8192,               // OS accept queue depth (php-via sets 4096)
        'max_conn'            => 50_000,             // php-via sets 10,000
        'buffer_output_size'  => 4 * 1024 * 1024,   // 4 MB per connection
    ]);
```

Add to OS (`/etc/sysctl.conf`):
```
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.ip_local_port_range = 1024 65535
```

Expected gain: moves the accept ceiling from ~200 → ~2000+ per worker.

### 2. Multiple workers (linear scaling, ~N×)

OpenSwoole `worker_num > 1` runs N worker processes, each with their own event
loop. Each worker can handle ~200 concurrent connections independently.
With 8 workers on an 8-core machine: ~1600 concurrent connections.

**Caveat for Via**: since 0.13.0, scoped signal values, session data, GlobalState, the client list
and a context directory live in shared memory, and `SwooleBroker` carries broadcasts between the
workers; 0.14.0 uses it without `withBroker()`. An action that reaches a worker other than the one
holding its tab is passed there, which costs about twice the CPU of an action that does not.

> **Caveats that remain.** Actions, scoped signal values and the client list now cross
> workers, and multi-worker is measured as a real win for CPU-bound handlers (7.8x on 16
> workers), see "Re-measured again after items 6-10". Two things do not cross. Mutating a scoped signal by reading it and calling
> `setValue()` loses updates under concurrency (measured 129 of 240 list appends
> surviving across 4 workers), so use `Signal::increment()` for counters and
> `Signal::mutate()` for everything else, which take the shared-memory paths that are
> race-free (240 of 240). And PHP statics in your own handlers are per-process, so a
> simulation kept in one diverges per worker. The server logs both at start-up whenever
> `worker_num > 1`.

### 3. Connection multiplexing / HTTP/2 (reduces connection count)

Modern browsers multiplex multiple requests over a single TCP connection with
HTTP/2. A user with 10 in-flight requests would use 1 connection instead of 10,
reducing instantaneous concurrency by ~10×. The website serves its SSE over HTTP/2 to browsers.
Between a proxy and php-via, OpenSwoole hands each connection to one worker, so an h2c upstream
puts every client on one worker until the connection carries 1,280 streams; an HTTP/1.1 upstream
spreads them across workers.

### 4. Reverse proxy (offload TLS + static assets)

Running TLS termination inside PHP/OpenSwoole is expensive. Offloading to Caddy
or nginx:
- Frees CPU cycles spent on TLS handshakes
- Allows keep-alive connection pooling between proxy and Via
- Enables HTTP/2 at the edge without changing Via's HTTP/1.1 internals

[Deployment](https://via.zweiundeins.gmbh/docs/deployment#caddy) has the Caddy configuration.

### 5. PatchManager channel tuning

Since 0.14.0 a full queue drops a view update first, which the next render sends again, and
never a `patchElements()` patch, a signal or a script. `withSseMaxQueuedBytes()` drops view frames
for a client whose unsent backlog passes 1 MB.

`Channel(50)` was sized conservatively. At high fan-out (hundreds of observers
per route), consider making the capacity configurable per-context. At 30k
connections with large GLOBAL-scoped broadcasts, a channel of 500 to 1,000
prevents drops under burst. The trade-off is memory: each Channel slot holds a
serialized SSE frame (about 100 to 500 bytes), so Channel(1000) × 30k connections =
about 3 to 15 GB RAM in the worst case. Keep it small for TAB-scoped; increase only for
ROUTE/GLOBAL.

### 6. Horizontal scaling (30k+ target)

To reach 30k concurrent connections:

```
[Load Balancer]
      │
   ┌──┴──┐
[Via 1] [Via 2] ... [Via N]   (each handles ~2,000 concurrent SSE connections)
      │
  [Redis / NATS]  ← MessageBroker for cross-node broadcast
```

With 15 nodes × 2,000 connections each = 30,000. The broker ensures a signal
mutation on node 1 fans out to SSE connections on nodes 2 to 15. Redis pub/sub
latency is ~0.5ms; NATS is ~0.1ms.

**The broker is already implemented.** The remaining infrastructure work is:
- A deploy config for each Via node
- A Redis/NATS cluster (or single instance for moderate load)
- A cookie-sticky load balancer, so that a user's page, SSE stream and actions reach the same
  node: contexts, scoped signal values and GlobalState are shared between the workers of one
  machine, not between nodes

### Realistic targets by approach

| Approach | Concurrent SSE connections | Effort |
|----------|---------------------------|--------|
| Baseline (current, single process) | 2,000 (0% failure) | - |
| OS tuning + increased backlog | ~5,000 | Low |
| Multi-worker (8 cores) + OS tuning | ~10,000 | Low |
| Multi-worker + reverse proxy (TLS offload) | ~20,000 | Medium |
| Horizontal scaling (5 nodes) + broker | ~40,000 | Medium |
| Horizontal scaling (15 nodes) + broker | ~120,000 | High |

The broker is already the hardest piece, and it's done.

---

## OPcache / JIT Tuning

Measured May 2026. PHP 8.4.20, OpenSwoole 25.2.0, WSL2 Linux 6.6.87.2.  
Test tool: `tests/Load/bench_opcache.php`: 5,000 actions × cold + warm pass (bench_app);
2,000 actions × cold + warm pass (spreadsheet-live / spreadsheet-raw-live, website app).
Run with `--app=bench` or `--app=website`.

### What was tested

Four workloads, seven profiles:

| Workload | App | What it stresses |
|----------|-----|------------------|
| **Counter** | bench_app | Pure framework overhead: context lookup, signal mutation, SSE patch queue |
| **CPU** | bench_app | Mandelbrot 50×50 grid, ~250k float ops/action. Canonical JIT target. |
| **IO** | bench_app | `usleep(2_000)` per action. Bottleneck is coroutine scheduling, not bytecode. |
| **Spreadsheet live** | website/app.php | Full Twig rendering + SQLite range query (20×10 viewport) + virtual scroll. Real-app baseline. |
| **Spreadsheet raw live** | website/app.php | Same as above but raw PHP string building replaces Twig on the SSE hot path. Isolates Twig overhead. |

### Results summary (warm pass, req/s)

#### bench_app workloads (5,000 actions, concurrency=200)

| Profile | Counter | CPU | IO |
|---------|---------|-----|-----|
| no-opcache (baseline) | 4,107 | 366 | 4,009 |
| opcache-default-cli | 3,872 | 642 | 3,846 |
| opcache-tuned | 4,815 | 817 | 4,449 |
| jit-function | 4,519 | 2,608 | **431** ⚠️ |
| jit-tracing | **4,980** | **2,875** | 4,522 |
| opcache-preload | SKIPPED† | | |
| multi-worker-4w | n/a ‡ | n/a ‡ | n/a ‡ |

† OPcache preloading causes SIGSEGV in OpenSwoole worker fork on this host (known incompatibility with POOL_MODE).  
‡ No usable result: ~83-90% of requests failed with 403 because the context was not registered on all workers. Measured before 0.13.0 made contexts reachable from every worker, and not re-run since. See "Re-measured on the real multi-worker path" above.

#### Spreadsheet live workload (1,000 actions, concurrency=50, website/app.php)

`navigate` action against `/examples/spreadsheet`: full Twig render, SQLite query, real SpreadsheetExample.php logic.

| Profile | Cold req/s | Warm req/s | vs baseline | Cold OK% |
|---------|------------|------------|-------------|----------|
| no-opcache (baseline) | 279 | 190 | n/a | 100% |
| opcache-default-cli | 247 | 265 | +39.5% | 100% |
| opcache-tuned | 314 | 260 | +36.8% | 100% |
| jit-function | 299 | 247 | +30.0% | 100% |
| jit-tracing | **385** | **242** | **+27.4%** | 100% |
| opcache-preload | SKIPPED† | | | |
| multi-worker-4w | n/a ‡ | n/a ‡ | | |

#### Spreadsheet raw live workload (2,000 actions, concurrency=100, website/app.php)

`navigate` action against `/examples/spreadsheet-raw`: raw PHP string building on SSE update path, Twig only for initial page load.
Numbers updated after Step 6 (grid extent cache); all measurements at concurrency=100, 2,000 actions.

| Profile | Cold req/s | Warm req/s | vs baseline | Cold OK% |
|---------|------------|------------|-------------|----------|
| no-opcache (baseline) | 1,149 | 827 | n/a | 100% |
| opcache-default-cli | 911 | 919 | +11.1% ¹ | 100% |
| opcache-tuned | 1,187 | 1,082 | +30.8% | 100% |
| jit-function | 1,108 | 969 | +17.2% ¹ | 100% |
| jit-tracing | **1,316** | **1,143** | **+38.2%** | 100% |
| opcache-preload | SKIPPED† | | | |

¹ Original measurement; not re-run after Step 6. Δ recalculated against updated no-opcache baseline (827 req/s).

### Key findings

**JIT is transformative for CPU-bound code.**  
`jit=tracing` lifts the Mandelbrot workload from 366 → 2,875 req/s (**+685%**, ~7.9×). The tight float loop is exactly what tracing JIT compiles best. `jit=function` gives a comparable gain (+612%) but is unsafe in OpenSwoole (see below).

**`jit=function` breaks OpenSwoole's coroutine hooks: avoid it.**  
IO throughput collapsed from 4,009 → 431 req/s (−89%) with `jit=function`. Root cause: function-mode JIT compiles `usleep()` as a regular function call, bypassing OpenSwoole's `SWOOLE_HOOK_ALL` coroutine hook that makes `usleep()` yield instead of blocking the thread. At 2ms blocking sleep, max throughput = 500 req/s, matching the observed 431. `jit=tracing` does not have this regression.

**OPcache alone gives +75 to 120% on CPU-bound work, modest gains elsewhere.**  
For applications without tight loops, opcache-tuned adds ~17% on the counter workload and ~19% on spreadsheet-live, free gains from eliminating parse overhead.

**IO-bound workloads are unaffected by OPcache/JIT.**  
All profiles hover within ±10% for the IO workload (except the `jit=function` anomaly above). If your bottleneck is database queries, external API calls, or network IO, OPcache/JIT won't help: invest in connection pooling and query optimisation instead.

**Real-app (Twig + SQLite): OPcache gives consistent +30 to 40%; JIT advantage narrows at higher concurrency.**  
The spreadsheet-live workload runs full Twig `renderBlock` + SQLite per action. After Twig file caching and partial block rendering were applied, throughput rose substantially from the April 2026 baseline. All OPcache profiles deliver about +30 to 40% warm gain over interpreted. `jit-tracing` cold (385 req/s) is the best single-pass number, but at concurrency=100 the warm pass (242) falls below opcache-tuned (260): SQLite I/O saturation masks the JIT advantage under sustained load. The raw comparison makes the real bottleneck clear: removing Twig from the SSE update path gives **3 to 4× throughput** (827 to 1,143 req/s raw vs 190 to 265 req/s Twig on this host at concurrency=50), making it the single largest optimization available, larger than JIT and larger than all other Twig optimizations combined.

### Recommendations

| Use case | Recommended `php.ini` settings |
|----------|-------------------------------|
| Application with CPU-heavy actions (templates, data transformation, crypto) | `opcache.jit=tracing`<br>`opcache.jit_buffer_size=64M`<br>`opcache.enable_cli=1` |
| Framework-heavy, mostly IO | `opcache.enable_cli=1`<br>`opcache.memory_consumption=128`<br>`opcache.validate_timestamps=0` (production only) |
| **Never use** in an OpenSwoole app | `opcache.jit=function`: destroys `usleep()`/`sleep()` coroutine yields |

Minimal production-safe config for a Via application:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0   ; set to 1 during development
opcache.jit=tracing             ; safe with OpenSwoole; skip if no CPU-bound work
opcache.jit_buffer_size=32M
```

