# Changelog

All notable changes to php-via will be documented in this file.

## [Unreleased]

### Breaking Changes

- **Inside a coroutine, `broadcast()` marks the scope and returns; the worker's next flush renders
  it.** This covers `Via::broadcast()`, `Context::broadcast()`, the auto-broadcast of scoped
  `setValue()`, `increment()` and `mutate()`, and broadcasts received from other workers or nodes.
  A flush re-renders each marked scope once, renders a context in several marked scopes once, and
  publishes each scope to the broker once. It runs at the end of the event-loop turn when the
  worker's last flush started at least one broadcast tick ago and ended at least half a tick ago,
  otherwise as soon as both have passed (`Config::withBroadcastTickMs()`, default 25 ms). Flushes
  of different scopes run side by side, so a view that waits on I/O delays only its own scope, and
  a scope broadcast while its fan-out runs is rendered by the first flush after that fan-out ends.
  What changes for apps:
  - Views render the state at flush time. Several writes in one action send one frame instead of
    one per write, as long as the action does not wait on I/O between them: a database call in
    between lets the flush send a half-updated frame, so write first and broadcast last. A value an
    action sets, broadcasts and resets before it returns never reaches clients.
  - Patches the action queues itself (`execScript()`, `$c->sync()`, `syncSignals()`) reach the tab
    before the broadcast's frame.
  - The action's HTTP response no longer waits for the fan-out, so a Datastar indicator can clear
    before the frame arrives. An error in the fan-out or in the broker publish is logged instead of
    failing the action.
  - Under sustained load a client gets about one frame per tick and skips the states in between.
    Flushes that take F ms, with F over half the tick, start F plus half a tick apart instead. A
    broadcast reaches clients at most that gap plus one flush after the call, and one more tick on
    another worker. A request that reaches a worker while a flush renders without waiting on I/O
    waits for that flush to end.
  - Dev Bar: fan-out renders appear as their own `broadcast {scope}` traces, and the action trace
    shows a `broadcast.schedule` span per call.
  - Two new warnings. "Broadcast chain limit reached" means views broadcast each other's scopes in
    a loop, which stops after 8 flushes. "The fan-out of scope ... has been running for" means a
    broadcast found that scope's fan-out still rendering after more than a second, usually a view
    blocked on I/O.

  `Via::flushBroadcasts()` runs the pending flush in the calling coroutine, for code that needs the
  frame before what it queues next. When another flush is rendering a scope the caller broadcast, it
  waits for that fan-out up to 1 s; past that it logs a warning and returns, and the frame follows
  on a later flush. `Config::withBroadcastCoalescing(false)` renders and publishes on every call, as
  before, except that a fan-out that other actions re-run 8 times in a row leaves the rest to a
  flush paced by the tick, which `getBroadcastStats()` counts under `flushes` (see Fixed).
  `withBroadcastTickMs(0)` keeps the coalescing but flushes every event-loop turn with no gap.
  Outside a coroutine (CLI scripts, tests) and during worker shutdown `broadcast()` stays
  synchronous. At shutdown the frames still waiting for the tick are rendered in a coroutine of
  their own, so they reach clients before the streams close unless a view waits on I/O, which then
  cannot hold up the stop. The owed publishes go out before the broker disconnects.

- **A closed tab is noticed when its connection closes.** OpenSwoole reports an HTTP/1.1 response
  as writable after the client has gone, so a stream with nothing to send stayed in `getClients()`,
  skipped `onClientDisconnect` and kept its context until a write to it failed, which for an idle
  tab could be never. The server's close event now ends the stream about 1 ms after the browser
  closes the connection. A browser that cancels one HTTP/2 stream keeps the connection, so no
  close event fires; each worker of a server that speaks HTTP/2 looks for such streams every
  250 ms and ends them, as 0.13.0 did within 100 ms. What changes for apps:
  - `onClientDisconnect` and the removal from `getClients()` run when an idle tab closes, on every
    worker, and `onDisconnect` / `onCleanup` follow after the cleanup delay
    (`withContextCleanupDelay()`, 5 s by default). Hooks that never ran for idle tabs now do, and
    their contexts are freed. A tab restored from the browser's back/forward cache after that delay
    is revived or reloads, like any tab that comes back after cleanup.
  - A stream that has sent nothing for 15 s writes the SSE comment `: keep-alive`, which browsers
    and Datastar ignore. Proxies that cut idle connections, such as nginx with its 60 s default
    `proxy_read_timeout`, therefore keep idle streams open. `Config::withSseKeepAliveMs()` sets the
    interval. `0` turns the comment off, and idle streams then wake once a minute. Code that reads
    the raw stream sees the comment.
  - `Config::withSsePollIntervalMs()` now sets only how often the Dev Bar stream polls. Page
    streams do not poll (see Performance).
  - With `dispatch_mode` 1, 3 or 7 in `withSwooleSettings()`, OpenSwoole has no close event, and
    the stream ends at its next keep-alive interval instead.
  - A stream whose context was destroyed without closing its channel sends its reload script at
    its next wake, up to the keep-alive interval later, instead of within 100 ms. Context cleanup
    closes the channel, so this is a safety net only.

- **Signals whose names differ only by punctuation no longer share an id.** A signal id replaced
  every byte outside `[A-Za-z0-9]` with `_`, so `user-name` and `user_name` in one tab were one
  signal, a page in `room:a-b` and `room:a.b` sent one browser value for both scopes' `topic`, a
  component `cats` with a GLOBAL `votes` got the GLOBAL signal `cats_votes`, a component TAB
  signal `q` in `search` took the id of a `q` in scope `search`, and a signal `ctx` in scope `via`
  overwrote the browser's `via_ctx`. An id that could be read two ways now ends in `____` and one
  code per `_` saying what that `_` stands for, so each signal has its own id. What changes for
  apps:
  - A scoped signal outside a component keeps its id when its name is letters and digits and its
    scope is letters and digits joined by `:`, or a route path of letters and digits:
    `global_count`, `room_lobby_messages`, `route__examples_counter_count`. A signal `ctx` in
    scope `via` is the one exception.
  - Every other id gains the suffix and still starts with the old id. That covers all TAB and
    component signals and scopes or names with `_` or other punctuation: `search_q` becomes
    `search_q____n`, `global_cats_votes` becomes `global_cats_votes____kn`. Templates that use
    `$signal->id()`, `bind()` or `text()` need no change. A hardcoded id stops matching, and
    `getScopedSignal()` returns null for it. Code outside a context, such as a timer, should look
    the signal up by name with the new `Via::getScopedSignalByName($scope, $name)`, adding the
    component namespace as a third argument for a component signal.
  - A tab left open across the deploy reconnects with the old ids, so revival does not restore
    its TAB and component values.
  - With `worker_num > 1`, deploy this version with a full restart, not a reload (`SIGUSR1`). A
    reload keeps the shared table: scoped signals whose ids changed start again from their
    declared defaults, an old worker that is still draining keeps writing the old ids, so updates
    made there are lost, and the old rows count against `withScopedSignalTableSize()` until the
    next full restart.

- **With `worker_num > 1`, session data values must be serializable and come back as copies.**
  `setSessionData()` with a closure, a PDO handle or another value that cannot be serialized now
  throws `\InvalidArgumentException`, and changing a stored object in place no longer changes
  what the session holds. See the session data entry under Fixed.

### Fixed

- **With `worker_num > 1`, session data set in one request could be missing in the next.**
  `sessionData()`, `setSessionData()` and `clearSessionData()` kept a copy in each worker, and a
  tab's requests land on any worker, so a value an action stored was missing whenever the next
  action or page load reached another worker. Session data now lives in shared memory, one row per
  session, and each write holds a lock on that session, so writes from several workers to different
  keys of one session all land (4 workers × 500 writes: 2,000 of 2,000 kept, about 680 without the
  lock). A key written twice keeps the last write, as before. What changes for apps:
  - Values must be serializable and come back as copies (see Breaking Changes).
  - Reading a key and writing it back is not atomic across workers: two tabs adding to one cart
    at the same moment on different workers can lose one of the adds.
  - One session's data is capped at 32 KB serialized, and a write past it throws
    `\OverflowException`. `Config::withSessionTableSize($maxSessions, $maxBytesPerSession)` raises
    the cap.
  - A write that cannot take the session's lock within about 7 s, because a worker died holding
    it or its event loop is blocked, throws `\RuntimeException`.
  - Past 4,096 sessions the first worker drops the least recently used ones every second and logs
    a warning, and a write that finds the table full drops them on its own worker first; a single
    worker still drops them past 10,000. `withSessionTableSize()` sets the count.
  - The table reserves about twice the session count, rounded up to a power of two, times the
    bytes per session: 257 MB of shared memory at the defaults. About 34 MB of it is resident from
    start-up, and the rest becomes resident as sessions store data and is not returned.
  - Session data survives a worker reload (`SIGUSR1`), since the master process holds it.
  - With one worker nothing changes: session data stays in a PHP array with no byte cap.

- **With `worker_num > 1`, a tab whose stream stayed busy could lose the ability to act on other
  workers.** The context directory entry that lets any worker rebuild a context expires after
  `withContextDirectorySize()`'s TTL (3,600 s by default) unless it is refreshed, and only an idle
  wake of the SSE loop refreshed it. A worker that destroys its copy of a context also cuts the
  entry to the revival window (600 s by default), even when the tab has moved its stream to
  another worker. A tab that got a frame at least every 100 ms for that long lost its entry, and
  its actions landing on another worker answered 400 `Invalid context` until it reconnected. Each
  worker now rewrites the entries of all its streams, busy or idle, from one timer every quarter
  of the TTL or of the revival window, whichever is shorter (150 s by default), and restores an
  entry that has gone.

- **With `worker_num > 1`, a client whose stream stayed busy for 120 s dropped out of
  `getClients()` for good.** A row expired 120 s after its last heartbeat, and the next
  `getClients()` deleted it, but the heartbeat ran only when the stream had nothing to send. A tab
  that got a frame at least every 100 ms for two minutes left the list until it reconnected. Rows
  no longer expire. The worker that registered a client removes it when the stream ends, and the
  clients of a worker that crashes or is killed leave the list when OpenSwoole restarts it: 65 ms
  after a SIGKILL in the test, against up to 120 s before. Every 60 s the first worker also drops
  the clients of any worker process that no longer exists, in case the restart missed some.

