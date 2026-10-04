<?php

declare(strict_types=1);

/*
 * F5: cost of Via::getClients() and of a broadcast whose per-context view calls it.
 *
 * In-process, no server. "shared" attaches a real OpenSwoole\Table-backed SharedClientRegistry
 * the way Via::start() does for worker_num > 1; "single" leaves it null (worker_num = 1).
 *
 * Each case times getClients() in a loop, then again right after a client connects (so a cached
 * result must be rebuilt and include the newcomer), then creates min(N, --fanout-cap)
 * TAB-primary contexts whose view calls getClients(), connects one
 * more client and times one Via::broadcast(Scope::GLOBAL).
 * With N > cap, the registry still holds N clients, which is the multi-worker picture: this
 * worker renders `cap` contexts, each reading all N clients. The full single-worker cost
 * is then estimated as measured + steady per-context cost x (N - cap), and flagged.
 *
 * Usage:
 *   php bench/contention/get_clients.php [--n=100,1000,5000] [--fanout-cap=1000]
 *        [--broadcasts=auto|K] [--call-budget=50000] [--modes=shared,single]
 *        [--view=count|avatars] [--timeout=110]
 *
 * Prints one JSON line to stdout; progress goes to stderr.
 */

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\Support\IdGenerator;
use Mbolli\PhpVia\Via;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Array-backed patch queues and no server binding, as in the Pest suite.
putenv('VIA_TEST_MODE=1');
ini_set('memory_limit', '4G');

/** @return array<string, string> */
function parseArgs(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDERR, 'see header of ' . __FILE__ . "\n");

            exit(0);
        } else {
            fwrite(STDERR, "unknown argument: {$arg}\n");

            exit(64);
        }
    }

    return $out;
}

function logErr(string $msg): void {
    fwrite(STDERR, sprintf('[get_clients %6.1fs] %s', microtime(true) - $GLOBALS['t0'], $msg) . "\n");
}

function cpuSeconds(): float {
    $r = getrusage();

    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
}

/** @param list<float> $xs */
function percentile(array $xs, float $p): float {
    if ($xs === []) {
        return 0.0;
    }
    sort($xs);
    $idx = (int) min(count($xs) - 1, max(0, (int) ceil($p * count($xs)) - 1));

    return $xs[$idx];
}

/** @param list<float> $xs */
function mean(array $xs): float {
    return $xs === [] ? 0.0 : array_sum($xs) / count($xs);
}

function r(float $x, int $d = 4): float {
    return round($x, $d);
}

function clientIdFor(int $i): string {
    // Same shape as IdGenerator::generateClientId() (8 hex chars), but deterministic.
    return sprintf('%08x', crc32('client-' . $i));
}

function contextIdFor(int $i): string {
    return sprintf('/presence_%016x', $i);
}

function emit(array $payload): void {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
}

$GLOBALS['t0'] = microtime(true);
$args = parseArgs($argv);

$ns = array_values(array_filter(array_map('intval', explode(',', $args['n'] ?? '100,1000,5000')), fn (int $n) => $n > 0));
$fanoutCap = max(1, (int) ($args['fanout-cap'] ?? 1000));
// auto: repeat small fan-outs so one noisy sample does not decide; shared-mode work scales with fan-out x N.
$broadcastsArg = $args['broadcasts'] ?? 'auto';
$callBudget = max(1, (int) ($args['call-budget'] ?? 50000));
$modes = array_values(array_intersect(['shared', 'single'], explode(',', $args['modes'] ?? 'shared,single')));
$viewKind = ($args['view'] ?? 'count') === 'avatars' ? 'avatars' : 'count';
$timeout = max(5, (int) ($args['timeout'] ?? 110));

$params = [
    'n' => $ns,
    'fanout_cap' => $fanoutCap,
    'broadcasts' => $broadcastsArg,
    'call_budget' => $callBudget,
    'modes' => $modes,
    'view' => $viewKind,
    'timeout_s' => $timeout,
    'php' => PHP_VERSION,
    'openswoole' => phpversion('openswoole') ?: null,
    'opcache_cli' => (bool) ini_get('opcache.enable_cli'),
];

$results = [];
$deadline = microtime(true) + $timeout;

pcntl_async_signals(true);
pcntl_signal(SIGALRM, function () use (&$results, $params): void {
    emit(['bench' => 'get_clients', 'error' => 'watchdog timeout', 'params' => $params, 'results' => $results]);

    exit(2);
});
pcntl_alarm($timeout);

// Revisions before 0.14 have the getter; later ones keep the value in the snapshot new Via() takes.
$defaultRows = method_exists(Config::class, 'getContextDirectoryRows') ? (new Config())->getContextDirectoryRows() : (new Config())->freeze()->contextDirectoryRows;

/**
 * @return array<string, mixed>
 */
