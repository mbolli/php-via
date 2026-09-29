# Performance & Correctness TODO

Tracked backlog from the comparison against Anders Murphy's Hyperlith render-loop
model (fixed clock + lock-step write/render batches + sorted invoke-all onto a
blocking pool + async-only-at-the-socket).

Context: php-via already matches Hyperlith on the wire (full-fragment morph over a
long-lived Brotli SSE stream) and beats it on shared-view render cost
(`ViewRenderer` renders once per scope and shares the string; Hyperlith re-renders
per connection). What php-via lacks is the *engine*: the clock, the coalescing, and
the lock step.

## Status (2026-08-26)

**Closed: 0a-0g, 1, 3, 4, 5, 6, 7, 8, 9, 10, 12, 13, 14. Only #2 remains open.**
Multi-worker went from non-functional to usable for stateful routes over the course of this work.

| # | Item | Status |
|---|---|---|
| 0a-0d | Multi-worker blockers (dispatch, worker_num, nodeId) | `DONE` `b4c3525` `692e334` `c5725cb` |
| 0e | `setInterval()` in every worker | `DONE` `f388526` |
| 0f | Rate limiter per-worker (`limit x worker_num`) | `DONE` `1ab7230` |
| 0g | Client registry per-worker | `DONE` `ed4f7dd` |
| 1 | Non-idempotent patches destroyed | `DONE` `c88a8d5` `025bfc5` |
| 2 | Brotli window size | `DONE` — window is not tunable; the real finding is 8.9 MB/connection |
| 3 | Coalesce broadcasts / split frame | `DONE` `e739186` |
| 4 | SSE loop liveness | `DONE` `1af5888` |
| 5 | Slow-consumer frame drop | `DONE` `964676f` |
| 6 | Cross-worker actions | `DONE` `f26604d` |
| 7 | Shared scoped signal values | `DONE` `d063820` (stage 1) `e2a506b` (stage 2) |
| 8 | `SharedTable` capacity + key length | `DONE` `2ece0a7` `7a79667` |
| 9 | SQLite instead of `SharedTable` | `DONE` — tiered, not replaced |
| 10 | First-class single-writer API | `DONE` (a) `e739186`, (b) as write-behind, no extra process |
| 11 | HTML caching in shared storage | **CLOSED — gate failed, do not build** |
| 12 | `Scope::ROUTE` never expanded | `DONE` `9e4e621` |
| 13 | `addScope()` disables the view cache | `DONE` `2163721` `18e20d2` `e7d0e83` |
| 14 | Signal-less component frozen | `DONE` `f1a3270` |

### What multi-worker does and does not do now

Works: actions land on any worker; scoped signal values are shared; `Signal::increment()` and
`Signal::mutate()` are race-free; server timers fire once; one rate limit; one client list.

Does not: mutating a scoped signal by reading it and calling `setValue()` loses updates under
concurrency (use `increment()`/`mutate()`), and PHP statics in user handlers stay per-process, so
a simulation kept in one diverges per worker. Both are logged at start-up when `worker_num > 1`.

### Prescriptions in this document that measurement refuted

Recorded because each looked obviously right and would have shipped a regression.

| Item | The plan said | What was true |
|---|---|---|
| 13 | Give the seven `addScope()` routes a real primary scope | Would have served one client's HTML to another; six of the seven already opt out via `cacheUpdates: false`, so it was also a no-op for them |
| 6 | Move `$revivableContexts` into `SharedTable` | Revival records only exist AFTER `destroyContext()`. A context alive on another worker has no record, so the directory had to be written at CREATION |
| 6 before 7 | Fix routing first, then shared values | Backwards. Routing alone turns a loud `400` into a silent wrong answer; 7 is a prerequisite |
| 7 | `SharedTable` has no atomic incr, so RMW needs an owner worker + broker | True of php-via's wrapper, not of `OpenSwoole\Table`. `incr()` is atomic (100% vs 31% under 8-way contention). Numeric RMW needed no bus at all |
| 7 | Non-numeric RMW needs the single-writer design | A ticket lock built from `incr()` was enough, because the callback stays on the calling worker and no closure crosses a process boundary |
| 8 | `Table::set()` returns false at capacity | It throws. And `incr()` neither throws nor returns a usable value — it warns and returns `false` |
| 8 | Capacity is `maxRows` | 1.6-2.0x `maxRows`, and rejection is intermittent by key hash |
| 4 | There is a 100 ms poll to optimise | No such poll in production; the defect was liveness, not latency |
| 3 | The fan-out is atomic by accident | It is not; a split frame was reproduced |
| — | `PERFORMANCE.md`'s 16-worker run measured php-via's multi-worker path | It used `withSwooleSettings`, so `getWorkerNum()` stayed 1 — no affinity, no `SharedTable`, no broker guard |

---

## Verification environment (baseline, 2026-08-24)

All empirical claims below are being validated against this environment. Anything marked
`UNVERIFIED` could not be measured here.

| Component | State |
|---|---|
| PHP | 8.5.9, **NTS** |
| ext-openswoole | 26 — installed and working; real multi-worker servers can be started |
| ext-sqlite3 / pdo_sqlite | installed |
| ext-redis | installed (a reachable Redis server is a separate question) |
| **ext-brotli** | **NOT installed** — item 2 cannot be measured here |

Test baseline: `vendor/bin/pest` -> **473 passed, 1 failed, 13 deprecated, 4 warnings**.
The single failure is `src/Http/RequestHandler.php:748` reaching `BROTLI_TEXT` with no
ext-brotli present — expected in this environment, not a regression. Any future run showing
a different pass count has introduced something.

Item 2 (brotli window) therefore stays **UNVERIFIED**: both the extension's default LGWIN
and the per-connection memory footprint need a machine with ext-brotli. Do not act on that
item's table until measured — the direction of the recommendation depends on the default.

Status legend: `BUG` = correctness defect, ship a fix. `OPT` = optional improvement.
`SPIKE` = research, not schedulable yet.

---

## 0. Multi-worker mode is non-functional today — `DONE` (all of 0a-0g)

> **0a-0g are DONE**, along with items 6, 7 (stage 1) and 8. Multi-worker now routes actions to
> any worker, shares scoped signal values, arms server timers once, enforces one rate limit, and
> reports one client list. What remains is stage 2 of item 7: read-modify-write on a *non-integer*
> scoped signal still loses updates across workers.

Verified on real `Via` servers at `worker_num` 2/4/8/16 with `SwooleBroker`. These are not in
any earlier draft. **They must be fixed before items 6, 7 or 8 can even manifest**, and two of
them had to be shimmed around before those items could be tested at all.

### 0a. `dispatch_mode => 7` is the wrong constant — session affinity has never executed

`src/Via.php:673` sets `dispatch_mode = 7` with the comment *"custom dispatch_func"*.
`SW_DISPATCH_USERFUNC` is **6**; 7 is stream mode, which ignores `dispatch_func` entirely.

```
dispatch_mode=6 -> 0->0 1->1 2->2 3->3 0->0 1->1 ...   (dispatch_func honored)
dispatch_mode=7 -> 0->3 1->3 2->3 3->3 0->3 1->0 ...   (dispatch_func ignored)
```

`SessionManager::workerForRequest()` is dead code in production. Every claim resting on
"sticky routing is load-bearing" currently rests on nothing. Measured effect at 16 workers with
a keep-alive client: **56.5% OK / 43.5% `400 Invalid context`**, versus 100% with mode 6 — i.e.
**the affinity feature currently performs worse than not having it**, because mode 7 scatters
per *request* where OpenSwoole's default fd-mod dispatch is at least per *connection*.

### 0b. `dispatch_func` fatals on PHP 8.4+ — 0a and 0b must ship together

```
PHP Fatal error: Maximum call stack size of 8306688 bytes
(zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?
```

The callback runs on the master reactor thread where PHP 8.4+'s stack-limit check mis-detects
the stack base; it fires on **every dispatch**. `zend.max_allowed_stack_size=-1` clears it.
So fixing 0a alone converts a silent no-op into a fatal-per-request. Either handle the ini, or
move affinity to an L7 proxy and drop `dispatch_func`.

### 0c. `$server->worker_num` does not exist on ext-openswoole 26 — zero cross-worker broadcast

`src/Via.php:816` passes `$server->worker_num` to `SwooleBroker::setServer()`. That property does
not exist in openswoole 26 (it is `$server->setting['worker_num']`), so every worker logs:

```
PHP Warning: Undefined property: OpenSwoole\Http\Server::$worker_num in src/Via.php on line 816
[ERROR] Broker connect failed in worker 0: SwooleBroker::setServer(): Argument #3 ($workerNum)
        must be of type int, null given — running without multi-node broadcast
```

The TypeError is swallowed, `$this->server` stays null, and `publish()` returns early forever.
**This is a fresh regression** — the branch just moved to `ext-openswoole ^26` in commit `e604545`.

### 0d. `SwooleBroker` nodeId is fork-inherited — the pipeMessage guard drops 100% of messages

`SwooleBroker::__construct()` generates `nodeId` via `random_bytes()`, but the broker is
constructed in user code **before** `start()`, so every worker inherits the same value:

```
[w0] setServer nodeId=a72b0ca809e28611
[w1] setServer nodeId=a72b0ca809e28611
[w2] setServer nodeId=a72b0ca809e28611
```

`Via.php:707`'s guard (`if ($msg['nodeId'] === $this->broker->getNodeId()) return;`, commented
"redundant belt-and-suspenders") therefore drops **every** pipe message. With 0c fixed but this
untouched, worker B still received nothing. Regenerate the nodeId in `setServer()`, or drop the
guard — `publish()` already skips self.

### 0e. `setInterval()` runs in every worker — `DONE` (`f388526`)

Real server, 4 workers, one 100 ms interval over 2000 ms: **fires=77, pids=4** against an
expected 18-20. After gating on the leader worker: **fires=19, pids=1**, identical at 1, 4 and 8
workers. `everyWorker: true` opts back in for genuinely per-process work.

Does **not** fix GameOfLife/StockTicker under multi-worker: they keep their simulation in PHP
statics, which are per-process and cannot be shared by item 7 either. Before, each worker
advanced its own divergent board; after, only the leader's advances. Both are broken; the change
swaps one failure mode for another in a configuration already documented as unsupported for
state like that.

### 0f. Rate limiter is per-worker — `DONE` (`1ab7230`)

`ActionHandler::$rateLimitBuckets` was a plain property on a handler built before the fork.
Reproduced exactly, `withActionRateLimit(5, 60)`, one client IP:

| workers | allowed (before) | allowed (after) |
|---|---|---|
| 1 | 5 | 5 |
| 2 | 10 | 5 |
| 4 | 20 | 5 |
| 8 | **40** | 5 |
| 16 | — | 5 |

Moved into `RateLimiter`, backed by a master-allocated `OpenSwoole\Table`. The timestamp list
could not be shared — read-modify-write on a Table row is not atomic across processes and there is
no CAS — so it became a sliding-window counter over atomic `incr()`/`decr()`, one row per IP per
window bucket, `estimate = prev * (1 - elapsed/window) + curr`. Denied requests are decremented
back out, preserving the old "hammering does not extend the lockout" behaviour.

**Two corrections to item 8's `Table` findings, both measured on ext-openswoole 26:**
- **`incr()` does not throw at capacity** — unlike `set()`, it emits `unable to allocate memory` as
  a PHP *warning* and returns `false`. Success has to be judged by whether a row appeared.