- **A tab that reconnected to the same worker before its old stream ended dropped out of its
  scopes and `getClients()`.** The new stream registered the tab, then ended the old stream, which
  still counted as the tab's last one and unregistered it again, firing `onClientDisconnect`. The
  tab then missed broadcasts to custom and session scopes until its next reconnect. In 0.13.0 this
  was every such reconnect of an idle HTTP/1.1 tab, such as a tab the browser hid and showed
  again, because the old stream never noticed its connection close. A tab whose new stream
  replaces a running one now stays connected: `onClientConnect` fires once and
  `onClientDisconnect` fires when its last stream ends.

- **A tab that reconnected to another worker could drop out of `getClients()`.** When its old
  stream ended after the new one had registered, the old worker deleted the new row. Each worker
  process now writes its own row for a client and removes only that one, and the list shows the
  tab once.

- **A full client registry broke SSE connections.** Past its capacity (the context directory size
  from `Config::withContextDirectorySize()`, 4,096 rows by default), registering a client threw,
  which ended that tab's SSE request, and so did every reconnect that registered it again. The
  client is now left out of `getClients()` and a warning is logged.

- **A `mutateGlobalState()` or `Signal::mutate()` call that timed out, or a worker that died while
  waiting in one, wedged the key.** Both left a ticket that the lock later served with no lease on
  it, and waiters only broke in on an expired lease, so every later mutate on that key threw
  `RuntimeException` after 5 s for as long as the server ran. One overload burst that timed out a
  few callers was enough. A caller that times out in a coroutine now leaves its ticket to a
  watcher coroutine, which passes the turn on as soon as it comes. A ticket nobody watches, from a
  process that died or from a caller outside a coroutine, is skipped once the queue has stood
  still for 2 s with no lease, so each one costs about 2 s. The same rule also skips a live worker
  that is next in line but cannot run for 2 s, for example because its event loop is blocked,
  just as an overdue holder is skipped, and its write can then overlap the next holder's.

- **Releasing the mutate lock could erase the next holder's lease.** The releasing worker cleared
  the lease after advancing the queue, and a holder that lost its lease that way could not be
  recovered if it then died. The lease is now cleared first, and only by the holder still being
  served.

- **A tab could keep an older frame than one it had already received.** When a view waited on I/O
  during a broadcast, a broadcast that came after it could render the same tab and finish first.
  The older frame then arrived last and stayed until the next broadcast to one of the tab's scopes,
  with one worker or several. A fan-out now renders the tab again when a fan-out that started
  after it finished that tab first, so the tab shows the older frame briefly and then the current
  one. After 8 such renders in a row it logs a warning and leaves the tab to the next flush of the
  scope, which renders it again.

- **With coalescing off, the last write under sustained load could be lost.** A broadcast that
  finds its scope's fan-out running makes that fan-out run one more pass, and a fan-out stopped
  after 8 passes in a row and dropped the broadcast still owed. When other actions kept
  broadcasting that long, which happens under load once the views wait on I/O, clients could end
  on an older state until the scope's next broadcast, and the log blamed a view that broadcasts its
  own scope. 0.13.0 renders this way on every broadcast and has the same fault. After 8 passes the
  fan-out now hands the owed broadcast to the next flush, paced by the tick, and returns; during
  shutdown it drops it, since no flush runs any more. "Broadcast re-entrancy limit reached" is
  logged only when the fan-out's own views broadcast its scope again in all 8 passes, directly,
  through another scope or from a coroutine they start during the fan-out, and that broadcast is
  still dropped, so such a view cannot hold the worker. This holds with coalescing on too. A
  coroutine the action started before it broadcast counts as another action. Coalesced broadcasts
  from other actions already went to the next flush and were not affected.

- **A page that embedded component HTML rendered in its handler reset the components on every
  page re-render.** The docs passed `$counter()` to `$c->view('page.html.twig', [...])`, which
  renders the component once, when the handler runs, so each broadcast that reached the page, each
  reconnect and each revival sent the page-load HTML again. The docs now call the component
  callables inside the view closure. A component the page frame already carries then sends only its
  signals in that sync, instead of rendering again for a `#c-` frame of its own. The docs also no
  longer suggest returning `''` from a page view on updates: a revived tab mounts its components
  under new ids, and only the page frame can bring those into the page.

### Performance

- **Broadcast storms cost a bounded number of renders.** Every `broadcast()` call and every scoped
  signal write re-rendered every client in the scope inside the calling action and published once
  per call, so N clients and M actions cost N x M renders, and each action waited for its own
  fan-out. Each worker now renders a scope at most once per flush and starts a flush at least one
  tick after the previous one started and half a tick after it ended, so flushes whose views render
  for F ms without waiting on I/O use at most F / max(tick, F + tick / 2) of the worker whatever
  the action rate, that is F / tick while F is at most half the tick and two thirds when F equals
  the tick. Between two flushes the worker always has half a tick for requests, timers and I/O.
  `MessageBroker::publish()` is called once per scope per flush, and never from two
  coroutines of one worker at the same time, so RedisBroker and NatsBroker no longer share their
  publish connection between coroutines. `$app->getStats()->getBroadcastStats()`, and
  `broadcast_stats` in the dev-mode `/_stats`, report per worker the broadcasts scheduled and
  coalesced, the flushes, the last, longest and total flush time, and the flushes that overran the
  tick. In a small `bench/contention/broadcast_storm.php` run (200 SSE clients, 50 actions from 10
  connections, 1 worker, 3 runs) renders fell from 10,000 to 400, frames per client from 50 to 2,
  and the action p50 from about 11 ms to between 0.5 and 1.2 ms. The last frame reached every
  client 17 to 28 ms after the last action, against 11 ms before.

- **Contended `mutateGlobalState()` and `Signal::mutate()` scale with coroutines per worker.**
  Every waiting coroutine held its own ticket and read the whole row at reactor speed, because
  OpenSwoole treats a coroutine sleep under 1 ms, such as the old 200 µs pause, as a plain yield.
  Coroutines of one worker now queue locally, so a worker holds one ticket and runs one poller per
  key. Polls read a single column, and waiters park on a 1 ms timer once the queue has not moved
  for 2 ms. Measured with `bench/contention/lock_contention.php` on one hot key, median of 3 runs
  on a 20-core host shared with other load, so the absolute rates move with that load: 4 workers
  x 32 coroutines went from 38k to 221k mutations/s and from 106 to 17 µs of CPU per mutation;
  8 workers x 32 coroutines from 12k to 50k and from 636 to 158 µs. A coroutine waiting behind a
  holder that stalls for 300 ms uses 6 ms of CPU instead of 300 ms. While the queue moves, each
  waiting worker still keeps one core busy polling.

  Concurrent mutations of one key now run FIFO within a worker and round-robin across workers,
  and callers of one worker return in the order they ran. A coroutine waits at most 2 s behind
  earlier callers of its own worker, or not at all once the local holder has overrun its 2 s
  lease, and its 5 s timeout starts when it takes a ticket, so a call can now take up to 7 s
  before it throws.

- **A broadcast reads each scoped signal from shared memory once per flush.** With `worker_num > 1`
  every read of a scoped signal went to the shared table (a sha1 of the key, a row lock, and an
  `unserialize()` for non-integers), and a fan-out read every signal again for every context, once
  in its view and once for its signals patch. A flush now reads each signal once and reuses the
  value for every context of every scope it renders. With `withBroadcastCoalescing(false)` or
  outside a coroutine, each fan-out reads each signal once. Actions, timers, hooks, page loads and
  SSE initial syncs still read shared memory on every call, and a write on this worker during a
  flush, a view's own included, is read back at once. A write on another worker arrives with a
  broadcast: a scope it marks before a running flush reaches that scope is read again there,
  including the tabs the flush rendered before the mark, and any other scope goes to the next
  flush. A tab that an older flush renders after a newer one did is rendered again (see Fixed). A
  view that computes a new value from a scoped signal during a broadcast now computes it from the
  flush's value, so use `increment()` or `mutate()` there. In `bench/contention/shared_read.php`
  (2,000 contexts, 5 scoped signals written by another worker, two runs per tree), store reads per
  broadcast fell from 20,000 to 5 with a view per context and from 10,005 to 5 with a cached route
  view. The store's cost over a single-worker run fell from 30 ms (+146%) to 1.6 ms (+8%) and from
  17 ms (+430%) to 0.6 ms (+14%) per broadcast.

- **`getClients()` no longer rebuilds the list on every call.** With one worker it copied every
  client into a new array; it now returns the stored one. With `worker_num > 1` every call scanned
  the shared table and regenerated each client's 1.5 KB identicon, about 5 µs per client, so a
  broadcast whose per-tab view counts the clients cost tabs x clients. Each worker now keeps the
  list it built last and rebuilds it only after a client connects or leaves on any worker, reusing
  the identicons it already made. A broadcast reads the list once for all the views it renders,
  like scoped signals, and again only when a client joins or leaves this worker meanwhile, or when
  other code on this worker reads the list while a view in the broadcast waits on I/O. A client
  joining another worker otherwise shows up on the next broadcast. Each worker that calls
  `getClients()` holds about 2.3 KB per client. In `bench/contention/get_clients.php` with 500
  clients, a call went from 2.4 ms to 0.1 µs, or 0.2 ms right after a connect, and a broadcast to
  100 tabs whose view counts the clients from 239 ms to 0.4 ms. With one worker a call went from
  36 µs to 0.1 µs.

- **Idle SSE streams cost nothing between events.** Every page stream woke every 100 ms to check
  its connection, and with `worker_num > 1` each wake also wrote the context directory and the
  client registry in shared memory, so idle CPU grew with the number of open tabs. A stream now
  sleeps until a patch is queued, its connection closes, the worker stops or the keep-alive
  interval passes, which is one wake per 15 s instead of 150. The directory refresh moved to one
  timer per worker (see Fixed).
  Patch latency does not change, because a queued patch always woke the stream at once.
  In `bench/contention/idle_sse.php` with 1,000 idle streams, worker CPU went from 4% of a core
  to below the 0.25% the run can resolve with one worker, and from 8.75% to below it with four.
  The workers' voluntary context switches, a proxy for wakes, fell from about 440 to 1 per second
  with one worker and from about 1,200 to 4 with four. A broadcast still reached all 1,000 streams,
  and SIGTERM still stopped the server within 100 ms. A server that speaks HTTP/2 also checks its
  streams for client resets every 250 ms, which took 75 µs per worker for 2,000 streams.

