<?php

declare(strict_types=1);

/*
 * F3: cross-process ticket lock contention (SharedTable::mutate, SharedSignalStore::mutate).
 *
 * The shared tables are allocated in the parent before forking, as Via::start() does. W forked
 * workers each run co::run with C coroutines; every coroutine mutates ONE hot key through the
 * public API (Via::mutateGlobalState() or Signal::mutate() on a ROUTE-scoped signal), released
 * together from a start barrier. Each worker performs a fixed number of mutations split across
 * its coroutines, so the total work is identical for every C and only the waiting changes.
 *
 * Usage:
 *   php bench/contention/lock_contention.php [--workers=4] [--coroutines=1,8,32] [--ops=20000]
 *       [--modes=global,signal] [--value=map|int] [--hold-us=0] [--reps=1] [--timeout=60]
 *
 *   --ops       mutations per worker (split across its coroutines)
 *   --value     map: small array rewritten each time (serialized path); int: integer via mutate
 *   --hold-us   busy work inside the mutator, i.e. time spent holding the lock
 *   --timeout   hard wall-clock budget for the whole run; children are killed when it expires
 *
 * Prints one JSON line to stdout; progress goes to stderr.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\State\SharedTable;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Table;

// No server is bound here, and the lock path must run exactly as in production.
putenv('VIA_TEST_MODE');

const HOT_KEY = 'hot';
const SIGNAL_NAME = 'hot';
const RUSAGE_SELF = 0;
const RUSAGE_CHILDREN = 1;

/** @return array<string, string> */
function parseArgs(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDERR, "see header of " . __FILE__ . "\n");

            exit(0);
        } else {
            fwrite(STDERR, "unknown argument: {$arg}\n");

            exit(2);
        }
    }

    return $out;
}

/** @return list<int> */
function intList(string $csv): array {
    return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $csv)), 'strlen')));
}

function cpuSeconds(int $who): float {
    $r = getrusage($who);

    return $r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec'] + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1e6;
}

function logLine(string $msg): void {
    fwrite(STDERR, '[lock_contention] ' . $msg . "\n");
}

/** @param list<int> $sorted */
function pct(array $sorted, float $p): float {
    $n = count($sorted);
    if ($n === 0) {
        return 0.0;
    }

    return $sorted[min($n - 1, (int) floor($p * ($n - 1) + 0.5))] / 1000;
}

function mountSignalWorker(SharedSignalStore $store, string $contextId, mixed $initial): Context {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->setSharedSignalStore($store);
    $app->page('/bench', function (Context $c) use ($initial): void {
        $c->scope(Scope::ROUTE);
        $v = $c->signal($initial, SIGNAL_NAME);
        $c->view(fn (): string => 'v=' . json_encode($v->getValue()));
    });

    $ctx = new Context($contextId, '/bench', $app, null, 'sess');
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/bench'], $ctx, []);

    return $ctx;
}

/**
 * One forked round: W workers x C coroutines on one hot key.
 *
 * @return array<string, mixed>
 */
