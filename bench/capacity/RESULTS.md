# Capacity results

## 0.14.0, measured 2026-10-02 and 2026-10-04

Measured with `capacity.php` against the website (`website/app.php`, production mode, Brotli on).
Page views and two workers were measured on 2026-10-04 at 7388732: the 0.14.0 release with the
website's growth-based cycle collector (`withGcIntervalMs(30_000, onGrowth: true)`), as the site
ships. Open tabs, private actions, visitor churn, shared clicks to 500 tabs and the Brotli level
were measured on 2026-10-04 at fbfa1f6, the 0.14.0 release tree before that change. Shared clicks to
2,000 tabs and the held and free clock comparison were measured on 2026-10-02 at 62d1e5b: 0.13.1
plus Datastar 1.0.4 and the 0.14.0 hook fixes.

- **Server:** two physical P-cores of an Intel i5-13500 (`taskset -c 2,4`) with their hyperthread
  siblings idle, held at 4.35 to 4.5 GHz by an idle-class busy loop on each core (README.md shows
  how). One worker unless noted, PHP 8.5.11, OpenSwoole 26.2.0, opcache on, Brotli level 4 unless
  noted. The checkout is on a local disk.
- **Harness:** cores 6 to 11. Each scenario starts a fresh server and requests its routes three
  times before measuring. Every figure is the median of three rounds. On fbfa1f6 every CPU and
  memory figure varied by under 5% between rounds; latency percentiles varied more. Each run
  started with the package below 95 °C and the sibling cores idle, and its median clock was 4.4 GHz
  or more. The page view runs on 7388732 varied by under 3%; their median clocks were 4.42 to
  4.65 GHz, but other load on the machine kept the package at 96 to 100 °C.
- **Calibration reference** (`calibrate.php` on core 2): `brotli 7 ms, php 60 ms` (61 ms on
  2026-10-04).

### Page views without an SSE stream

Sixteen keep-alive clients for 10 s, the way a crawler fetches pages. Memory is the worker's RSS
growth during the run, held until each page's 30 s connect timeout.

| Page | HTML | req/s | CPU per view | Memory |
|---|---|---|---|---|
| `/docs/api` | 173 KB | 174 | 5.76 ms | +35 MB (20.3 KB per view) |
| `/docs/signals` | 35 KB | 660 | 1.53 ms | +281 MB (43.5 KB per view) |
| `/` | 99 KB | 306 | 3.27 ms | +166 MB (55.3 KB per view) |

Two 5 s bursts of `/docs/signals` with a 35 s pause between them:

| First burst | Second burst |
|---|---|
| 46 → 199 MB (3,471 views) | 199 → 251 MB (3,430 views) |

#### Against the earlier run

On 2026-10-02 the same pages cost 1.27, 2.82 and 4.20 ms, on 62d1e5b with the website before its
redesign. A paired run on 2026-10-04 interleaved four builds over three rounds (median CPU per view):

| Build | `/docs/signals` | `/` | `/docs/api` |
|---|---|---|---|
| D: 62d1e5b and its website | 1.26 ms | 2.84 ms | 4.25 ms |
| A: 7388732 with 62d1e5b's website | 1.36 ms | 2.87 ms | 4.27 ms |
| B: 0cee1c7, PHP's own collector runs | 2.02 ms | 3.45 ms | 5.90 ms |
| C: 7388732, `onGrowth` collector | 1.53 ms | 3.27 ms | 5.76 ms |
| HTML, old and new website | 22 and 35 KB | 68 and 99 KB | 88 and 173 KB |

- D reproduces the earlier figures. The framework changes (A against D) add 0.1 ms on
  `/docs/signals` and nothing measurable on the larger pages.
- Most of the rest is the redesigned website (B against A): its pages are 1.6, 1.5 and 2.0 times
  larger, and each view renders them and compresses them with Brotli.