function runCase(string $mode, int $n, int $fanoutCap, string $broadcastsArg, int $callBudget, string $viewKind, int $defaultRows, float $deadline): array {
    $via = new Via((new Config())->withLogLevel('error'));
    $app = $via->getApp();
    $m = min($n, $fanoutCap);
    $broadcasts = $broadcastsArg === 'auto'
        ? max(1, min(20, intdiv(1_000_000, $m * $n)))
        : max(1, (int) $broadcastsArg);

    $tableRows = null;
    $registry = null;
    if ($mode === 'shared') {
        // Via::start() sizes it from getContextDirectoryRows(); an operator expecting N clients raises that.
        $tableRows = max($defaultRows, $n);
        $registry = new SharedClientRegistry($tableRows);
        $app->setClientRegistry($registry);
    }

    $now = time();
    // $remote: a client whose stream another worker serves, so only that worker writes the row.
    $connect = function (int $i, bool $remote) use ($app, $registry, $now): void {
        $clientId = clientIdFor($i);
        $ip = sprintf('10.%d.%d.%d', ($i >> 16) & 255, ($i >> 8) & 255, $i & 255);
        if ($remote && $registry !== null) {
            $registry->register(contextIdFor($i), $clientId, $ip, $now);

            return;
        }
        $app->registerClient(contextIdFor($i), [
            'id' => $clientId,
            'identicon' => IdGenerator::generateIdenticon($clientId),
            'connected_at' => $now,
            'ip' => $ip,
        ]);
    };
    for ($i = 0; $i < $n; ++$i) {
        $connect($i, $mode === 'shared' && $i >= $m);
    }

    $registered = $registry !== null ? $registry->count() : count($via->getClients());
    if ($registered !== $n || count($via->getClients()) !== $n) {
        return ['mode' => $mode, 'n' => $n, 'error' => "registered {$registered} of {$n} clients"];
    }

    // getClients() in a loop: what a single call costs at this N.
    $calls = max(3, intdiv($callBudget, $n));
    $via->getClients();
    $samples = [];
    $cpu0 = cpuSeconds();
    $w0 = hrtime(true);
    for ($k = 0; $k < $calls; ++$k) {
        $t = hrtime(true);
        $list = $via->getClients();
        $samples[] = (hrtime(true) - $t) / 1e6;
    }
    $callsWallMs = (hrtime(true) - $w0) / 1e6;
    $callsCpuMs = (cpuSeconds() - $cpu0) * 1000;
    unset($list);

    $memBefore = memory_get_usage();
    $held = $via->getClients();
    $resultKb = (memory_get_usage() - $memBefore) / 1024;
    unset($held);

    // Same call right after a connect elsewhere, so a cached result has to be rebuilt (and stay fresh).
    $changeCalls = max(3, intdiv($calls, 4));
    $changeSamples = [];
    $changeFresh = true;
    for ($k = 0; $k < $changeCalls; ++$k) {
        $extra = $n + 1000 + $k;
        $connect($extra, $mode === 'shared');
        $t = hrtime(true);
        $list = $via->getClients();
        $changeSamples[] = (hrtime(true) - $t) / 1e6;
        $changeFresh = $changeFresh && count($list) === $n + 1;
        unset($list);
        $app->unregisterClient(contextIdFor($extra));
    }

    $allMedian = percentile($samples, 0.5);
    $row = [
        'mode' => $mode,
        'n' => $n,
        'table_rows' => $tableRows,
        'calls' => $calls,
        'call_ms_median' => r($allMedian),
        'call_ms_mean' => r(mean($samples)),
        'call_ms_p95' => r(percentile($samples, 0.95)),
        'call_cpu_ms_mean' => r($callsCpuMs / $calls),
        'call_us_per_client' => r($allMedian * 1000 / $n, 3),
        'call_result_kb' => r($resultKb, 1),
        'calls_wall_ms' => r($callsWallMs, 1),
        'changed_calls' => $changeCalls,
        'changed_call_ms_median' => r(percentile($changeSamples, 0.5)),
        'changed_call_saw_new_client' => $changeFresh,
        'fanout_contexts' => $m,
        'broadcasts' => $broadcasts,
    ];

    // Broadcast: m TAB-primary contexts whose view reads getClients().
    $state = new stdClass();
    $state->ts = [];
    $state->minSeen = PHP_INT_MAX;
    $state->maxSeen = 0;

    $contexts = [];
    for ($i = 0; $i < $m; ++$i) {
        $id = contextIdFor($i);
        $ctx = new Context($id, '/presence', $via);
        $ctx->view(function () use ($via, $state, $viewKind): string {
            $state->ts[] = hrtime(true);
            $clients = $via->getClients();
            $count = count($clients);
            $state->minSeen = min($state->minSeen, $count);
            $state->maxSeen = max($state->maxSeen, $count);
            $html = '<div id="presence"><span>' . $count . ' online</span>';
            if ($viewKind === 'avatars') {
                foreach (array_slice($clients, 0, 8) as $c) {
                    $html .= '<img src="' . ($c['identicon'] ?? '') . '" alt="' . htmlspecialchars((string) ($c['id'] ?? '')) . '">';
                }
            }

            return $html . '</div>';
        });
        $via->contexts[$id] = $ctx;
        $contexts[] = $ctx;
    }

    $predictedMs = $allMedian * $m * $broadcasts;
    $remainingMs = ($deadline - microtime(true)) * 1000;
    if ($predictedMs > $remainingMs * 0.8) {
        $row['broadcast_skipped'] = sprintf('predicted %.1f s, %.1f s left before watchdog', $predictedMs / 1000, $remainingMs / 1000);
        logErr("{$mode} n={$n}: broadcast skipped, " . $row['broadcast_skipped']);

        return $row;
    }

    $bMs = [];
    $bCpuMs = [];
    $steady = [];
    $patchesOk = true;
    for ($b = 0; $b < $broadcasts; ++$b) {
        // The presence pattern: a client connects on this worker, which then broadcasts.
        $extra = $n + 1000 + $b;
        $connect($extra, false);
        $state->ts = [];
        $cpu0 = cpuSeconds();
        $t = hrtime(true);
        $via->broadcast(Scope::GLOBAL);
        $end = hrtime(true);
        $bCpuMs[] = (cpuSeconds() - $cpu0) * 1000;
        $bMs[] = ($end - $t) / 1e6;
        $app->unregisterClient(contextIdFor($extra));

        // Consecutive view entries bound one context's full sync (render + patch queue).
        $ts = $state->ts;
        $ts[] = $end;
        $per = [];
        for ($j = 1, $c = count($ts); $j < $c; ++$j) {
            $per[] = ($ts[$j] - $ts[$j - 1]) / 1e6;
        }
        $tail = array_slice($per, intdiv(count($per), 2));
        $steady[] = mean($tail);

        if (count($state->ts) !== $m) {
            $patchesOk = false;
        }
        foreach ($contexts as $ctx) {
            $got = 0;
            while ($ctx->getPatch() !== null) {
                ++$got;
            }
            if ($got === 0) {
                $patchesOk = false;
            }
        }
    }

    $measured = percentile($bMs, 0.5);
    $steadyMs = percentile($steady, 0.5);
    $row += [
        'broadcast_ms_median' => r($measured, 2),
        'broadcast_cpu_ms_median' => r(percentile($bCpuMs, 0.5), 2),
        'broadcast_ms_per_context' => r($measured / $m),
        'broadcast_ms_per_context_steady' => r($steadyMs),
        'broadcast_ms_full_n' => r($m === $n ? $measured : $measured + $steadyMs * ($n - $m), 1),
        'broadcast_full_n_extrapolated' => $m !== $n,
        'views_saw_new_client' => $state->minSeen === $n + 1 && $state->maxSeen === $n + 1,
        'every_context_got_patch' => $patchesOk,
    ];

    return $row;
}

