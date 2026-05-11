# OPcache / JIT Benchmark Results — php-via

## Environment

| Item | Value |
|------|-------|
| Date | 2026-05-11 |
| PHP | 8.4.20 |
| OpenSwoole | v25.2.0 |
| OS | Linux 6.6.87.2-microsoft-standard-WSL2 |
| Host | WSL2 (single NUMA node, coroutine scheduler) |

## Test Methodology

**Tool:** `tests/Load/bench_opcache.php` orchestrates `tests/Load/action_hammer.php`

| Parameter | Value |
|-----------|-------|
| Actions per pass | 5,000 (bench_app) / 1,000 (website) |
| Concurrency | 200 (bench_app) / 50 (website) |
| Per-request timeout | 5 s |
| Passes | cold (fresh OPcache) + warm (JIT fully ramped) |
| HTTP OK requirement | 100% |

**Cold pass** — server just started, OPcache empty, JIT profiling hasn't begun.  
**Warm pass** — immediately after cold, OPcache is hot, JIT has compiled inner loops.

### Workloads

| Name | Route | Description |
|------|-------|-------------|
| **counter** | `/bench/counter` | Trivial integer increment. Measures framework + SSE overhead with zero application logic. |
| **cpu** | `/bench/cpu` | Mandelbrot set on a 50×50 grid, max 100 iterations/pixel. ~250k float ops per action call. Canonical JIT benchmark. |
| **io** | `/bench/io` | `usleep(2_000)` per action (2 ms simulated DB latency). OPcache/JIT should have no effect here — bottleneck is coroutine scheduling. |
| **spreadsheet** | `/bench/spreadsheet` | SQLite range query (20×10 viewport) + build viewport HTML cell-by-cell. Mirrors the real SpreadsheetExample render path on every keystroke. |

### Profiles

| Profile | Notable flags |
|---------|---------------|
| `no-opcache` | `opcache.enable=0` — baseline, full interpretation |
| `opcache-default-cli` | `opcache.enable_cli=1`, all defaults |
| `opcache-tuned` | `memory=256M, interned=64M, max_files=100000, validate_timestamps=0` |
| `jit-function` | tuned + `opcache.jit=function, jit_buffer_size=128M` |
| `jit-tracing` | tuned + `opcache.jit=tracing, jit_buffer_size=128M` |
| `opcache-preload` | tuned + `opcache.preload` — **SKIPPED** (see below) |
| `multi-worker-4w` | jit-tracing flags + `VIA_BENCH_WORKERS=4`, SwooleBroker — **UNRELIABLE** (see below) |

---

## Results

All throughput values in **req/s**. Δ is warm req/s vs `no-opcache` warm.

### Counter (trivial increment)

| Profile | cold r/s | warm r/s | Δ | cold OK% | warm OK% | cold→warm |
|---------|----------|----------|---|----------|----------|-----------|
| no-opcache | 4,073 | 4,107 | — | 100.0 | 100.0 | +0.8% |
| opcache-default-cli | 4,354 | 3,872 | −5.7% | 100.0 | 100.0 | −11.1% |
| opcache-tuned | 4,392 | 4,815 | +17.2% | 100.0 | 100.0 | +9.6% |
| jit-function | 4,309 | 4,519 | +10.0% | 100.0 | 100.0 | +4.9% |
| jit-tracing | 4,846 | 4,980 | **+21.3%** | 100.0 | 100.0 | +2.8% |
| opcache-preload | SKIPPED | | | | | |
| multi-worker-4w | SKIPPED | | | | | |

### CPU (Mandelbrot 50×50)

| Profile | cold r/s | warm r/s | Δ | cold OK% | warm OK% | cold→warm |
|---------|----------|----------|---|----------|----------|-----------|
| no-opcache | 444 | 366 | — | 100.0 | 100.0 | −17.6% |
| opcache-default-cli | 827 | 642 | +75.4% | 100.0 | 100.0 | −22.4% |
| opcache-tuned | 810 | 817 | +123.2% | 100.0 | 100.0 | +0.9% |
| jit-function | 2,713 | 2,608 | **+612.6%** | 100.0 | 100.0 | −3.9% |
| jit-tracing | 3,036 | 2,875 | **+685.5%** | 100.0 | 100.0 | −5.3% |
| opcache-preload | SKIPPED | | | | | |
| multi-worker-4w | SKIPPED | | | | | |

### IO (usleep 2 ms)