- **`incr()` capacity is 1.7-2.0x the requested rows** (8192 -> 15,827), not the 1.6-1.95x
  measured for `set()`. `getSize()` equals `maxRows` exactly here — no power-of-two rounding at
  these sizes.

Overflow fails open and logs once; an exhausted table means an unusual number of distinct client
IPs, and denying everyone would be the larger outage.

**Found while fixing this — `log('warning')` was silently demoted to info severity** (`e2b8208`).
`Logger::LEVELS` defined only `'warn'`, so `'warning'` fell through to the info default: it printed
a `[WARNING]` label but was *filtered as info*, meaning `withLogLevel('warn')` — the setting whose
whole purpose is showing warnings — **suppressed every one of them**. Eight call sites used that
spelling, including the invalid-wire-scope rejection and the context authorisation failures. The
same fallback applied to `'warning'` as a *threshold*, so asking for warnings-and-above quietly got
info-and-above.

### 0g. Client registry is per-worker — `DONE`

Reproduced with 6 SSE connections held open, one `getClients()` count per probed worker:

| workers | before | after |
|---|---|---|
| 1 | 6,6,6,6,6,6,6,6 | 6 x8 |
| 4 | 2,1,2,2,2,1,1,1 | 6 x8 |
| 8 | 1,1,1,1,1,1,**0,0** | 6 x8 |

`SharedClientRegistry` keys by hashed context ID and heartbeats from the same SSE idle branch as
the context directory, so a worker that dies without running its disconnect path cannot leave
its clients in the list forever.

**The identicon is deliberately not stored.** It is a 1,550-byte SVG derived deterministically
from the client ID, so storing it would have quintupled the row for something any worker can
regenerate.

---

## 1. Non-idempotent patches are silently destroyed — `DONE` (`c88a8d5`, `025bfc5`)

**Files:** `src/Context/PatchManager.php:68-88` (eviction), `:270-283` (`markSynced`),
`:228` (`recreatePatchChannel`), `src/Http/ActionHandler.php:101`

Root cause confirmed: `prepareSignalsForPatch()` calls `markSynced()` at **queue** time, so any
patch destroyed before transmission is never retried. Element patches are idempotent
full-fragment morphs and survive this; `signals` and `script` patches do not.

Reproduced on both queue paths (array/test-mode and real `Channel`), identical threshold —
25 broadcasts (50 patches) converges, 26 (52 patches) diverges permanently:

```
TAB    status : server='RUNNING'  client='idle'   <-- STALE
SCOPED players: server=7          client=7        OK
status->hasChanged()=false
after 10 more broadcasts: client status='idle'
*** TAB PERMANENTLY DIVERGED ***
```

### Three corrections to the original description

**1. The trigger is reconnect, not queue overflow.** `SseHandler.php:167` calls
`recreatePatchChannel()`, which discards the **entire** pending queue; the `sync()` that follows
only re-sends signals still marked `changed` — and the dropped ones were marked synced at queue
time. Verified with **2 pending patches**, nowhere near the 50-slot cap:

```
pending patches at reconnect: 2 (well under the 50-slot cap)
server turn=YOUR_TURN  client turn=waiting
*** STALE after a single reconnect — no overflow required ***
```

Every network blip, mobile handoff, sleep/wake and proxy timeout is a coin flip on whatever is
in flight, and contexts survive `contextCleanupDelayMs = 5000`, so up to 5 s of broadcasts queue
into a dead channel before being destroyed. **This is the dominant real-world path.**

**2. It corrupts server state, not just the view.** `ActionHandler.php:101` ->
`SignalFactory::injectSignals()` writes client values back into TAB signals with
`markChanged: false`. A stale client therefore **overwrites the authoritative server value on
the user's next action**, leaving no dirty flag to repair it:

```
server score=100  client score=0
after ONE user action: server score=0  hasChanged=false
*** SERVER STATE DESTROYED (rolled back to stale client value) ***
```

The earlier "client stays stale until the value changes again" understates this — the server
converges *down* to the stale value.

**3. `script` patches are lost too, and neither proposed fix covers them.** `execScript()` is
non-idempotent and evicted by the same policy. `LoginExample.php:73,107,143,169` uses it for
post-login `window.location.href` navigation and `SpreadsheetExample.php:350` for clipboard
writes. A lost script is a lost *action* — never retried, never noticed.

### Reachability

Scoped signals are **immune** (verified): `syncScopedSignals()` never calls `markSynced()` and
re-queues the full snapshot every sync, so eviction self-repairs. The defect is exactly and only
`prepareSignalsForPatch()`.

- **`GameOfLifeExample` cannot exhibit this** — highest-rate broadcaster (200 ms) but declares
  **zero signals**; element-only and idempotent. The flagship stress case is the wrong place to
  look for it.
- **`SpreadsheetExample` is the realistic victim** — 19 explicit `Scope::TAB` signals plus a
  scoped `v` (2-3 patches/broadcast, so 17-26 broadcasts to overflow), and the server
  authoritatively writes `editing`, `editValue`, `focusRow/Col`, `viewRow/Col`, `jump`, `pasted`
  (lines 111-399). Arrow-key repeat is ~30 events/s; five collaborators overflow in 1-2 s. A
  dropped `editing=false` sticks a cell in edit mode, and the next action rolls the *server* back
  to `editing=true`.
- ChatRoom (`messageInput`) and LiveAuction (`bidInput`) share the shape at lower rates.
- With Brotli on, the blocked-write path effectively never overflows (GoL compresses to ~3.2 KB/s,
  so the 1 MB `socket_buffer_size` at `Via.php:652-655` takes ~5 min to fill). Without Brotli,
  ~11 s of a genuinely stalled client suffices.

### Fix evaluation — both prototyped

**(a) Defer `markSynced()` to after a successful write — VIABLE. Ship this.** My earlier concern
(*"a patch can be evicted before it is ever written, so how would `markSynced()` ever be called"*)
was **backwards in a useful way**: the patch never reaches the writer, so `markSynced()` is never
called, the signal **stays dirty**, and the next `syncSignals()` re-includes it. Eviction becomes
self-healing instead of lossy. Works because `Coroutine\Channel` passes PHP values **without
serialization** (verified: object identity and closures survive push/pop), so a patch can carry
`Signal` references directly; component forwarding needs no extra routing.

Converges at N=25/60/200 and on the reconnect repro. Costs measured: patch volume rises during a
stall (same 61 broadcasts went `elements=25 signals=25` -> `elements=16 signals=34`), so the
queue overflows ~33% sooner — but the extra evictions land on idempotent element patches.
`write()` returning true means *buffered*, not *received* — strictly better than queue time, not a
delivery guarantee. `hasChangedSignals()` (`:166`) stays true while a write is pending, so
component sync-skipping degrades under backpressure.

**(b) Full snapshot in signal patches — REJECT.** It must be *unconditional* to work (a
`hasChanged`-gated snapshot still loses the last patch), and unconditional snapshots **clobber
every `data-bind` input on any page receiving broadcasts**, because TAB signals are always
client-writable:

```
--- current ---                    --- proto b ---
typed 'H' -> browser holds 'H'     typed 'H' -> browser holds ''
typed 'E' -> browser holds 'HE'    typed 'E' -> browser holds ''
*** USER INPUT CLOBBERED ***
```

Note (b) **passes the existing suite unchanged** — meaning no current test covers concurrent
typing during a broadcast. Add one regardless of which fix ships.

### Ship (a) plus three things it does not cover

1. **Type-aware eviction.** Evict the oldest **`elements`** patch; never silently drop `signals`
   or `script`. Both eviction sites (`:68-72` array, `:77-84` channel) need it — they are
   duplicated, so encode the invariant in a comment at each.
2. **`recreatePatchChannel()` / `closePatchChannel()` must not silently discard.** Under (a)
   signals self-repair, but `script` patches are still destroyed. Drain and re-queue, or log.
3. **`script` needs its own policy** — non-idempotent and unrecoverable under both fixes. A lost
   redirect is a broken login flow.

Also: `queuePatch()` ignores `push()`'s return value. On a **closed** channel `isFull()` still
reports `true` while data remains, so the eviction loop runs and then `push()` returns `false`,
dropping the patch with no signal to the caller. Same class, same fix location.

---

## 2. Brotli window size is untuned — `DONE`, **and the proposed fix is impossible**

ext-brotli was built from source (0.21.0, `--with-libbrotli`) to verify this. Answers to the
three actions, in order:

**1. The default LGWIN is 22 (4 MB)** — `BROTLI_DEFAULT_WINDOW`. Confirmed from the extension
source: `brotli_compress_init()` takes only `(level, mode, dict)` and passes `0` for lgwin, which
the helper resolves to the default. **So the window cannot be set at all from PHP**, and action 3
("add a window argument to `Config::withBrotli()`") is not implementable without an upstream
change. Neither the memory saving from a smaller window nor Anders' CPU win from a larger one is
available.

**2. The memory footprint is real, and worse than this item estimated.** Measured against real
captured Game-of-Life SSE frames (~127 KB each), RSS delta per encoder at steady state:

| level | per encoder | at 2,000 conns | ratio | CPU/frame |
|---|---|---|---|---|
| 1 | 574 KB | **1.1 GB** | 19.0:1 | 90 µs |
| 2 | 8,573 KB | 16.4 GB | 26.9:1 | 190 µs |
| 3 | 8,567 KB | 16.3 GB | 27.8:1 | 218 µs |
| **4 (default)** | 8,851 KB | **16.9 GB** | 30.3:1 | 277 µs |
| 5 | 9,677 KB | 18.5 GB | 40.3:1 | 450 µs |
| 8 | 13,577 KB | 25.9 GB | 49.5:1 | 1,094 µs |
| 11 | 31,474 KB | 60.0 GB | 75.3:1 | **94,551 µs** |

The estimate in the table below said ~8 GB at LGWIN 22; it is **16.9 GB**, because the encoder
holds hash tables as well as the 4 MB ring buffer — roughly 2x the window.

**The growth is lazy and saturating**, which the original note guessed correctly: 7 KB at init,
plateauing after ~8 MB of traffic (identical at 8, 64 and 256 MB fed) and never shrinking. So the
cost tracks traffic, not connections — this item was also right that the existing
2,000-connection result does not clear it, since that run used a low-volume counter.

**What shipped:** the measurements are documented on `Config::withBrotli()` and in
`PERFORMANCE.md`. The actionable lever is the quality level, not the window: level 1 costs 15x
less memory and 3x less CPU for 37% more bytes on the wire. The default was left at 4 — it is
right for hundreds of connections and wrong for thousands, and that is a deployment decision the
documentation now supports rather than one to guess at globally.

---

### Original analysis

**File:** `src/Http/Middleware/BrotliMiddleware.php:40`

