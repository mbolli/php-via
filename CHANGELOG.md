# Changelog

All notable changes to php-via will be documented in this file.

## [0.14.0] - Unreleased

### Highlights

- **Datastar 1.0.4.** php-via serves Datastar 1.0.4 instead of 1.0.1. The SSE format is the same,
  but Datastar now cancels an in-flight request when any element sends another one to the same URL,
  so check pages where two elements post the same action. Breaking Changes lists the rest.
- **Web components.** `Config::withDatastarRocket()` serves Datastar with Rocket, so Rocket
  components such as Starbase's run on php-via pages, and `Config::withImportMap()` pins them with
  integrity hashes. See [Web components](https://via.zweiundeins.gmbh/docs/web-components).
- **A versioned Datastar URL.** The default shell loads `/datastar.js?v=<hash>`, so browsers fetch
  the new bundle after an upgrade instead of reusing the cached one.

### Breaking Changes

- **Datastar 1.0.1 to 1.0.4.** PHP code needs no changes, and `starfederation/datastar-php` 1.0.1
  keeps working. In the browser:
  - **Requests cancel per method and URL,** from any element, where 1.0.1 cancelled per element. Give
    two elements that post the same action a query each (`?from=button`). See [Actions](https://via.zweiundeins.gmbh/docs/actions#cancellation).
  - **Retries** send the current signals and stop after 10 attempts. With `retry: 'error'` or
    `'always'`, HTTP errors count toward them. See [Lifecycle](https://via.zweiundeins.gmbh/docs/lifecycle#reconnect).
  - **The `retrying` fetch event** fires only when a retry is scheduled and carries no `message`.
    Datastar no longer logs each retry with `console.error`.
  - **`data-bind` on checkboxes and radios** updates the signal on `input` instead of `change`. A
    script that dispatches `change` has to dispatch `input`, or bind with `__event.change`.
  - **Deleting a signal,** by a patch or by assigning `null`, fires `data-on-signal-patch`.
- **`Signal::bind()` and `Config::getStaticCacheControl()` take a new optional parameter.** A
  subclass that overrides either has to declare it.

### New Features

- **`Config::withDatastarRocket()`** serves Starbase's build of Datastar 1.0.4 with Rocket at
  `/datastar.js` (22 KB with Brotli, against 12 KB), and the default shell adds the import map Rocket
  components need. Unlike the official Rocket bundle, the build survives morphs that reorder keyed
  components. Sources, patches and hashes are in `public/DATASTAR.md`.
- **`Config::withImportMap()`** adds modules and integrity hashes to the import map php-via writes,
  so Starbase components load pinned from its catalog. `Config::getDatastarIntegrity()` returns the
  served bundle's hash for apps that pin Datastar too. Custom shells get the map as
  `{{ import_map }}`, Twig layouts as `importMap`. See
  [Web components](https://via.zweiundeins.gmbh/docs/web-components#own-modules).
- **Versioned Datastar URL.** `Config::getDatastarUrl()` returns `/datastar.js?v=<content hash>`,
  cached for a year by default. Load Datastar from it in custom shells (`{{ datastar_url }}`) and Twig
  layouts (`datastarUrl`): an unversioned `datastar.js` stays cached for up to an hour after an
  upgrade. See [Web components](https://via.zweiundeins.gmbh/docs/web-components#own-shell).
- **`Signal::bind('value')`** binds an element property (`data-bind__prop.value`), the form to use
  on web components. The Twig `bind()` function takes the property as a second argument.
- **`.mjs` files** from `withStaticDir()` are served as `application/javascript`, and `.map` files
  as `application/json`.

### Fixed

- `.json` files from `withStaticDir()` were served as `application/octet-stream`, which breaks JSON
  module imports. They are now `application/json`.

### Docs

- New [Web components](https://via.zweiundeins.gmbh/docs/web-components) page.

## [0.13.1] - 2026-10-02

### Highlights

- **Page views no longer leak memory.** A page whose handler called `$c->scope()`, which most do,
  stayed registered for the life of the worker together with its components and its response's
  Brotli encoder: about 0.6 MB per page view with Brotli on. A page view that never opens its stream
  now holds 13 to 35 KB until the connect timeout. In a 5 s burst on the website, 0.13.0 grew by
  2.4 GB and kept it; 0.13.1 grows by 147 MB and reuses it.
- **An open tab costs less.** A tab no longer keeps its page response's Brotli encoder: a docs tab
  went from 2.8 MB to 574 KB, a home page tab from 1.3 MB to 642 KB. Most of what remains is the
  live stream's encoder; [Performance](https://via.zweiundeins.gmbh/docs/performance#tab-memory)
  shows the trade against the Brotli level.
- **Multi-worker pages keep working when the context directory is full.** Every page view answered
  500 once about 8,000 contexts had been created within the revival window.

### Fixed

- Page contexts that called `$c->scope()` were never freed. The registry now remembers every scope a
  context joined, and teardown removes it from all of them.
- Components that joined a scope stayed registered after their page was destroyed and kept receiving
  broadcasts. Their `onDisconnect` and `onCleanup` callbacks now run and their timers stop when the
  page is destroyed; they never ran before. The scope's signals and actions stay for other pages.
- A response's Brotli encoder lived as long as its tab. It is now created on the first write and
  released when the response ends.
- Both maps of context sessions grew by one entry per page view.
- A revival that arrived while a destroyed context's cleanup callbacks ran could be torn down with
  it. Teardown now removes only entries that still belong to the destroyed context.
- A revival whose page handler threw left the half-built context in its scopes, with its timers
  running.
- After a revival, components lost their DOM wrappers because component IDs were random. A
  component created with a name (`$c->component($fn, 'name')`) now gets the same ID every time its
  page is built.
- A stream with nothing to send on connect never cleared the reconnect banner after a dropped
  connection, and sent no headers until its first keep-alive. Every connect now sends
  `_disconnected: false`.
- With `worker_num > 1`, a full context directory turned every page view into a 500. New contexts
  now get no row, a warning is logged at most every 10 s per worker, and those tabs' actions on other
  workers answer 400. With the revival window at 0, no rows are written, and the sweep of expired
  rows runs at most once a second. See
  [Deployment](https://via.zweiundeins.gmbh/docs/deployment#same-machine) for sizing.
- A broadcast that reached a context through another scope, a wildcard or `Scope::GLOBAL`, or a
  component re-rendered with its page, served the update cached at an earlier broadcast, and a render
  that started before a broadcast could store its older result over the newer one. Each scope still
  renders once per flush.
- Two revivals of one tab at the same moment (an action and the stream reconnect) both registered,
  leaving one copy running with its timers. The later one now returns the registered context.
- A component's actions could not read the request or set cookies. `input()`, `file()`,
  `cookie()` and `setCookie()` on a component now use its page's request.
- A tab whose stream was down when an action reached it was freed after the connect timeout (30 s),
  while Datastar's next reconnect attempt can be 30 s away, and the patches the action queued went
  with it. It now waits for the new reconnect timeout, 60 s after the last action
  (`withContextReconnectTimeout()`). [Lifecycle](https://via.zweiundeins.gmbh/docs/lifecycle#without-stream)
  lists which timer frees a context without a stream.
- The default shell never showed its "Not connected" warning: it listened with
  `data-on-datastar-fetch`, which Datastar 1.0 ignores. It now uses `data-on:datastar-fetch`.

### Known limitations

- An action a component registers in a scope of its own is not found. Register it with
  `Scope::TAB` and broadcast with `$app->broadcast($scope)`, as
  [Components](https://via.zweiundeins.gmbh/docs/components#scoped-components) shows.
- The update cache is keyed by scope alone, so two different views with caching on in one scope
  receive each other's HTML. See [Views](https://via.zweiundeins.gmbh/docs/views#caching).
- Signals and actions of a scope stay after its last context leaves, as they did in 0.13.0, so
  per-entity scopes such as `room:<id>` accumulate for the life of the worker. A scoped action
  should use the `Context` it receives rather than a captured `$c`, which it would keep alive.

### Docs

- New [Performance](https://via.zweiundeins.gmbh/docs/performance) page, with the harness in
  `bench/capacity`.

## [0.13.0] - 2026-10-02

### Highlights

- **Multi-worker mode works.** With `worker_num > 1`, scoped signals, session data, the client
  list and the context directory are shared between workers, and an action reaches its tab on any
  worker. In 0.12.0 an action that landed on another worker answered 400.
- **Broadcasts coalesce.** A worker renders each broadcast scope at most once per tick (25 ms by
  default), so the cost no longer grows with the number of actions. In the benchmark, a storm of
  500 actions on 5,000 clients reached every client in 120 ms instead of 18 s, with 124 times less
  worker CPU than broadcasting on every call.
- **Race-free shared state.** `Signal::increment()` and `mutate()`, `Via::incrementGlobalState()`
  and `mutateGlobalState()`, and `#[Signal(atomic: true)]` update shared values without losing
  writes between workers. `Config::withPersistentGlobalState()` keeps GlobalState in SQLite across
  restarts.
- **Closed tabs are noticed.** A stream ends about 1 ms after the browser closes it, so
  `onClientDisconnect` and the cleanup hooks run for idle tabs too, and a 15 s keep-alive comment
  keeps idle streams open behind proxies.
- **Fewer ways to lose a worker or leak memory.** A throw in an action, view or timer no longer
  kills the worker, `onShutdown` callbacks run on stop, and a context whose SSE stream never
  connects is freed after 30 s.
- **Before you upgrade:** php-via needs ext-openswoole 26, `broadcast()` inside a coroutine now
  returns before the fan-out, most TAB and component signal ids change, and action POSTs without
  an `Origin` header get 403 when `withTrustedOrigins()` is set. With `worker_num > 1`, deploy with
  a full restart. Breaking Changes below has the details.

### Breaking Changes

- **ext-openswoole 26 is required.** The `ext-openswoole` constraint is now `^26.0` instead of `*`.
  Upgrade the extension (`pecl install openswoole-26.2.0`) before running `composer update`. No code
  changes are needed.
- **`broadcast()` inside a coroutine returns before the fan-out.** Broadcasts, scoped signal writes
  and broker messages mark the scope, and the worker renders each marked scope once per flush, at
  most once per tick (`Config::withBroadcastTickMs()`, default 25 ms). Views render the state at
  flush time, patches the action queues itself (`execScript()`, `sync()`) reach the tab before the
  broadcast frame, and fan-out errors are logged instead of failing the action.
  `Via::flushBroadcasts()` forces the flush, and `Config::withBroadcastCoalescing(false)` restores
  synchronous broadcasts. See [Broadcasting](https://via.zweiundeins.gmbh/docs/broadcasting#timing).
- **Closed idle tabs are detected.** `onClientDisconnect`, the removal from `getClients()` and the
  cleanup hooks now run for idle tabs, and their contexts are freed. Idle streams write a
  `: keep-alive` comment after 15 s (`Config::withSseKeepAliveMs()`), and `withSsePollIntervalMs()`
  only paces the Dev Bar stream. See [Lifecycle](https://via.zweiundeins.gmbh/docs/lifecycle#disconnect-detection).
- **Signal ids are unique.** Names that differ only in punctuation no longer share an id. Readable
  scoped ids such as `global_count` stay; TAB and component ids gain a `____` suffix. Templates that
  use `$signal->id()`, `bind()` or `text()` need no change, hardcoded ids break, and
  `Via::getScopedSignalByName()` finds a scoped signal outside a context. Tabs left open across the
  deploy lose their values on revival, and with `worker_num > 1` the deploy needs a full restart,
  not `SIGUSR1`. See [signal()](https://via.zweiundeins.gmbh/docs/api#context-signal).
- **`Via::setInterval()` runs on one worker.** Pass `everyWorker: true` for a timer in every process.
- **Requests without an `Origin` header are denied outside dev mode** for actions when
  `withTrustedOrigins()` is set, and always for the Dev Bar endpoints and `POST /_session/close`.
  Non-browser clients need `Config::withAllowMissingOrigin()`.
- **`clientWritable: false` is enforced on TAB signals,** and component TAB signals take client
  values like page TAB signals. The parameter type widens from `bool` to `?bool`.
- **With `worker_num > 1`, session data values must be serializable** and come back as copies.
  GlobalState keys longer than 63 characters throw `\InvalidArgumentException` whenever the shared
  table is used (`worker_num > 1` or `withPersistentGlobalState()`).
- **Shell placeholders use the name passed to `signal()`** (`{{ graph_display }}`, not `{{ graph }}`)
  and are HTML-escaped JSON. Full-document views now get `appendToHead()`/`appendToFoot()` content
  and the signal seed.

### New Features

- **Shared state across workers:** scoped signals, session data, the client list and a context
  directory live in shared memory. `withScopedSignalTableSize()`, `withSessionTableSize()` and
  `withContextDirectorySize()` size them. See [Deployment](https://via.zweiundeins.gmbh/docs/deployment#same-machine).
- **Atomic updates:** `Signal::increment()`, `Signal::mutate()`, `Via::incrementGlobalState()`,
  `Via::mutateGlobalState()` and `#[Signal(atomic: true)]`. See [Signal](https://via.zweiundeins.gmbh/docs/api#signal).
- **`Config::withPersistentGlobalState()`** keeps GlobalState in SQLite across restarts.
- **`Config::withContextConnectTimeout()`** (default 30 s) frees a context whose SSE stream never
  connects.
- **`Config::withSseMaxQueuedBytes()`** (default 1 MB) drops element frames for a slow client
  instead of blocking its stream.
- **`Config::withStrictTabSignals()`**, and `clientWritable` for every scope.
- **`Stats::getBroadcastStats()`** reports flushes, coalesced broadcasts and flush times.

### Performance

- **A broadcast storm renders each scope once per flush,** so its cost no longer grows with the
  action rate, and the broker gets one publish per scope per flush. Measurements are in
  `bench/contention/RESULTS.md`.
- **`getClients()` returns the stored list** instead of copying it on every call.

### Fixed

- Multi-worker mode did not work: an invalid `dispatch_mode`, a dispatch function that crashed on
  PHP 8.4, broker node ids shared between workers, and `$server->worker_num`, which OpenSwoole 26
  does not have.
- With `worker_num > 1`, session data set in one request could be missing in the next.
- A context whose SSE stream never connected stayed in memory until the worker stopped.
- A context revived by an action without signals reset the tab's values on its next connect.
- A throw in an action, a view or a timer killed the worker. Actions now answer 500.
- `onShutdown` callbacks never ran when the server stopped. The default `max_wait_time` is now 3 s.
- A signal patch dropped from a full queue was never resent.
- Two broadcasts could interleave mid-fan-out, and a tab could keep an older frame than the newest.
- Signals declared with `Scope::ROUTE` emitted no patches, and a component without signals froze
  on its first render.
- Components rendered in a page handler reset on every page re-render; the docs now render them
  inside the view.
- `withActionRateLimit()` counted per worker.
- `log('warning')` was filtered as info.
- A full GlobalState table threw a bare OpenSwoole error, and values were capped at 4 KB (now 32 KB).
- A `#[Signal]` an action wrote directly was overwritten when the action ended.
- `clientWritable: false` was ignored on TAB signals, component signals never received client
  values, and JSON object values were not injected.
- A view that renders a full HTML document lacked `via_ctx`, the signal seed and the head/foot
  includes.
- An `Origin` allowlist let requests without `Origin` through, and `POST /_session/close` had no
  Origin check.
- A shared scope that silently disabled the update cache, and a TAB action name registered twice,
  now log a warning.

### Dependencies

- `openswoole/core` and `openswoole/ide-helper` from `^22` to `^26`, with no source changes.
  Development: Pest 5, and `rector/type-perfect` removed.

## [0.12.0] - 2026-07-08

### New Features

- **Context revival**: a tab backgrounded long enough that its context is destroyed now rebuilds an
  equivalent context on reconnect (same context ID, so the already-loaded DOM keeps working) and
  re-seeds signal values the client still holds, **instead of hard-reloading the page**. This
  preserves local (`_`-prefixed) signals, scroll position, and focus that a reload would wipe. It is
  on by default (10-minute window) and needs no app code; tune or disable it with
  `Config::withContextRevivalWindow()` (`0` = fall back to the previous reload behavior). Revival
  re-runs the page handler, so, exactly as on a reload, server-only state (`#[Persist]`) resets and
  `onDisconnect`/connect hooks re-fire. Named components survive; anonymous components (no explicit
  name) reset. As part of this, TAB-scoped action IDs are now deterministic (previously random per
  registration) so a revived context's action URLs match the ones already in the DOM.
- **`Config::withContextCleanupDelay()`**: configures the grace period (default: 5 seconds) before
  an inactive context (one whose SSE connection has closed) is destroyed, allowing time for page
  navigation or a brief reconnect. Previously hardcoded; mirrors the existing
  `Config::withGcInterval()` pattern. Pass `0` to disable the grace period and clean up immediately
  on disconnect.

## [0.11.0] - 2026-07-07

### New Features

- **`Config::withStaticCacheControl()`**: sets the `Cache-Control` header for `/datastar.js`,
  `/via.css`, and files served via `withStaticDir()`. Defaults to `no-cache` in devMode (so
  edits to a `withStaticDir()` file are visible on the next reload) or
  `public, max-age=3600, must-revalidate` otherwise. Pass a string to apply one value to every
  static response, e.g. `public, max-age=31536000, immutable` for fingerprinted filenames, or a
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

- Extracted static-file conditional-GET logic into `Support\ConditionalGet`, free of OpenSwoole
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
  `Context::component()` now also accepts a class-name string. The closure API is unchanged:
  composition is purely additive.
  - `#[Signal]`: TAB-scoped, client-writable reactive property.
  - `#[Signal(Scope::ROUTE|SESSION|GLOBAL|"custom")]`: scoped reactive signal that
    auto-broadcasts to its scope.
  - `#[Persist]`: server-only instance state that survives between action calls.
  - `#[Broadcast(Scope::X)]`: sets the context's primary broadcast scope.
  - `#[Action(name?, scope?)]`: marks a public method as a client-callable action.
  - `#[OnDisconnect]` / `#[OnCleanup]`: lifecycle hooks (max one each).

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
  // With prefix: routes declared with short paths, prefix prepended automatically
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
- **`Via` default server settings:** Added `'max_conn' => 10000` and `'backlog' => 4096` to prevent TCP accept-queue saturation under burst SSE load. Previously the OS default (~128 to 512) was exhausted at ~200 concurrent connections; tested clean to 2,000 after this change.
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
  // Server-authoritative (default): client cannot overwrite
  $counter = $c->signal(0, 'count', Scope::ROUTE);

  // Collaborative: client may push values (e.g. data-bind on a shared input)
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
- **Example summaries:** 3 to 6 paragraph descriptions per example explaining the concepts demonstrated
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
