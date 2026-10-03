#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * F4: what the SharedSignalStore read-through costs on the broadcast path.
 *
 * In-process, the way tests/Feature/MultiWorkerStateTest.php stands Via instances in for workers.
 * Per mode, N contexts on /bench render a view that reads S scoped signals. The same R broadcasts
 * are timed on a Via without a store (single-worker baseline) and on one backed by a real
 * OpenSwoole\Table SharedSignalStore, interleaved so host noise hits both. With --writer=remote the
 * store instance's values are written by a second Via sharing the store, standing in for another
 * worker, and the fan-out arrives through the SwooleBroker receive path (handlePipeMessage); with
 * --writer=local it is a plain broadcast(). Every frame is compared with the baseline to catch
 * stale reads. Store reads per broadcast are counted afterwards by swapping the store's tables for
 * a counting subclass.
 *
 * Modes: tab   = TAB-primary contexts that addScope() the route (one render per context)
 *        route = ROUTE-primary contexts (one cached render, but each context still sends signals)
 *
 * Usage: php bench/contention/shared_read.php [--contexts=2000] [--signals=5] [--broadcasts=20]
 *        [--warmup=3] [--modes=tab,route] [--array-items=20] [--view-reads=1]
 *        [--count-broadcasts=3] [--micro-iters=200000] [--writer=remote|local] [--timeout=90]
 *
 * One JSON line on stdout, progress on stderr.
 */

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Via;
use OpenSwoole\Table;

// No server is started; this makes PatchManager queue into arrays instead of coroutine Channels.
putenv('VIA_TEST_MODE=1');
ini_set('memory_limit', '-1');

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

const BENCH = 'shared_read';
const ROUTE = '/bench';

const DEFAULTS = [
    'contexts' => 2000,
    'signals' => 5,
    'broadcasts' => 20,
    'warmup' => 3,
    'modes' => 'tab,route',
    'array-items' => 20,
    'view-reads' => 1,
    'count-broadcasts' => 3,
    'micro-iters' => 200000,
    'writer' => 'remote',
    'timeout' => 90,
];

/** Counts every operation on the table it replaces, so reads can be counted without touching src/. */
final class CountingTable extends Table {
    /** @var array<string, int> */
    public array $ops = ['get_row' => 0, 'get_column' => 0, 'exists' => 0, 'set' => 0, 'incr' => 0, 'decr' => 0, 'del' => 0, 'iterate' => 0];

    /** @return array<string, mixed>|bool|float|int|string */
    public function get(string $key, ?string $column = null): array|bool|float|int|string {
        if ($column === null || $column === '') {
            ++$this->ops['get_row'];

            return parent::get($key);
        }
        ++$this->ops['get_column'];

        return parent::get($key, $column);
    }

    public function exists(string $key): bool {
        ++$this->ops['exists'];

        return parent::exists($key);
    }

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value): bool {
        ++$this->ops['set'];

        return parent::set($key, $value);
    }

    public function incr(string $key, string $column, int $incrBy = 1): int {
        ++$this->ops['incr'];

        return parent::incr($key, $column, $incrBy);
    }

    public function decr(string $key, string $column, int $decrBy = 1): int {
        ++$this->ops['decr'];

        return parent::decr($key, $column, $decrBy);
    }

    public function del(string $key): bool {
        ++$this->ops['del'];

        return parent::del($key);
    }

    /** @return null|array<string, mixed> */
    public function current(): ?array {
        ++$this->ops['iterate'];

        return parent::current();
    }

    public function reset(): void {
        $this->ops = array_fill_keys(array_keys($this->ops), 0);
    }
}

final class RenderCounter {
    public int $renders = 0;
}

function logLine(string $msg): void {
    fwrite(STDERR, '[' . BENCH . '] ' . $msg . "\n");
}

/**
 * @param list<string> $argv
 *
 * @return array<string, int|string>
 */