`brotli_compress_init($this->level)` passes quality only; no window parameter, so every
SSE connection inherits the extension default LGWIN. Anders measured 30:1 -> 150-250:1
compression and **4-8x less server CPU** going from a 32 KB to a ~256 KB window
(https://andersmurphy.com/2025/04/15/why-you-should-use-brotli-sse.html). The docs
(`website/templates/docs/design.html.twig`, `comparisons.html.twig`) already explain why
the sliding window matters but never expose it.

**Memory footprint — this cuts both ways and must be measured before tuning:**

The encoder holds per-connection state whose ring buffer grows lazily toward the window
cap as the stream feeds it. Cost is `N_connections x window`, so it scales with exactly
the thing php-via is optimised for (many long-lived streams):

| LGWIN | Window  | x2,000 conns (ring buffers alone) |
|-------|---------|-----------------------------------|
| 18    | 256 KB  | ~512 MB                           |
| 22    | 4 MB    | ~8 GB  <- libbrotli's default     |

If the extension passes libbrotli's default (LGWIN 22), php-via may *already* be on the
bottom row for high-volume routes — meaning this is a latent memory issue to fix by
*lowering* the window, not only a CPU win to be had by raising it.

**The existing 2,000-connection result in PERFORMANCE.md does not clear this.** That run
(`--route=/examples/counter`) holds connections open against a low-volume counter, so the
ring buffers never grew near the cap. A Game-of-Life-shaped connection (~15 MB raw over
18 s) does reach it.

**Action:**
1. Determine the actual default LGWIN that `ext-brotli` passes through.
2. Re-run `tests/Load/sse_connections.php` against `/examples/game-of-life`, not
   `/examples/counter`, and record RSS delta per connection at steady state.
3. Only then add a window argument to `Config::withBrotli()`, and document the
   memory/CPU trade in the same table shape as above.

---

## 3. Coalesce broadcasts behind a server tick — `DONE` (`e739186`) — **SPLIT FRAME REPRODUCED**

**Files:** `src/Via.php:471` (`broadcast`), `:1416` (`doSyncLocally`)

The coalescing case stands unchanged: no debounce, dirty-flag or throttle exists in `src/`.
Ten actions inside 100 ms produce ten cache invalidations, ten renders, 10xN pushes.
`PERFORMANCE.md:56` measures it — ~46 req/s with 50 observers, 99,492 patches for 2,000
actions. The bottleneck is amplification, not PHP.

### CORRECTION — the "atomic by accident" fan-out does not exist (verified)

An earlier draft asserted `doSyncLocally()`'s loop has no suspension point and therefore
guarantees one broadcast = one frame for everyone, and that this accident must be preserved
deliberately. **Measured on a real OpenSwoole server: false.**

`Channel::push()` **synchronously resumes a parked consumer inside the `push()` call**, before
the pusher's next statement. Since every idle SSE coroutine is parked in `pop()` (see item 4),
`queuePatch()` yields on essentially every push. Observed interleave, real HTTP server:

```
[8023.76ms] cid=4  BC before push -> conn2
[8024.31ms] cid=2  conn2 RESUMED ... -> calling res->write()
[8024.34ms] cid=4  BC after  push -> conn2      <- broadcaster resumed mid-write
[8024.84ms] cid=2  conn2 res->write() RETURNED
```

Client socket I/O executes **interleaved with the fan-out loop**. The split-frame failure is
reproducible today: with the loop re-reading a shared view cache per context and a consumer
invalidating on resume, four contexts received `["frame1","frame2","frame2","frame2"]`.

**Client-visible split reproduced end-to-end.** ROUTE scope, `cacheUpdates: false`, 6 live SSE
clients, two concurrent broadcasts each mutating a shared PHP static:

| view body | render sequence in one round | client sequences |
|---|---|---|
| pure CPU | `[11 11 11 11 11 11][12 12 12 12 12 12]` | **1 distinct** — all clients saw F11 then F12 |
| one `file_get_contents` | `[11 11 12 12 12 12 12 12 12 12 12 12]` | **2 distinct** — clients 2-5 **never saw F11** |

Broadcast A rendered two contexts at frame 11, suspended on the hooked read, broadcast B ran its
*entire* fan-out, then A resumed and rendered its remaining four against B's newer state. **This
needs no tick, no `pop($timeout)`, and no code change to occur** — which is why this item is now
a `BUG`.

Reachable today: it needs a suspension point in a view *and* every context rendering, i.e.
`cacheUpdates: false` or a TAB-primary scope. Both ship: `website/app.php`'s `presenceDemo` and
`livePollDemo` are `cacheUpdates: false`, and `SpreadsheetExample` is TAB-primary (item 13). With
`cacheUpdates: true` only one render happens, so the loop is interrupted at most once — but the
interrupted render then **overwrites the newer broadcast's cache entry with the older frame**, the
same hazard wearing a different hat.

**Counter-intuitive result: enabling `twigCacheDir` makes the fan-out MORE atomic.** A *first-ever*
Twig compile suspends (14.6 ms / 3 ticks uncached; 208 ms / 6 ticks compiling to disk), but once
compiled, the cached path is `include` + `filemtime`, **neither of which is hooked**. Turning the
cache on removes the hooked source read from every steady-state path and adds no per-render I/O.

**Consequences for the design:**

- **Hoisting the render out of the loop is mandatory, not a review note.** Render once into a
  local, push that value to every context, never re-read the view cache per context. This is
  *sufficient* for the split-frame problem specifically — the failure only occurs when the loop
  re-reads shared state mid-iteration.
- **Stop describing the loop as atomic.** Anything else placed in it — a `SharedTable` read, a
  per-context signal diff, an `isWritable()` check, item 5's frame-drop logic — runs exposed to
  arbitrary consumer code including socket writes, and must be independently re-entrancy-safe.
- **`Timer::tick` fails in BOTH directions, and they are complementary.**
  *Overlap when the body yields:* with a 30 ms tick and a 70 ms body, three callbacks were in
  flight simultaneously — ticks do not serialize.
  *Dropped when the loop is blocked:* under 216 ms non-yielding stalls, a 50 ms tick fired only
  **82/120 and 67/120 times** (32% and 44% loss) — ticks are dropped, not queued.
  So a tick-drain design will **skip ticks under exactly the load it exists to handle**. The
  drain must therefore be **idempotent and size-bounded**, never "one tick's worth of work". The fan-out body *will* yield
  (above), so a naive "one tick drains dirty scopes" **will overlap itself under load and
  interleave two frames** — precisely the failure this item exists to prevent. Requires an
  explicit `$tickInFlight` guard, or drain-and-reschedule instead of `Timer::tick`.

True atomicity is obtainable only via an `isEmpty()` guard plus a non-channel yield, which costs
back the full poll latency. Not recommended — hoist the render instead.

**Ordering: this item now depends on item 4 landing first.**

---

## 4. SSE loop liveness — `DONE` (`1af5888`), **not the latency optimization previously described**

**Files:** `src/Http/SseHandler.php:180-250`, `src/Context/PatchManager.php:93-115`

### CORRECTION — there is no 100 ms poll in production (verified)

The premise of the earlier draft was that `ssePollIntervalMs = 100` adds ~50 ms median
latency. **Measured: php-via does not pay this.** `OpenSwoole\Coroutine\Channel::pop(float
$timeout = -1)` arms a timer **only when `$timeout > 0`**. `0` and `-1` are identical: park
until push or close. Independently reproduced:

```
[main] after spawning A, elapsed 0.0ms
[main] 250ms later, still nothing => pop(0) PARKED
[A] pop(0) returned 'late' after 251.4ms errCode=0
```

`PatchManager.php:111` is `$this->patchChannel->pop(0)` under a docblock at :109 reading
*"Non-blocking pop: return immediately if data is available, null otherwise."* **The docblock
is wrong.** In production (`useArray === false`), `getPatch()` returns null only when the
channel is *closed* — never when merely empty.

Therefore the `usleep()` branch at `SseHandler.php:219` is **dead code** — measured
`usleep-branch-taken=0` on every connection in every run. Measured latency, broadcast every
25 ms, 40 rounds:

| pattern | N | p50 (ms) | p99 (ms) | max (ms) |
|---|---|---|---|---|
| `pop(0)` — what php-via actually runs | 500 | 0.086 | 0.197 | 0.227 |
| `pop(0.1)` — the proposed change | 500 | 0.106 | 0.248 | 0.281 |
| genuine non-blocking + `usleep(100ms)` | 500 | 50.374 | 98.991 | 101.482 |

The "~50 ms median / 100 ms worst case" figure is numerically exact **for the pattern the code
was intended to implement** — and php-via implements something else. Switching to
`pop($timeout)` buys **zero latency**.

### PRIOR ART — this was shipped and reverted; do not repeat it blindly

`git log -S` on the SSE loop turns up the whole round trip:

- **`0c05bc7` (2026-03-02)** *"replace Coroutine::sleep(1) with event-driven channel
  blocking"* — introduced `pop($pollTimeout)`, i.e. exactly what this item proposes, and
  added `Config::$ssePollIntervalMs` to tune it.
- **`fcab883` (2026-03-04, two days later)** *"prevent 100% CPU spinloop by yielding when
  no patch is available"* — reverted it: *"Channel::pop(timeout) returns false immediately
  on a closed channel, causing the SSE loop to spin at 100% CPU after context cleanup."*

That is the same failure A1 reproduced from the other direction (47.5M iterations in 3 s).

**Consequently the earlier claim that `SseHandler.php:219`'s `usleep` is "dead code" is
WRONG, and so is the framing of this whole item.** Measured directly:

| channel state | `pop(0)` | `pop(0.1)` |
|---|---|---|
| open + empty | **parks** (returned only on push, 121 ms) | parks |
| closed | returns instantly — **819,424 iterations / 50 ms** | returns instantly — **1,239,663 iterations / 50 ms** |

The `usleep` branch is unreachable only while the channel is **open**; on a **closed**
channel `pop(0)` returns immediately, `getPatch()` returns null, and the `usleep` is what
stops the spin. It is the spinloop guard `fcab883` deliberately added. A1 measured
`usleep-branch-taken=0` because its fixtures kept channels open.

**And the timeout buys nothing on its own:** `pop($timeout)` spins just as fast on a closed
channel. Switching to it without discriminating `errCode` reintroduces `fcab883` verbatim.

So the fix is neither "add a timeout" nor "keep pop(0)" — it is to **distinguish
`CHANNEL_TIMEOUT` from `CHANNEL_CLOSED`**, which neither previous attempt did:

- `CHANNEL_TIMEOUT` (-1): normal idle. Run the liveness checks, loop again. This is what
  restores shutdown/disconnect detection.
- `CHANNEL_CLOSED` (-2): the channel was closed by cleanup or by
  `recreatePatchChannel()`. **Exit the loop** — never spin, and never re-read the property
  (which is what produced A1's stale-consumer bug, where the old coroutine stole alternating
  patches from the live one). Safe because the replacement SSE coroutine has already started
  (`SseHandler.php:167`), and because `recreatePatchChannel()` now carries pending patches
  across to the new channel (commit 025bfc5).

Note a closed channel still drains buffered data with `errCode = 0`, so remaining patches
are delivered before the loop sees `CHANNEL_CLOSED`.

### The real defect: liveness

Because an idle SSE coroutine parks indefinitely, the loop's `isShuttingDown()`,
`$response->isWritable()`, and context-destroyed safety-valve checks (`SseHandler.php:183-197`,
`:224-227`) **only execute after a patch arrives**. An idle connection observes neither server
shutdown nor client disconnect. Measured on a real server: SSE loops with a 6-second deadline
stranded for **61 s and 19 s**, resuming only when an unrelated broadcast pushed to them.

**And the CPU baseline it proposed to remove is zero.** Measured on the worker process at
steady state with no patches flowing: 1,000 idle SSE connections cost **0.01% CPU** at
`ssePollIntervalMs=100`, and 0.05% at `=1`. For contrast, a synthetic loop of the shape the code
*appears* to have costs 2.14% at 1,000 coroutines / 100 ms, and 47.5% at 1 ms. The interval is
irrelevant because the loop never reaches the `usleep`. End-to-end patch latency is likewise
independent of the knob: p50 4.3 ms at `=100`, p50 10.0 ms at `=2000`.

Ship the change — for liveness, not latency. Keep `ssePollIntervalMs` as the timeout; it is now
genuinely load-bearing as the bound on shutdown and disconnect detection.

### Three blockers, one of which the earlier draft got dangerously wrong

1. **The prescribed "retry on `CHANNEL_CLOSED`" fix livelocks the worker.** `close()` resumes
   the parked waiter *synchronously, before* `recreatePatchChannel()` reaches its reassignment
   (`PatchManager.php:228`). So "re-read the property and retry" re-reads the **same closed
   channel**; `pop()` on a closed channel returns instantly without yielding; the loop spins and
   `close()` never returns. Measured: **47.5 million iterations in 3 s at 100% CPU**, worker
   wedged, broken only by a watchdog that production code does not have.
   The retry path **must contain a yield**. Better: capture the channel object once at loop
   entry and exit on `CHANNEL_CLOSED` — never re-read the property mid-loop.
2. **Channel identity.** Confirmed, with a strict ordering constraint: replacing the property
   *without* `close()` strands the coroutine permanently, and OpenSwoole then **discards parked
   consumers** on channel destruction (`Channel::~Channel() ERRNO 10003 ... 1 consumers will be
   discarded`). So `close()` in `recreatePatchChannel()` is load-bearing *today*. Once the pop
   has a timeout, dropping `close()` is cleaner (recovers in one timeout, no livelock, no
   special case) — but **do not remove `close()` before the timeout is in place**.
3. **NEW — the stale consumer, a live bug today.** After `recreatePatchChannel()`, the old SSE
   coroutine wakes on `CLOSED`, gets null, sleeps, finds the context still exists so does not
   break, then re-reads the property onto the **new** channel and round-robins against the live
   consumer: old received patches 2/4/6, new received 1/3/5. **Half the patches go to the dead
   connection.** Only masked when `isWritable()` has already flipped. The blocking-pop change
   makes this *more* likely, which is why blocker 1's fix must include channel ownership.

### Also fixed by the same pass

`errCode` fully disambiguates `false` (`CHANNEL_OK=0`, `TIMEOUT=-1`, `CLOSED=-2`,
`CANCELED=-3`), and resets per operation rather than being sticky — so the `SseHandler.php:214`
comment claiming `usleep` is "the only reliable way to yield regardless of channel state" is
refuted. Note a closed channel still **drains its buffer** with `errCode=0`; `CHANNEL_CLOSED`
on pop means "closed *and* empty".

Fix the `PatchManager.php:93-95` and `:109-110` docblocks first — they misdocument the runtime
and are what caused both items to misdiagnose the system.

---

## 5. Slow-consumer frame drop — `DONE` (`964676f`)

**File:** `src/Http/SseHandler.php` (patch pop -> `$response->write()`)

No check between popping a patch and writing it beyond the loop-top `isWritable()` guard,
so a slow client's socket buffer just grows, backed only by drop-oldest-at-50 — up to 50
buffered full-page HTML fragments per stalled client. Anders drops the frame if the
previous send is still in flight, which is what makes his degradation uniform ("under load
everyone slows down equally") instead of memory-bound on the worst client.

Blocked on item 3: dropping a *frame* requires a frame boundary to drop against.

---

## 6. Multicore — use the process model OpenSwoole already gives you — `DONE` (`f26604d`)

**Shipped:** `SharedContextDirectory` plus the `ActionHandler` revival fallback.

| workers | before | after |
|---|---|---|
| 1 | 100.0% | 100% |
| 2 | 51.0% | 100% |
| 4 | 26.2% | 100% |
| 8 | 13.0% | 100% |
| 16 | **6.9%** | **100%** (1000/1000 at concurrency 100) |

**Two corrections to the plan below.**

1. **The record must be written at context CREATION, not destruction.** Revival records existed
   only after `destroyContext()` — a returning tab whose context had been cleaned up. A context
   *alive* on another worker has no revival record at all, so "move `$revivableContexts` into
   `SharedTable`" would not have fixed cross-worker actions. Entries are heartbeated from the SSE
   loop's idle branch; without that a long-lived stream outlives its own record.
2. **Item 7 was a prerequisite, not a follow-up.** Verified before building: a revived context
   re-runs the route handler on the receiving worker, so without shared scoped values it mutates
   a copy nobody is watching — a silent wrong answer instead of a loud 400. Confirmed end to end
   after both landed: 200 actions across 4 workers leave the shared counter at exactly 200.

Keys are hashed (a context ID is its route plus 18 characters against a 63-char limit, which
would otherwise cap routes at 45). Records are shape-checked on read, since rows outlive a deploy
and a record written by an older build must mean "cannot revive" rather than a TypeError in the
action path. Directory overflow is logged, not thrown.

The original analysis below is kept for the reasoning about rebuild-vs-share, which held up.

### The hard constraint: contexts cannot cross a process boundary

`Context` holds `private $viewFn` (a closure), `$actionRegistry` (closures), and an
`OpenSwoole\Coroutine\Channel`. None of that is serializable, so a live context can never
be moved into a `Table` or handed to another worker. Sticky routing is therefore
*load-bearing and permanent* in the process model, not a convenience to be engineered away.

### php-via already has the correct answer, and it is not sharing — it is rebuild

`reviveContextFromClient()` (`src/Via.php:1250`) reconstructs a context by **re-running the
route handler** under the original id, then restoring client-held signal values from the
request. The revival *record* it needs (`Application::recordRevivable()`,
`src/Core/Application.php:420-435`) is tiny flat serializable data:

```php
['route' => string, 'params' => array, 'sessionId' => ?string, 'expiresAt' => int]
```

That is exactly the shape `SharedTable` already stores (serialized value in one string
column), and it fits well inside the 4096-byte default `maxValueBytes`. `MAX_REVIVABLE`
maps cleanly onto `Table`'s fixed `maxRows`.

**So the fix for the whole multi-worker story is two changes, both small:**

1. **Move `$revivableContexts` into `SharedTable`. CONFIRMED per-process.** Context created on
   worker 0: reconnect on the owning worker revived cleanly; reconnect on a different worker fell
   back to a full-page reload.

   **The record fits, with two caveats.** Measured serialized sizes against the 4096-byte
   default: minimal 92 B, typical page 147 B, one route param 165 B, three params 239 B,
   pathological 341 B — under 10% of the cap.
   - **Key length.** The key is the context id (`route . '_/' . <16 hex>`) = `strlen(route) + 18`
     against `MAX_KEY_LENGTH`, leaving only **46 characters of route** before `normalizeKey()`
     throws — from inside the cleanup timer. Hash it (`substr(sha1($ctxId), 0, 32)`) instead.
   - **Cap mismatch.** `Application::MAX_REVIVABLE = 10_000` vs `SharedTable`'s 1024-row default
     (~1650 real capacity — item 8). Moving records in without raising
     `withGlobalStateTableSize()` shrinks the revival window ~6x.

2. **Give `ActionHandler` the revival fallback `SseHandler` already has.** The 400 is real and
   easy to hit (`src/Http/ActionHandler.php:67-72`, no `reviveContext()` attempt unlike
   `SseHandler.php:70`). **But my causal link to `PERFORMANCE.md:150` is REFUTED.**

   | config | keep-alive client | new conn per request |
   |---|---|---|
   | `withSwooleSettings(['worker_num'=>16])` — **exactly `PERFORMANCE.md:137-142`** | **100.0% OK** | 5.0% OK |
   | `withWorkerNum(16)` as shipped (dispatch_mode 7) | 56.5% OK | 44.0% OK |
   | `withWorkerNum(16)` + dispatch_mode 6 | **100.0% OK** | **100.0% OK** |

   **`PERFORMANCE.md`'s 16-worker run never exercised php-via's session affinity at all.** It
   sets `worker_num` via `withSwooleSettings`, so `getWorkerNum()` stays 1 — no `dispatch_mode`,
   no `dispatch_func`, **and no `SharedTable`** (`Via.php:686` is gated on `getWorkerNum() > 1`).
   It ran on OpenSwoole's default fd-mod dispatch, which is per-connection sticky, giving 100%
   for a keep-alive client. So the 99.9% -> 89.7% drop is **not** mis-routed actions;
   `PERFORMANCE.md`'s own explanation (broker round-trips pushing responses past client timeouts)
   survives and mine does not. The ~10% loss also tracks the concurrency=500 collapse
   (17.8% -> 6.7%), which is latency-shaped, not routing-shaped.

   **Action: re-run and re-label `PERFORMANCE.md:135-155`** — as configured it measured 16
   workers on default dispatch with per-worker `GlobalState`, not the path the section documents.

Together these make a mis-routed request **self-healing on any worker** instead of a 400 — but
only after blockers 0a-0d, since the revival fallback is a symptom of 0a and cannot help until
records are shared.

### What this downgrades

The non-consistent hash (`crc32(sessionCookie) % workerNum`,
`src/Core/SessionManager.php:44-59`) stops being a correctness problem and becomes a
performance one. A worker-count change still rehashes every session, but with shared
revival records the affected contexts *rebuild* on their new worker rather than being
orphaned. Worth documenting the rebuild cost of a rescale; not worth consistent hashing yet.

### Remaining hole to design for

Scoped signals (`ROUTE`, `SESSION`, custom) live in per-process `SignalManager`, not on the
client and not in `SharedTable` — only `GlobalState` is shared. A context revived on a
worker that has never seen that scope gets freshly-initialised scoped signals rather than
the live shared values. TAB-scoped signals are safe (restored from the client via
`$clientSignals`); shared-scope values are not. Decide whether scoped signals move into
`SharedTable` alongside the revival records, or whether revival on a cold worker is
explicitly documented as resetting them.

---

## 7. Shared-scope signal values are per-worker — `DONE` (`d063820` stage 1, `e2a506b` stage 2)

**Shipped in two stages, and the design came out much smaller than sketched below.**

`SharedSignalStore` backs the VALUE only. The `Signal` object stays per-process — identity,
scope, client-writable flag and dirty tracking are all per-worker concerns, since each worker
tracks what *it* still owes its own clients. Null when `worker_num` is 1, so single-worker is
untouched.

**Stage 1 — integers, no bus needed.** `OpenSwoole\Table::incr()` on a `TYPE_INT` column is
atomic across processes: 160,000 increments from 8 racing processes, **100% retained** against
31% for `get()`+`set()`. `Signal::increment()` uses it. Over 4 forked workers x 500 mutations:

| | kept |
|---|---|
| `increment()` | 2000 / 2000 |
| `setValue($signal->int() + 1)` | 802 / 2000 |

**Stage 2 — everything else, a ticket lock rather than an owner worker.** `Signal::mutate()`
takes a ticket lock on the value's own row, runs the callback on the calling worker, writes back.
A ticket lock because `incr()` is the only cross-process atomic available — `OpenSwoole\Lock`
must not be used inside coroutine context and `Table` has no CAS. **Keeping the callback local is
what made this small**: no closure crosses a process boundary, so the broker-forwarding design
below was unnecessary. Over 4 forked workers appending to one list, 60 each:

| | kept |
|---|---|
| `mutate()` | 240 / 240 (and 800/800 at 8x100, 1600/1600 at 16x100) |
| `setValue($signal->array() + [...])` | 116-184 / 240 |

Crash safety: waiters break an overdue lease after 2 s, each once per turn they see, and also
move on a turn that has stood still for 2 s with no lease: the ticket of a process that died
waiting. A caller that times out in a coroutine leaves its ticket to a watcher coroutine that
passes the turn on when it comes. A burst can still over-advance and skip tickets; those waiters
proceed, so mutual exclusion degrades to the unlocked behaviour rather than deadlocking. Verified
by SIGKILLing a process inside its own callback and while it waits: recovery in about 2 s with
the right value.

**Do not mix `mutate()` and `increment()` on one signal.** `increment()` deliberately skips the
lock, so a `mutate()` beside it can write back over an increment.

**Value size.** A growing collection hits the serialized cap; at the original 4 KB that was
~241 short entries. Measurement showed the cap bought nothing — OpenSwoole maps the table lazily,
so a 1024-row table costs a flat ~8 MB whether the value column is 4 KB or 64 KB, and grows only
as large values are written. Default raised to 32 KB (`7a79667`), admitting ~1,900 short entries.
Scoped signals still suit bounded values; an unbounded collection does not belong in one.


**Files:** `src/State/SignalManager.php:17`, `src/Context/PatchManager.php:225-247`,
`src/Core/Application.php:56-63, 244-263`

`GlobalState` is `SharedTable`-backed (`Application::getGlobalState()` delegates when
`$sharedTable !== null`). **Scoped signals are not.** `SignalManager::$signals` is a plain
per-process PHP array holding every non-TAB signal — `Scope::GLOBAL`, `Scope::ROUTE`,
`Scope::SESSION`, and custom scopes alike.

**STATUS: CONFIRMED empirically, currently MASKED by blockers 0c/0d.** With the broker dead,
worker B receives nothing — silence rather than staleness. **Item 7 goes live the moment the
broker is fixed.**

Measured, 4 workers, repaired dispatch and broker. `/g` carries a `Scope::GLOBAL` signal
`gcount` *and* writes `gs_count` into `GlobalState` — a built-in control in the same fragment:

```
POST bump #2 (session A) -> HTTP 200
  SSE A got: <div id="out">RENDERED pid=25953 gcount=2 gs_count=2</div>
             signals {"global_gcount":2}
  SSE B got: <div id="out">RENDERED pid=25954 gcount=0 gs_count=2</div>
             signals {"global_gcount":0}
```

`gs_count` is correct on both workers; `gcount` is not — the clean side-by-side showing
`SharedTable`-backed state crosses and `SignalManager`-backed state does not. The **rendered HTML
is stale too**, and a fresh page load on worker B also showed `gcount=0`. `Scope::ROUTE` behaves
identically. Because `syncScopedSignals()` ignores `hasChanged()`, B re-pushes the stale value on
*every* subsequent broadcast, clobbering any correct value a client already holds.

With `worker_num > 1` the failure sequence is:

1. Worker A runs an action, `$sig->setValue(5)`, calls `broadcast(Scope::GLOBAL)`.
2. A's `syncLocally()` renders and pushes `5` to A's clients. Correct.
3. The broker publishes; worker B calls `syncLocally(Scope::GLOBAL)`.
4. B's `syncScopedSignals()` reads **B's own** `SignalManager` copy — still the old value —
   and pushes it to B's clients. B also re-renders the shared view from that stale value.

`syncScopedSignals()` deliberately ignores `hasChanged()` ("Always sync scoped signals
during broadcast … because multiple contexts need to receive the same value"), so B does
not merely fail to update — it *actively pushes stale state on every broadcast*.

Only the invalidation crosses the worker boundary. The state does not.

### The docs currently claim the opposite

`website/templates/docs/deployment.html.twig:352` — "cross-worker broadcasts
(`Scope::GLOBAL`, `Scope::ROUTE`) work automatically" — and `broker.html.twig:62` —
"GlobalState and broadcasts work correctly across all workers without sticky sessions."
Both are true of *`GlobalState`* and of *broadcast delivery*, and both read as covering
scoped signals, which they do not. Fix the docs in the same change as the code, or ahead
of it.

### Do NOT just move `SignalManager` into `SharedTable`

Three blockers, in descending severity:

1. **Read-modify-write races — this trades a visible bug for an invisible one.**
   `SharedTable` exposes only `set()`/`get()`/`delete()` over a single PHP-serialized
   string column (`src/State/SharedTable.php:63-105`). No CAS, no atomic `incr`, no lock.
   `$count->setValue($count->int() + 1)` on two workers silently loses one increment.
   Stale reads are at least observable; lost writes are not.
   `OpenSwoole\Lock` is **not** the escape hatch — OpenSwoole's own docs state process-level
   synchronisation must not be used inside coroutine context, which is the entire request path.

   **CORRECTION — the limitation is php-via's wrapper, not `OpenSwoole\Table`.** `Table` has
   atomic `incr()`/`decr()` on typed `TYPE_INT` columns; `SharedTable` cannot use them only
   because it stores one PHP-serialized string column. Measured, 8 processes racing on one row,
   160,000 increments:

   | path | kept |
   |---|---|
   | `Table::incr()` | **160,000 (100.00%)** |
   | `get()` + `set()` | 49,825 (31.14%) |

   So **numeric** scoped signals — counters, the common RMW case — need no bus, no owner worker
   and no tick: an INT column plus `incr()` is race-free by construction. This is already proven
   in production code (`src/Http/RateLimiter.php`). Only non-numeric RMW (list append, map
   update) needs the single-writer design below. That makes an atomic numeric fast path a
   cheap first stage rather than everything gating on the full architecture.
2. **Serialization on every read.** `get()` is an `unserialize()` per call. A scoped signal
   read inside a hot render (Game of Life touches state per cell across 2,500 cells) goes
   from a property read to 2,500 unserializes per frame. This is a cliff in exactly the
   workload php-via advertises.
   **Status: closed for broadcasts.** A flush holds a read epoch, and each scoped signal is read
   from the Table once per flush for all the contexts it renders (`SharedSignalStore` read
   snapshots; `bench/contention/shared_read.php` at 2,000 contexts: 20,000 reads per broadcast
   down to 5). Actions, timers, hooks and page loads still read, and unserialize, on every call.
3. **Caps.** 4096 bytes per value and 1024 rows by default. A ROUTE-scoped signal holding a
   todo list or spreadsheet overflows — `set()` already throws `\OverflowException`.

### The design that works

Keep `Signal` objects per-process as facades (id, name, scope, client-writable flag) and
back only their *value* with shared storage, for non-TAB scopes only. TAB signals stay
in-process — they are per-connection, need no sharing, and are the hot path.

Then classify by mutation shape, because only one shape is actually broken:

- **Wholesale-set signals** (board state, a rendered snapshot, "current track") — last-write-wins
  is correct. `SharedTable` as-is is sufficient.
- **Read-modify-write signals** (counters, accumulators, list append) — need a single writer.

For the RMW case the natural fit is the one this document already argues for elsewhere:
**Anders' single-writer batch.** Designate an owner worker per scope (hash the scope name),
forward mutations to it over the existing broker, and let it apply them in the same tick
that drives item 3's coalesced fan-out. One writer per scope removes the race by
construction rather than by locking, and reuses machinery items 3 and 6 already require.

`OpenSwoole\Atomic` is a cheaper partial answer for numeric-only signals if the RMW set
turns out to be small.

### Sequencing — **CORRECTED: this is now the FIRST multi-worker item, not the last**

The original note put this below item 6, reasoning from `PERFORMANCE.md:148-155` (16 workers
worse than 1). That measurement has since been shown to have never exercised the multi-worker
path at all (`68054f2`), and the real one gives a different picture: action success is exactly
`1/worker_num`, every failure a `400 "Invalid context"`.

More importantly, fixing that 400 **requires** this item. See the box at the top of item 6:
a revived context reads its scoped signals from the receiving worker's `SignalManager`, so
routing fixes without shared values produce silent wrong answers.

The interim constraint still stands and is now enforced at start-up (`80239c4`): `worker_num > 1`
logs a warning that stateful routes are unsupported.

---

## 8. `SharedTable` capacity — `DONE` (`2ece0a7`, `7a79667`)

Both premises of the earliest draft were already refuted (`set()` throws rather than returning
false; capacity is not `maxRows`). Re-measured on ext-openswoole 26.2.0 while fixing:

| `maxRows` | `getSize()` | usable | first rejection |
|---|---|---|---|
| 16 | 64 | 80 | none within 5x |
| 100 | 128 | 256 | 253 |
| **1024 (default)** | **1024** | **1776** | **1621** |
| 4096 | 4096 | 8043 | 6635 |

Rejection is per-key-hash and intermittent, and there is no eviction, so the effective ceiling is
not a number a caller can plan against. `withGlobalStateTableSize()` now documents `maxRows` as a
**floor**.

**Shipped:** exhaustion raises a shaped `\OverflowException` naming its own remedy instead of a
bare `OpenSwoole\Exception` reaching the caller as a generic 500; `MAX_KEY_LENGTH` 64 -> 63;
class docblock's false "keys are trimmed to 64 characters" corrected (it throws).

**The 64-char key does not truncate** — two 64-character keys differing only in the final
character stay distinct. It was a PHP warning on every write, so noise on the hot path, not
corruption. Inverting the shipped `accepts keys of exactly 64 chars` test was flagged in the test
body rather than quietly rewritten.

**Not a SharedTable defect after all:** the "writes are non-atomic, 114 of 200 keys applied"
observation was a *user loop* of 200 `setGlobalState()` calls, which is inherently non-atomic.
`SharedTable::set()` is a single-key API. The actionable part was the shaped exception.

---

## 9. SQLite instead of `SharedTable`? — `DONE`: tiered, not replaced

**Shipped as `Config::withPersistentGlobalState($path, $flushMs)`.** Re-measured against the
actual interface before building:

| | µs |
|---|---|
| raw `OpenSwoole\Table::get` | 0.083 |
| `SharedTable::get` (serialize wrapper) | 0.193 |
| SQLite point SELECT | **2.757** — 14x the wrapper, 33x raw |

So the verdict below holds, and the tier is **write-behind, not write-through**: reads never
touch SQLite. Writes land in the table and set a dirty flag; a leader-worker timer drains the
dirty set into one batched transaction.

| dirty keys | flush | per row |
|---|---|---|
| 10 | 11.5 µs | 1.15 µs |
| 100 | 102.5 µs | 1.03 µs |
| 1000 | 799.9 µs | 0.80 µs |

`wal_checkpoint(TRUNCATE)` of a 4.3 MB WAL: **659.6 µs** — an order of magnitude below the
8753 µs recorded earlier for a PASSIVE checkpoint under load.

**One bullet in the verdict below is now wrong.** "Atomic read-modify-write — SQLite wins, Table
cannot solve it" was refuted by item 7: `Table::incr()` is atomic across processes, and the
non-numeric case is covered by `Signal::mutate()`'s ticket lock. SQLite is not needed for
atomicity.

---

### Original analysis

### The blocker: SQLite calls block the whole worker

`Via.php:659` sets `hook_flags => SWOOLE_HOOK_ALL`. That hooks PHP's stream, socket and
file *functions*. It cannot intercept SQLite's internal `pread`/`pwrite`, which the library
issues directly through its own VFS in C. There is no coroutine SQLite client in
OpenSwoole and there cannot be a useful one — the async clients (MySQL, Redis, Postgres)
work by hooking a *socket*, and SQLite has none.

So every SQLite call **stalls the entire worker event loop**, not just the calling
coroutine: every SSE connection, every in-flight action, on that worker.

**CORRECTION, MEASURED — the blocking is real but the mechanism above is WRONG, and my own
earlier "reads are fine because mmap avoids syscalls" correction is REFUTED too.**

Syscall attribution via `/proc/self/io`, 1M-row table, schema identical to `SpreadsheetExample`:

| config | µs/query | read syscalls/query |
|---|---|---|
| point, baseline | 4.41 | **2.000** |
| point, `cache_size=15625` | 3.48 | 0.000 |
| **viewport (20x10), baseline** | **1099.6** | **83.84** |
| **viewport, `mmap_size=256MB`** | **1062.2** | **0.003** |

`mmap_size` does exactly what was claimed — 84 `pread`s per viewport query go to **zero**. It buys
**3.4%**. The other 96.6% is VDBE + b-tree + PHP-binding work: non-yielding CPU inside the
coroutine, which stalls the loop *identically* to a syscall.

So the hook is irrelevant in both directions. **Reads are cheap because they are small, not
because they avoid syscalls** — and a hookable/async SQLite would rescue nothing. The only levers
are: do less SQLite work, or do it somewhere without an event loop.

Per-operation floor (200k ops each):

| | µs/op |
|---|---|
| `OpenSwoole\Table::get` | **0.135** |
| PHP array lookup | 0.114 |
| SQLite prepared point SELECT (tuned) | **3.613** |

A SQLite point read is **27x an `OpenSwoole\Table` get** — not "the same order", as my correction
claimed. The verdict table below is still right to say "never" for hot per-cell paths; the
arithmetic behind it is 27x, not ~1x.

**Pragma recommendation, revised against measurement:** adopt `cache_size=15625` and
`temp_store=MEMORY`. Drop `page_size=4096` — it is SQLite's default since 3.12, a no-op.
**Do not adopt `mmap_size`** — it doubles cold-connection first-query cost (7.5 -> 14.3 µs) for
the mmap setup, so it is a net loss for the revival-record/connect-time pattern, and
`cache_size` gets the entire warm-read win without it. **Do not adopt `busy_timeout=5000`** —
see item 10; on a coroutine loop that pragma converts a blocked checkpoint into a measured
**4.95-second event-loop freeze**.

Event-loop stall by operation (heartbeat-gap method, validated against a yielding control):

| operation | p50 | max |
|---|---|---|
| warm point SELECT | 4.86 µs | 28.9 µs |
| 200k-row range SELECT | **45.1 ms** | 47.3 ms |
| INSERT, implicit txn, sync=NORMAL | 10.0 µs | **7936 µs** (autocheckpoint) |
| INSERT inside `BEGIN` | 2.71 µs | 51.4 µs |
| COMMIT of 100 rows, sync=NORMAL | 89.5 µs | 163 µs |
| `wal_checkpoint(PASSIVE)` after 5k rows | **8753 µs** | 20515 µs |

**Second-order effects that change what to worry about.** With 200 live SSE streams, the visible
breakage is **not** patch cadence — it is interactive latency. `/ping` on a fresh TCP connection
went p95 693 µs -> 13 ms (reads) -> 18 ms (checkpoint) -> **209 ms** (long reads), a 19x-300x
degradation, while established SSE streams moved only 102 -> 137 ms because a 100 ms cadence
absorbs sub-100 ms jitter. So "one blocking query stalls all 2,000 SSE connections" is true but
misleading: **action latency and connection establishment break first.**

Unrelated but worth knowing: under `SWOOLE_HOOK_ALL`, PHP's `fsync()` on a plain file stream
**fails** (`Can't fsync this stream!`, returns `false`). Any user code doing durable file writes
silently loses its fsync.

Note also that php-via already does this in `website/src/Examples/SpreadsheetExample.php`
and `ChatRoomExample.php` (plain synchronous `\SQLite3` inside coroutines), and
`tests/Load/bench_sticky.php:39-41` already excludes the spreadsheet workload because N
processes contend on one db file. The exposure is known; it just is not measured.

### Where SQLite is genuinely better, and it is not nothing

Against `OpenSwoole\Table`, SQLite wins on:

- **No caps.** Table is fixed `maxRows` x `maxValueBytes` at startup (item 8 above is the
  fallout). SQLite has neither limit in any practical sense.
- **Durability.** Table is memory-only. It survives worker restarts (allocated in the
  master, fork-inherited) but not a server restart.
- **Atomic read-modify-write.** `UPDATE ... SET v = v + 1` inside a transaction is atomic
  across processes. This is precisely the race item 7 identifies and that Table cannot
  solve — no CAS, no `incr` on a serialized string column, and `OpenSwoole\Lock` is
  unusable in coroutine context.
- **Queryability.** Revival-record LRU eviction becomes `DELETE WHERE expiresAt < ?`
  instead of the hand-rolled `uasort` + slice in `Application::pruneRevivableIfNeeded()`.

### Verdict: tier it, do not replace it

Judge each consumer by call frequency, because that is what decides whether blocking matters:

| Consumer | Path | Verdict |
|---|---|---|
| Revival records (#6) | Cold — one write at cleanup, one read at reconnect | **SQLite is better.** No row cap, survives restart (a server restart stops forcing every client to reload), eviction becomes a `DELETE`. |
| `GlobalState` | Depends entirely on user code; may sit inside a render | **Keep Table.** Offer SQLite as an opt-in durable tier. |
| Scoped signal values (#7) | Hot — GoL touches state across 2,500 cells per frame | **Never.** 2,500 blocking calls per frame. Table only, or read once per broadcast into a local, which item 3's design mandates anyway. |

Keep `Table` as the hot shared-memory primitive — sub-microsecond memcpy from mmap, no
syscall, no event-loop stall. Add SQLite as an **optional durable tier behind the same
`SharedTable` interface**, write-through, selected per consumer. The interface
(`set`/`get`/`delete`) is already narrow enough to accept a second implementation without
touching callers.

If the RMW case from item 7 turns out to need real atomicity, the ordering is: prove
multi-worker is a win (#6) -> route scoped-signal writes through a single owner worker
(#7's design, which is Anders' single writer) -> and note that a single writer removes the
need for SQLite's atomicity anyway. Solve it with the architecture, not the storage engine.

### Before any of this, measure

Do not carry Anders' SQLite numbers across. His 100k TPS figures assume WAL, a managed
single writer, and batched transactions **on the JVM with a thread pool** — the throughput
of SQLite is not the constraint here; event-loop occupancy is. The question to answer is:

> What is the p99 wall time of one prepared SQLite point query under the coroutine
> scheduler, and how much does it move SSE patch latency at 500 / 1,000 / 2,000 connections?

`tests/Load/profile_spreadsheet.php` already drives real `\SQLite3` queries under SPX and
is the natural harness. If a warm point query is single-digit microseconds the tiering
argument holds comfortably; if it is tens of microseconds, SQLite is cold-path-only and
even the revival-record use needs a second look.

---

## 10. A first-class single-writer API for users — `DONE`

**(a) — batching the fan-out on a tick** shipped as item 3 (`e739186`).

**(b) — a dedicated writer process** was not built, and does not need to be. Its two jobs were
cross-worker RMW and getting blocking persistence off the event loop. The first is solved without
it (item 7: atomic `incr` plus a ticket lock). The second is solved by *when* the writes happen,
not by *where*: the write-behind flush in item 9 drains the dirty set on a fixed tick inside one
transaction — Anders' shape exactly — on the leader worker, using the leader-gated timer from
`f388526`. No extra process, no IPC, and no change to read semantics.

The recommendation below said (b) "should land together with item 9's SQLite tier and item 7's
scoped-signal ownership — they are one design". That was right about them being one design and
wrong about its shape: once item 7 no longer needs an owner worker, what remains is a flush
timer, not a process.

Cost of durability, measured: a sub-millisecond stall once per flush interval on one worker, and
a bounded loss window equal to that interval. Against 2.757 µs on *every read* if SQLite sat in
front instead — each one non-yielding CPU that stalls the whole worker's loop.

---

### Original analysis

Anders: *"I have a single writer process. It doesn't even have an event loop. All it does
is drain queues on a fixed tick rate in one giant transaction and do the writes."*
EDJ runs the same shape. The question is what php-via should offer users.

### First: separate two things that get conflated

- **(a) Batching the fan-out on a tick** — in-process, no new process, no API change.
  This is item 3. It is where nearly all the measured win is.
- **(b) A dedicated writer process owning a transactional store** — solves cross-worker
  RMW and lets blocking persistence happen off the event loop. This is a new opt-in API
  with *different read semantics*.

They are independent. (a) does not require (b). Do not ship them as one thing.

### php-via already has a single writer — it just does not batch

With `worker_num = 1`, OpenSwoole coroutines are cooperatively scheduled: PHP code between
yield points runs to completion with no preemption. **The worker's event loop already *is*
the single writer.** There is no cross-thread race to solve; the only missing half of
Anders' model is the batch boundary, which is item 3.

**Precise caveat — the guarantee is narrower than "actions are atomic":** it holds only for
mutations containing no yield point. An action that reads state, performs I/O (a hooked
file read, a broadcast that blocks, a Redis/NATS call), then writes, *does* yield in the
middle and can interleave with another action on the same worker. This is the same class of
"atomic by accident" property flagged in item 3. Worth an explicit note in the docs, and
worth auditing the examples for read -> I/O -> write shapes.

Note that synchronous `\SQLite3` calls do **not** yield (item 9 — they are unhookable), so
they are ironically safe for atomicity while being the worst thing for the event loop.

### What (b) would actually buy, and cost

Buys, all of which are already open items:
- Cross-worker RMW safety without locks or CAS (**item 7**) — one writer per scope removes
  the race by construction. `OpenSwoole\Lock` is unusable in coroutine context anyway.
- Blocking persistence off the event loop (**item 9**) — a writer process has no SSE
  connections, so a synchronous SQLite call stalls nothing. This is the cleanest answer to
  item 9's blocker, and it is exactly why Anders' writer "doesn't even have an event loop."
- Batched transactions — one `BEGIN`/`COMMIT` per tick, `SAVEPOINT` per logical write that
  may fail (Anders: the outer transaction is "purely a perf/mechanical thing", savepoints
  give you real transaction semantics inside it).

Costs, and the first one is the reason this cannot be retrofitted transparently:

1. **It breaks read-after-write.** Today `$count->setValue($count->int() + 1)` takes effect
   immediately and the next line reads the new value. Behind a queued writer it does not.
   Anders lives with this because under CQS "your reads don't care about your writes" — but
   php-via's entire public API and every example in `website/src/Examples/` is built on
   synchronous mutate-then-read. **This must be an opt-in store with its own API surface,
   never a change to how `Signal` behaves.**
2. **It only governs state it owns.** php-via "state" is currently PHP statics, `GlobalState`,
   or the user's own database. A writer can only batch what it holds, so this is a new store,
   not a wrapper over existing state.
3. **Rollback is meaningless without a transactional store.** SAVEPOINT semantics need a DB.
   Over a PHP array there is nothing to roll back to, so (b) only makes sense paired with
   SQLite (item 9) — which is fine, since (b) is also what makes SQLite safe here.
4. **No benefit at `worker_num = 1`** beyond what item 3 already delivers, per above.

### Sketch, explicitly not a spec

Dedicated `OpenSwoole\Process` (not a task worker — those are request/response, a writer
wants a drain loop). Workers enqueue over a pipe, or over `OpenSwoole\Table` + `Atomic`
head/tail counters as a lock-free ring (EDJ's approach: *"it just keeps draining without any
locks"*). The writer drains on a tick into one transaction, then publishes a scope
invalidation through the **existing broker**, and the normal fan-out path takes over
unchanged. That reuse matters: the broker, the scope registry, and item 3's tick are all
already required by other items.

### Write exclusivity: what (b) must enforce, and what it cannot

**Correctness does not require it.** SQLite already enforces one write transaction at a
time at the file level. A second writer does not corrupt anything — it serializes, or gets
`SQLITE_BUSY`/`SQLITE_LOCKED`. Anders' point (*"as long as you're doing a single managed
writer at application level so you avoid BUSY/LOCKED"*) is that the app-level single writer
exists to make BUSY **impossible by construction**, not to make writes safe. SQLite is the
safety net; the managed writer is the simplification that lets you batch aggressively.

**But co-writing would be practically unusable, not merely discouraged.** php-via's writer
is deliberately greedy: one `BEGIN IMMEDIATE ... COMMIT` spanning the whole tick drain.
Any external writer is locked out for that entire window. At a 100 ms tick under load, an
external writer sees BUSY *most* of the time, not occasionally. The failure presents as
mysterious intermittent write failures in user code, which is the worst possible diagnostic.

**`PRAGMA locking_mode = EXCLUSIVE` is not the answer.** It would hold the file lock for
the connection's lifetime and reject other writers immediately — but in WAL mode it also
blocks other processes from *reading*, and php-via's worker processes must read. Write
exclusivity and multi-process reads are both required, so the enforcement cannot come from
SQLite's locking mode.

**Nothing can portably detect a user's arbitrary `new \SQLite3($sameFile)`.** You cannot
enumerate who holds a file open, and a user's connection will not take your advisory lock.
So the design is two layers, neither of which is prevention:

1. **Startup — hard throw on the common footgun.** The writer takes an exclusive `flock()`
   on a sidecar (`<db>.via-writer.lock`) and throws if it cannot acquire it. This catches
   *two php-via instances pointed at the same file* — a dev server left running, two apps
   sharing a path — which is the likely mistake and is worth failing fast and loudly on.
2. **Runtime — "treat BUSY as fatal and loud" is REFUTED.** SQLite error code 5 is overloaded.
   Three non-contention causes were reproduced:
   - **An unfinalized cursor at COMMIT on the *same* connection.** One process, one connection,
     zero contention: a `PRAGMA wal_checkpoint` result set not yet finalized makes COMMIT return
     `5: cannot commit transaction - SQL statements in progress`. PHP finalizes `SQLite3Result`
     on GC, which is **non-deterministic** — so this fires on a garbage-collection timing
     accident. An operator page on `lastErrorCode() === 5` would be a false alarm.
   - **First-time WAL conversion racing a reader.** `PRAGMA journal_mode=WAL` returns
     `5: database is locked` if any reader holds a read transaction. Both examples run it via
     `exec()` and **ignore the return value**, silently leaving the db in DELETE journal mode.
   - **`SQLITE_BUSY_SNAPSHOT`, which `busy_timeout` cannot absorb at all** — see below.

   The salvageable version is narrower: use `BEGIN IMMEDIATE` **always**; keep `busy_timeout = 0`
   **only on the writer's transaction path**; discriminate on `lastErrorMsg()` —
   `"database is locked"` under `BEGIN IMMEDIATE` is the real invariant violation,
   `"cannot commit transaction"` is a local bug. **Never let a `busy_timeout` apply to a
   `wal_checkpoint` call.**

   With `BEGIN IMMEDIATE` the single-writer invariant does hold: 3158 transactions, `bt=0`, zero
   BUSY with no contender. That part of the design is sound.

3. **~~THE CASE THAT ACTUALLY BREAKS THE DESIGN~~ — premise REFUTED, see the box below.**
   php-via *always* has long-lived readers, because every SSE connection is one. Under a
   sustained reader, `wal_checkpoint` never completes and **returns no error** — it returns a row
   with `busy=1`. Measured over four seconds of writes:

   | scenario | txns ok | BUSY | ckpt blocked | WAL after 4 s |
   |---|---|---|---|---|
   | autocheckpoint, long reader present | 961 | **0** | 0 (silent) | **201 MB** |
   | explicit `TRUNCATE` per txn, reader present | 859 | **0** | 859/859 | **180 MB** |
   | explicit `TRUNCATE`, no reader (control) | 210 | 0 | 0 | 0 |
   | explicit `TRUNCATE`, reader present, **`bt=5000`** | **1** | 0 | 0 | ckpt took **4 948 317 µs** |

   The runtime layer would see zero BUSY and report healthy while the WAL grew to **200 MB in
   four seconds**. Adding `busy_timeout` to make the checkpoint wait is strictly worse: a
   **4.95-second total event-loop freeze**.

   > ### ⚠ REFUTED — the premise, not the measurement (`tests/Feature/WalCheckpointReadersTest.php`)
   >
   > *"php-via always has long-lived readers, because every SSE connection is one"* is false. An
   > SSE connection is a **coroutine**, not an open SQLite snapshot. What pins the WAL is a
   > statement left **mid-scan**, or an explicit read transaction — not the existence of a
   > connection. Re-measured, churning writes between each reader state then attempting TRUNCATE:
   >
   > | reader state | busy | WAL after |
   > |---|---|---|
   > | range query drained to EOF | 0 | truncated |
   > | single row, one `fetchArray`, **not drained** | **1** | held at 17 MB |
   > | the same plus explicit `finalize()` | 0 | truncated |
   > | `querySingle()`, either form | 0 | truncated |
   > | partial scan, loop broken early | **1** | held |
   > | `INSERT` via `execute()`, result discarded | 0 | truncated |
   > | idle connection | 0 | truncated |
   > | inside explicit `BEGIN` | **1** | held |
   >
   > **And php-via's own read helpers are safe.** `SpreadsheetExample::getCell()` fetches one row
   > and never steps the statement to DONE, which looks like the failing shape — but PHP frees
   > `SQLite3Result` by **refcount**, so a result held only in a local is finalised the moment the
   > function returns. Deterministic, not at the mercy of the cycle collector. Verified directly:
   > the same query blocks only when the result is kept alive in an outer scope. (An earlier draft
   > of this correction claimed `getCell()` was buggy and "fixed" it — it was not; the change was
   > reverted.)
   >
   > **So 10(b) is not blocked by a property the design cannot provide.** The requirement is
   > reader hygiene — never hold a partially consumed cursor or an open read transaction across a
   > checkpoint — which is enforceable and already satisfied everywhere in this codebase. The
   > health signal below (WAL size + checkpoint-blocked count) is still the right one, and
   > `busy_timeout` on a checkpoint is still strictly worse than not checkpointing.

   **So the health signal is WAL file size and checkpoint-blocked count, not `SQLITE_BUSY`** —
   and the writer needs a reader-quiescent window it can actually checkpoint in, which nothing in
   the current design provides. This is the open problem in item 10(b), and it has no answer yet.

**The write side is the easy half, and it is confirmed.** Tick-batching gives **9.1x throughput
and 9.8x less total event-loop stall**, and bounds the worst single stall to ~208 µs at
batch=100 versus 9.4 ms unbatched. Two-process test: WAL readers are genuinely unblocked across
56 write transactions each held for a full 100 ms tick — reader point-SELECT p50 **3.44 µs
concurrent vs 3.48 µs alone, zero errors**. A writer process with no event loop is the correct
home for it.

**The contract to document:** php-via owns the write side of the store's file; user code may
read it freely (WAL, multiple readers, no contention), and must route all writes through the
store API so they land in the writer's batch. Users wanting their own SQLite database
alongside simply point it at a different file. No conflict, no ceremony.

**Interim only.** The stated objective is that `SpreadsheetExample` and `ChatRoomExample`
*migrate onto* this API rather than coexisting beside it. That makes them the two
validation targets, and they are usefully different:

- **ChatRoom** — append-only writes, one bulk read on connect. The easy case; it exercises
  batching and little else.
- **Spreadsheet** — sparse writes, plus a **range `SELECT` on every render**
  (`SpreadsheetExample.php:877`, "the server fetches only the visible range from SQLite on
  every render"). This is the case that actually tests the design.

**Spinach: a single-writer API fixes the write side, and Spreadsheet's problem is the read
side. CONFIRMED — and 7.9x of that read cost is a bad query shape, not SQLite.**

`EXPLAIN QUERY PLAN` shows `SEARCH cells USING INDEX sqlite_autoindex_cells_1 (row>? AND row<?)`
— the composite PK seeks the **row band only**, then filters `col` per entry. At 1000 columns
that scans 20,000 index entries to return 200 rows.

| store / query form (per 200-cell viewport) | p50 | saturation N @5 renders/s |
|---|---|---|
| SQLite range query — **as shipped** | 1078 µs | **~185** |
| SQLite `WITHOUT ROWID` (covering) | 719 µs | ~288 |
| SQLite, rewritten as 20 per-row seeks | **137 µs** | ~1500-2000 |
| `OpenSwoole\Table`, 200 point gets | **36.7 µs** | ~5400 |
| **as shipped, on today's realistic sparse table** (10k cells, 320 KB) | **19.0 µs** | **~10 000** |

**At today's data size the Spreadsheet read path is not a problem at all** — 19 µs per render,
2000 connections at 40% event-loop occupancy. It becomes one at ~185 connections only if the
sheet grows dense. Past saturation the failure is total: at N=1000 as-shipped, tick lateness p50
is **884 ms** and heartbeat gaps reach **1.9 s**.

**Migration requirements, ranked by effort/benefit:**
1. **`exec('BEGIN')` -> `BEGIN IMMEDIATE`** in `setCells()` (`SpreadsheetExample.php:939`).
   `setCell()` calls `refreshExtentCache()` (`:906`), which runs a `SELECT` inside that deferred
   transaction — the read-then-write shape. Two writers in that shape starved one side to
   **0 successful transactions out of 6580 attempts**, in all three trials, **with
   `busy_timeout=5000` providing zero protection** (it is `SQLITE_BUSY_SNAPSHOT`, which bypasses
   the busy handler). **This is a latent hard bug the instant `worker_num > 1`, independent of
   any migration.** Fix it now.
2. **Rewrite the range query as per-row seeks** — 7.9x for a few lines, moving saturation from
   185 to ~1500 connections. Do this before building anything.
3. **A read-through `Table` cache is the only thing that closes the remaining gap** (36.7 µs,
   29x). Of the three read-story candidates named below, this is the only one that reaches
   Table-class latency — **the "mmap-tuned direct read path" is measurably not a candidate**
   (item 9).
4. **Handle `PRAGMA journal_mode=WAL` failing** — both examples ignore its return value; a
   silent failure leaves DELETE journal mode and invalidates every multi-process assumption.

Original framing follows: A writer process does nothing for `getViewport()` — that query still runs in the
worker coroutine on every render. Migrating Spreadsheet onto a write-only API would batch
its writes and leave the blocking read exactly as it is today. If these examples are the
objective, **the API needs a read story, not just a write story**: either the mmap-tuned
direct read path (item 9's correction above, if measurement supports it), a read-through
`Table` cache, or item 11's cached-HTML path — which sidesteps the per-render query
entirely by caching the rendered output instead of re-querying the inputs.

Also note `SpreadsheetExample.php:939-943` already wraps writes in a manual
`BEGIN`/`COMMIT`. Part of the batching notion exists; it is per-action rather than
per-tick, and per-process rather than single-writer.

### Recommendation

Ship (a) — item 3 — and document the no-yield-point caveat. That captures the coalescing
win for every deployment, single- or multi-worker, with no semantic change.

Treat (b) as a **separate opt-in store**, and only after item 6 proves multi-worker is a
win at all (`PERFORMANCE.md:148-155` currently shows 16 workers performing *worse* than 1).
If (b) is built, it should land together with item 9's SQLite tier and item 7's scoped-signal
ownership — they are one design, and building any of the three alone means building the
other two badly.

---

## 11. Cached rendered HTML in shared/persistent storage — **GATE FAILED. DO NOT BUILD.**

The measurement this item asked for has been run. **It fails on every route that actually
broadcasts.** Not opt-in, not per-route, not as a spike.

Update-render cost, from the framework's own `render.regions` spans:

| route | broadcasts? | renders/broadcast | p50 | p99 | update payload |
|---|---|---|---|---|---|
| `/docs/api` | **no** | 1 | **6.12 ms** | 17.09 ms | 64 KB |
| `/docs/faq` | **no** | 1 | 5.51 ms | 10.30 ms | 47 KB |
| `/examples/spreadsheet` | yes | **N** | 0.78 ms | 1.79 ms | 22 KB xN |
| `/examples/stock-ticker` | yes | 1 | 0.63 ms | 1.66 ms | 27 KB |
| **`/examples/game-of-life`** | yes | 1 | **0.28 ms** | 0.77 ms | **130 KB** |

Cost of the round trip this item would add: 0.015 ms write + 0.012 ms read for 130 KB.

- **Game of Life renders 130 KB in 0.28 ms** — ~460 MB/s of string concat, the *cheapest* real
  broadcast on the site. At its 200 ms tick that is **1.4 ms of CPU per second per worker, 0.14%
  of a core.** Caching would save at most 0.25 ms per frame while adding a write, a read, an
  invalidation protocol and a dirty-flag design. The earlier draft predicted caching loses here;
  it loses by a wider margin than predicted.
- **The expensive renders never broadcast.** `/docs/api` at 6.1 ms p50 / 17 ms p99 is a static
  documentation page. Its cost is on initial page load, where `ViewRenderer` deliberately never
  caches because the HTML embeds unique context IDs. Making a shared cache serve those would mean
  caching HTML with per-context IDs baked in — a different and worse problem.
- **Cross-worker dedup, the "strongest argument", is worth W x 0.28 ms per frame.** At 16 workers
  and 5 fps that is ~22 ms/s, about 2% of one core — recovered by adding a write and 15 reads to
  the hottest path, and gated behind item 6 proving multi-worker is a win at all (item 0 says it
  currently is not).

**C1 confirmed the baseline exactly:** 1 real render per broadcast regardless of N (1/5/20/50
contexts), and **never zero** across 100 consecutive broadcasts. Fan-out dedup, not a cache
across time — as described.

**The one workload with real substance is Spreadsheet, for a reason not previously stated — see
item 13.** It gets *no dedup at all*, so a broadcast costs N renders and N SQLite range queries,
with `db.get_cell_range` at **63% of render time**. But cached HTML is not the fix; item 13's two
in-process fixes come first and need neither a writer process nor a storage tier.

---

## 12. `Scope::ROUTE` is never expanded in `createSignal()` — `DONE` (`9e4e621`)

**File:** `src/Context/SignalFactory.php:52-68`

`createSignal()` resolves `Scope::SESSION` to a concrete session id (`:58`) and handles the
*implicit* path where a signal inherits a non-TAB context scope (`:52`). It does **not** expand
an **explicit** `Scope::ROUTE` argument.

So `$c->signal(0, 'n', Scope::ROUTE)` registers under the literal string `'route'` while the
context's actual scope is `route:/r`. `syncScopedSignals()` iterates the context's scopes and
never finds it — **no `datastar-patch-signals` event is ever emitted for that signal.** Observed:
`/r` produced element patches and zero signal patches, while the idiomatic forms on `/g` and
`/r2` produced both.

Second consequence: the literal `'route'` bucket is **shared across every route**, so two routes
using this form collide on signal ids.

Found incidentally while verifying item 7. Unlike items 0 and 7 this is **not multi-worker
specific** — it reproduces on a single worker. Expand to `Scope::routeScope($context->getRoute())`
the way `SESSION` is resolved, and add a test covering the explicit-scope form of each constant.

---

## 13. `addScope()` without `scope()` silently disables the view cache — `DONE`, **prescribed fix REFUTED**

**Shipped:** `2163721` (diagnostic + docs), `18e20d2` (example declarations), `e7d0e83` (query memo)

The mechanism was described correctly. `ViewRenderer::renderView()` gates caching on
`$shouldCache = $scope !== Scope::TAB && $isUpdate` where `$scope` is `getPrimaryScope()`, and
`addScope()` never touches the primary scope — so a context that only calls `addScope()` stays
TAB-primary and opts out of the update cache invisibly. The measurement was right too: 4 contexts
on `/examples/spreadsheet` = 4 renders, 4 range queries, 0 cache hits.

**The prescribed fix (#1, "give these routes a real primary scope") would have shipped a
cross-client HTML leak.** Every one of the seven views embeds per-client data:

| example | per-client content in the view |
|---|---|
| Spreadsheet | `viewRow`/`viewCol` viewport, `focusRow`/`focusCol`, own cursor hue, own selection |
| ChatRoom | `username` (SESSION), `contextId`, `messageInput->id()` (TAB signal id) |
| ShoppingCart, FileUpload, LiveAuction, TypeRace, MissionControl | TAB signal ids in `data-bind` targets |

A shared cache entry is keyed on the scope alone, so the second client in the scope would receive
the first client's HTML. **`b0b8dda` already fixed exactly this leak once** ("Using a single
component with `addScope()` caused TAB-scoped syncs to broadcast to all route-scope users — other
tabs saw the clicking user's `tabCount`"). The TAB-primary opt-out is the *correct* behaviour, not
the bug; it was simply arrived at by accident rather than declared.

**And the impact claim was wrong.** Six of the seven examples already pass `cacheUpdates: false`,
which bypasses the cache regardless of primary scope — so for them the primary-scope change is a
no-op, not a recovery. Only ChatRoom was cacheable, and it is the one that would have leaked.

| route | primary | shared scope | `cacheUpdates` |
|---|---|---|---|
| /examples/spreadsheet | tab | example:spreadsheet | **false** |
| /examples/shopping-cart | tab | cart:{sess} | **false** |
| /examples/file-upload (+2) | tab | upload:{sess} | **false** |
| /examples/live-auction | tab | example:auction | **false** |
| /examples/type-race | tab | example:typerace:race-1 | **false** |
| /examples/mission-control | tab | example:mission | **false** |
| /examples/chat-room (+/room/{room}) | tab | example:chat:{room} | true |
| **/examples/composition** | tab | session:{id}, global | true |

`/examples/composition` was **missing from the original list**: it reaches its shared scopes through
the Composition API's `#[Signal(Scope::SESSION)]` / `#[Signal(Scope::GLOBAL)]` registration, not an
explicit `addScope()`, so a grep for `addScope` could not find it.

### What shipped instead

1. **Option 3, narrowed.** Warning on every TAB-primary shared-scope context would fire on
   legitimate code an author cannot act on. It now fires only when the context *also* declares no
   TAB-scoped signals — the case where promoting the scope is plausibly a free N-to-1 win.
   `hasSignals()` is a heuristic, not a proof, so the message asks for a decision. **Zero of the 21
   website examples warn**, verified by driving the real handlers.
2. **The two cacheable routes now declare `cacheUpdates: false`.** A no-op today; it records the
   intent so promoting the scope cannot silently start sharing. Guarded by a test that drives every
   example route, so a new example cannot pick up the idiom without the declaration.
3. **The scopes guide documents the coupling**, which it previously never mentioned.

### The perf recovery, correctly attributed

N renders per broadcast is **irreducible** — the renders genuinely differ. What was reducible is
the SQLite work inside them: identical viewports produce identical cell data, and clients
overwhelmingly share a viewport. Memoised by range tuple, invalidated wholesale by `setCell()`
(the only writer), following the `$extentCache` pattern already in the file.

| | |
|---|---|
| cold range query | 0.0876 ms |
| warm memo hit | 0.0002 ms (376x) |
| query share of a cold render | **56.9%** (backs the original 63% figure) |

| clients | cold | memo | saved |
|---|---|---|---|
| 2 | 0.307 ms | 0.220 ms | 28% |
| 8 | 1.229 ms | 0.617 ms | 50% |
| 32 | 4.916 ms | 2.207 ms | 55% |

Cursor-only broadcasts — the common case, since every focus move bumps the scope version — touch no
cell data at all, so the reuse spans broadcasts as well as clients within one.

**Measurement note:** a first harness reported only 1.08x at 16 clients. It cleared the memo once
per *rep* rather than per *render*, so 15 of its 16 "no-memo" renders were memo hits. Its 1.126 ms
is reproduced exactly by the corrected model as `0.154 + 15 x 0.066`.

---

## 14. A component with no signals is frozen forever — `DONE` (`f1a3270`)

**Files:** `src/Context/PatchManager.php:162-168`, `src/Context/SignalFactory.php:184-192`

The component sync-skip is `$component->shouldCacheUpdates() && !$component->getSignalFactory()->hasChangedSignals()`.
`hasChangedSignals()` iterates the component's own signals and returns `false` for an **empty**
set. So a component with **no signals at all** and the default `cacheUpdates: true` is skipped on
**every** broadcast, for the life of the process.

Measured over 30 broadcasts x 3 contexts (90 render opportunities):

| component | `cacheUpdates` | renders after page load | |
|---|---|---|---|
| pure signal | true | 3 (first broadcast, then skipped) | correct |
| external PHP static, **declared** | false | 90 | correct |
| external PHP static, **author forgot** | true | **0 — never re-rendered** | **permanently stale** |

No exception, no warning, nothing above debug level. The client freezes on its first-render value.
This is a correctness trap for anyone writing a stateless component that reads `GlobalState` or a
PHP static — exactly the shape the `cacheUpdates: false` hatch exists for, with no signal that it
was needed.

The skip itself is worth keeping: **16x** on the measured shape (broadcast p50 0.236 ms with the
skip vs 3.864 ms without), scaling as N_contexts x component render cost. Fix by treating an
*empty* signal set as "cannot prove purity — always sync", which is the safe default and costs
nothing for components that do declare signals.

---

## Outcome

Everything in Tiers 1-3 below landed except item 2. The ordering held up with one exception,
recorded in the status table at the top: item 7 had to precede item 6, not follow it.

**Still open**

- **#2** — brotli window size. `UNVERIFIED`; needs a machine with ext-brotli. The memory
  consequence of `LGWIN` is the thing to measure first.
- **#9 / #10** — **DONE.** Shipped as a write-behind durable tier
  (`Config::withPersistentGlobalState()`), not a writer process: reads stay in shared memory at
  0.193 µs, writes set a dirty flag, and a leader-worker timer drains the dirty set into one
  batched transaction (~1 µs per changed key). The checkpoint blocker that gated this was
  refuted — see the box under item 10 — and the writer process turned out to be unnecessary once
  item 7 removed the need for an owner worker.
- **#11** — closed. Gate failed on measurement: every broadcasting route is too cheap to cache,
  every expensive route never broadcasts.
- **#6c** (real threads) — closed. OpenSwoole's multicore story is processes.

The original ordering is kept below for the reasoning.

## Suggested order (original)