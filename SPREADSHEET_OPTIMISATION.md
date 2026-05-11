# SpreadsheetExample Performance Optimisation

Summary of the changes made to the `SpreadsheetExample` and the php-via framework to reduce SSE update payload size and increase throughput.

## Results

All numbers measured on PHP 8.4.20, opcache-tuned (no JIT), `--actions=2000 --concurrency=100`.

### Step-by-step improvements

| Step | Change | Throughput (warm) | Payload | Function calls/render |
|---|---|---|---|---|
| 0 — original | Twig, no cache, `block: 'demo'` | 162 req/s | 179 KB | ~2.2M |
| 1 — Twig file cache | `withTwigCacheDir()` | ~215 req/s (+33%) | 179 KB | ~2.2M |
| 2 — Partial block | `block: 'spreadsheet_update'` | ~215 req/s | **~30 KB** | ~2.2M |
| 3 — Event delegation | 200 per-cell handlers → 1 on `<tbody>` | ~215 req/s | **21 KB** | **~1.1M** |
| ~~4 — HTML minification~~ | ~~`preg_replace` in `ViewRenderer`~~ | ~~**225 req/s**~~ | ~~**21 KB**~~ | ~~~1.1M~~ |
| 5 — Raw PHP renderer | `SpreadsheetRawExample`, no Twig on SSE | **805–942 req/s** | 26 KB | **237K** |

> Steps 1–3 compound and are all currently committed. Step 4 (HTML minification) was
> subsequently **reverted**: Brotli compression over the SSE stream makes whitespace
> removal redundant, and the two `preg_replace` calls added complexity for no net gain.
> Step 5 is an independent alternative that replaces Twig on the SSE hot path while
> keeping Twig for the initial page load.

### Throughput (req/s, warm pass)

| Step | Throughput | vs original |
|---|---|---|
| 0 — original | 162 req/s | baseline |
| 1+2+3 — all Twig optimisations (current state) | **~215 req/s** | **+33%** |
| 5 — raw PHP renderer | **805–942 req/s** | **+397–481%** |

> The step 5 range reflects different test parameters: 805 req/s was measured with
> `--actions=2000 --concurrency=100, opcache-tuned`; 942 req/s with
> `--actions=1000 --concurrency=50, no-opcache` (bench_opcache.php, May 2026).
> With `jit-tracing` the raw path reaches **1,072 req/s** under the same conditions.

### SSE payload size per action

| Step | Payload | vs original |
|---|---|---|
| 0 — original | 179 KB | baseline |
| 2 — partial block only | ~30 KB | −83% |
| 3+4 — event delegation + minification | **21 KB** | **−88% (8.5×)** |
| 5 — raw PHP (no minification pass) | 26 KB | −85% |

### Full-stack SPX profile (200 actions × 2 syncs = 400 renders)

**Twig path** — 449ms total, 2.25ms/action:

```
 Excl.    | Called   | Layer
----------+----------+----------------------------------------------
  87.9ms  |     402  | Twig block body (foreach rows/cols)           ← 88% of hot path
  26.0ms  |  275.9K  | CoreExtension::getAttribute  (cell hash lookups)
   9.3ms  |    8.8K  | array_intersect_key
   8.1ms  |     402  | ob_get_clean
   0.9ms  |     402  | syncScopedSignals (JSON signal patch)
   0.4ms  |     804  | flatToNested / queuePatch
   0.2ms  |     201  | syncLocally (scope registry walk + broker publish)
   ≪0.1ms |       —  | preg_replace minification, signal reads, SQLite
```

**Raw PHP path** — 120ms total, 0.60ms/action (**3.8× faster**):

```
 Excl.    | Called   | Layer
----------+----------+----------------------------------------------
  51.5ms  |     402  | view closure body (PHP string concat loop)    ← 73% of hot path
   3.6ms  |  85.6K   | htmlspecialchars
   1.7ms  |     926  | SQLite3Result::fetchArray
   1.0ms  |     402  | syncScopedSignals
   0.2ms  |     804  | queuePatch
   0.2ms  |     201  | syncLocally
   0.2ms  |     201  | ViewCache::invalidate
   0.1ms  |     201  | ScopeRegistry::getContextsByScope
```

The framework itself (signals, scope registry, patch queue, broadcast) adds **≤0.5ms/action**
regardless of renderer. Twig's template engine overhead (hash lookups, output buffering,
array ops) is what distinguishes the two paths.