function runOnce(string $mode, int $workers, int $coroutines, int $opsPerWorker, bool $isMap, int $holdUs, float $deadline, string $tmpDir, array &$livePids): array {
    $initial = $isMap ? ['n' => 0, 'w' => -1] : 0;

    // Barrier lives in its own table so its row locks never touch the row under test.
    $ctl = new Table(16);
    $ctl->column('v', Table::TYPE_INT, 8);
    $ctl->create();
    $ctl->set('ready', ['v' => 0]);
    $ctl->set('start', ['v' => 0]);

    $table = null;
    $store = null;
    $signalId = '';
    if ($mode === 'global') {
        $table = new SharedTable(maxRows: 64);
        $table->set(HOT_KEY, $initial);
    } else {
        $store = new SharedSignalStore(maxRows: 64);
        $signalId = mountSignalWorker($store, '/bench_/master', $initial)->getSignal(SIGNAL_NAME)->id();
    }

    // Children stop a little before the parent's kill deadline so a timed-out round still reports.
    $budget = max(0.5, $deadline - microtime(true));
    $childDeadlineNs = hrtime(true) + (int) (($budget - min(0.5, $budget / 4)) * 1e9);
    $cpuChildrenBefore = cpuSeconds(RUSAGE_CHILDREN);
    $pids = [];

    for ($w = 0; $w < $workers; ++$w) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            // A child must never fall back into the parent's sweep loop, and SIGKILL skips
            // destructors that would otherwise tear down the inherited shared tables.
            try {
                runWorker($w, $mode, $coroutines, $opsPerWorker, $isMap, $holdUs, $table, $store, $initial, $ctl, $childDeadlineNs, $tmpDir);
            } catch (Throwable $e) {
                fwrite(STDERR, "[lock_contention] worker {$w} failed: " . $e->getMessage() . "\n");
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }
        $pids[] = $pid;
        $livePids[$pid] = true;
    }

    $barrierOk = true;
    while ($ctl->get('ready', 'v') < $workers) {
        if (microtime(true) > $deadline) {
            $barrierOk = false;

            break;
        }
        usleep(100);
    }
    $ctl->set('start', ['v' => 1]);

    $timedOut = !$barrierOk;
    $remaining = array_flip($pids);
    while ($remaining !== []) {
        $done = pcntl_waitpid(-1, $status, WNOHANG);
        if ($done > 0) {
            unset($remaining[$done], $livePids[$done]);

            continue;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            foreach (array_keys($remaining) as $pid) {
                posix_kill($pid, SIGKILL);
            }
            foreach (array_keys($remaining) as $pid) {
                pcntl_waitpid($pid, $status);
                unset($livePids[$pid]);
            }

            break;
        }
        usleep(500);
    }
    $cpuChildren = cpuSeconds(RUSAGE_CHILDREN) - $cpuChildrenBefore;

    $final = $mode === 'global' ? $table->get(HOT_KEY) : $store->get($signalId);
    $finalN = $isMap ? (int) ($final['n'] ?? -1) : (int) $final;

    $starts = [];
    $ends = [];
    $cpu = 0.0;
    $opsDone = 0;
    $errors = 0;
    $errorMsg = null;
    $sumCsNs = 0;
    $lat = [];
    for ($w = 0; $w < $workers; ++$w) {
        $meta = @file_get_contents("{$tmpDir}/w{$w}.json");
        if ($meta === false) {
            ++$errors;
            $errorMsg ??= "worker {$w} wrote no result";

            continue;
        }
        $m = json_decode($meta, true);
        $starts[] = $m['t_start'];
        $ends[] = $m['t_end'];
        $cpu += $m['cpu_s'];
        $opsDone += $m['ops_done'];
        $errors += $m['errors'];
        $errorMsg ??= $m['error_msg'];
        $sumCsNs += $m['sum_cs_ns'];
        $bin = (string) @file_get_contents("{$tmpDir}/w{$w}.lat");
        if ($bin !== '') {
            array_push($lat, ...array_values(unpack('q*', $bin)));
        }
        @unlink("{$tmpDir}/w{$w}.json");
        @unlink("{$tmpDir}/w{$w}.lat");
    }
    sort($lat);

    $wallNs = $starts === [] ? 0 : max($ends) - min($starts);
    $wall = $wallNs / 1e9;
    $expected = $workers * $opsPerWorker;

    return [
        'mode' => $mode,
        'workers' => $workers,
        'coroutines' => $coroutines,
        'waiters_max' => $workers * $coroutines,
        'ops_total' => $expected,
        'ops_done' => $opsDone,
        'wall_s' => round($wall, 4),
        'ops_per_s' => $wall > 0 ? (int) round($opsDone / $wall) : 0,
        // utime+stime of the workers from barrier release to their last mutation
        'cpu_s' => round($cpu, 4),
        // getrusage(RUSAGE_CHILDREN) over the whole round, including fork and Via boot
        'cpu_s_children' => round($cpuChildren, 4),
        'cpu_cores' => $wall > 0 ? round($cpu / $wall, 2) : 0,
        'cpu_us_per_op' => $opsDone > 0 ? round($cpu * 1e6 / $opsDone, 2) : 0,
        'lat_us' => [
            'p50' => round(pct($lat, 0.50), 1),
            'p90' => round(pct($lat, 0.90), 1),
            'p99' => round(pct($lat, 0.99), 1),
            'max' => round(pct($lat, 1.0), 1),
        ],
        // Lock held: mutator entry to mutate() return (callback, write, release)
        'hold_us_mean' => $opsDone > 0 ? round($sumCsNs / 1e3 / $opsDone, 2) : 0,
        'lock_busy_pct' => $wallNs > 0 ? round(100 * $sumCsNs / $wallNs, 1) : 0,
        // Lock idle between one holder releasing and the next one noticing its turn
        'handoff_us' => $opsDone > 0 ? round(max(0, $wallNs - $sumCsNs) / 1e3 / $opsDone, 2) : 0,
        'finish_spread_s' => $ends === [] ? 0 : round((max($ends) - min($ends)) / 1e9, 4),
        'final' => $finalN,
        'expected' => $expected,
        'correct' => $finalN === $expected && !$timedOut && $errors === 0,
        'errors' => $errors,
        'error_msg' => $errorMsg,
        'timed_out' => $timedOut,
    ];
}