## [0.13.0] - 2026-09-29

### New Features

- **Multi-worker mode works.** With `worker_num > 1`, scoped signal values, the SSE client list
  and the context directory now live in shared memory, so an action served by any worker reaches
  the context it names. Before, an action on a worker that had not rendered the page got 400
  `Invalid context`: measured over 1,000 actions, success fell to 51% at 2 workers and 6.9% at 16.
  It is now 100% at every worker count. A worker that does not hold a context rebuilds it by
  re-running the route handler, as SSE reconnects already did, and adopts the live shared values
  instead of the declared defaults. The server logs a warning at start-up listing what stays
  per-worker: PHP statics in app code, server-owned TAB signals and session data.

- **`Signal::increment()` and `Signal::mutate()`** for race-free updates to shared signals.
  `increment()` is atomic across workers; `mutate()` runs a callback under a per-signal lock.
  Over 4 workers doing 500 mutations each, `increment()` kept 2000 of 2000 against 802 for
  `setValue($signal->int() + 1)`. Do not mix the two on one signal: `increment()` skips the lock.

- **`Config::withPersistentGlobalState($path, $flushMs = 1000)`** keeps GlobalState in a SQLite
  file across restarts. Reads never touch SQLite. Writes mark the key dirty and the leader worker
  writes the dirty keys in one transaction every `$flushMs`, about 1 µs per key. Anything written
  since the last flush is lost if the process dies.

- **`Config::withSseMaxQueuedBytes()`** (default 1 MB, `0` disables) drops element patches for a
  client whose unsent backlog exceeds the threshold, instead of parking its SSE coroutine in
  `write()` until the client drains or disconnects (measured at 20 s). Element patches are
  idempotent, so the client catches up on the next broadcast. Signal and script patches are never
  dropped.

- **`Config::withScopedSignalTableSize()` and `Config::withContextDirectorySize()`** size the new
  shared tables.

- **`#[Signal(Scope::GLOBAL, atomic: true)]`:** atomic counters for the composition API.
  PageMount hydrates each `#[Signal]` property before an `#[Action]` and assigns it back after, so
  `++$this->votes` on a shared signal was a read-modify-write with the whole action body in the
  gap — and the attribute API had no way to reach `Signal::increment()`. With `atomic: true`,
  syncBack applies the action's net *change* through `increment()` instead. Measured over 6 worker
  processes doing 500 actions each on one signal, the plain property retained 2410–2514 of 3000
  and `atomic: true` retains all 3000. Rejected at mount on a non-integer property.
  The trade-off: assignment to an atomic property becomes an adjustment, so `$this->votes = 0` is a
  decrement-by-current rather than a reset. Use `$ctx->getSignal('votes')->setValue(0)` to mean SET.

- **`Via::incrementGlobalState()` and `Via::mutateGlobalState()`:** atomic read-modify-write for
  GlobalState, closing the last cross-worker store that had no race-free mutation path.
  `Signal` gained `increment()`/`mutate()` when scoped signal values started crossing workers, but
  GlobalState kept only get/set — leaving `setGlobalState($k, globalState($k) + 1)` as the only way
  to express a shared counter. Measured over 4 worker processes doing 500 mutations each on one
  key, that read-modify-write retained 772–1238 of 2000 increments; `incrementGlobalState()`
  retains all 2000. For non-integers, appending to a list through `setGlobalState()` kept 536–735
  of 2000 entries against 2000 through `mutateGlobalState()`.
  `SharedTable` gained the storage split this needs — an atomic `TYPE_INT` column for integers
  plus the same ticket lock `SharedSignalStore` uses — and the distinction survives the durable
  snapshot, so a persisted counter comes back on the atomic path after a restart rather than as an
  opaque blob that reads correctly and then throws on its next increment.
  Single-worker behaviour is unchanged, and existing `setGlobalState()` calls keep working.

- **`clientWritable` for every scope, and `Config::withStrictTabSignals()`.** `clientWritable` is
  now `?bool` on `Context::signal()`, `Signal` and `#[Signal]`: `null` keeps the old rule (TAB
  writable, scoped server-owned), `true` and `false` apply to any scope. The new
  `Config::withStrictTabSignals()` (off by default) makes TAB signals server-owned unless declared
  `clientWritable: true`.

- **`Config::withAllowMissingOrigin()`** (off by default) accepts action POSTs without an `Origin`
  header in production, for non-browser clients. Dev mode always accepts them.

### Fixed

- **Scoped signals of two different scopes could share one value across workers.** Signal ids
  are sanitised, so `user:a-b@x.com` and `user:a.b@x.com` produce the same id for a signal of
  the same name. With `worker_num > 1` the shared store was keyed by that id alone, so one
  user's value showed up in the other's scope. Rows are now keyed by the raw scope plus the id.
  Single-worker servers were not affected.

- **`mutateGlobalState()` and `Signal::mutate()` could lose writes on a fresh key.** The lock
  row was created with `exists()` then `set()`, so two workers touching a new key together could
  both create it: the second reset the value and the ticket counter while the first was inside its
  callback, and both held the lock. With 8 workers creating 200 keys in step, 44 to 76 of 1600
  appends were lost per run. Rows are now created atomically.

- **Multi-worker was non-functional.** Four independent faults, each enough on its own:
  `dispatch_mode => 7` is not a valid OpenSwoole constant, so session affinity never ran; the
  `dispatch_func` installed beside it fatals on PHP 8.4; every broker generated its node id before
  the fork, so sibling workers shared one id and dropped each other's messages as their own; and
  `$server->worker_num` does not exist on ext-openswoole 26, so the broker fan-out looped zero
  times. The custom dispatch is removed, the node id is generated per process, and the worker
  count is read from `$server->setting`.

- **A signal patch dropped from a full queue was never resent.** Signals were marked synced when
  the patch was queued rather than delivered, so an evicted delta left the browser out of step for
  good. Delivery is now confirmed by the SSE writer and an evicted signal stays dirty for the next
  sync. Eviction under pressure now drops element patches first, then signal patches, and script
  patches only as a last resort.

- **The SSE loop stopped noticing shutdown, destroyed contexts and dead clients.**
  `Channel::pop(0)` blocks with no timeout, so the loop parked indefinitely and its liveness
  checks never ran. It now pops with a real timeout and exits on a closed channel.

- **Two broadcasts could interleave mid-fan-out,** so a client received half of one frame and half
  of the next. Fan-outs are now serialised per scope.

- **A signal declared with `Scope::ROUTE` emitted no patches.** It was compared against the
  unexpanded constant instead of the route-qualified scope, so the browser never saw it change.

- **A component that declares no signals froze on its first render.** With `cacheUpdates: true`,
  the default, an empty signal set counted as "nothing changed" on every broadcast. It now syncs.

- **`withActionRateLimit()` was enforced per worker,** so the effective limit was
  `limit × worker_num`. The counter is now shared and the limit holds at any worker count. If the
  table fills up, requests are allowed and the overflow is logged once.

- **`Via::setInterval()` fired once per worker.** It now runs on one worker; see Breaking Changes.

- **`log('warning')` was filtered as info,** and `withLogLevel('warning')` meant info and above.
  Both spellings now map to the warn level, so `withLogLevel('warn')` shows the warnings it
  previously hid.

- **GlobalState table limits.** A full table raises `\OverflowException` instead of a bare
  OpenSwoole error. The value cap per key is raised from 4 KB to 32 KB: a growing list overflowed at
  about 240 short entries. `maxRows` is documented as a floor, since OpenSwoole admits more keys
  than requested but rejects by hash once past it.

- **A shared scope that silently disabled the update cache is now reported.** A context that joins
  a shared scope with `addScope()` stays TAB-primary and re-renders per client on every broadcast.
  A warning is logged once per route when such a view still claims to be cacheable and declares no
  TAB signals.

- **`onShutdown` callbacks now run when the server is stopped.** OpenSwoole owns SIGTERM in
  server processes, so the `Process::signal(SIGTERM, ...)` calls in the master and in every worker
  failed with a "processor has been registered by the system" warning and the only code that ran
  the callbacks never did. Measured with 2 workers and one open SSE stream: `kill -TERM` and
  `kill -INT` to the master ran no callback and ended in a scheduler deadlock; SIGINT to the process
  group ran them and then died with `Uncaught OpenSwoole\ExitException` from the worker's `exit(0)`.
  Cleanup now runs from `workerExit`, in a coroutine: server intervals are cleared, patch channels
  are closed and the worker waits for the SSE loops to leave through their normal exit path
  (`onClientDisconnect` included), then each callback runs in its own try/catch, then the broker
  disconnects. Each worker arms an idle keepalive timer so `workerExit` fires even when nothing else
  is scheduled. OpenSwoole's manager now ignores SIGINT, so the master alone drives a Ctrl-C stop.
  Behaviour changes: callbacks run once per worker, inside a coroutine. They also run on every
  worker reload (SIGUSR1) and `max_request` recycle, which end that worker's SSE streams too, so
  `onClientDisconnect` fires and the clients reconnect. A callback cannot tell a reload from a
  stop. The default `max_wait_time` is now 3 seconds (was 1): OpenSwoole counts it in whole
  seconds, so a stopping worker gets roughly `max_wait_time` minus up to one second, and with 1 a
  callback yielding a few hundred milliseconds was regularly killed. A stop can take up to
  `max_wait_time` while coroutines finish: a coroutine, socket or `Event::add` fd the app keeps
  alive past `onShutdown` holds the worker until OpenSwoole kills it, and the worker then logs how
  many coroutines were left. `isShuttingDown()` is now public so background loops can end on
  their own. The signal warnings are gone from the logs.

- **Persistent GlobalState no longer shares one SQLite connection across `fork()`.** The snapshot
  was opened in the master before the workers were forked, and the final drain was an `onShutdown`
  callback, so with several workers each one drained and checkpointed over that inherited
  connection. The boot connection is now closed after loading, the leader worker opens its own for
  the periodic flush and flushes once more when it stops, and the final drain runs once in the
  master's `shutdown` event after every worker has stopped. The leader's stop flush is there because
  a second SIGTERM to the master can end it before that event (seen in about 1 of 8 runs).
  `SqliteSnapshot` reopens after `close()` and throws instead of using a connection opened in
  another process.