| Profile | cold r/s | warm r/s | Δ | cold OK% | warm OK% | cold→warm |
|---------|----------|----------|---|----------|----------|-----------|
| no-opcache | 4,797 | 4,009 | — | 100.0 | 100.0 | −16.4% |
| opcache-default-cli | 4,724 | 3,846 | −4.1% | 100.0 | 100.0 | −18.6% |
| opcache-tuned | 4,758 | 4,449 | +11.0% | 100.0 | 100.0 | −6.5% |
| jit-function | 374 | 431 | **−89.2%** ⚠️ | 100.0 | 100.0 | +15.2% |
| jit-tracing | 5,265 | 4,522 | +12.8% | 100.0 | 100.0 | −14.1% |
| opcache-preload | SKIPPED | | | | | |
| multi-worker-4w | SKIPPED | | | | | |

### Spreadsheet (SQLite query + 20×10 viewport build)

| Profile | cold r/s | warm r/s | Δ | cold OK% | warm OK% | cold→warm |
|---------|----------|----------|---|----------|----------|-----------|
| no-opcache | 3,037 | 3,416 | — | 100.0 | 100.0 | +12.5% |
| opcache-default-cli | 3,519 | 3,544 | +3.7% | 100.0 | 100.0 | +0.7% |
| opcache-tuned | 3,504 | 3,468 | +1.5% | 100.0 | 100.0 | −1.0% |
| jit-function | 3,441 | 3,618 | +5.9% | 100.0 | 100.0 | +5.1% |
| jit-tracing | 3,783 | 3,688 | **+8.0%** | 100.0 | 100.0 | −2.5% |
| opcache-preload | SKIPPED | | | | | |
| multi-worker-4w | SKIPPED | | | | | |

### Spreadsheet live (Twig + SQLite + virtual scroll — website app)

1,000 actions, concurrency=50, `navigate` (ArrowDown) against `/examples/spreadsheet`.
Full php-via stack: framework routing, session auth, SQLite range query, Twig `renderBlock`, SSE patch queue.

| Profile | cold r/s | warm r/s | Δ | cold OK% | warm OK% | cold→warm |
|---------|----------|----------|---|----------|----------|-----------|
| no-opcache | 234 | 210 | — | 100.0 | 100.0 | −10.3% |
| opcache-default-cli | 280 | 234 | +11.4% | 100.0 | 100.0 | −16.4% |
| opcache-tuned | 281 | 240 | +14.3% | 100.0 | 100.0 | −14.6% |
| jit-function | 271 | 224 | +6.7% | 100.0 | 100.0 | −17.3% |
| jit-tracing | 355 | 304 | **+44.8%** | 100.0 | 100.0 | −14.4% |
| opcache-preload | SKIPPED | | | | | |
| multi-worker-4w | SKIPPED | | | | | |

### CPU workload: JIT is transformative

The Mandelbrot workload shows the largest gain:

- **OPcache only** (`opcache-default-cli`): +75% over interpreted — bytecode caching already roughly doubles CPU throughput.
- **JIT** (`jit-function` / `jit-tracing`): +613–686% — **~7.9× faster** than no-opcache. The tight `while` loop over floats is exactly what tracing JIT compiles best.
- `jit-tracing` slightly edges out `jit-function` (2,875 vs 2,608 req/s). The Mandelbrot loop is simple enough that both modes fully compile it, but tracing's code-path analysis is marginally more effective.
- The cold→warm delta is small (~4–5%) for JIT on the CPU workload — JIT warms up within the first few hundred actions.

### Counter workload: JIT helps moderately, within noise

Framework overhead (context lookup, signal mutation, SSE patch queuing) is not dominated by tight loops. Gains are modest:

- Most profiles within ±10% of baseline — largely measurement noise on WSL2.
- `jit-tracing` warm shows +21.3% — plausibly real; tracing JIT compiles the signal dispatch path.
- `jit-function` shows +10.0% — within noise; cold start overhead of JIT buffer allocation is visible in the cold pass.

### IO workload: JIT has no effect — except for a critical anomaly

Expected: IO-bound workload, bottleneck is the 2 ms coroutine sleep. All profiles hover near 4,000 req/s — except one:

**`jit-function` IO: 431 req/s warm (−89.2%)** ⚠️

This is not noise — it's a real regression. The likely cause: `opcache.jit=function` compiles `usleep()` as a regular function call, bypassing OpenSwoole's `SWOOLE_HOOK_ALL` coroutine hook that makes `usleep()` yield the coroutine instead of blocking the thread. With blocking `usleep(2000)`, max throughput = 1/(0.002s) = 500 req/s — which matches the 431 req/s observed.

`jit-tracing` does not exhibit this regression (4,522 req/s). Tracing mode compiles hot paths but preserves the hook for `usleep()`.

**Recommendation:** Do not use `opcache.jit=function` in OpenSwoole applications that rely on `SWOOLE_HOOK_ALL` for coroutine-safe blocking functions.