function runWorker(int $w, string $mode, int $coroutines, int $opsPerWorker, bool $isMap, int $holdUs, ?SharedTable $table, ?SharedSignalStore $store, mixed $initial, Table $ctl, int $deadlineNs, string $tmpDir): void {
    if ($mode === 'global') {
        $via = new Via((new Config())->withLogLevel('error'));
        $via->getApp()->setSharedTable($table);
        $op = static fn (callable $m): mixed => $via->mutateGlobalState(HOT_KEY, $m);
    } else {
        $signal = mountSignalWorker($store, '/bench_/w' . $w, $initial)->getSignal(SIGNAL_NAME);
        $op = static fn (callable $m): mixed => $signal->mutate($m, broadcast: false);
    }

    $ctl->incr('ready', 'v', 1);
    while ($ctl->get('start', 'v') !== 1) {
        if (hrtime(true) > $deadlineNs) {
            posix_kill(posix_getpid(), SIGKILL);
        }
        usleep(50);
    }

    $cpu0 = cpuSeconds(RUSAGE_SELF);
    $tStart = hrtime(true);
    $state = ['lat' => [], 'sum_cs' => 0, 'done' => 0, 'errors' => 0, 'error_msg' => null, 't_end' => $tStart];

    co::run(static function () use (&$state, $w, $coroutines, $opsPerWorker, $isMap, $holdUs, $op, $deadlineNs): void {
        for ($k = 0; $k < $coroutines; ++$k) {
            $share = intdiv($opsPerWorker, $coroutines) + ($k < $opsPerWorker % $coroutines ? 1 : 0);
            Coroutine::create(static function () use (&$state, $w, $share, $isMap, $holdUs, $op, $deadlineNs): void {
                $tIn = 0;
                $mutator = static function (mixed $v) use (&$tIn, $w, $isMap, $holdUs): mixed {
                    $tIn = hrtime(true);
                    if ($holdUs > 0) {
                        $until = $tIn + $holdUs * 1000;
                        while (hrtime(true) < $until) {
                            // simulated work while holding the lock
                        }
                    }

                    return $isMap ? ['n' => (int) ($v['n'] ?? 0) + 1, 'w' => $w] : (int) $v + 1;
                };

                for ($i = 0; $i < $share; ++$i) {
                    if (($i & 63) === 0 && hrtime(true) > $deadlineNs) {
                        ++$state['errors'];
                        $state['error_msg'] ??= 'worker deadline reached';

                        return;
                    }
                    $t0 = hrtime(true);

                    try {
                        $op($mutator);
                    } catch (Throwable $e) {
                        ++$state['errors'];
                        $state['error_msg'] ??= $e::class . ': ' . $e->getMessage();

                        continue;
                    }
                    $t1 = hrtime(true);
                    $state['lat'][] = $t1 - $t0;
                    $state['sum_cs'] += $t1 - $tIn;
                    ++$state['done'];
                    $state['t_end'] = $t1;
                }
            });
        }
    });

    $cpu = cpuSeconds(RUSAGE_SELF) - $cpu0;
    file_put_contents("{$tmpDir}/w{$w}.lat", $state['lat'] === [] ? '' : pack('q*', ...$state['lat']));
    file_put_contents("{$tmpDir}/w{$w}.json", json_encode([
        't_start' => $tStart,
        't_end' => $state['t_end'],
        'cpu_s' => $cpu,
        'ops_done' => $state['done'],
        'errors' => $state['errors'],
        'error_msg' => $state['error_msg'],
        'sum_cs_ns' => $state['sum_cs'],
    ]));
}

// ── main ────────────────────────────────────────────────────────────────────

if (!extension_loaded('openswoole') || !function_exists('pcntl_fork')) {
    fwrite(STDERR, "ext-openswoole and ext-pcntl are required\n");

    exit(1);
}

$args = parseArgs($argv);
$workers = max(1, (int) ($args['workers'] ?? 4));
$coroutineSweep = intList($args['coroutines'] ?? '1,8,32');
$opsPerWorker = max(1, (int) ($args['ops'] ?? 20000));
$modes = array_values(array_intersect(array_map('trim', explode(',', $args['modes'] ?? 'global,signal')), ['global', 'signal']));
$value = ($args['value'] ?? 'map') === 'int' ? 'int' : 'map';
$holdUs = max(0, (int) ($args['hold-us'] ?? 0));
$reps = max(1, (int) ($args['reps'] ?? 1));
$timeout = max(1.0, (float) ($args['timeout'] ?? 60));

if ($coroutineSweep === [] || $modes === []) {
    fwrite(STDERR, "need at least one --coroutines value and one of --modes=global,signal\n");

    exit(2);
}