function parseArgs(array $argv): array {
    $p = DEFAULTS;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDERR, "usage: php bench/contention/shared_read.php [--key=value ...]\ndefaults: " . json_encode(DEFAULTS) . "\n");

            exit(0);
        }
        if (!preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) || !array_key_exists($m[1], DEFAULTS)) {
            fwrite(STDERR, "unknown argument: {$arg}\n");

            exit(64);
        }
        $p[$m[1]] = is_int(DEFAULTS[$m[1]]) ? (int) $m[2] : $m[2];
    }

    foreach (['contexts', 'signals', 'broadcasts', 'array-items', 'view-reads', 'micro-iters', 'timeout'] as $k) {
        if ($p[$k] < 1) {
            fwrite(STDERR, "--{$k} must be >= 1\n");

            exit(64);
        }
    }
    foreach (['warmup', 'count-broadcasts'] as $k) {
        if ($p[$k] < 0) {
            fwrite(STDERR, "--{$k} must be >= 0\n");

            exit(64);
        }
    }
    $modes = array_values(array_filter(array_map('trim', explode(',', (string) $p['modes']))));
    if ($modes === [] || array_diff($modes, ['tab', 'route']) !== []) {
        fwrite(STDERR, "--modes must be a comma list of tab,route\n");

        exit(64);
    }
    $p['modes'] = implode(',', $modes);
    if (!in_array($p['writer'], ['remote', 'local'], true)) {
        fwrite(STDERR, "--writer must be remote or local\n");

        exit(64);
    }

    return $p;
}

/** @return list<string> signal kinds by slot; slot 0 is always the array signal */
function signalKinds(int $count): array {
    $kinds = [];
    for ($i = 0; $i < $count; ++$i) {
        $kinds[] = match ($i % 4) {
            0 => 'array',
            2 => 'string',
            default => 'int',
        };
    }

    return $kinds;
}

function valueFor(string $kind, int $slot, int $tick, int $arrayItems): mixed {
    return match ($kind) {
        'int' => $slot * 1000 + $tick,
        'string' => sprintf('slot %d status at tick %d', $slot, $tick),
        'array' => array_map(
            static fn (int $k): array => ['id' => $k, 'label' => 'item-' . (($k + $tick) % $arrayItems), 'score' => ($k * 7 + $tick + $slot) % 100],
            range(0, $arrayItems - 1),
        ),
        default => throw new LogicException("unknown signal kind {$kind}"),
    };
}

function newVia(): Via {
    return new Via((new Config())->withLogLevel('error'));
}

/**
 * @param list<string>         $kinds
 * @param array<string, mixed> $p
 */
function defineRoute(Via $app, string $mode, array $kinds, array $p, RenderCounter $counter): void {
    $viewReads = (int) $p['view-reads'];
    $arrayItems = (int) $p['array-items'];

    $app->page(ROUTE, function (Context $c) use ($mode, $kinds, $viewReads, $arrayItems, $counter): void {
        if ($mode === 'route') {
            $c->scope(Scope::ROUTE);
        } else {
            $c->addScope(Scope::routeScope(ROUTE));
        }

        /** @var array<int, Signal> $signals */
        $signals = [];
        foreach ($kinds as $slot => $kind) {
            $signals[$slot] = $c->signal(valueFor($kind, $slot, 0, $arrayItems), 's' . $slot, Scope::ROUTE, autoBroadcast: false);
        }
        // A per-viewer TAB signal is what makes a TAB-primary view legitimately per context.
        $me = $mode === 'tab' ? $c->signal('viewer ' . $c->getId(), 'me') : null;

        $c->view(function () use ($signals, $kinds, $me, $viewReads, $counter): string {
            ++$counter->renders;
            $html = '<div id="bench">';
            if ($me !== null) {
                $html .= '<p>' . htmlspecialchars($me->string()) . '</p>';
            }
            foreach ($signals as $slot => $signal) {
                for ($r = 0; $r < $viewReads; ++$r) {
                    $html .= match ($kinds[$slot]) {
                        'int' => '<b>' . $signal->int() . '</b>',
                        'string' => '<i>' . htmlspecialchars($signal->string()) . '</i>',
                        'array' => '<ul>' . implode('', array_map(
                            static fn (array $row): string => '<li data-id="' . $row['id'] . '">' . htmlspecialchars($row['label']) . ' ' . $row['score'] . '</li>',
                            $signal->array(),
                        )) . '</ul>',
                        default => throw new LogicException("unknown signal kind {$kinds[$slot]}"),
                    };
                }
            }

            return $html . '</div>';
        }, shareRender: $mode === 'route');
    });
}