- **A `#[Signal]` written directly inside an `#[Action]` is no longer discarded.** `syncBack()`
  assigned every reactive property back to its signal unconditionally, so an action whose body was
  `$ctx->getSignal('votes')->increment()` ended each round exactly where it started — the untouched
  property still held the pre-increment value and was written straight back over the increment
  (measured: 0 after five increments). Properties the action did not change are now left alone.
  This also stops a shared signal being clobbered with a stale hydrated value when another worker
  wrote it while the action was running. Where an action both writes the signal directly and
  changes the property, the direct write wins, which makes
  `$this->votes = $ctx->getSignal('votes')->increment()` correct rather than double-counting.

- **A throw from an action, a view or a timer no longer kills the worker.** `ActionHandler` caught
  `\Exception` only, so a `TypeError` or `ValueError` in an action closure escaped the request
  coroutine: the connection dropped, the worker exited with code 255 and every context on it was
  gone, so the tab's next action got 400 from the respawned worker. The initial page render, the
  per-tab `$c->setInterval()` callback and the broadcast fan-out had no guard at all, and a plain
  `RuntimeException` from a view was enough. Every request, SSE, per-tab and server interval,
  cleanup, fan-out and broker entry point now catches `\Throwable`. These guards, and the existing
  ones around `Via::setInterval()`, the client connect and disconnect callbacks and revival, log
  the class, message and `file:line`. `RequestHandler::handleRequest()` has a last guard that
  answers 500 when nothing has been written yet; an SSE stream that already wrote is only ended,
  and its exit bookkeeping still runs, so the context is cleaned up as usual. A page whose handler
  or render throws has its context torn down before the 500, timers and scopes included. A context
  whose view throws during a broadcast is skipped, so the other contexts still get the frame, and
  each broadcast logs one line per distinct failure with the number of contexts it hit. A broker
  message whose fan-out throws is logged and the Redis and NATS receive loops keep reading.
  Behaviour changes: an action that throws an `\Error` answers 500 `Action failed`, as an
  `\Exception` already did, and the worker keeps running with whatever the action changed before
  it threw, where the crash used to wipe every context on that worker. A page whose render throws
  answers 500 instead of dropping the connection. A per-tab interval keeps ticking after a throw,
  as `Via::setInterval()` already did. An SSE stream whose initial sync throws answers 500 and
  fires neither `onClientConnect` nor `onClientDisconnect`. The default shell retries the stream
  within 15 s; a custom full-document view without such an interval does not reconnect.

- **`clientWritable` is honoured for TAB signals, and component signals receive client values.**
  `SignalFactory` built TAB signals without the flag and `injectSignals()` accepted every TAB value,
  so `$c->signal(..., clientWritable: false)` was silently ignored and a TAB signal could not be
  kept server-owned: Datastar posts every signal with each `@post`, and the browser copy overwrote
  the server value before the action ran. A rejected TAB value that differs from the server's
  marks the signal changed, so the next sync puts the server value back in the browser (scoped
  signals are sent on every sync anyway). `injectSignals()` also only looked at the page context's
  own signals, so a `data-bind` inside a component never reached the component's action; ids the
  page does not own now go to its components, nested ones included. The same applies on revival
  for components declared with an explicit namespace; an auto-named component gets new signal ids
  when the page is rebuilt, so its signals start from their initial values. Re-declaring a TAB
  signal still sets it to the new initial value, and now logs a warning, once per name, when that
  changes the live value or explicitly asks for a different `clientWritable` (the first one is
  kept). The warning gives the type and size of both values, never their content. A signal holding
  a JSON object also never received the posted value: the object was split into `id.key` entries
  that matched no signal. An object posted under a known signal id is now that signal's value.

- **A view that renders a full `<html>` document gets `via_ctx`, a signal seed and the head/foot
  includes.** `HtmlBuilder` returned such a view unchanged, so `appendToHead()`/`appendToFoot()`
  content never reached it and signal values arrived only with the first SSE frame, which left
  expressions like `$x.y` throwing until then. The initial render now adds, right after the opening
  `<head>` tag and so ahead of the layout's SSE bootstrap, a `via_ctx` meta (only when the document
  has none) and a `data-signals__ifmissing` meta with what the first sync sends: changed TAB
  signals, scoped signals and the page's component signals. Datastar compiles that attribute as
  code, so `@`, `;`, `\\` and non-ASCII characters in the values are `\u`-escaped. Includes go
  before the first `</head>` and the last `</body>` unless their exact markup is already there, also
  on every SSE update, since the morph replaces `<head>` too. The layout still carries the SSE
  bootstrap and `datastar.js`. Shell pages get the same seed in `{{ head_content }}`.

- **Shell signal placeholders use the name passed to `signal()`.** `graph_display` and
  `graph_ports` both filled `{{ graph }}`. The shell is now filled in one pass, so placeholder text
  inside the view or a signal value is left alone.

- **An Origin allowlist no longer lets requests without an Origin through.** With
  `withTrustedOrigins()` set, `ActionHandler` accepted any action POST that carried no `Origin`
  header, even in production, while without an allowlist the same request was denied: the
  stricter setting was the laxer one for that case. The Dev Bar's `/_via/signal` and
  `/_via/reset` had their own copy of the check that let a missing `Origin` or `Host` through in
  every mode. Both now use one `OriginPolicy`: a missing `Origin` is accepted in dev mode or with
  the new `Config::withAllowMissingOrigin()`, and denied otherwise. The first such denial per
  worker logs a warning that names the opt-in, and the 403 body says `missing Origin`.
  `POST /_session/close` had no Origin check at all, so a cross-site page that knew a context id
  could schedule that tab's cleanup, which ended the context if its SSE connection was down when
  the timer fired; it now uses the same policy.

- **Registering a TAB action name twice in one context logs a warning.** The later callback
  replaced the earlier one with only a debug line. It still does, and now logs a warning once per
  action id. Scoped actions keep the first registration, as before.

### Breaking Changes

- **`Via::setInterval()` runs on the leader worker only.** With `worker_num > 1` each worker used to
  arm its own timer, so a 100 ms interval fired about 4 times as often on 4 workers. Pass
  `everyWorker: true` for work that must run in every process.

- **GlobalState keys longer than 63 characters throw `\InvalidArgumentException`** wherever the
  shared table backs GlobalState: with `worker_num > 1` or `withPersistentGlobalState()`. A
  64-character key used to be accepted with a "key is too long" warning on every write.

- **Action POSTs without an `Origin` header get 403 in production when `withTrustedOrigins()` is
  set.** Browsers send `Origin` on every POST, so browser traffic is unaffected. Non-browser
  clients that post to `/_action/*` (curl scripts, server-to-server calls, uptime checks) need
  `Config::withAllowMissingOrigin()`. Without an allowlist nothing changes for actions. The Dev
  Bar's `/_via/signal` and `/_via/reset` no longer accept a missing `Origin`, or an `Origin`
  without a `Host` header, outside dev mode. The same applies to `POST /_session/close`, with or
  without an allowlist. A page served with `Referrer-Policy: no-referrer` sends its tab-close
  beacon with `Origin: null`, which is denied; the SSE disconnect then schedules the cleanup.

- **Shell placeholders and full-document includes changed.** A custom shell that used
  `{{ graph }}` for a signal named `graph_display` must use `{{ graph_display }}`. Placeholder
  values are HTML-escaped, so a string placeholder inside a `<script>` arrives escaped: read the
  signal from Datastar instead. They are also JSON with `@`, `;`, `\\` and non-ASCII characters
  `\u`-escaped, like the seed, so they are safe inside a Datastar attribute. Content from
  `appendToHead()`/`appendToFoot()` that full-document pages silently dropped now appears there,
  so a `<title>` appended for shell pages lands next to the layout's own: append it only for shell
  pages. Every page also gains the seed meta. The Dev Bar is no longer re-added to component
  updates.

- **`clientWritable: false` on a TAB signal is enforced.** It was ignored before. Such a signal no
  longer takes the browser's value on actions or on revival, where it starts from the handler's
  initial value. The parameter type widens from `bool` to `?bool`, which breaks a subclass of
  `Context`, `Signal` or `SignalFactory` that overrides one of these signatures with `bool`.
  With `worker_num > 1`, an action on a worker that does not hold the context rebuilds it the same
  way, so a server-owned TAB signal is per-worker state: use `worker_num = 1` or a scoped signal.

- **Component TAB signals are client-writable by default, like page TAB signals.** They take the
  browser's value on every action, which overwrites a value the server set (from an interval, a
  broadcast or another action) and has not synced yet. `clientWritable: false` or
  `Config::withStrictTabSignals()` opts out.

- **`ext-openswoole` now requires v26:** the extension constraint was unbound (`*`) and is now
  `^26.0`, matching the upgrade of `openswoole/core` and `openswoole/ide-helper` from v22 to v26.
  Apps running ext-openswoole 22 must upgrade the extension before updating php-via. Previously the
  unbound constraint allowed a v22 extension to be paired with the v26 core library — or the
  reverse — with no install-time error.
  Migration: rebuild the extension (`pecl install openswoole-26.2.0`), then `composer update`.
  php-via's own API is unchanged, so no application code changes are required.

### Dependencies

- Upgraded `openswoole/core` `^22.2` → `^26.2` and `openswoole/ide-helper` `^22.1` → `^26.2`.
  php-via imports nothing from `OpenSwoole\Core\*` and uses none of the APIs dropped in v26
  (`Coroutine::fgets`/`fread`/`fwrite`, `Coroutine\System::fgets`/`fread`/`fwrite`,
  `Coroutine::getuid`, `Coroutine::suspend`, and the `Coroutine\PostgreSQL` fetch methods that moved
  to the new `Coroutine\PostgreSQLStatement`), so no source changes were needed.
- Upgraded `pestphp/pest` `^4.0` → `^5.0`, which pulls PHPUnit 12 → 13. `phpunit.xml` needs no
  schema changes.
- Removed `rector/type-perfect`: the package is abandoned and `tomasvotruba/type-coverage` 2.3.0
  absorbed it, so both registered the same PHPStan services and the analyser crashed with
  "Multiple services of type `Rector\TypePerfect\Reflection\MethodNodeAnalyser` found".
- Pinned `tomasvotruba/type-coverage` to `^2.3`: the `type_perfect:` block in `.phpstan.neon` now
  depends on the copy bundled from 2.3.0, which the previous `^2.0` could have resolved away.
