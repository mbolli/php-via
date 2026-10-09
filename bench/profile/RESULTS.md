# Profiling results

## Website on 0.14.1, measured 2026-10-09

Where the worker's CPU goes in ten scenarios against the website, from Excimer samples (1 ms of
worker CPU each). README.md explains the setup and the category rules. The flame graphs are in
`svg/`, the folded stacks in `folded/`.

### Findings

- **Syntax highlighting is most of a page view.** `CodeRuntime::highlight()` runs tempest/highlight
  on every code block of every render: 52 to 69% of the worker's CPU on the docs pages and the home
  page. With a per-worker memo of its output (a temporary patch, not committed), a view of
  `/docs/api` cost 1.79 ms instead of 5.77 ms, `/docs/signals` 0.69 instead of 1.51, `/` 1.40
  instead of 3.13 and `/docs/faq` 0.90 instead of 2.97. The code blocks are fixed text in the
  templates, so their output can be cached by code, language and gutter, or highlighted when Twig
  compiles the template: none of the 23 templates with `{% code %}` blocks puts a Twig expression
  inside one.
- **Brotli is the second cost everywhere.** It takes 18 to 24% of a page view (1.4 ms of the 5.8 ms
  for the 170 KB `/docs/api`) and 16 to 23% of the broadcast scenarios. With small frames it takes
  more than half: the home counter sends about 1.7 small frames per tab per click, and
  `brotli_compress_add()` with `BROTLI_FLUSH` costs about 12 µs per frame per tab, 52% of the
  worker's time.
- **Per-tab renders dominate broadcasts that cannot share a render.** The Chat Room renders its
  50 messages once per tab: 0.46 ms per tab per message, of which Twig is 0.20 ms, Brotli 0.08 ms,
  the Datastar SDK 0.07 ms and SQLite 0.03 ms. Each render runs the same `getMessages()` query
  again, 300 times per message. The Leaderboard spends 53% in Twig, a third of that in
  `CoreExtension::getAttribute`, and the Spreadsheet 55% in its own `renderDynamic()`
  (about 0.11 ms per tab per action).
- **The Datastar SDK's event formatting is a visible cost.** `PatchElements::getMultiDataLines`
  splits the HTML at every newline and calls `getDataLine()` per line: 15% of the Chat Room, 8% of
  the Leaderboard and the counter. One `str_replace()` of `"\n"` would build the same text.
- **php-via's own code is small.** Its broadcast, SSE loop, patch, render, signal and HTTP code
  together take 2 to 9% of a page view and 10 to 21% of a broadcast scenario. The Dev Bar
  tracing, which the website turns on in production, adds 1 to 4% (`Tracer::span` around every
  render).
- **The master process does the socket I/O, and no sample covers it.** In `POOL_MODE` the reactor
  threads write every frame to the clients. That was 24% of the server's CPU with the counter's
  33 frames per tab per second, 7% for the Leaderboard and 1% for page views.

### Setup and versions

- Commit 4db06c9 (0.14.1) plus this directory and the opt-in include in `website/app.php`. The
  website in production mode (no `APP_ENV`), Brotli level 4, one worker, Dev Bar on as on the
  live site, `VIA_ACTION_RATE_LIMIT` lifted.
- PHP 8.5.11 NTS with opcache, OpenSwoole 26.2.0, ext-brotli 0.21.0, SQLite 3.53.4, Twig 3.28.0,
  tempest/highlight 2.27.0, starfederation/datastar-php 1.0.1. Excimer 1.2.7 (34183f9) built
  locally, FlameGraph 41fee1f.
- Intel i5-13500. Server on P-cores 2 and 4 (`taskset -c 2,4`), their siblings 3 and 5 idle, held
  at 4.5 GHz by idle-class busy loops at the start. Load on cores 6 to 11. Each scenario started a
  fresh server and requested its routes three times before the window.
- Windows of 30 s (idle: 120 s). The samples matched the worker's CPU from `/proc` within 1% in
  every scenario under load.

