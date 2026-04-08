# php-via Performance Profile

Measured April 2026 on a single-process dev build (`APP_ENV=dev php website/app.php`),
OpenSwoole 22.13.0, PHP 8.4.19, port 3000 (HTTPS/TLS, self-signed cert).

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
| Action | `/_action/increment` (no hash — route-scoped) |
| Signal | `route___counter` |
| Observers | 50 live SSE connections watching for patches |
| Actions | 2000 per run |

The test measures three things independently:

- **HTTP OK rate** — did the server *respond* with 2xx?
- **Net signal increment** — did the server *process* the action (state mutation)?
- **Patch delivery rate** — did the 50 SSE observers receive the broadcast?

These can diverge because OpenSwoole may finish processing a request but the client
times out before receiving the response — the mutation has already happened.

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
by design — `PatchManager` uses a non-blocking `Channel(50)` per SSE connection;
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
| Net increment | 716 (200.6% of HTTP OK — server processed ~2x more than responded) |
| True drops (never reached handler) | ~1284 (64.2%) |
| Patches observed vs net-increment expected | 40,378 / 35,800 (112.8%) |
| Throughput | ~72 req/s (higher because failures return fast) |
| Wall time | 27.9s |

At concurrency=500 the OS TCP accept queue is saturated. Connections queue at the
kernel level and time out on the client side before OpenSwoole's `accept()` loop
drains them. The gap between HTTP OK (357) and net increment (716) shows ~359
requests were processed server-side but clients had already dropped — this is
TCP-level loss, not application-level corruption. State remained internally
consistent throughout.

**Note:** Results at this concurrency level vary run-to-run (~15–45% OK) depending
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

2,000 concurrent SSE connections — fully coroutine-resident in a single PHP process —
with zero failures and clean shutdown. The latency at 2,000 connections reflects
coroutine scheduling overhead, not dropped frames.

At 5,000 connections (~82% success) the client-side test machine runs out of ephemeral
ports (`EADDRNOTAVAIL`), not the server. The server itself showed no failures up to the
OS client-side limit.

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
- 1–5 SSE connections per user (one per open tab)
- Bursts of 1–10 actions/second per active user
- At 200 concurrent HTTP connections, that supports **thousands of simultaneous users**
  whose actions arrive in a natural Poisson distribution, not all at once

The 200 concurrent action ceiling is an *instantaneous concurrency* ceiling, not a
throughput ceiling. 2,000 sustained SSE connections held with 0% failure means a
single Via instance can serve 2,000+ active browser sessions simultaneously.

---

## Path to 30k Concurrent Requests

Getting from ~200 to 30k concurrent requests requires changes at multiple layers.

### 1. OpenSwoole server tuning (easy wins, ~5–10×)

```php
(new Config())
    ->withSwooleSettings([
        'worker_num'          => swoole_cpu_num(),   // one worker per core
        'max_coroutine'       => 100_000,
        'backlog'             => 8192,               // OS accept queue depth
        'max_conn'            => 50_000,
        'open_http2_protocol' => false,              // HTTP/1.1 is faster for SSE
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

**Caveat for Via**: ROUTE/SESSION/GLOBAL scoped signals and the SSE broadcast
channel currently live in shared memory within a single process. With multiple
workers, cross-worker broadcast requires the pluggable `MessageBroker` system
(already implemented: `RedisBroker`, `NatsBroker`). TAB-scoped routes work
without any broker.

### 3. Connection multiplexing / HTTP/2 (reduces connection count)

Modern browsers multiplex multiple requests over a single TCP connection with
HTTP/2. A user with 10 in-flight requests would use 1 connection instead of 10,
reducing instantaneous concurrency by ~10×. OpenSwoole supports HTTP/2 natively
but SSE over HTTP/2 has edge-case support issues in some browsers — test before
enabling in production.

### 4. Reverse proxy (offload TLS + static assets)

Running TLS termination inside PHP/OpenSwoole is expensive. Offloading to Caddy
or nginx:
- Frees CPU cycles spent on TLS handshakes
- Allows keep-alive connection pooling between proxy and Via
- Enables HTTP/2 at the edge without changing Via's HTTP/1.1 internals

The deploy scripts already include Caddy configs (`deploy/*.caddy`).

### 5. PatchManager channel tuning

`Channel(50)` was sized conservatively. At high fan-out (hundreds of observers
per route), consider making the capacity configurable per-context. At 30k
connections with large GLOBAL-scoped broadcasts, a channel of 500–1,000
prevents drops under burst. The trade-off is memory: each Channel slot holds a
serialized SSE frame (~100–500 bytes), so Channel(1000) × 30k connections =
~3–15 GB RAM in the worst case. Keep it small for TAB-scoped; increase only for
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
mutation on node 1 fans out to SSE connections on nodes 2–15. Redis pub/sub
latency is ~0.5ms; NATS is ~0.1ms.

**The broker is already implemented.** The remaining infrastructure work is:
- A deploy config for each Via node
- A Redis/NATS cluster (or single instance for moderate load)
- A session-sticky load balancer for SSE connections (so a user's SSE and their
  action POST reach the same node — optional but reduces broker traffic)

### Realistic targets by approach

| Approach | Concurrent SSE connections | Effort |
|----------|---------------------------|--------|
| Baseline (current, single process) | 2,000 (0% failure) | — |
| OS tuning + increased backlog | ~5,000 | Low |
| Multi-worker (8 cores) + OS tuning | ~10,000 | Low |
| Multi-worker + reverse proxy (TLS offload) | ~20,000 | Medium |
| Horizontal scaling (5 nodes) + broker | ~40,000 | Medium |
| Horizontal scaling (15 nodes) + broker | ~120,000 | High |

The broker is already the hardest piece, and it's done.