- Refreshed lockfiles: `friendsofphp/php-cs-fixer` 3.95.18, `twig/twig` 3.28, `symfony/*` 1.41, and
  `tempest/highlight` 2.27 (website).
- Website: `postcss` `^8.5.14` → `^8.5.26`. `public/css/site.css` was rebuilt with no content
  change — newer postcss emits fewer line breaks. `open-props` was already current at 1.7.23.
- The `src/Via.php` PHPStan exclusion is still required: the v26 stubs still omit
  `OpenSwoole\Event::EVENT_READ`, so lifting it still crashes the analyser.

## [0.12.0] - 2026-07-08

### New Features

- **Context revival**: a tab backgrounded long enough that its context is destroyed now rebuilds an
  equivalent context on reconnect — same context ID, so the already-loaded DOM keeps working — and
  re-seeds signal values the client still holds, **instead of hard-reloading the page**. This
  preserves local (`_`-prefixed) signals, scroll position, and focus that a reload would wipe. It is
  on by default (10-minute window) and needs no app code; tune or disable it with
  `Config::withContextRevivalWindow()` (`0` = fall back to the previous reload behavior). Revival
  re-runs the page handler, so — exactly as on a reload — server-only state (`#[Persist]`) resets and
  `onDisconnect`/connect hooks re-fire. Named components survive; anonymous components (no explicit
  name) reset. As part of this, TAB-scoped action IDs are now deterministic (previously random per
  registration) so a revived context's action URLs match the ones already in the DOM.
- **`Config::withContextCleanupDelay()`**: configures the grace period (default: 5 seconds) before
  an inactive context — one whose SSE connection has closed — is destroyed, allowing time for page
  navigation or a brief reconnect. Previously hardcoded; mirrors the existing
  `Config::withGcInterval()` pattern. Pass `0` to disable the grace period and clean up immediately
  on disconnect.

## [0.11.0] - 2026-07-07

### New Features

- **`Config::withStaticCacheControl()`**: sets the `Cache-Control` header for `/datastar.js`,
  `/via.css`, and files served via `withStaticDir()`. Defaults to `no-cache` in devMode (so
  edits to a `withStaticDir()` file are visible on the next reload) or
  `public, max-age=3600, must-revalidate` otherwise. Pass a string to apply one value to every
  static response, e.g. `public, max-age=31536000, immutable` for fingerprinted filenames — or a
  closure `(string $filePath, string $mimeType): string` to fine-tune the value per file (e.g.
  long-cache fonts and fingerprinted assets, short-cache everything else). A string is always
  taken literally, never invoked as a function name.

### Bug Fixes

- **Static asset caching**: `/datastar.js`, `/via.css`, and `withStaticDir()` responses now emit
  `ETag`/`Last-Modified` and honor `If-None-Match`/`If-Modified-Since` with a `304`. Previously
  `Cache-Control` was hardcoded to `public, max-age=3600` with no revalidation support, and
  `/datastar.js`/`/via.css` sent no cache headers at all.
- **Static file Brotli cache**: the in-memory compressed-body cache is now keyed by file path
  *and* mtime. Previously, editing a `withStaticDir()` file without restarting the worker kept
  serving the stale pre-edit compressed bytes indefinitely.

### Internal

- Extracted static-file conditional-GET logic into `Support\ConditionalGet` — free of OpenSwoole
  types so it's unit tested independently of a running server.

## [0.10.1] - 2026-06-15

### Fixed

- Added the one missing parameter type (`Context` on a `DevBarController` closure) so the
  full-project PHPStan `type_coverage` run passes again. No runtime change.

### Documentation & Website

- Homepage: new feature cards for the Dev Bar and the closure/composition API choice, plus a
  "Built-in debugging / observability" row in the comparison table.

## [0.10.0] - 2026-06-15

### New Features

- **Composition API (class-based pages & components)**: a declarative alternative to the
  closure API, built entirely on top of the existing infrastructure. Annotate a class with
  PHP attributes and mount it with `Via::mount(SomeClass::class, '/route')`;
  `Context::component()` now also accepts a class-name string. The closure API is unchanged —
  composition is purely additive.
  - `#[Signal]` — TAB-scoped, client-writable reactive property.
  - `#[Signal(Scope::ROUTE|SESSION|GLOBAL|"custom")]` — scoped reactive signal that
    auto-broadcasts to its scope.
  - `#[Persist]` — server-only instance state that survives between action calls.
  - `#[Broadcast(Scope::X)]` — sets the context's primary broadcast scope.
  - `#[Action(name?, scope?)]` — marks a public method as a client-callable action.
  - `#[OnDisconnect]` / `#[OnCleanup]` — lifecycle hooks (max one each).

- **Dev Bar**: an opt-in in-page debug overlay with a request-trace waterfall and a
  multi-panel inspector (signals, logs, connections), enabled via `Config::withTracing()`.
  `Config::withTracingWrites()` additionally allows editing signal values from the overlay
  (dev only). `Context::span()` records custom spans in the trace.

- **`Config::withEmbeddable()`**: relaxes framing/CORS headers so a php-via app can be
  embedded in a cross-origin iframe.

### Bug Fixes

- **Signals**: cast nested signal keys to string in `nestedToFlat()`, fixing type errors with
  numeric-keyed nested signal structures.

### Documentation & Website

- New composition API docs page, plus a live `CompositionDemo` + `VoteWidget` example.
- Migrated the Greeter, Todo, Wizard, and Theme Builder examples to the composition API
  (CounterExample stays on the closure API as the canonical reference).
- Homepage code viewer gained a Closure ↔ Composition toggle alongside the TAB/GLOBAL tabs.
- Added a Dev Bar guide page.

### Internal

- Extracted `castToType` from `Router` into a reusable `TypeCaster`.
- Tightened patch array type annotations for PHPStan.

## [0.9.0] - 2026-05-12

### New Features

- **`Via::notFound(callable $handler): self`**: register a custom handler invoked when no
  route matches. The handler receives the raw `OpenSwoole\Http\Request` and
  `OpenSwoole\Http\Response` and is responsible for setting the status code and ending the
  response. Falls back to the previous plain-text `404 Not Found` response when unset.

- **Twig `auto_reload`**: when a Twig file cache directory is configured via
  `Config::withTwigCacheDir()`, compiled templates now automatically recompile when the
  source file changes. Previously, cached templates were never invalidated during the same
  server process lifetime, causing stale output after template edits.

### Bug Fixes

- **`SwooleBroker`**: removed a dead `$handler` field that was never used.

### Documentation

- Website improvements: branded 404 error page, docs table-of-contents component, expanded
  deployment and scaling guides, spreadsheet example optimised from 162 to 805+ req/s (~5×)
  via partial block rendering and removing Twig from the SSE hot path.

## [0.8.0] - 2026-05-04

### Breaking Changes

- **`Config::withTrustProxy()` removed:** the dynamic per-request base-path detection
  mechanism (`detectBasePathFromRequest()`, `withTrustProxy()`, `getTrustProxy()`) has been
  removed entirely. The base path must now be known at startup and set via
  `Config::withBasePath(string $basePath)`, which throws `\InvalidArgumentException` for
  invalid values (absolute URLs, protocol-relative paths, backslashes, etc.).
  Migration: remove any `->withTrustProxy(true)` call; if your app is mounted at a sub-path,
  set it explicitly: `->withBasePath('/myapp')`.

### New Features

- **Multi-worker support:** php-via can now run with multiple OpenSwoole workers sharing a
  single port, enabling CPU parallelism on multi-core hosts.
  - `Config::withWorkerNum(int $n)`: set the number of worker processes (default 1). Requires
    a multi-worker-capable broker.
  - `Config::withGlobalStateTableSize(int $maxRows, int $maxValueBytes)`: tune the shared
    memory table used for `GlobalState` in multi-worker mode (defaults: 1024 rows × 4096 B).
  - **`SwooleBroker`:** new broker that uses OpenSwoole's inter-worker IPC pipe to fan-out
    scope invalidations across all worker processes on the same machine. No external
    infrastructure required. `InMemoryBroker` is rejected at startup when `worker_num > 1`.
  - **`SharedTable`:** wraps `OpenSwoole\Table` (shared memory, mmap'd into all workers on
    fork) as the `GlobalState` backend when `worker_num > 1`. Values are PHP-serialized;
    throws `\OverflowException` if a value exceeds the configured column size.
  - **Session-affinity dispatch:** when `worker_num > 1`, a custom `dispatch_func` routes
    every request from the same browser session (page load, SSE, action POSTs) to the same
    worker via `SessionManager::workerForRequest()`. No external load balancer required; all
    `Context` lookups and `sessionData()` calls always hit the correct process.

- **POOL_MODE + USR1 graceful worker reload:** the server now runs in `POOL_MODE`.
  Sending SIGUSR1 to the master process triggers graceful worker rotation without dropping
  active connections; the fresh worker re-includes route definitions, picking up new class
  definitions from disk. The master PID is written to `sys_get_temp_dir()/php-via-master.pid`
  in dev mode.

- **`composer run dev` / `scripts/dev.sh` hot-reload workflow:** an `entr`-based file
  watcher that sends SIGUSR1 on every `.php` or `.twig` change, plus an optional pnpm CSS
  watcher for the website. Run `composer run dev` from the project root; requires `entr`
  (`apt install entr` / `brew install entr`).

- **File upload + SharedWorker Upload demos:** two new website examples: a streaming
  file-upload progress example and a SharedWorker-based upload demo that maintains upload
  state across multiple tabs.

- **Auto-inject signals and actions into Twig:** `Context::render()` (and `$c->view()` with
  a template string) now automatically injects all registered signals and actions as named
  variables into every Twig render. No manual data-array passing required:
  - Signals are keyed by their user-supplied baseName (e.g. `$c->signal(0, 'count')` → `{{ count }}`)
  - Actions are keyed by the camelCase form of their registration name
    (e.g. `'refresh-graphs'` → `{{ refreshGraphs }}`)
  - Explicit entries in the `$data` array still win on conflict
  - Result is memoized after the first call; zero overhead on SSE ticks
  - A `_via` debug key is injected in dev mode listing all injected signal and action names

- **`Context::getSignal(string $name): ?Signal`:** retrieve a registered signal by its
  user-supplied name. Works for all scopes (TAB, ROUTE, SESSION, GLOBAL, custom).