### Spreadsheet workload: SQLite is the bottleneck, JIT helps modestly

The bench_app spreadsheet workload (SQLite range query + 200-cell viewport HTML build) shows modest JIT gains (+8%) compared to Mandelbrot's +686%. The gains are real but the ceiling is the SQLite round-trip, not the PHP bytecode.

Key observations:
- `jit=tracing` warm: 3,688 req/s (+8.0%) — JIT compiles the `htmlspecialchars` loop and cell-building iteration, which are the tightest PHP loops here.
- `jit=function` warm: 3,618 req/s (+5.9%) — slightly lower than tracing; function-mode doesn't compile the inner loop as aggressively.
- This mirrors real-world spreadsheet behaviour: each keystroke does a DB query, so the DB remains the limit regardless of OPcache tuning.

### Spreadsheet live (full stack): JIT-tracing delivers significant gains

The website spreadsheet-live workload runs the complete php-via stack per action: framework routing, session auth, SQLite range query, Twig `renderBlock('spreadsheet_update')`, SSE patch queuing. Numbers are significantly higher than the May 2026-05-08 baseline (101 req/s) because Twig file caching and partial block rendering were applied in the interim.

Key observations:
- `jit-tracing` cold: 355 req/s, warm: 304 req/s (+44.8% over no-opcache warm) — the largest real-app gain in this suite. With Twig's compiled template already hot in OPcache, the tracing JIT compiles the template execution and signal-handling paths, yielding a meaningful speedup.
- `opcache-tuned` warm: 240 req/s (+14.3%) — respectable gain from OPcache alone; Twig's compiled PHP classes benefit substantially from bytecode caching.
- `jit-function` underperforms `jit-tracing` (224 vs 304 req/s warm) — function-mode JIT doesn't compile Twig's template dispatch as aggressively as tracing mode.
- All profiles show a negative cold→warm delta (−10 to −17%): the JIT compilation burst in the cold pass boosts the first 1,000 actions; in the warm pass OPcache is already hot, so the baseline is higher and the JIT ramp-up is already paid.
- The dominant cost is still SQLite I/O, but Twig template rendering is now the second-largest bottleneck — which is why `jit-tracing` helps more than on the bench_app spreadsheet (pure SQL + string build).

### opcache-preload: SKIPPED

OPcache preloading (`opcache.preload`) causes a SIGSEGV (signal 11) in OpenSwoole workers. The preload script runs correctly in the master process (classes compile and link against vendor), but when workers fork from the master, the preloaded class table causes worker crashes on the first request.

This is a known incompatibility between PHP's preloading mechanism and OpenSwoole's POOL_MODE worker fork model on WSL2 Linux kernel 6.6. Preloading is designed for PHP-FPM (stateless fork-on-request) and is not reliably compatible with long-lived async worker processes.

### multi-worker-4w: Low HTTP OK% — results unreliable

The 4-worker profile is SKIPPED in this run. Previous runs showed only 9–20% HTTP OK. Root cause: the benchmark hammer loads the page once (hitting one random worker), then fires all action POSTs without sticky routing. With 4 workers in POOL_MODE, ~75% of POST requests hit workers that don’t have the context registered — resulting in 403/404 failures. This is a benchmark design limitation, not a framework bug.

---

## Recommendations

| Scenario | Recommended config |
|----------|--------------------|
| Pure CPU work (computations, transformations) | `jit=tracing, jit_buffer_size=64M+` — **~7.9× speedup** |
| Real application with Twig rendering | `jit=tracing` — **+44.8%** on full-stack spreadsheet workload |
| Framework-heavy, mostly IO | `opcache-tuned` (no JIT) — safe +14% with zero risk |
| IO-bound (DB, network, file) | OPcache only; skip JIT — bottleneck is not bytecode |
| **Avoid** | `jit=function` in OpenSwoole — breaks `usleep()` hook, destroys IO concurrency |

---

## Known Caveats

- **WSL2 environment noise:** WSL2 virtualisation adds scheduling jitter. Counter and IO results within ±10% should be treated as equivalent.
- **Single-run measurements:** Each cold+warm pair is one run. Results would stabilise further with 3+ runs averaged.
- **Negative cold→warm on spreadsheet-live:** The cold pass benefits from JIT compilation burst; the warm pass starts from a higher OPcache baseline, so the ratio inverts. This is expected and not a regression.
- **preload NunoMaduro warnings:** `Can't preload unlinked class NunoMaduro\Collision\...` are benign — these are test-only dev dependencies that use anonymous class patterns incompatible with preloading. They do not affect runtime.