$parentPid = getmypid();
$livePids = [];
$tmpDir = sys_get_temp_dir() . '/via-lock-contention-' . $parentPid;
@mkdir($tmpDir, 0o700, true);

$cleanup = static function () use (&$livePids, $tmpDir, $parentPid): void {
    if (getmypid() !== $parentPid) {
        return;
    }
    foreach (array_keys($livePids) as $pid) {
        @posix_kill($pid, SIGKILL);
        @pcntl_waitpid($pid, $status);
    }
    $livePids = [];
    foreach ((array) glob($tmpDir . '/*') as $f) {
        @unlink((string) $f);
    }
    @rmdir($tmpDir);
};
register_shutdown_function($cleanup);
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $sig) {
    pcntl_signal($sig, static function () use ($cleanup): void {
        $cleanup();

        exit(130);
    });
}

// Load and compile the Via classes once before forking, so cpu_s_children does not charge each
// child for autoloading them (the parent of a global-mode round never builds a Via otherwise).
mountSignalWorker(new SharedSignalStore(maxRows: 64), '/bench_/preload', 0);
new SharedTable(maxRows: 64);

$deadline = microtime(true) + $timeout;
$runs = [];
$aborted = false;

foreach ($modes as $mode) {
    foreach ($coroutineSweep as $c) {
        for ($r = 0; $r < $reps; ++$r) {
            if (microtime(true) > $deadline) {
                $aborted = true;
                logLine("global timeout reached, skipping {$mode} C={$c} rep={$r}");

                continue;
            }
            $res = runOnce($mode, $workers, max(1, $c), $opsPerWorker, $value === 'map', $holdUs, $deadline, $tmpDir, $livePids);
            $res['rep'] = $r;
            $runs[] = $res;
            logLine(sprintf(
                '%-6s W=%d C=%-3d %8d ops/s  wall=%.3fs cpu=%.3fs (%.2f cores, %.1fus/op)  p99=%.0fus  hold=%.2fus handoff=%.2fus  final=%d/%d%s',
                $mode,
                $workers,
                $c,
                $res['ops_per_s'],
                $res['wall_s'],
                $res['cpu_s'],
                $res['cpu_cores'],
                $res['cpu_us_per_op'],
                $res['lat_us']['p99'],
                $res['hold_us_mean'],
                $res['handoff_us'],
                $res['final'],
                $res['expected'],
                $res['correct'] ? '' : '  INCORRECT' . ($res['error_msg'] ? ' (' . $res['error_msg'] . ')' : ''),
            ));
        }
    }
}

// Degradation from the smallest to the largest C, per mode, on the median rep.
$summary = [];
foreach ($modes as $mode) {
    $byC = [];
    foreach ($runs as $run) {
        if ($run['mode'] === $mode) {
            $byC[$run['coroutines']][] = $run;
        }
    }
    if (count($byC) < 2) {
        continue;
    }
    ksort($byC);
    $median = static function (array $rs, string $k): float {
        $v = array_map(static fn (array $r): float => (float) $r[$k], $rs);
        sort($v);

        return $v[intdiv(count($v), 2)];
    };
    $lo = array_key_first($byC);
    $hi = array_key_last($byC);
    $tLo = $median($byC[$lo], 'ops_per_s');
    $cLo = $median($byC[$lo], 'cpu_us_per_op');
    $summary[$mode] = [
        'c_low' => $lo,
        'c_high' => $hi,
        'ops_per_s_ratio' => $tLo > 0 ? round($median($byC[$hi], 'ops_per_s') / $tLo, 3) : null,
        'cpu_us_per_op_ratio' => $cLo > 0 ? round($median($byC[$hi], 'cpu_us_per_op') / $cLo, 3) : null,
    ];
}

$gitHead = trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --short HEAD 2>/dev/null'));
$gitDirty = trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' status --porcelain --untracked-files=no -- src 2>/dev/null')) !== '';

echo json_encode([
    'bench' => 'lock_contention',
    'git' => $gitHead . ($gitDirty ? '+dirty' : ''),
    'php' => PHP_VERSION,
    'openswoole' => phpversion('openswoole'),
    'params' => [
        'workers' => $workers,
        'coroutines' => $coroutineSweep,
        'ops_per_worker' => $opsPerWorker,
        'modes' => $modes,
        'value' => $value,
        'hold_us' => $holdUs,
        'reps' => $reps,
        'timeout_s' => $timeout,
    ],
    'all_correct' => $runs !== [] && array_reduce($runs, static fn (bool $ok, array $r): bool => $ok && $r['correct'], true),
    'aborted' => $aborted,
    'summary' => $summary,
    'runs' => $runs,
], JSON_UNESCAPED_SLASHES), "\n";