- **`Context::getAction(string $name): ?Action`:** retrieve a registered action by its
  user-supplied name. Useful in action bodies that need sibling actions without captured vars.

### Security

- **Session ownership enforced on `/_action` and `/_sse`:** both handlers now verify that
  the caller's session cookie matches the session that originally created the context.
  A mismatch or absent cookie returns HTTP 403. Contexts with no session binding (e.g.
  `GLOBAL`-scoped pages) remain openly accessible.
- **CSRF hardening on `/_action`:** three weaknesses addressed:
  1. GET requests to `/_action/{id}` now return HTTP 405 (Method Not Allowed), preventing
     top-level cross-site navigation CSRF.
  2. When no `withTrustedOrigins()` allowlist is configured, the framework no longer allows
     all origins by default; it falls back to a same-host check derived from the `Host`
     header. Absent `Origin` is only permitted in dev mode (curl/local tools); in production
     it is denied.
  3. `website/app.php` now enables `withSecureCookie(true)` and `withTrustedOrigins()` in
     non-dev mode when `CORS_ORIGIN` is set to a concrete origin.

### Bug Fixes

- **Broadcast context count:** `logBroadcast` for `route:/path` scopes always logged `0`
  contexts (hardcoded). `syncContextsOnRoute()` now returns the actual count of synced
  contexts and it is passed to the logger.
- **Shutdown signal loop:** workers no longer call `$server->shutdown()` on SIGINT/SIGTERM,
  which was sending SIGTERM back to the master, causing a cascading loop that left orphaned
  processes holding the port. Workers now run cleanup callbacks and `exit(0)`. The
  master-process `onStart` handler is the sole driver of `$server->shutdown()`.
- **Website examples:** various fixes including chat-room typing indicator (hidden from the
  typing user; stale user list on disconnect), file-upload and login template variable
  alignment with signal baseNames, real IP extraction from proxy headers in `SseHandler`,
  and `data-ignore-morph` on the file-upload nav-confirm dialog.

### Refactoring

- All 15 website example files updated to use auto-inject: signal and action variables
  are no longer passed explicitly to `render()` / `view()` calls. Action closures now
  receive the `Context` as a parameter and call `$ctx->getSignal()` instead of capturing
  variables from the outer scope.

### Documentation

- **Twig docs:** rewrote "Passing data to templates" section; updated reactive-text,
  two-way binding, and action examples to use auto-injected `Signal`/`Action` objects
  (`{{ count.int }}`, `{{ inc.url }}`).
- **Views docs:** removed manual signal/action passing from "Defining a view" and
  "Partial updates" examples; simplified component view example.
- **Components docs:** removed manual `count_id`/`count_val`/`inc_url` passing from the
  "Creating a component" example.
- **API docs:** documented auto-inject behaviour on `view()`; added `getSignal()` and
  `getAction()` method entries; updated component example.

## [0.7.1] - 2026-04-22

### Bug Fixes

- **Signal native storage:** `Signal::setValue()` no longer pre-encodes arrays/objects as
  JSON strings. Values are stored as their native PHP type and serialized once by the Datastar
  SDK when building the SSE payload. Previously, arrays were double-encoded and arrived at the
  client as a JSON string instead of an array.
- **`Signal::string()` / `Signal::bool()`:** both methods now guard against array/object
  values to avoid PHP "Array to string conversion" notices.

### New API

- **`Signal::array(): array`:** convenience accessor that returns the signal value cast to
  a PHP array, consistent with the existing `int()`, `float()`, `string()`, and `bool()` casts.

### Dependencies

- Upgraded Datastar JS client to `v1.0.1` (`public/datastar.js`).
- Upgraded Datastar PHP SDK to v1 final; pinned `starfederation/datastar-php` to stable `^1.0` (resolved to `1.0.0`) in root and website lockfiles.
- Removed `src/Via.php` from PHPStan analysis (OpenSwoole stub gap for `Event::EVENT_READ`); suppressed false-positive `alwaysTrue` warning in `SseHandler`.
- CI: install `website/vendor` before running PHPStan.

## [0.7.0] - 2026-04-10

### New Features

- **Proactive GC timer:** `Via` now runs `gc_collect_cycles()` on a configurable periodic timer (default 30 s) to prevent PHP's cycle collector from causing unpredictable mid-request pauses as circular references accumulate in the long-running process.
  - `Config::withGcInterval(int $ms)`: set interval in milliseconds; pass `0` to disable and rely on PHP's automatic trigger
  - `Via::runGcCycle()`: the GC tick body, public so it can be called from tests or triggered manually
  - Each run logs at `debug` level: `GC: N cycles freed, mem=X MB peak=Y MB`
  - `Stats::getAll()` now includes `gc_runs` and `gc_cycles_freed` counters

- **`Via::setInterval(callable $callback, int $ms): void`:** Process-wide recurring timer. Started automatically when the server starts, cleared on shutdown. No manual `onStart`/`onShutdown` wiring needed.

- **`Via::group(string|callable $prefixOrFn, ?callable $fn): RouteGroup`:** Register a group of routes with an optional URL prefix and/or shared middleware.
  ```php
  // With prefix — routes declared with short paths, prefix prepended automatically
  $app->group('/admin', function (Via $app): void {
      $app->page('/', fn(Context $c) => ...);      // → /admin
      $app->page('/users', fn(Context $c) => ...); // → /admin/users
  })->middleware(new AuthMiddleware());

  // Middleware-only (no prefix)
  $app->group(function (Via $app): void {
      $app->page('/login/dashboard', fn(Context $c) => ...);
      $app->page('/login/profile', fn(Context $c) => ...);
  })->middleware(new AuthMiddleware());
  ```

- **MessageBroker: pluggable multi-node broadcasting:** `broadcast()` now propagates across workers/servers via a swappable broker. Ships with `InMemoryBroker` (default, single-node), `RedisBroker`, and `NatsBroker`.
  - Both brokers support auth (`$password`/`$authToken`, `#[\SensitiveParameter]`), TLS (`$tls`, `$tlsCaFile`), and a custom channel/subject param to isolate traffic
  - Auto-reconnect with exponential backoff (1 s base, 30 s cap); `Config::onBrokerError()` for observability
  - `Config::withBroker()` / `Config::getBroker()` for configuration
  - TAB scope skips broker publish (no cross-node recipients possible)

- **`GET /_health`:** JSON health endpoint: `{"status":"ok"|"degraded","broker":{…},"connections":{…}}`. HTTP 503 when broker is in reconnect backoff.

- **Scope injection protection:** broker wire scopes are validated via `Scope::isValidWireScope()` before `syncLocally()`; invalid scopes are logged and dropped.

## [0.6.0] - 2026-04-08

### New Features

- **Cookie helpers:** Safe, coroutine-friendly cookie access on `Context`:
  - `$c->cookie(string $name): ?string`: read a request cookie (replaces `$_COOKIE`, which is unsafe in OpenSwoole)
  - `$c->setCookie(string $name, string $value, ...)`: queue a cookie for the response; safe defaults: `secure: true`, `httpOnly: true`, `sameSite: 'Lax'`
  - `$c->deleteCookie(string $name, string $path = '/')`: expire a cookie (sets `expires=1`, empty value)
  - Queued cookies are flushed to the HTTP response by `RequestHandler` (page load) and `ActionHandler` (action). SSE streams seal their headers after the first write, so cookies must be set before or via an action response.

- **File upload support:** `$c->file(string $name): ?array` returns the upload array (`name`, `type`, `tmp_name`, `size`) for multipart form submissions, or `null` if missing or errored. Use Datastar's `contentType: 'form'` modifier to submit a `<form enctype="multipart/form-data">` as a real multipart POST.

- **Multipart signal parsing:** `Via::parseSignals()` (public static) handles three signal sources: GET `?datastar=<json>`, JSON body (standard actions), and `$post['datastar']` field (urlencoded forms). The existing `readSignals()` is now a thin wrapper. `via_ctx` fallback extracted from `$request->post` for multipart actions where Datastar sends no signals.

- **Brotli compression:** Native PHP brotli compression via `ext-brotli`, served over HTTPS/HTTP2 from OpenSwoole directly (no proxy required). Requires either `withCertificate()` or `withH2c()`.
  - `Config::withBrotli(bool $enabled, int $dynamicLevel = 4, int $staticLevel = 11)`: enable brotli with configurable levels. Dynamic level (4) is used for pages and SSE streams (hot path, low CPU). Static level (11 = max ratio) is used for static assets, lazy-compressed once and cached in memory per process.
  - `Config::withCertificate(string $certFile, string $keyFile)`: direct TLS termination in OpenSwoole. Enables HTTP/2 automatically.
  - `Config::withH2c(string $enabled)`: h2c (cleartext HTTP/2) for proxy scenarios where Caddy/Nginx handles TLS and proxies to OpenSwoole via h2c. Satisfies the brotli HTTPS requirement without needing a cert on the PHP side.
  - `BrotliMiddleware`: PSR-15 setWriter-style middleware. Attaches `brotli_write` and `brotli_finish` callables as PSR-7 request attributes before delegating. Implements `SseAwareMiddleware` so it also runs on SSE handshake requests. Auto-registered as the outermost global middleware when brotli is enabled.
  - Hard error at `start()` if ext-brotli is missing or HTTPS/h2c is not configured.

### Bug Fixes

- **`Application::scheduleContextCleanup()`:** Accepts an optional `$isActiveCheck` callable. When it returns true (active SSE count > 0), the timer reschedules itself instead of destroying the context, preventing a race where the cleanup timer fires while a new SSE connection is mid-handshake.
- **`Via::scheduleContextCleanup()`:** Passes `fn(): bool => ($this->activeSseCount[$contextId] ?? 0) > 0` as the guard, wiring the cleanup timer to the live SSE connection counter.
- **`Via` default server settings:** Added `'max_conn' => 10000` and `'backlog' => 4096` to prevent TCP accept-queue saturation under burst SSE load. Previously the OS default (~128–512) was exhausted at ~200 concurrent connections; tested clean to 2,000 after this change.
- **`SseHandler` brotli header ordering:** `Content-Encoding: br` header was set before the expired-context and `hasView()` early-return paths, corrupting raw SSE payloads written before `end()`. Headers are now set only after both early returns are cleared.
- **`SseHandler` expired-context reload deduplication:** A backgrounded tab that cannot execute `window.location.reload()` would reconnect indefinitely, generating log noise and redundant SSE writes. First reconnect from a dead context sends the reload; subsequent reconnects receive an immediate `response->end()`. Entries evicted after 5 minutes.
- **`SseHandler` `hasView()` race path:** The post-cleanup race reload event now routes through `$brotliWrite` when brotli is active, so the frame is properly encoded rather than written raw after the brotli header is set.
- **`SseHandler` `isWritable()` guard:** Added `$response->isWritable()` check before `response->write()` in the SSE keep-alive loop's context-destroyed path, preventing writes to already-closed connections.