/**
 * Mount the route under $id as a page load plus SSE connect would.
 */
function mount(Via $app, string $id): Context {
    $ctx = new Context($id, ROUTE, $app, null, 'bench-session');
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($id, 'bench-session');
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()[ROUTE], $ctx, []);
    $ctx->renderView(false);
    $ctx->sync();
    drain([$ctx]);

    return $ctx;
}

/**
 * Consume every queued patch as the SSE loop would (confirm after "delivery").
 *
 * @param array<int, Context> $contexts
 *
 * @return array{0: int, 1: array<int, string>} patch count, per-context digest of the frames
 */
function drain(array $contexts): array {
    $patches = 0;
    $digests = [];
    foreach ($contexts as $k => $ctx) {
        $frames = '';
        while (($patch = $ctx->getPatch()) !== null) {
            ++$patches;
            if (isset($patch['confirm'])) {
                ($patch['confirm'])();
            }
            $content = $patch['content'];
            $frames .= $patch['type'] . ':' . (is_string($content) ? $content : json_encode($content)) . "\n";
        }
        $digests[$k] = md5($frames);
    }

    return [$patches, $digests];
}

/**
 * @param list<string> $kinds
 */
function writeTick(Context $writer, array $kinds, int $tick, int $arrayItems): void {
    foreach ($kinds as $slot => $kind) {
        $writer->getSignal('s' . $slot)?->setValue(valueFor($kind, $slot, $tick, $arrayItems));
    }
}

/**
 * How a fan-out reaches the reader. A remote write arrives as a SwooleBroker pipe message in
 * production, so use that receive path when it exists; a local write is a plain broadcast().
 *
 * @return array{0: string, 1: Closure(Via, string): void}
 */
function deliverer(string $writer): array {
    if ($writer === 'remote' && method_exists(Via::class, 'handlePipeMessage')) {
        $method = new ReflectionMethod(Via::class, 'handlePipeMessage');
        if ($method->getNumberOfParameters() === 2) {
            return ['pipe_message', static function (Via $app, string $scope) use ($method): void {
                $method->invoke($app, 1, json_encode(['scope' => $scope, 'nodeId' => 'bench-remote-worker']));
            }];
        }
    }

    return ['broadcast', static function (Via $app, string $scope): void {
        $app->broadcast($scope);
    }];
}

function cpuUs(): int {
    $ru = getrusage();

    return (int) $ru['ru_utime.tv_sec'] * 1_000_000 + (int) $ru['ru_utime.tv_usec']
        + (int) $ru['ru_stime.tv_sec'] * 1_000_000 + (int) $ru['ru_stime.tv_usec'];
}

/**
 * @param list<float> $values
 *
 * @return array{median: float, mean: float, min: float, p90: float, max: float}
 */
function stats(array $values): array {
    sort($values);
    $n = count($values);
    $pick = static fn (float $q): float => $values[(int) min($n - 1, max(0, (int) ceil($q * $n) - 1))];
    $median = $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;

    return [
        'median' => round($median, 4),
        'mean' => round(array_sum($values) / $n, 4),
        'min' => round($values[0], 4),
        'p90' => round($pick(0.9), 4),
        'max' => round($values[$n - 1], 4),
    ];
}

