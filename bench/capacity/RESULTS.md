# Capacity results: 0.13.0 against 0.13.1

Measured 2026-10-02 with `capacity.php` against the website (`website/app.php`, production mode,
Brotli on).

- **Server:** two physical P-cores of an Intel i5-13500 (`taskset -c 2,4`), one worker,
  `reactor_num` 2, PHP 8.5.11, OpenSwoole 26.2.0, opcache on, Brotli level 4 unless noted.
- **Harness:** cores 6 to 11. Each scenario starts a fresh server.
- **Calibration reference** (`calibrate.php` on core 2): `brotli 7 ms, php 62 ms`.

0.13.0 is tag v0.13.0. 0.13.1 is the release branch with the teardown, Brotli encoder, context
directory and SSE fixes, plus the website's static pages and widget scopes.

## Page views without an SSE stream

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

## Open tabs

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

## Actions on the home page

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

## Two workers

| | 0.13.0 | 0.13.1 |
|---|---|---|
| `/docs/signals`, 12 s | 8,189 OK, then 113,732 errors (context directory full) | 16,017 OK (1,327 req/s), 0 errors |
| `/docs/api`, 10 s | 459 req/s | 463 req/s |

## Notes

- The CPU figures are for this desktop core; scale them with `calibrate.php` (see README.md).
  The memory figures carry over.
- The memory per open tab is measured about 1.5 s after the tabs open, on streams that carry
  little traffic. A stream's Brotli encoder grows with what it compresses and levels off near
  9 MB at level 4 (575 KB at level 1) after about 8 MB of traffic (PERFORMANCE.md), so busy
  streams such as a live game cost more.
- One 0.13.0 run lost its server at about 980 open docs tabs; a rerun did not reproduce it.