### Improvements

- **SSE:** removed unnecessary 30-second keepalive comment (not needed with HTTP/2 or Caddy; was corrupting brotli streams).
- **Contact Form example:** Demonstrates multipart file upload, server-side per-field validation, and block re-rendering via SSE. State shared through PHP reference captures (no client-reactive signals needed).

### Tests

- **Unit tests now run by default:** `phpunit.xml` updated to include `tests/Unit/` in the default test suite. `vendor/bin/pest` now runs all 236 tests (Feature + Unit).
- **SignalFactory test semantics corrected:** Scoped signals intentionally do NOT update their value on re-registration (only the first registration uses `$initialValue`). This prevents a re-render or a second joining context from overwriting live shared state with a stale initial value. Test expectations updated to match; `setValue()` is the documented mutation path.

### Website

- **Presence component:** Debounced `onClientConnect`/`onClientDisconnect` broadcasts: rapid bursts (e.g. load tests) collapse into a single `Timer::after(200ms)` broadcast, preventing O(N²) render cascades. Scope widened to `Scope::GLOBAL` so the count reflects all connected users across all routes.
- **GameOfLifeExample:** `clientCount` now uses `getContextsByScope(Scope::routeScope('/examples/game-of-life'))` instead of global `getClients()`.
- **SpreadsheetExample:** `clientCount` now uses `getContextsByScope(self::SCOPE)` (`'example:spreadsheet'`) instead of global `getClients()`.

## [0.5.0] - 2026-03-25

### New Features

- **PSR-15 Middleware:** Full middleware support with global and per-route registration. Global middleware runs on all page/action requests via `$app->middleware()`. Per-route middleware via `$app->page('/admin', ...)->middleware(new AuthMiddleware())`. Implements the onion model with zero overhead when no middleware is registered. OpenSwoole requests are converted to PSR-7 at the boundary and back.
  - `SseAwareMiddleware` marker interface for middleware that should also run on SSE handshake requests
  - `MiddlewareDispatcher`: onion-style PSR-15 pipeline executor
  - `PsrRequestFactory` / `PsrResponseEmitter`: OpenSwoole ↔ PSR-7 adapters
  - `RouteDefinition`: fluent API for route + handler + middleware
  - Middleware attributes bridged to `Context::getRequestAttribute()` / `Context::getRequestAttributes()`
- **Per-session data storage:** `Context::sessionData()`, `setSessionData()`, `clearSessionData()` for server-side per-session state keyed on session cookie. Survives page refreshes and context destruction (unlike signals).
- **`Context::input(string $name, mixed $default)`:** Safe replacement for `$_GET`/`$_POST` access. Checks POST first, then query string. Coroutine-safe in OpenSwoole (superglobals are not).
- **CSRF protection:** `ActionHandler` validates the `Origin` header against a configurable allowlist (`Config::withTrustedOrigins()`). `null` = no restriction (dev), `[]` = block all, `['https://...']` = strict allowlist.
- **Secure session cookies:** `Config::withSecureCookie(true)` enables `__Host-` cookie prefix (enforces HTTPS, `Path=/`, no `Domain`), `SameSite=Lax`.
- **Action rate limiting:** `Config::withActionRateLimit(int $max, int $window)` enables per-IP sliding-window rate limiting on action endpoints (returns 429 + `Retry-After`).
- **Proxy trust:** `Config::withTrustProxy(bool)` gates `X-Base-Path` header processing behind an explicit opt-in.
- **NATS Visualizer example:** JetStream, durable consumers, KV heartbeats with OpenSwoole-native NatsClient.
- **Login Flow example:** Now demonstrates PSR-15 `AuthMiddleware` with a public login form and a middleware-protected dashboard route. Auth data flows via request attributes.
- **Middleware docs page:** Full documentation covering global/per-route middleware, writing middleware, SSE-aware middleware, request attributes, and built-in security features.

### Security

- **Superglobal elimination:** Removed all `$_GET`/`$_POST`/`$_FILES`/`$_SESSION` writes from `RequestHandler`. Migrated 7 example files (12 call sites) from superglobals to `Context::input()` / `Context::sessionData()`.
- **XSS fix:** `dump()` Twig function output is now HTML-escaped (`htmlspecialchars`). Previously marked `is_safe => ['html']` without escaping.
- **`/_stats` endpoint:** Now gated behind `devMode`. Previously exposed client IPs and memory usage to unauthenticated requests.

### Improvements

- **Shopping Cart:** Migrated cart storage to `sessionData` API.
- **Wizard example:** Wizard state persists across page refreshes via `sessionData`.
- Updated API docs with middleware, sessionData, input(), and security Config options.
- Updated comparisons table: Auth/middleware marked as ✓ (was "coming soon").

### Dependencies

- Added `psr/http-server-middleware` ^1.0 and `nyholm/psr7` ^1.8
- Added `tuupola/cors-middleware` ^1.5 (website project)

### Chore

- Added `.gitattributes` to exclude dev directories from Composer distribution.
- Removed legacy `examples-source/` folder and stale `via:cut` template markers.

## [0.4.3] - 2026-03-23

### New Features

- **`Context::removeScope(string $scope)`:** Remove a scope from a live context so it no longer receives broadcasts targeting that scope. TAB scope is protected. Backed by new `Via::unregisterContextInScope()`.
- **Live Search example:** Instant client-side filtering with debounced signal updates. Demonstrates TAB-scoped input signals and conditional rendering.
- **Shopping Cart example:** Multi-item cart with quantity controls, subtotals, and a running total. Demonstrates multiple TAB-scoped signals and computed view state.
- **Theme Builder example:** Live colour/font customiser with full undo/redo history. Demonstrates TAB-scoped signal stacks and action composition.
- **Multi-step Wizard example:** Guided form with step validation, progress indicator, and review step. Demonstrates TAB-scoped step state and conditional block rendering.
- **Live Auction example:** Real-time shared auction with countdown clock, anti-snipe bid extension, bid history, and sold state. Demonstrates ROUTE scope + timer broadcasting with `cacheUpdates: false`.
- **Type Race example:** Multiplayer typing race with custom per-room scope, live progress bars, WPM tracking, 3-second countdown, and "Race Again" that resets in-place and absorbs lone waiters from other rooms via live scope migration (`removeScope` / `addScope`).

### Improvements

- Chat Room messages persisted to SQLite (last 50 per room).

## [0.4.2] - 2026-03-19

### Bug Fixes

- **SSE reconnect race: UI hangs with HTTP 400 after a few minutes:** When a
  client's SSE connection drops and immediately reconnects, two coroutines
  briefly overlap. The old coroutine's exit path unconditionally called
  `scheduleContextCleanup()`, firing a 5 s timer that destroyed the still-live
  context. The new SSE loop then polled a closed channel indefinitely, and every
  subsequent action returned 400. Fixed by tracking active SSE coroutine count
  per context (`Via::$activeSseCount`) and only scheduling cleanup when the last
  coroutine exits. A safety-valve reload is also sent if the context is found
  destroyed mid-loop.

### Improvements

- **Debuggable TAB-scoped action IDs:** TAB-scoped actions now prefix their
  random hex ID with the action name when one is provided (e.g.
  `resize-3df6c542507ab8e1` instead of `3df6c542507ab8e1`), making request logs
  readable without affecting uniqueness or security.

## [0.4.1] - 2026-03-19

### Bug Fixes

- **Timer leak via `Context::interval()`:** `interval()` called `Timer::tick()` directly, bypassing
  `ContextLifecycle`. Timers were never recorded and therefore never cancelled on context cleanup.
  After extended uptime, leaked timers accumulated
  and eventually drove the process to 100% CPU. Fixed by delegating to `lifecycle->registerTimer()`
  so every timer is tracked and cleared on cleanup, consistent with `setInterval()`.

- **Callback accumulation in `scheduleContextCleanup()`:** the method was called on every SSE
  disconnect, including reconnections, and each call unconditionally appended a new closure to
  `ContextLifecycle::$cleanupCallbacks`. A tab reconnecting N times accumulated N closures, growing
  without bound for the context's lifetime and contributing to memory pressure and GC load under
  prolonged uptime. Fixed by tracking registration state in `$viaUnsetCallbackRegistered` and
  skipping duplicate registrations.

## [0.4.0] - 2026-03-18

### Features

- **Auto-block view rendering:** `view()` now accepts a `block:` named parameter. On SSE updates the
  named Twig block is extracted and sent instead of the full page, eliminating the need to manually
  thread `$isUpdate` through view callables.
  ```php
  // Before
  $c->view(fn (bool $isUpdate) => $c->render('todo.html.twig', $data, $isUpdate ? 'demo' : null));

  // After
  $c->view(fn () => $c->render('todo.html.twig', $data), block: 'demo');
  ```
  The block content must have a root element with a unique `id`; Datastar uses it to find the morph
  target in the DOM.

- **`clientWritable` flag for scoped signals:** Scoped signals (ROUTE / SESSION / GLOBAL / custom)
  are now **server-authoritative** by default: client-sent values are silently ignored, preventing
  arbitrary clients from overwriting shared state. Pass `clientWritable: true` to opt a scoped signal
  in to client writes:
  ```php
  // Server-authoritative (default) — client cannot overwrite
  $counter = $c->signal(0, 'count', Scope::ROUTE);

  // Collaborative — client may push values (e.g. data-bind on a shared input)
  $note = $c->signal('', 'note', Scope::ROUTE, clientWritable: true);
  ```
  TAB-scoped signals (the default) are always client-writable and unaffected by this change.

### Bug Fixes

- Fixed examples (GameOfLife, ChatRoom, ClientMonitor) that were sending full-page HTML patches
  instead of only the dynamic block. All three now use `block: 'demo'` and emit partial patches.

### Website

- Applied `block:` to all examples with a named update block (GameOfLife, ChatRoom, ClientMonitor,
  Todo, Components)