/**
 * Replace every OpenSwoole\Table the store holds with a counting copy carrying the same rows.
 *
 * @return list<CountingTable>|string the installed tables, or why counting is unavailable
 */
function installCountingTables(SharedSignalStore $store, int $stringBytes): array|string {
    $installed = [];
    foreach ((new ReflectionObject($store))->getProperties() as $prop) {
        if (!$prop->isInitialized($store)) {
            continue;
        }
        $orig = $prop->getValue($store);
        if (!$orig instanceof Table || $orig instanceof CountingTable) {
            continue;
        }

        // A row set from [] comes back with every column at its zero value, which reveals the schema.
        $probe = '__bench_schema_probe__';
        if (!$orig->set($probe, [])) {
            return "could not probe schema of {$prop->getName()}";
        }
        $schema = $orig->get($probe);
        $orig->del($probe);
        if (!is_array($schema)) {
            return "could not read schema of {$prop->getName()}";
        }

        $copy = new CountingTable((int) $orig->size);
        foreach ($schema as $column => $zero) {
            match (true) {
                is_int($zero) => $copy->column((string) $column, Table::TYPE_INT, 8),
                is_float($zero) => $copy->column((string) $column, Table::TYPE_FLOAT, 8),
                default => $copy->column((string) $column, Table::TYPE_STRING, $stringBytes),
            };
        }
        $copy->create();
        foreach ($orig as $key => $row) {
            $copy->set((string) $key, $row);
        }
        $copy->reset();

        try {
            $prop->setValue($store, $copy);
        } catch (Throwable $e) {
            return "could not swap {$prop->getName()}: " . $e->getMessage();
        }
        $installed[] = $copy;
    }

    return $installed === [] ? 'store holds no OpenSwoole\Table property' : $installed;
}

/**
 * @param list<string>         $kinds
 * @param array<string, mixed> $p
 *
 * @return array<string, mixed>
 */