- With PHP's own collector, every run walks the page contexts that wait for their connect timeout,
  and at 500 views per second that costs `/docs/signals` 0.5 ms per view (B against C), `/` and
  `/docs/api` 0.1 to 0.2 ms. The `onGrowth` collector removes it and holds 1 to 2 KB more per view.
- A is 62d1e5b's website on 7388732, with the calls 0.14 removed changed to their replacements:
  `via_head()` and `via_foot()` in the shell, an explicit scope on the shared signals and
  `onWorkerStart()`. Each build had its own Twig cache directory: the website's
  (`sys_get_temp_dir()/php-via-twig-cache`) keys compiled templates by relative path, so two
  checkouts that share it render each other's templates.

### Open tabs

| | Level 4 | Level 1 | No Brotli |
|---|---|---|---|
| Memory per `/docs/api` tab (1,000 open) | 588 KB | 77 KB | 49 KB |
| Memory per `/` tab (500 open) | 671 KB | 145 KB | 93 KB |
| CPU to open a `/docs/api` tab | 6.1 ms | 5.1 ms | 4.6 ms |
| CPU to open a `/` tab | 4.0 ms | 3.3 ms | 3.0 ms |
| Idle CPU, 1,000 docs tabs over 30 s | 0.2% of a core | | |

A visitor who opens and closes a tab on `/docs/faq` while 1,000 `/docs/api` tabs stay open, one
visitor every 1.2 s (50 visitors; 10 for 0.13.0, tag v0.13.0 with its website, on 2026-10-02):

| | CPU per visitor | Bytes to the open tabs |
|---|---|---|
| 0.13.0 | 506 ms | 25.4 KB |
| 0.14.0 | 6.6 ms | 0.8 KB |

Both figures include what the open tabs cost meanwhile: their keep-alive comments take about 2 ms
of CPU per 1.2 s and make up most of the 0.8 KB.

### Actions on the home page

Private actions (the TAB counter), 500 tabs open:

| Rate | CPU per action | p99 response | p99 until the tab's stream shows it |
|---|---|---|---|
| 100/s | 0.14 ms | 1.2 ms | 1.2 ms |
| 300/s | 0.11 ms | 1.2 ms | 1.2 ms |

Shared clicks (the home counter; "visible" is the time until every observed tab, 100 of them,
shows the click). The 2,000-tab rows are from 62d1e5b:

| Tabs and rate | CPU | Visible p50 / p99 | CPU per receiving tab |
|---|---|---|---|
| 500 tabs, 5/s | 13% | 19 / 22 ms | 0.052 ms |
| 500 tabs, 20/s | 47% | 18 / 20 ms | 0.047 ms |
| 2,000 tabs, 2/s | 21% | 71 / 88 ms | 0.053 ms |
| 2,000 tabs, 5/s | 50% | 70 / 75 ms | 0.050 ms |
| 2,000 tabs, 10/s | 95% | 68 / 92 ms | 0.048 ms |

A click re-renders only the counter components: 33.3 frames per tab per second at 20 clicks/s.

Brotli level on the home page, 500 tabs, 20 shared clicks per second:

| | Memory per tab | Bytes on the wire per tab per second | CPU |
|---|---|---|---|
| Level 4 | 671 KB | 0.69 KB | 47% |
| Level 1 | 145 KB | 10.84 KB | 39% |
| No Brotli | 93 KB | 25.32 KB | 25% |

### Two workers

`withWorkerNum(2)` with `SwooleBroker`, on the same two cores:

| | One worker | Two workers |
|---|---|---|
| `/docs/signals` | 680 req/s | 1,308 req/s (13,095 OK in 10 s, 0 errors) |
| `/docs/api` | 177 req/s | 348 req/s |

Both columns come from one paired run on 7388732; its one-worker figures are 2 to 3% above the
page view table's, which ran in another session.

### Held and free clock

The same build (62d1e5b) on the same cores without the busy loops, two rounds:

| | Held clock | Free clock |
|---|---|---|
| Private action, 100/s | 0.20 ms | 0.74 and 1.23 ms |
| Private action, 300/s | 0.16 ms | 0.49 and 0.95 ms |
| Visitor churn, 1,000 tabs open | 5.2 ms | 16.8 and 19.8 ms |
| Shared clicks, 500 tabs, 5/s | 12% | 17% and 22% |
| Shared clicks, 500 tabs, 20/s | 46% | 58% and 61% |
| CPU to open a `/` tab | 3.3 ms | 3.3 ms |

A core that waits between requests clocks down and sleeps. A scenario that keeps it a few percent
busy then measures 3 to 6 times the CPU, and its p99 responses double; at about half a core the
factor is 1.3, and a busy core measures the same. The extra time is spare capacity, since the
clock rises with the load.

### Notes

- The CPU figures are for this desktop core at full clock; scale them with `calibrate.php` (see
  README.md). The memory figures carry over.
- Against 62d1e5b, fbfa1f6 costs more per opened tab, in step with the larger pages, and 3 to 27%
  more memory per open tab (20 KB more per home tab without Brotli). Private actions cost 0.14 and
  0.11 ms instead of 0.20 and 0.16 ms, and a visitor 6.6 ms instead of 5.2 ms. Idle CPU and shared
  clicks to 500 tabs stayed within the noise. Only the page view costs were isolated (see "Against
  the earlier run").
- Against the superseded figures below, which ran on a free clock, private actions, visitor churn
  and shared clicks to 500 tabs fell 1.3 to 5.4 times (see "Held and free clock").
- The memory per open tab is measured about 1.5 s after the tabs open, on streams that carry
  little traffic. A stream's Brotli encoder grows with what it compresses and levels off near
  9 MB at level 4 (575 KB at level 1) after about 8 MB of traffic (PERFORMANCE.md), so busy
  streams such as a live game cost more.

## 0.13.0 against 0.13.1, measured 2026-10-02 (superseded)

**Superseded by the 0.14.0 section above.** These figures were taken from a checkout on a FUSE
mount without holding the clock. Cores that idled between requests clocked down, so private
actions, visitor churn and shared clicks to 500 tabs measured 1.3 to 5.4 times their held-clock
CPU, and every request paid two `realpath()` calls on the mount, which 0.14.0 no longer makes.
The memory figures, page views, opening tabs and broadcasts to 2,000 tabs still hold.

Measured 2026-10-02 with `capacity.php` against the website (`website/app.php`, production mode,
Brotli on).

- **Server:** two physical P-cores of an Intel i5-13500 (`taskset -c 2,4`), one worker,
  `reactor_num` 2, PHP 8.5.11, OpenSwoole 26.2.0, opcache on, Brotli level 4 unless noted.
- **Harness:** cores 6 to 11. Each scenario starts a fresh server.
- **Calibration reference** (`calibrate.php` on core 2): `brotli 7 ms, php 62 ms`.

0.13.0 is tag v0.13.0. 0.13.1 is the release branch with the teardown, Brotli encoder, context
directory and SSE fixes, plus the website's static pages and widget scopes.

### Page views without an SSE stream

Sixteen keep-alive clients for 10 s, the way a crawler fetches pages. Memory is the worker's RSS
growth during the run; in 0.13.0 it is never freed, in 0.13.1 it is held until each page's 30 s
connect timeout.

| Page | 0.13.0 req/s | 0.13.0 memory | 0.13.1 req/s | 0.13.1 memory |
|---|---|---|---|---|
| `/docs/api` | 230 (4.38 ms CPU each) | +1,784 MB | 245 (4.12 ms) | +32 MB |
| `/docs/signals` | 729 (1.40 ms) | +4,318 MB | 797 (1.29 ms) | +262 MB |
| `/` | 403 (2.51 ms) | +2,605 MB | 384 (2.64 ms) | +135 MB |

Two 5 s bursts of `/docs/signals` with a 35 s pause between them:

| | First burst | Second burst |
|---|---|---|
| 0.13.0 | 42 → 2,490 MB (4,152 views) | 2,491 → 4,413 MB (3,344 views) |
| 0.13.1 | 42 → 189 MB (4,427 views) | 190 → 219 MB (3,838 views) |

### Open tabs

| | 0.13.0 | 0.13.1 level 4 | 0.13.1 level 1 | 0.13.1 no Brotli |
|---|---|---|---|---|
| Memory per `/docs/api` tab (1,000 open) | 2,834 KB | 574 KB | 72 KB | 47 KB |
| Memory per `/` tab (500 open) | 1,295 KB | 642 KB | 124 KB | 74 KB |
| CPU to open a `/docs/api` tab | 8.2 ms | 5.1 ms | 3.7 ms | 3.6 ms |
| Idle CPU, 1,000 docs tabs | 0.7% of a core | 0.4% | | |

A visitor who opens and closes a tab on `/docs/faq` while 1,000 `/docs/api` tabs stay open:

| | CPU per visitor | Bytes to the open tabs |
|---|---|---|
| 0.13.0 | 545 ms | 30.7 KB |
| 0.13.1 | 16 ms | 1.0 KB |

### Actions on the home page

Private actions (the TAB counter), 500 tabs open:

| Rate | 0.13.0 | 0.13.1 |
|---|---|---|
| 100/s | 1.19 ms CPU each, p99 response 2.8 ms | 1.08 ms, p99 3.6 ms |
| 300/s | 0.96 ms, p99 2.8 ms | 0.59 ms, p99 2.5 ms |

Shared clicks (the home counter; "visible" is the time until every observed tab shows the click):

| Tabs and rate | 0.13.0 CPU | 0.13.0 visible p50 / p99 | 0.13.1 CPU | 0.13.1 visible p50 / p99 |
|---|---|---|---|---|
| 500 tabs, 5/s | 42% | 69 / 85 ms | 18% | 31 / 39 ms |
| 500 tabs, 20/s | 98% | 84 / 115 ms | 58% | 24 / 45 ms |
| 2,000 tabs, 2/s | 61% | 227 / 265 ms | 21% | 78 / 108 ms |
| 2,000 tabs, 5/s | 119% | 317 / 459 ms | 50% | 76 / 100 ms |
| 2,000 tabs, 10/s | 118% | 317 / 505 ms | 90% | 71 / 120 ms |

In 0.13.1 a counter click re-renders only the counter components (frames per tab per second at
20 clicks/s: 63.7 before, 33.3 after).

Brotli level on the home page, 500 tabs, 20 shared clicks per second:

| | Memory per tab | Bytes on the wire per tab per second | CPU |
|---|---|---|---|
| Level 4 | 642 KB | 0.70 KB | 58% |
| Level 1 | 124 KB | 10.04 KB | 56% |
| No Brotli | 74 KB | 23.81 KB | 35% |

### Two workers

| | 0.13.0 | 0.13.1 |
|---|---|---|
| `/docs/signals`, 12 s | 8,189 OK, then 113,732 errors (context directory full) | 16,017 OK (1,327 req/s), 0 errors |
| `/docs/api`, 10 s | 459 req/s | 463 req/s |

### Notes

- The CPU figures are for this desktop core; scale them with `calibrate.php` (see README.md).
  The memory figures carry over.
- The memory per open tab is measured about 1.5 s after the tabs open, on streams that carry
  little traffic. A stream's Brotli encoder grows with what it compresses and levels off near
  9 MB at level 4 (575 KB at level 1) after about 8 MB of traffic (PERFORMANCE.md), so busy
  streams such as a live game cost more.
- One 0.13.0 run lost its server at about 980 open docs tabs; a rerun did not reproduce it.