- Added **Signal Injection** and **Block Rendering** test suites (17 new tests)
- Updated docs: views, FAQ, and API reference reflect `block:` convention and `clientWritable` flag

## [0.3.0] - 2026-03-17

### Features
- **App-level hooks:** `onClientConnect()` / `onClientDisconnect()` callbacks fire when SSE connections open or close
- **Per-context overrides:** custom shell template, head/foot HTML per page via `Context` API
- **Static file serving:** built-in for development; serve CSS/JS/images without a reverse proxy
- **TUI request logger:** colorful structured terminal output with method/status/timing glyphs
- **Component re-rendering:** components participate in broadcast sync; dirty components re-render on page-level broadcasts
- **Performance:** skip re-rendering clean components during page sync, reducing unnecessary SSE patches

### Bug Fixes
- fix: `Coroutine::sleep()` TypeError on OpenSwoole: use `usleep()` with `SWOOLE_HOOK_ALL`
- fix: broken signal approach in live-poll replaced with `patchElements`
- fix: component re-rendering wired into broadcast sync correctly
- fix: shell template path co-located with `HtmlBuilder` (no more `../../templates/` relative path)

### Website
- **Consolidated examples:** 11 standalone example apps merged into the website's single Via server under `/examples/{name}`
- **Tabbed source panel:** each example shows PHP handler and Twig template in switchable tabs (CSS-only, no JS)
- **Example summaries:** 3–6 paragraph descriptions per example explaining the concepts demonstrated
- **Examples-source accuracy:** all source display files updated to match actual handler logic (board model, scope prefixes, signal names, template variables)
- **Client Monitor revamp:** replaced timer-driven polling with `onClientConnect`/`onClientDisconnect` hooks
- **Removed Global Notifications** example (concepts merged into All Scopes)
- **All Scopes redesign:** CSS class-based cards replacing inline styles, reduced emoji usage
- **Docs section:** FAQ entries for `PatchElementsNoTargetsFound` and duplicate ID collision pitfalls; design philosophy page; comparison table with Phoenix LiveView column
- **Home page overhaul:** tabbed code demos, glass UI, scope badges, animations
- **Twig `{% code %}` tag:** syntax highlighting via `mbolli/tempest-highlight-datastar` package

## [0.2.0] - 2026-03-12

### Dependencies
- Migrated from `Swoole` to `OpenSwoole` extension
- Updated bundled `datastar.js` from RC.7 to RC.8

### SSE Improvements
- **Reduced poll overhead:** idle SSE connections yield the worker coroutine via `usleep()` (hooked by `SWOOLE_HOOK_ALL`) rather than blocking the process
  - Automatic cleanup of zombie contexts on disconnect
  - Removed unused `$pollTimeout` from `PatchManager`

### Features
- feat: graceful shutdown; timers and open contexts cleaned up on SIGTERM/SIGINT; `Via::onShutdown()` callback hook added
- **Crash logging:** diagnostics captured when a worker dies
  - `register_shutdown_function` catches PHP fatals (OOM, stack overflow, compile errors) in each worker
  - `set_exception_handler` catches uncaught exceptions that escape all coroutines
  - `workerError` event logs abnormal worker exits including OS signal number
  - `Logger::fatal()`: always emits regardless of log level; includes timestamp and current/peak memory
- feat: page handler exceptions caught and logged with full stack trace, return 500 instead of crashing the worker
- feat: move `datastar.js` and `via.css` to `public/` for direct serving by reverse proxies

### Bug Fixes
- fix: `Coroutine::sleep()` TypeError on OpenSwoole: replaced with `usleep()` and enabled `SWOOLE_HOOK_ALL` so OpenSwoole yields the coroutine non-blocking; fixes worker crashes on idle SSE connections
- fix: `detectBasePathFromRequest()` no longer locks basePath to `/` when a direct hit (health check, systemd probe) arrives before Caddy's first proxied request; lock only triggers when `X-Base-Path` header is present
- fix: `HtmlBuilder` throws `RuntimeException` instead of silently calling `str_replace` on `false` when shell template cannot be read
- fix: incorrect `?: []` fallbacks on `array_keys`/`array_values` in shell template processing

### Production Deployment
- **systemd template unit** (`deploy/via@.service`): one service instance per example
  - Each instance independently managed and restarted by systemd
  - Memory capped at 128 MB per process; crash marker written to journal on abnormal exit
  - `StartLimitBurst=5` / `StartLimitIntervalSec=120` in `[Unit]` prevents restart storms
- feat: `deploy/via.target` groups all instances for unified start/stop/status
- **Caddy config** (`deploy/examples.caddy`)
  - Static assets served from disk via `file_server` with path `rewrite` (fixes 404 for `/gameoflife/datastar.js` etc.)
  - `X-Base-Path` header injected per subpath for correct internal URL generation

### Examples
- gameoflife: post iframe height to parent via `postMessage` + `ResizeObserver` for auto-resize when embedded

## [0.1.0] - 2025-12-21

Initial pre-release. API is not yet stable and may change in future versions.

### Core Features
- Via application class with Swoole HTTP server
- Context management for page state
- Reactive signals for state synchronization
- Action triggers for server-side event handling
- SSE (Server-Sent Events) support
- HTML composition helpers
- Component system for reusable UI
- Twig template integration

### Routing & Parameters
- **Automatic path parameter injection** - Route parameters automatically injected into callable parameters
  - Parameters matched by name from function signature: `function($c, string $username)`
  - No need to call `$c->getPathParam()` - params are automatically populated
  - Works with multiple parameters in any order
  - Supports default values and nullable parameters
  - Backward compatible: `$c->getPathParam()` still works
  - 7 comprehensive tests verifying injection behavior

- **Path parameters support** - Dynamic route parameters inspired by go-via v0.1.4
  - Route patterns support `{param_name}` syntax (e.g., `/users/{id}`)
  - `Context::getPathParam(string $name)` - Retrieve parameter values from URL
  - Multiple parameters in single route (e.g., `/blog/{year}/{month}/{slug}`)
  - Mix of parameters and static segments (e.g., `/products/{id}/reviews`)
  - Example: `examples/path_params.php` - Comprehensive demonstration

### Scope System & Caching
- **Global scope** - App-wide state shared across all routes
  - `Via::globalState()` / `Via::setGlobalState()` - Get/set global state values
  - `Via::broadcast(Scope::GLOBAL)` - Broadcast to all contexts across all routes
  - `Context::action($fn, $name, Scope::GLOBAL)` - Create actions with global scope
  - `Context::scope(Scope::GLOBAL)` - Set context to global scope
  - Global view cache - Single render cached app-wide (maximum performance)
  - Automatic detection: uses only global-scoped actions = Global scope
  - 15 comprehensive tests covering all scope scenarios
  - Example: `examples/global_notifications.php` - notification system across all pages

- **Automatic scope detection and caching** - Framework automatically detects whether a page uses global, route, or tab scope
  - Global scope: Pages using only global-scoped actions are cached app-wide
  - Route scope: Pages using only route-scoped actions are cached per-route (one render for all users on same route)
  - Tab scope: Pages with TAB-scoped signals/actions render fresh for each context (per-user state)
  - Scope detection is automatic based on signal/action scope patterns
  - 79 comprehensive tests covering all features
  - See `src/Scope.php` for scope constants and helpers

### Real-time Features
- **`Context::setInterval()` method** - Execute functions periodically using Swoole timers
  - Takes callback and milliseconds interval
  - Returns timer ID for potential cleanup
  - Automatically cleaned up when context is destroyed
  - Example: `$c->setInterval(fn() => $c->sync(), 200)`

- **Action handler** - Supports both GET and POST parameters
  - Actions can receive data via `$_GET` or `$_POST`
  - Enables flexible action invocation patterns

### Examples
- `counter_basic.php` - Simple counter
- `counter.php` - Counter with step control  
- `greeter.php` - Form handling
- `components.php` - Component composition
- `todo.php` - Todo list with local state
- `path_params.php` - Path parameter demonstration with automatic injection
- `global_notifications.php` - Global state and broadcasting across all routes
- `chat_room.php` - Multi-room chat with custom scopes
- `stock_ticker.php` - Real-time stock data with scoped state
- `client_monitor.php` - Monitor connected clients and contexts
- `profile_demo.php` - Interactive profile with intervals
- `all_scopes.php` - Demonstrates TAB, ROUTE, SESSION, and GLOBAL scopes
- `game_of_life.php` - Multiplayer Conway's Game of Life
  - Shows automatic Route scope detection and caching
  - Multiple users can draw simultaneously with different colors
  - Real-time synchronization across all connected clients

### Configuration & Infrastructure
- **BasePath support** - Serve applications under subpaths (e.g., `/myapp`)
  - `Config::withBasePath()` - Configure application base path
  - Automatic detection from request headers
  - Consistent resource loading across examples and templates
  - Navigation link updates for subpath deployment

- **onStart() callbacks** - Execute code when server starts
  - `Via::onStart()` - Register callbacks to run on server start
  - Useful for initialization, logging, or setup tasks

- **Swoole settings** - Configure Swoole HTTP server
  - `Config::withSwooleSettings()` and `getSwooleSettings()`
  - Customize server behavior and performance

- **HEAD method support** - Handle HEAD requests properly

### Template System
- **Twig @via namespace** - Register `@via` namespace for built-in templates
- **View caching** - Cache rendered templates for better performance
- **Shell template support** - Embed content in shell templates
- **Route-scoped signal option** - Create signals scoped to specific routes

### Resource Management
- **Cleanup callbacks** - Register cleanup functions on SSE disconnect
  - `Context::onCleanup()` / `Context::onDisconnect()` - Register callbacks for cleanup
  - `Context::setInterval()` - Timers are automatically cleaned up
  - Automatic cleanup of timers and resources when context is destroyed
- **Memory management** - Enhanced patch channel handling
  - Drop oldest patches when channel is full
  - Prevent memory leaks with proper resource cleanup

### Examples & Deployment
- `start-all.sh` - Script to run all examples simultaneously
- `examples/index.php` - Overview page for all examples
- Caddy configurations for production deployment
- Service files for systemd integration

### Development Tools
- Composer scripts:
  - `composer phpstan` - Run PHPStan static analysis
  - `composer cs-fix` - Run PHP-CS-Fixer code formatter
- Comprehensive test suite with Pest (79 tests, 230+ assertions)
- PHPStan level 6 compliance