function runMode(string $mode, array $kinds, array $p, Config $cfg, Closure $deliver): array {
    $n = (int) $p['contexts'];
    $s = count($kinds);
    $arrayItems = (int) $p['array-items'];
    $scope = Scope::routeScope(ROUTE);

    $baseCounter = new RenderCounter();
    $storeCounter = new RenderCounter();

    $base = newVia();
    defineRoute($base, $mode, $kinds, $p, $baseCounter);

    $store = new SharedSignalStore(storeSize($cfg)['rows'], storeSize($cfg)['bytes']);
    $shared = newVia();
    $shared->setSharedSignalStore($store);
    defineRoute($shared, $mode, $kinds, $p, $storeCounter);

    $writerApp = null;
    $remoteWriter = null;
    if ($p['writer'] === 'remote') {
        $writerApp = newVia();
        $writerApp->setSharedSignalStore($store);
        defineRoute($writerApp, $mode, $kinds, $p, new RenderCounter());
        $remoteWriter = mount($writerApp, ROUTE . '_/writer');
    }

    $t0 = hrtime(true);
    $baseCtx = [];
    $sharedCtx = [];
    for ($i = 0; $i < $n; ++$i) {
        $id = sprintf('%s_/%06d', ROUTE, $i);
        $baseCtx[$i] = mount($base, $id);
        $sharedCtx[$i] = mount($shared, $id);
    }
    logLine(sprintf('%s: mounted 2 x %d contexts in %.2f s', $mode, $n, (hrtime(true) - $t0) / 1e9));

    $baseWriter = $baseCtx[0];
    $sharedWriter = $remoteWriter ?? $sharedCtx[0];

    $runs = [
        'no_store' => ['app' => $base, 'ctx' => $baseCtx, 'counter' => $baseCounter],
        'store' => ['app' => $shared, 'ctx' => $sharedCtx, 'counter' => $storeCounter],
    ];
    $wall = ['no_store' => [], 'store' => []];
    $cpu = ['no_store' => [], 'store' => []];
    $renders = ['no_store' => [], 'store' => []];
    $patches = ['no_store' => [], 'store' => []];
    $mismatched = 0;
    $unchanged = 0;
    $prevBase = null;

    $warmup = (int) $p['warmup'];
    $total = $warmup + (int) $p['broadcasts'];
    $tick = 0;
    for ($it = 0; $it < $total; ++$it) {
        ++$tick;
        writeTick($baseWriter, $kinds, $tick, $arrayItems);
        writeTick($sharedWriter, $kinds, $tick, $arrayItems);

        $digests = [];
        foreach ($it % 2 === 0 ? ['no_store', 'store'] : ['store', 'no_store'] as $which) {
            $run = $runs[$which];
            gc_collect_cycles();
            $r0 = $run['counter']->renders;
            $c0 = cpuUs();
            $w0 = hrtime(true);
            $deliver($run['app'], $scope);
            $w1 = hrtime(true);
            $c1 = cpuUs();
            [$np, $digests[$which]] = drain($run['ctx']);
            if ($it >= $warmup) {
                $wall[$which][] = ($w1 - $w0) / 1e6;
                $cpu[$which][] = ($c1 - $c0) / 1e3;
                $renders[$which][] = $run['counter']->renders - $r0;
                $patches[$which][] = $np;
            }
        }

        // The baseline is fresh by construction, so any differing frame is a stale shared read.
        $mismatched += count(array_diff_assoc($digests['no_store'], $digests['store']));
        if ($prevBase !== null) {
            $unchanged += count(array_intersect_assoc($prevBase, $digests['no_store']));
        }
        $prevBase = $digests['no_store'];
    }

    $summary = [];
    foreach (['no_store', 'store'] as $which) {
        $w = stats($wall[$which]);
        $summary[$which] = [
            'ms_per_broadcast' => $w,
            'cpu_ms_per_broadcast_mean' => round(array_sum($cpu[$which]) / count($cpu[$which]), 4),
            'us_per_context_median' => round($w['median'] * 1000 / $n, 4),
            'renders_per_broadcast' => round(array_sum($renders[$which]) / count($renders[$which]), 2),
            'patches_per_broadcast' => round(array_sum($patches[$which]) / count($patches[$which]), 2),
        ];
    }
    logLine(sprintf(
        '%s: no_store %.3f ms, store %.3f ms per broadcast (median), mismatched frames %d',
        $mode,
        $summary['no_store']['ms_per_broadcast']['median'],
        $summary['store']['ms_per_broadcast']['median'],
        $mismatched,
    ));

    $overheadMs = $summary['store']['ms_per_broadcast']['median'] - $summary['no_store']['ms_per_broadcast']['median'];
    $overheadCpuMs = $summary['store']['cpu_ms_per_broadcast_mean'] - $summary['no_store']['cpu_ms_per_broadcast_mean'];

    // Base code reads the store once per accessor call: view reads per render, plus S per context
    // for the scoped-signals patch.
    $viewRenders = $mode === 'tab' ? $n : 1;
    $readThroughEstimate = $s * (int) $p['view-reads'] * $viewRenders + $s * $n;

    $counting = null;
    $countError = null;
    $countBroadcasts = (int) $p['count-broadcasts'];
    if ($countBroadcasts > 0) {
        $tables = installCountingTables($store, storeSize($cfg)['bytes']);
        if (is_string($tables)) {
            $countError = $tables;
            logLine("{$mode}: read counting unavailable: {$tables}");
        } else {
            $totals = array_fill_keys(array_keys($tables[0]->ops), 0);
            $countMismatched = 0;
            for ($i = 0; $i < $countBroadcasts; ++$i) {
                ++$tick;
                writeTick($baseWriter, $kinds, $tick, $arrayItems);
                writeTick($sharedWriter, $kinds, $tick, $arrayItems);
                foreach ($tables as $t) {
                    $t->reset();
                }
                $deliver($shared, $scope);
                foreach ($tables as $t) {
                    foreach ($t->ops as $op => $c) {
                        $totals[$op] += $c;
                    }
                }
                $deliver($base, $scope);
                [, $dStore] = drain($sharedCtx);
                [, $dBase] = drain($baseCtx);
                $countMismatched += count(array_diff_assoc($dBase, $dStore));
            }
            $mismatched += $countMismatched;
            $counting = array_map(static fn (int $c): float => round($c / $countBroadcasts, 2), $totals);
        }
    }

    $reads = $counting === null ? null : $counting['get_row'] + $counting['get_column'] + $counting['exists'] + $counting['iterate'];

    // Every context must have received at least one frame, or the timings measure a no-op.
    $fanoutOk = $summary['no_store']['patches_per_broadcast'] >= $n
        && $summary['store']['patches_per_broadcast'] >= $n
        && $summary['store']['renders_per_broadcast'] === $summary['no_store']['renders_per_broadcast']
        && $summary['store']['renders_per_broadcast'] >= 1;
    if (!$fanoutOk) {
        logLine("{$mode}: WARNING fan-out did not reach every context, timings are not comparable");
    }

    $result = [
        'fanout_ok' => $fanoutOk,
        'no_store' => $summary['no_store'],
        'store' => $summary['store'],
        'overhead_ms_per_broadcast_median' => round($overheadMs, 4),
        'overhead_pct_median' => round(100 * $overheadMs / max(1e-9, $summary['no_store']['ms_per_broadcast']['median']), 2),
        'overhead_us_per_context_median' => round($overheadMs * 1000 / $n, 4),
        'overhead_cpu_ms_per_broadcast_mean' => round($overheadCpuMs, 4),
        'mismatched_frames' => $mismatched,
        'baseline_frames_unchanged_between_ticks' => $unchanged,
        'store_reads_per_broadcast' => $reads,
        'store_reads_per_context' => $reads === null ? null : round($reads / $n, 3),
        'store_ops_per_broadcast' => $counting,
        'store_read_count_error' => $countError,
        'readthrough_reads_estimate' => $readThroughEstimate,
        'ns_per_store_read_est' => $reads ? round($overheadMs * 1e6 / $reads, 1) : null,
    ];

    unset($runs, $baseCtx, $sharedCtx, $base, $shared, $writerApp, $remoteWriter, $store);
    gc_collect_cycles();

    return $result;
}