logErr('params ' . json_encode($params));

// Warm-up: first-use costs (autoload, closures, time()) should not land in the first measured case.
foreach ($modes as $mode) {
    runCase($mode, 20, 20, '1', 200, $viewKind, $defaultRows, $deadline);
}
gc_collect_cycles();

$cpuStart = cpuSeconds();
foreach ($ns as $n) {
    foreach ($modes as $mode) {
        $row = runCase($mode, $n, $fanoutCap, $broadcastsArg, $callBudget, $viewKind, $defaultRows, $deadline);
        $results[] = $row;
        logErr(sprintf(
            '%-6s n=%-6d call=%8.3f ms  after-connect=%8.3f ms  broadcast(%d ctx)=%s ms  full-N=%s ms',
            $mode,
            $n,
            $row['call_ms_median'] ?? -1,
            $row['changed_call_ms_median'] ?? -1,
            $row['fanout_contexts'] ?? 0,
            $row['broadcast_ms_median'] ?? '-',
            $row['broadcast_ms_full_n'] ?? '-',
        ));
        gc_collect_cycles();
    }
}

pcntl_alarm(0);

$summary = [];
foreach ($results as $row) {
    if (isset($row['error'])) {
        continue;
    }
    $key = $row['mode'] . '_n' . $row['n'];
    $summary[$key . '_call_ms'] = $row['call_ms_median'];
    $summary[$key . '_changed_call_ms'] = $row['changed_call_ms_median'];
    if (isset($row['broadcast_ms_median'])) {
        $summary[$key . '_broadcast_ms'] = $row['broadcast_ms_median'];
        $summary[$key . '_broadcast_full_n_ms'] = $row['broadcast_ms_full_n'];
    }
}

emit([
    'bench' => 'get_clients',
    'params' => $params,
    'summary' => $summary,
    'results' => $results,
    'cpu_s' => r(cpuSeconds() - $cpuStart, 2),
    'wall_s' => r(microtime(true) - $GLOBALS['t0'], 2),
    'peak_mem_mb' => r(memory_get_peak_usage(true) / 1048576, 1),
]);