| Scenario | Load |
|---|---|
| pages-api, pages-signals, pages-home, pages-faq | 16 keep-alive clients fetching one page, no SSE |
| pages-mixed | the same, rotating `/`, `/docs/api`, `/docs/signals`, `/docs/faq`, `/examples` |
| spreadsheet | 300 tabs; 4 of them edit 5 cells per second, each edit three actions (`focusCell`, `startEdit`, `commitEdit`), each a broadcast |
| chat | 300 tabs in the lobby; 4 messages per second |
| leaderboard | 1,000 tabs; 10 votes per second, at most one render per 400 ms (the site's throttle) |
| counter | 500 home tabs; 20 shared-counter clicks per second, as in `bench/capacity` |
| idle | 1,000 home tabs, no activity |

### Against bench/capacity

The scenarios that overlap with `bench/capacity/RESULTS.md` agree within 4%: page views cost 5.81,
1.53 and 3.16 ms of server CPU against 5.76, 1.53 and 3.27 ms there; a shared click cost 0.049 ms
per receiving tab against 0.047 ms; 1,000 idle tabs used 0.2% of a core, as there.

### Page views

Shares of the worker's samples, in percent.

| Category | pages-api | pages-signals | pages-home | pages-faq | pages-mixed |
|---|---:|---:|---:|---:|---:|
| Syntax highlighting (tempest/highlight) | 68.9 | 51.7 | 55.1 | 64.6 | 62.9 |
| Twig | 3.2 | 10.0 | 17.0 | 6.8 | 8.2 |
| Brotli | 24.0 | 22.9 | 17.6 | 19.0 | 20.2 |
| Cycle GC | 0.1 | 2.0 | 1.0 | 3.8 | 1.3 |
| via: render | 0.4 | 1.6 | 1.0 | 0.6 | 1.0 |
| via: HTTP, middleware, PSR-7 | 1.0 | 3.6 | 2.0 | 1.9 | 1.8 |
| via: Dev Bar tracing | 1.3 | 2.3 | 1.7 | 1.3 | 1.8 |
| via: other | 0.4 | 2.6 | 2.0 | 0.8 | 1.1 |
| Rest (each under 1% everywhere) | 0.8 | 3.5 | 2.6 | 1.2 | 1.7 |
| Samples (ms of worker CPU) | 29,995 | 29,928 | 29,955 | 29,953 | 29,948 |
| Worker CPU per view | 5.77 ms | 1.51 ms | 3.13 ms | 2.97 ms | 2.82 ms |
| Master process, share of server CPU | 0% | 1% | 1% | 1% | 1% |

"Rest" holds SQLite, JSON, socket writes, OpenSwoole calls, the event loop and php-via's
broadcast, SSE, patch and state code, each under 1%. Over 95% of every page scenario is the page
request itself; the cycle collector is the rest.

### Tabs and broadcasts

| Category | spreadsheet | chat | leaderboard | counter | idle |
|---|---:|---:|---:|---:|---:|
| Twig | 0 | 44.1 | 53.2 | 0.2 | 0 |
| Website code | 55.0 | 3.6 | 2.7 | 0 | 0.4 |
| Brotli | 22.8 | 16.9 | 15.9 | 52.4 | 10.8 |
| Datastar SDK (event text) | 1.9 | 15.2 | 8.0 | 8.4 | 0 |
| SQLite | 0.2 | 6.6 | 0 | 0 | 0 |
| Socket writes (Response) | 1.0 | 0.8 | 1.3 | 8.6 | 41.4 |
| OpenSwoole calls (channels, coroutines) | 0.9 | 0.7 | 1.2 | 4.4 | 5.2 |
| Cycle GC | 0 | 0 | 0.1 | 0.1 | 22.4 |
| via: SSE loop | 2.2 | 3.1 | 2.8 | 8.1 | 6.5 |
| via: patches | 1.4 | 0.9 | 1.1 | 3.9 | 0 |
| via: render | 7.1 | 4.3 | 5.4 | 1.9 | 0 |
| via: signals and state | 2.6 | 0.5 | 0.9 | 2.0 | 2.2 |
| via: Dev Bar tracing | 2.4 | 2.1 | 3.9 | 3.6 | 0 |
| via: other | 2.1 | 0.7 | 2.9 | 4.4 | 7.3 |
| Event loop (no PHP frame) | 0 | 0 | 0 | 0.1 | 3.9 |
| Rest (each under 1% everywhere) | 0.5 | 0.5 | 0.6 | 1.9 | 0 |
| Samples (ms of worker CPU) | 14,700 | 16,420 | 8,264 | 11,111 | 232 |
| Worker CPU per operation | 98.0 ms per edit | 136.8 ms per message | 27.5 ms per vote | 18.5 ms per click | |
| Server CPU, share of a core | 51% | 57% | 30% | 49% | 0.2% |
| Master process, share of server CPU | 4% | 3% | 7% | 24% | 20% |

The same samples by what the worker was doing (inclusive: a Twig render during a broadcast counts
as broadcast fan-out):

| Phase | spreadsheet | chat | leaderboard | counter | idle |
|---|---:|---:|---:|---:|---:|
| broadcast fan-out (`runFlush` and below) | 70.5 | 62.6 | 70.1 | 14.6 | 0 |
| SSE stream loop (`runStream` and below) | 28.7 | 37.1 | 29.2 | 83.9 | 27.2 |
| action request | 0.4 | 0.1 | 0.2 | 0.8 | 0 |
| timers and cleanup | 0.1 | 0.1 | 0.2 | 0.2 | 68.1 |
| other | 0.3 | 0.1 | 0.3 | 0.5 | 4.7 |

- The counter renders once per click for all tabs (`shareRender`), so its fan-out is 15% and the
  streams' compression and writes are the rest.
- A vote on the Leaderboard costs 27.5 ms, but the throttle allows at most 2.5 renders per second
  per tab, so a tab's render costs at least 0.11 ms.
- In the idle run, 79 of the 96 socket-write samples are `Response::isWritable()` in the stream
  heartbeat timer (`SseHandler::endResetStreams`). In absolute terms the whole idle run is 2 ms of
  CPU per second.

### Profiler overhead

The same load without Excimer and with it at 1 ms, alternating, three rounds of 15 s each
(`overhead.sh`). Median of the paired differences:

| Load | Without | With | Difference |
|---|---:|---:|---:|
| `/docs/api` views, CPU per view | 6.63 ms | 7.24 ms | +6.4% |
| `/docs/signals` views, CPU per view | 1.71 ms | 1.77 ms | +4.2% |
| Counter, 500 tabs, 20 clicks/s, server CPU | 55.1% | 56.7% | +4.3% |

During these runs the package was at 100 °C and the held cores ran at 4.0 GHz instead of 4.5, and
another session's processes used cores 8 to 11, so the absolute figures are 10 to 15% above the
scenario runs. Only the differences between paired runs are used. The shares in the tables above
include the overhead.

### Caveats

- Excimer cannot see C code that runs outside a PHP call: OpenSwoole reading requests and waking
  coroutines lands on the next PHP root frame ("event loop", under 1% except idle), and the master
  process's reactor threads are not sampled at all (the "Master process" row).
- A sample inside an internal function is taken when the call returns. `fold.php` names the call
  from the source line, and picks the costliest one when a line has several (Brotli before SQLite
  before socket writes before JSON). Generic calls such as `preg_match()` count for their caller.
- Kernel time spent in a syscall counts for the PHP call that made it, since the timer counts the
  process's CPU time. Time spent waiting costs no CPU and has no samples.
- The highlighting figure comes from one 15 s run per page with the temporary memo, against the
  30 s scenario runs; the effect is large enough that the noise does not matter.
- The clocks were checked at 4.5 GHz when the scenarios started but not logged during them. The
  agreement with `bench/capacity` is the check that they held.