---

## Step 1 — Twig file cache

**Problem:** Twig compiled every template from scratch on every request.

**Changes:**

`src/Config.php` — add cache configuration support:

```php
private string|false $twigCacheDir = false;

public function withTwigCacheDir(string $dir): self {
    $this->twigCacheDir = $dir;
    return $this;
}

public function getTwigCacheDir(): string|false {
    return $this->twigCacheDir;
}
```

`src/Core/Application.php` — pass cache dir to Twig `Environment`:

```php
$this->twig = new Environment($loader, [
    'cache' => $this->config->getTwigCacheDir(),
    'autoescape' => 'html',
    'strict_variables' => true,
]);
```

`website/app.php` — enable the cache:

```php
->withTwigCacheDir(sys_get_temp_dir() . '/php-via-twig-cache')
```

**Impact:** Eliminated Twig compilation cost on every request. +53% throughput on first bench run.

---

## Step 2 — Partial rendering with a dedicated update block

**Problem:** The `block: 'demo'` used for SSE updates included the entire `#spreadsheet` div:
- Static JS strings (`data-on:keydown`, `data-on:wheel`, `data-on:mousemove`, etc.)
- 200 per-cell `data-on:click` handlers containing the full action URL
- All non-dynamic markup

This sent ~179 KB per SSE event even though only the grid content changes.

**Changes:**

`website/templates/examples/spreadsheet.html.twig` — extract a narrow `spreadsheet_update`
block that contains only the dynamic DOM inside the static `#spreadsheet` shell:

```twig
{% block demo %}
<div id="spreadsheet"
     data-on:keydown="..."
     data-on:wheel="..."
     {# ... all static event handlers ... #}
>
  {% block spreadsheet_update %}
  <div id="ss-dynamic" style="display:contents">
    <div id="ss-toolbar">...</div>
    <table id="ss-grid-table">
      <thead id="ss-thead">...</thead>
      <tbody id="ss-tbody"
        data-on:click="const td = evt.target.closest('td.ss-cell:not(.ss-focused)');
                        if (!td) return;
                        ${{ shiftId }} = evt.shiftKey;
                        ${{ trId }} = +td.dataset.row;
                        ${{ tcId }} = +td.dataset.col;
                        @post('{{ focusCellUrl }}')"
        data-on:dblclick="evt.target.closest('td.ss-cell') && @post('{{ startEditUrl }}')"
      >
        {# rows and cells — each <td> carries data-row and data-col only #}
      </tbody>
    </table>
    <div id="ss-vthumb">...</div>
    <div id="ss-hthumb">...</div>
  </div>
  {% endblock %}
</div>
{% endblock %}
```

`website/src/Examples/SpreadsheetExample.php` — point `ctx->view()` at the new block:

```php
$ctx->view(function (...) { ... }, block: 'spreadsheet_update', cacheUpdates: false);
```

**Impact:** SSE payload drops from 179 KB to ~21 KB (8.5×).

---

## Step 3 — Event delegation (eliminate per-cell handlers)

**Problem:** Each of the 200 rendered `<td>` cells had its own `data-on:click` handler
embedding the full action URL. This added ~670 bytes × 200 = 134 KB of repeated attribute
markup to every SSE update.

**Change:** Replace per-cell handlers with a single delegated handler on `<tbody>`:

```twig
{# Before — repeated on every <td> #}
<td data-on:click="@post('{{ focusCellUrl }}&r={{ absRow }}&c={{ absCol }}')">

{# After — one handler on <tbody>, reads row/col from data attributes #}
<tbody
  data-on:click="const td = evt.target.closest('td.ss-cell:not(.ss-focused)');
                 if (!td) return;
                 ${{ trId }} = +td.dataset.row;
                 ${{ tcId }} = +td.dataset.col;
                 @post('{{ focusCellUrl }}')"
>
  <td data-row="{{ absRow }}" data-col="{{ absCol }}">
```

**Impact:** Eliminates 134 KB of repeated attribute text. Also halves the number of Twig
function calls per render (2.2M → 1.1M) because `EscaperRuntime::escape` was running once
per handler per cell.

---

## Step 4 — HTML minification for block renders

**Problem:** Twig indentation produces large runs of inter-tag whitespace in the rendered
HTML (a 21 KB logical output inflated by ~30% of pure whitespace bytes).

