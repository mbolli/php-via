# Profiling benchmark

Sampled flame graphs of the website (`website/app.php`) under load, with every sample sorted into a
category: Twig, syntax highlighting, Brotli, SQLite, the Datastar SDK, socket writes, and php-via's
own parts (broadcast, SSE loop, patches, render, signals, HTTP). Results are in
[RESULTS.md](RESULTS.md).

## Files in this directory

- `excimer.php`: starts an [Excimer](https://github.com/wikimedia/mediawiki-php-excimer) profiler in
  each worker. `website/app.php` includes it only when `VIA_PROFILE=1`; without that variable the
  site runs as before and Excimer is not loaded.
- `run.sh`: starts and stops the server on its cores, holds their clocks, and sends commands to the
  profiler.
- `load.php`: the load for each scenario. It resets the profiler once the load is steady and dumps
  it at the end of the window, and prints the window's server CPU per process.
- `scenarios.sh`: runs every scenario on a fresh server and folds the result.
- `fold.php`: turns the dumps into collapsed stacks, sorts the samples into categories, and renders
  the SVGs.
- `table.php`: the category table across scenarios, as Markdown.
- `overhead.sh`: the same load with and without the profiler.
- `svg/`: the flame graphs from the run in RESULTS.md, `NAME.svg` and `NAME.cats.svg` per scenario.
- `folded/`: that run's folded stacks (gzip) and the load's figures, for `flamegraph.pl` or a
  different categorisation.

## Tools to build first

Excimer and FlameGraph stay outside the repository. Excimer builds against the PHP in use:

```bash
git clone --depth 1 https://github.com/wikimedia/mediawiki-php-excimer.git excimer
cd excimer && phpize && ./configure && make      # modules/excimer.so
git clone --depth 1 https://github.com/brendangregg/FlameGraph.git
```

Load it per process with `php -d extension=/abs/path/excimer.so`; `run.sh start profile` does that.

## Running the scenarios

The server runs on cores 2 and 4 (two P-cores with their hyperthread siblings idle) and the load on
cores 6 to 11, as in `bench/capacity`. Hold the server cores' clocks while measuring: a core that
waits between requests clocks down, and its requests then cost more CPU (`bench/capacity/README.md`).

```bash
export EXCIMER=/abs/path/excimer/modules/excimer.so FLAMEGRAPH=/abs/path/FlameGraph/flamegraph.pl
bench/profile/run.sh spin-start
bench/profile/scenarios.sh                    # or name scenarios: pages-api chat counter ...
bench/profile/overhead.sh                     # optional, about 8 minutes
bench/profile/run.sh spin-stop
php bench/profile/table.php tmp/prof pages-api pages-signals pages-home pages-faq pages-mixed \
  spreadsheet chat leaderboard counter idle
```

Everything is written to `tmp/prof/` (ignored by git): the dumps in `ctl/`, and per scenario
`NAME.json` (the load's figures), `NAME.txt` (the category table), `NAME.folded`,
`NAME.cats.folded`, `NAME.svg` and `NAME.cats.svg`. Each server gets its own `TMPDIR`, so its Twig
cache does not mix with another checkout's.

To profile something else, start the server with `run.sh start profile`, put load on it, and run
`run.sh ctl reset`, then `run.sh ctl 'dump NAME'`. Fold the dump with
`php fold.php out=tmp/prof/NAME twig=tmp/prof/sys/php-via-twig-cache flamegraph=$FLAMEGRAPH tmp/prof/ctl/NAME.w*.stacks`.

## How the profiler samples

Excimer counts CPU time of the worker (`EXCIMER_CPU`, period 1 ms, `VIA_PROFILE_PERIOD` changes it).
When a period has passed, PHP takes the sample at its next check, so each sample is 1 ms of worker
CPU and the sample count is the worker's CPU time in milliseconds. In the run in RESULTS.md the
samples matched the worker's CPU from `/proc` within 1% in every scenario under load.

Excimer reads the current coroutine's stack. A coroutine's stack starts where the coroutine
starts, so the graphs have one root per kind of coroutine:

- `Via::{closure:1482}` is OpenSwoole's request callback. Page views, actions and the SSE streams
  run in it; a stream's loop is `SseHandler::runStream` under it.
- `Via::{closure:3255}` is the broadcast flush. The fan-out to every tab of a scope (`runFlush`,
  `syncContexts`, `syncFanOut`) and the per-tab renders it starts are under it.
- Other `Via::{closure:N}` roots are timers: the cycle collector, the stream heartbeat, website
  intervals such as the stock ticker.

Two kinds of time need care:

- **Internal functions.** A sample that falls inside `brotli_compress_add()`, `json_encode()` or
  `SQLite3Stmt::execute()` is taken when the call returns, on the calling line, so Excimer's stack
  ends in the PHP function that made the call. `excimer.php` records that line, and `fold.php`
  reads the source line and adds the internal call on it as a leaf frame written with `()`, such as
  `brotli_compress_add()`. Method calls are only named for SQLite3, OpenSwoole's `Response`, server
  and channel objects, and only in files that use those classes.
- **Work outside PHP.** OpenSwoole's C code that runs before PHP is called (reading a request,
  waking a coroutine) is counted at the next PHP frame, which is a coroutine root with nothing on
  it; those samples are "event loop". The master process does all socket I/O to clients in its
  reactor threads (the server runs in `POOL_MODE`), and no sample covers that. `load.php` reports
  the master's CPU separately, and RESULTS.md gives it as a share of the server's CPU. Time spent
  waiting (a coroutine blocked on a channel, a socket or a timer) costs no CPU and has no samples.

## How the samples are categorised

`fold.php` walks each stack from the leaf towards the root and gives the sample the category of
the first frame that matches a rule. Frames that match no rule (generic internal functions such as
`preg_match()` or `str_replace()`) pass the sample on to their caller: a `str_replace()` inside
Twig counts as Twig, the same call in `PatchManager` as php-via. A stack of only a coroutine root is
"event loop". The rules, in the order they are tried on a frame:

| Category | Frames |
|---|---|
| Brotli | `brotli_*()` |
| SQLite | `SQLite3::*()`, `PDO*`, the website's `Support\Sqlite`, php-via's `State\SqliteSnapshot` |
| JSON | `json_encode()`, `json_decode()` |
| socket writes | `Response::write()`, `end()`, `header()` and the other response calls; `Server::send()` |
| OpenSwoole calls | `Channel::*()`, `Coroutine::*()`, `Timer::*()`, `Process::*()` |
| cycle GC | `gc_collect_cycles()`, `Support\CycleCollector` |
| highlighting | `Tempest\Highlight\*`, `Mbolli\TempestHighlightDatastar\*`, the website's `Twig\CodeRuntime` |
| Twig | `Twig\*` and compiled templates (shown as `twig:NAME::block`) |
| website code | `PhpVia\Website\*`, closures in `app.php` and `routes.php` |
| via: broadcast | `Via::broadcast`, the flush and sync methods, `Context::syncFanOut`, `Broker\*`, `ScopeRegistry` |
| Datastar SDK | `starfederation\datastar\*`, which formats the SSE event text |
| via: SSE loop | `SseHandler`, `SseStream`, `SwooleSSEGenerator`, `BrotliStream` |
| via: patches | `PatchManager`, `Context::sync` and its siblings |
| via: render | `Rendering\*`, `Twig\TwigEngine`, `Context::render*` and `view`, `Via::buildHtmlDocument` |
| via: signals/state | `State\*`, `Signal`, `SignalFactory`, `Composition\*`, type casting, tab state |
| via: HTTP + PSR | `Http\*`, `Router`, sessions, the PSR-7 and CORS libraries |
| via: Dev Bar tracing | `Tracing\*`, `DevBar\*`, `Context::span` |
| via: other | the rest of `Mbolli\PhpVia\*` |

The phases in the tables are inclusive and come from frames anywhere in the stack: "broadcast
fan-out" is everything under the broadcast flush, Twig included; "SSE stream loop" is everything
under `runStream`, which is mostly compressing and writing what the fan-out queued.

## Reading the graphs

Each frame is coloured by its own category; grey frames are internal functions or roots that pass
the sample on. The legend under the title gives every category's share of the samples. Width is
CPU time. `NAME.cats.svg` has two levels only: the category, and above it the class whose frame
decided the category. Open an SVG in a browser to click into frames and search.