/**
 * The scoped signal table size: from the getters on revisions before 0.14, from the snapshot new Via() takes after.
 *
 * @return array{rows: int, bytes: int}
 */
function storeSize(Config $cfg): array {
    if (method_exists($cfg, 'getScopedSignalTableRows')) {
        return ['rows' => $cfg->getScopedSignalTableRows(), 'bytes' => $cfg->getScopedSignalTableValueBytes()];
    }
    $settings = (clone $cfg)->freeze();

    return ['rows' => $settings->scopedSignalTableRows, 'bytes' => $settings->scopedSignalTableValueBytes];
}

/**
 * Raw SharedSignalStore::get() cost per value kind, for reading the overhead numbers.
 *
 * @param array<string, mixed> $p
 *
 * @return array<string, float|int>
 */
function micro(array $p, Config $cfg): array {
    $iters = (int) $p['micro-iters'];
    $store = new SharedSignalStore(64, storeSize($cfg)['bytes']);
    $values = [
        'int' => valueFor('int', 1, 1, (int) $p['array-items']),
        'string' => valueFor('string', 2, 1, (int) $p['array-items']),
        'array' => valueFor('array', 0, 1, (int) $p['array-items']),
    ];
    $out = ['array_serialized_bytes' => strlen(serialize($values['array']))];
    foreach ($values as $kind => $value) {
        $id = 'micro_' . $kind;
        $store->set($id, $value);
        $sink = null;
        $t0 = hrtime(true);
        for ($i = 0; $i < $iters; ++$i) {
            $sink = $store->get($id);
        }
        $out["store_get_ns_{$kind}"] = round((hrtime(true) - $t0) / $iters, 1);
        if ($sink !== $value) {
            throw new RuntimeException("micro: {$kind} value did not round-trip");
        }
    }

    return $out;
}