**Change:** `src/Rendering/ViewRenderer.php` — apply two `preg_replace` passes to block
renders only (not full-page initial renders):

```php
if ($block !== null) {
    $twigTemplate = $this->twig->load($template);
    $html = $twigTemplate->renderBlock($block, $data);
    $html = (string) preg_replace('/>\s+</', '><', $html);
    return (string) preg_replace('/(\s)\s+/', '$1', $html);
}
return $this->twig->render($template, $data);
```

The two passes:
1. `/>\s+</` → `><` — collapse whitespace between tags
2. `/(\s)\s+/` → `$1` — collapse multiple spaces/newlines inside attribute values

**Impact:** Removes ~30% of SSE payload bytes. Measured cost: negligible (not visible in
SPX profile).

---

## End-to-end profiling scripts

`/tmp/spx_e2e.php` — Twig renderer (full stack with framework overhead):

```bash
VIA_TEST_MODE=1 SPX_ENABLED=1 SPX_AUTO_START=1 SPX_REPORT=fp \
  SPX_BUILTINS=1 SPX_METRICS=wt SPX_FP_LIMIT=30 SPX_FP_COLOR=0 \
  php -d extension=/tmp/spx.so /tmp/spx_e2e.php
```

`/tmp/spx_e2e_raw.php` — raw PHP string builder renderer (same framework path):

```bash
VIA_TEST_MODE=1 SPX_ENABLED=1 SPX_AUTO_START=1 SPX_REPORT=fp \
  SPX_BUILTINS=1 SPX_METRICS=wt SPX_FP_LIMIT=30 SPX_FP_COLOR=0 \
  php -d extension=/tmp/spx.so /tmp/spx_e2e_raw.php
```

Both cover: signal reads → SQLite → signal updates → `ctx->sync()` → view closure →
`queuePatch` → `syncSignals` → `app->broadcast()` → `syncLocally`.

---

## Step 5 — Raw PHP renderer (`SpreadsheetRawExample`)

`website/src/Examples/SpreadsheetRawExample.php` implements the same spreadsheet feature
but replaces Twig with raw PHP string concatenation on the SSE hot path. The initial page
load still uses a Twig template (`spreadsheet-raw.html.twig`) for the shell; only the
repeated SSE updates (every keypress, scroll, cell click) bypass Twig.

**Strategy:** the `ctx->view()` callable checks `$isUpdate`:

```php
$c->view(function (bool $isUpdate) use (...): string {
    // ... build $d array (signal reads, SQLite, cursor state) ...

    if ($isUpdate) {
        return self::renderDynamic($d);  // raw PHP — no Twig
    }

    return $c->render('examples/spreadsheet-raw.html.twig', array_merge($d, [
        'initialDynamic' => self::renderDynamic($d),  // inline on first load too
    ]));
}, cacheUpdates: false);
```

`renderDynamic()` is a private static method that builds the exact same HTML as
`{% block spreadsheet_update %}` but using PHP string concatenation and explicit
`htmlspecialchars()` calls.

**Trade-offs vs Twig:**

| Concern | Twig | Raw PHP |
|---|---|---|
| Throughput | 225 req/s | **805 req/s** |
| Payload size | 21 KB | 26 KB (5 KB larger — no minification pass) |
| Maintainability | Template changes in one place | Logic duplicated in PHP |
| XSS safety | Auto-escaped by Twig | Manual `htmlspecialchars()` — easy to miss |
| Profiler call count | 2.3M calls | **237K calls** (10× fewer) |

---

## What's left

| Opportunity | Expected gain | Complexity |
|---|---|---|
| Pre-flatten cell data before Twig (`$cells[$r][$c]`) | Minor (fewer hash lookups) | Low |
| Add minification pass to raw PHP renderer | Save ~5 KB per action | Low |
| Replace `CoreExtension::getAttribute` with direct array access in Twig | Needs Twig rewrite | High |
| `ROUTE` scope with `cacheUpdates: true` | Only helps if view is user-agnostic | N/A here |

At 0.60ms/action raw PHP (1,667 req/s single-core theoretical) and 805 req/s actual bench
(48% efficiency), the remaining bottleneck is network/HTTP overhead and the two-sync-per-action
cost from `ctx->sync() + app->broadcast()`. The framework layer itself is negligible (~0.2ms/action).