/**
 * Class of every table-like property a store holds, to show it is not an in-memory fallback.
 *
 * @return array<string, string>
 */
function storeBackend(Config $cfg): array {
    $store = new SharedSignalStore(8, storeSize($cfg)['bytes']);
    $out = [];
    foreach ((new ReflectionObject($store))->getProperties() as $prop) {
        if ($prop->isInitialized($store) && is_object($value = $prop->getValue($store))) {
            $out[$prop->getName()] = $value::class;
        }
    }

    return $out;
}

$p = parseArgs($_SERVER['argv'] ?? []);
$timeout = (int) $p['timeout'];
$startedAt = hrtime(true);

if (function_exists('pcntl_alarm')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use ($p, $timeout): void {
        fwrite(STDERR, '[' . BENCH . "] watchdog: exceeded {$timeout} s, aborting\n");
        echo json_encode(['bench' => BENCH, 'error' => "watchdog timeout after {$timeout} s", 'params' => $p]) . "\n";

        exit(2);
    });
    pcntl_alarm($timeout);
} else {
    set_time_limit($timeout);
}

$cfg = new Config();
$kinds = signalKinds((int) $p['signals']);
[$delivery, $deliver] = deliverer((string) $p['writer']);
$gitHead = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null'));
$srcDirty = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain -- src 2>/dev/null')) !== '';

logLine(sprintf('contexts=%d signals=%d (%s) broadcasts=%d modes=%s writer=%s delivery=%s', $p['contexts'], count($kinds), implode(',', $kinds), $p['broadcasts'], $p['modes'], $p['writer'], $delivery));

$result = [
    'bench' => BENCH,
    'params' => $p,
    'env' => [
        'git_head' => $gitHead,
        'src_dirty' => $srcDirty,
        'php' => PHP_VERSION,
        'openswoole' => phpversion('openswoole') ?: null,
        'opcache_cli' => (bool) ini_get('opcache.enable_cli'),
        'jit' => (string) ini_get('opcache.jit'),
        'store_backend' => storeBackend($cfg),
        'delivery' => $delivery,
        'store_rows' => storeSize($cfg)['rows'],
        'store_value_bytes' => storeSize($cfg)['bytes'],
    ],
    'signal_kinds' => $kinds,
    'micro' => micro($p, $cfg),
];

$headline = [];
foreach (explode(',', (string) $p['modes']) as $mode) {
    $r = runMode($mode, $kinds, $p, $cfg, $deliver);
    $result[$mode] = $r;
    $headline["{$mode}_no_store_ms_median"] = $r['no_store']['ms_per_broadcast']['median'];
    $headline["{$mode}_store_ms_median"] = $r['store']['ms_per_broadcast']['median'];
    $headline["{$mode}_overhead_ms_median"] = $r['overhead_ms_per_broadcast_median'];
    $headline["{$mode}_overhead_pct_median"] = $r['overhead_pct_median'];
    $headline["{$mode}_store_reads_per_broadcast"] = $r['store_reads_per_broadcast'];
    $headline["{$mode}_mismatched_frames"] = $r['mismatched_frames'];
}
$result['headline'] = $headline;
$result['wall_s'] = round((hrtime(true) - $startedAt) / 1e9, 2);
$result['peak_mem_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);

if (function_exists('pcntl_alarm')) {
    pcntl_alarm(0);
}

logLine(sprintf('done in %.2f s', $result['wall_s']));
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
