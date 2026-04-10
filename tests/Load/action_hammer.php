<?php

declare(strict_types=1);

/**
 * action_hammer.php — Action delivery integrity load test.
 *
 * Fires N concurrent POST /_action/{action} requests in parallel coroutines
 * while one SSE observer listens for signal patches. Measures HTTP success
 * rate, SSE patch delivery rate, and validates the final signal value.
 *
 * Designed to run against the php-via website with zero modifications.
 * Defaults target the CounterExample at /examples/counter.
 *
 * How it works:
 *   1. Load --route to get a context ID (parsed from Datastar's
 *      <meta data-signals='{"via_ctx":"..."}'> tag in the page HTML).
 *   2. Open one SSE connection on that context to observe patches.
 *   3. Fire N POST /_action/{action} concurrently, each carrying
 *      {"via_ctx": "...", "step": 1, "count": 0} as the JSON body.
 *   4. Collect and count arriving SSE patches.
 *   5. Report: HTTP OK%, patch delivery %, final signal value.
 *
 * What is tested:
 *   - PatchManager Channel(50) backpressure / drop behaviour under load
 *   - HTTP action handler throughput (req/s)
 *   - SSE patch delivery rate — how many patches reach the observer
 *   - Final signal value correctness (count should equal HTTP-OK count
 *     since each successful increment adds 1)
 *
 * What is NOT tested (by design):
 *   - Intermediate patch ordering — Channel is non-blocking, drops are intentional
 *   - Cross-node delivery (see ScalingTest.php + broker tests)
 *
 * Usage:
 *   php tests/Load/action_hammer.php [options]
 *
 * Options:
 *   --url=URL          Base URL of the Via app     (default: http://127.0.0.1:3000)
 *   --route=PATH       Page route to load           (default: /examples/counter)
 *   --action=NAME      Action name to fire          (default: increment)
 *   --signal=NAME      Signal name to watch         (default: count)
 *   --signals=JSON     Extra signals for action body (default: {"step":1})
 *   --actions=N        Total actions to fire        (default: 10000)
 *   --concurrency=N    Parallel action coroutines   (default: 200)
 *   --timeout=SEC      Per-request timeout (s)      (default: 5)
 *   --help             Show this help
 *
 * Prerequisites:
 *   - ext-openswoole in CLI PHP binary
 *   - The php-via website (or any Via app) running at --url
 *   - The route must register the action with the given --action name
 *     and call $ctx->sync() or $ctx->syncSignals() after mutating state
 *
 * Metrics output (stdout, one line per batch + summary):
 *   [batch  1/50]  200 sent   OK:200  FAIL:0
 *   ...
 *   === SUMMARY ===
 *   Actions sent       : 10000
 *   HTTP OK            : 9980  (99.8%)
 *   HTTP errors        : 20
 *   Patches observed   : 7234  (72.5% of HTTP OK)
 *   Final count        : 9980  (matches HTTP OK: YES)
 *   Wall time          : 4.32s
 *   Throughput         : 2315 req/s
 */

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Http\Client;

// ── Bootstrap ────────────────────────────────────────────────────────────────

if (!extension_loaded('openswoole')) {
    fwrite(STDERR, "Error: ext-openswoole is required.\n");

    exit(1);
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

$opts = getopt('', ['url:', 'route:', 'action:', 'signal:', 'signals:', 'actions:', 'concurrency:', 'observers:', 'timeout:', 'help']);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__);

    exit(0);
}

$baseUrl = (string) ($opts['url'] ?? 'https://localhost:3000');
$route = (string) ($opts['route'] ?? '/examples/counter');
$actionName = (string) ($opts['action'] ?? 'increment');
$signalName = (string) ($opts['signal'] ?? 'count');
$extraSignals = json_decode((string) ($opts['signals'] ?? '{"step":1}'), true) ?? ['step' => 1];
$totalActions = (int) ($opts['actions'] ?? 10000);
$concurrency = (int) ($opts['concurrency'] ?? 200);
$numObservers = (int) ($opts['observers'] ?? 1);
$timeoutSec = (float) ($opts['timeout'] ?? 5.0);

$parsedUrl = parse_url($baseUrl);
$host = $parsedUrl['host'] ?? '127.0.0.1';
$port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
$ssl = ($parsedUrl['scheme'] ?? 'http') === 'https';

echo "action_hammer.php\n";
echo "  URL         : {$baseUrl}\n";
echo "  Route       : {$route}\n";
echo "  Action      : {$actionName} (URL scraped from page)\n";
echo "  Signal      : {$signalName}\n";
echo "  Actions     : {$totalActions}\n";
echo "  Concurrency : {$concurrency}\n";
echo "  Observers   : {$numObservers}" . ($numObservers > 1 ? '  (broadcast fan-out mode)' : '') . "\n";
echo "\n";

// ── Main coroutine entry ──────────────────────────────────────────────────────

Coroutine::run(function () use (
    $host,
    $port,
    $ssl,
    $route,
    $actionName,
    $signalName,
    $extraSignals,
    $totalActions,
    $concurrency,
    $numObservers,
    $timeoutSec
): void {
    // Step 1: Load the page once to discover the action URL.
    echo "Loading page {$route} ...\n";
    [$ctxId, $initialSignals, $actionPath] = loadPage($host, $port, $ssl, $route, $actionName);

    if ($ctxId === null) {
        fwrite(STDERR, "ERROR: Could not find via_ctx in <meta data-signals> on {$route}.\n");
        fwrite(STDERR, "       Is the Via app running? Is the route correct?\n");

        exit(1);
    }

    if ($actionPath === null) {
        fwrite(STDERR, "ERROR: Could not find action '{$actionName}' in page HTML.\n");
        fwrite(STDERR, "       Looking for data-on:click containing \"/_action/{$actionName}\"\n");
        fwrite(STDERR, "       Check --action matches the registered action name.\n");

        exit(1);
    }

    echo "  via_ctx     : {$ctxId}\n";
    echo "  Action URL  : {$actionPath}\n";
    echo '  Signals     : ' . json_encode($initialSignals) . "\n\n";

    // Step 2: When --observers > 1, load the page N more times to get N
    // independent via_ctx values (one per simulated browser). Each will hold
    // an SSE connection. Actions will be fired from the FIRST context.
    // Because the counter is ROUTE-scoped, every action broadcasts patches
    // to ALL N SSE connections simultaneously — stressing the Channel fills.
    $observerCtxIds = [$ctxId];

    if ($numObservers > 1) {
        echo "Opening {$numObservers} observer SSE connections (loading page for each) ...\n";
        $loadChan = new Channel($numObservers - 1);
        for ($i = 1; $i < $numObservers; ++$i) {
            Coroutine::create(function () use ($host, $port, $ssl, $route, $actionName, $loadChan): void {
                [$c] = loadPage($host, $port, $ssl, $route, $actionName);
                $loadChan->push($c ?? '');
            });
        }
        for ($i = 1; $i < $numObservers; ++$i) {
            $c = $loadChan->pop(15.0);
            if ($c !== false && $c !== '') {
                $observerCtxIds[] = $c;
            }
        }
        printf("  Loaded %d contexts\n\n", count($observerCtxIds));
    }

    // Step 3: Open one SSE connection per observer context.
    // patchChan receives a value each time any observer sees our signal.
    $patchChan = new Channel(10000);
    $observerDone = new Channel(1);

    foreach ($observerCtxIds as $obsCtxId) {
        $sseQuery = urlencode((string) json_encode(['via_ctx' => $obsCtxId]));
        Coroutine::create(function () use (
            $host,
            $port,
            $ssl,
            $sseQuery,
            $patchChan,
            $observerDone,
            $signalName
        ): void {
            openSseObserver($host, $port, $ssl, $sseQuery, $patchChan, $observerDone, $signalName);
        });
    }

    // Allow SSE connections to establish before hammering.
    usleep(500_000);

    // Capture baseline: wait up to 3s for initial SSE state pushes to arrive,
    // then drain all of them. The last value seen is the signal's value before
    // our actions run — essential for route-scoped signals that persist across runs.
    $baselineValue = null;
    $baselineDeadline = microtime(true) + 3.0;
    while (microtime(true) < $baselineDeadline) {
        $v = $patchChan->pop(0.2);
        if ($v !== false) {
            $baselineValue = $v;

            break; // got first patch; drain the rest below
        }
    }
    // Drain any further initial-state patches that arrived simultaneously.
    while (!$patchChan->isEmpty()) {
        $v = $patchChan->pop(0);
        if ($v !== false) {
            $baselineValue = $v;
        }
    }
    if ($baselineValue !== null) {
        echo "  Baseline {$signalName} : {$baselineValue}\n\n";
    }

    // Step 4: Fire actions — all using the first context's via_ctx + signals.
    $baseBody = array_merge($initialSignals, $extraSignals, ['via_ctx' => $ctxId]);

    echo "Firing {$totalActions} {$actionPath} actions";
    if ($numObservers > 1) {
        $expectedPatches = "~{$totalActions}×{$numObservers}";
        echo " (expect {$expectedPatches} patches across {$numObservers} observers)";
    }
    echo " ...\n\n";

    $wallStart = microtime(true);
    $totalOk = 0;
    $totalFail = 0;
    $batchCount = (int) ceil($totalActions / $concurrency);

    for ($batch = 1; $batch <= $batchCount; ++$batch) {
        $batchSize = min($concurrency, $totalActions - ($batch - 1) * $concurrency);
        $resultChan = new Channel($batchSize);

        for ($i = 0; $i < $batchSize; ++$i) {
            Coroutine::create(function () use (
                $host,
                $port,
                $ssl,
                $actionPath,
                $baseBody,
                $resultChan,
                $timeoutSec
            ): void {
                $ok = fireAction($host, $port, $ssl, $actionPath, $baseBody, $timeoutSec);
                $resultChan->push($ok ? 1 : 0);
            });
        }

        $batchOk = 0;
        for ($i = 0; $i < $batchSize; ++$i) {
            $batchOk += $resultChan->pop(10.0) === 1 ? 1 : 0;
        }
        $batchFail = $batchSize - $batchOk;

        $totalOk += $batchOk;
        $totalFail += $batchFail;

        printf(
            "[batch %3d/%d]  %4d sent   OK:%-4d FAIL:%-4d\n",
            $batch,
            $batchCount,
            $batchSize,
            $batchOk,
            $batchFail
        );
    }

    $wallTime = microtime(true) - $wallStart;

    // Allow stragglers to arrive.
    usleep(800_000);

    // Collect all patches across all observers.
    $observedCount = 0;
    $lastValue = null;

    while (!$patchChan->isEmpty()) {
        $val = $patchChan->pop(0);
        if ($val !== false) {
            ++$observedCount;
            $lastValue = $val;
        }
    }

    $observerDone->push(1);

    $throughput = $wallTime > 0 ? round($totalActions / $wallTime) : 0;
    $finalInt = $lastValue !== null ? (int) $lastValue : null;
    $baselineInt = $baselineValue !== null ? (int) $baselineValue : null;
    $netIncrement = ($finalInt !== null && $baselineInt !== null) ? $finalInt - $baselineInt : null;

    echo "\n=== SUMMARY ===\n";
    printf("Actions sent       : %d\n", $totalActions);
    printf("HTTP OK            : %d  (%.1f%%)\n", $totalOk, $totalActions > 0 ? $totalOk / $totalActions * 100 : 0);
    printf("HTTP errors        : %d\n", $totalFail);

    if ($numObservers > 1) {
        // In broadcast mode the expected patch count = HTTP OK × observers.
        $expectedTotal = $totalOk * $numObservers;
        $dropCount = max(0, $expectedTotal - $observedCount);
        $dropPct = $expectedTotal > 0 ? round($dropCount / $expectedTotal * 100, 1) : 0.0;
        $deliveryPct = $expectedTotal > 0 ? round($observedCount / $expectedTotal * 100, 1) : 0.0;
        printf("Observers          : %d\n", $numObservers);
        printf("Expected patches   : %d  (HTTP OK × observers)\n", $expectedTotal);
        printf(
            "Patches observed   : %d  (%.1f%% delivered, %.1f%% dropped)\n",
            $observedCount,
            $deliveryPct,
            $dropPct
        );
        if ($netIncrement !== null) {
            $serverOkPct = $totalOk > 0 ? round($netIncrement / $totalOk * 100, 1) : 0.0;
            printf(
                "Net increment      : %d  (baseline %d → %s, %.1f%% of HTTP OK processed server-side)\n",
                $netIncrement,
                $baselineInt,
                $lastValue,
                $serverOkPct
            );
        } else {
            printf("Signal value       : %s\n", $lastValue ?? 'none');
        }
    } else {
        $observedPct = $totalOk > 0 ? round($observedCount / $totalOk * 100, 1) : 0.0;
        $valueMatch = $netIncrement !== null && $netIncrement === $totalOk;
        printf("Patches observed   : %d  (%.1f%% of HTTP OK)\n", $observedCount, $observedPct);
        if ($netIncrement !== null) {
            printf(
                "Net increment      : %d  (baseline %d → %s, matches HTTP OK: %s)\n",
                $netIncrement,
                $baselineInt,
                $lastValue,
                $valueMatch ? 'YES' : 'NO — server processed fewer than HTTP OK'
            );
        } else {
            printf("Final %-12s : %s\n", $signalName, $lastValue ?? 'none');
        }
    }

    printf("Wall time          : %.2fs\n", $wallTime);
    printf("Throughput         : %d req/s\n", $throughput);
    echo "\n";
    echo "Note: patch delivery < 100% is expected under load — PatchManager\n";
    echo "      Channel(50) is non-blocking by design; state is always correct.\n\n";
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Load the page HTML and return:
 *   [via_ctx string|null, signals array, actionPath string|null]
 *
 * Parses via_ctx from:
 *   <meta data-signals='{"via_ctx":"/examples/counter_/abc","count":0,...}'>
 *
 * Note: the attribute uses SINGLE quotes around JSON that contains double
 * quotes, so we must match specifically on the quote style used.
 *
 * Scrapes the action URL from:
 *   data-on:click="@post('/_action/increment-{hash}')"  — Datastar v3 style
 *   data-on-click="@post('/_action/increment-{hash}')"  — Datastar v2 style
 *
 * @return array{null|string, array<string, mixed>, null|string}
 */
function loadPage(string $host, int $port, bool $ssl, string $route, string $actionName): array {
    $client = new Client($host, $port, $ssl);
    $client->set(['timeout' => 10.0]);
    $client->get($route);

    $html = $client->body ?? '';
    $client->close();

    // The attribute uses single quotes so the JSON inside is unambiguous.
    // Match data-signals='...JSON...' — the JSON ends at the next unescaped '
    if (preg_match("/data-signals='([^']+)'/", $html, $m)) {
        $signals = json_decode(html_entity_decode($m[1]), true);
    } elseif (preg_match('/data-signals="([^"]+)"/', $html, $m)) {
        $signals = json_decode(html_entity_decode($m[1]), true);
    } else {
        $signals = null;
    }

    if (!is_array($signals)) {
        return [null, [], null];
    }

    $ctxId = isset($signals['via_ctx']) ? (string) $signals['via_ctx'] : null;

    // Discover the action URL. A page may have multiple actions with the same
    // name but different scopes (e.g. both session-scoped with hash and
    // route-scoped without hash). Prefer the hashless (route/global) URL over
    // the hashed (TAB/session) one, since it's the broadcast target.
    $actionPath = null;
    $pattern = '/@post\(\'(\/\_action\/' . preg_quote($actionName, '/') . '(?:-[a-f0-9]+)?)\'\)/i';
    if (preg_match_all($pattern, $html, $am)) {
        foreach ($am[1] as $candidate) {
            // Prefer exact name match (no hash = route/global scope).
            if ($candidate === '/_action/' . $actionName) {
                $actionPath = $candidate;

                break;
            }
            // Fall back to first hashed match.
            $actionPath ??= $candidate;
        }
    }

    return [$ctxId, $signals, $actionPath];
}

/**
 * POST to the given action path (e.g. /_action/increment-abc123) with all
 * signals as JSON body. Returns true on HTTP 2xx.
 *
 * @param array<string, mixed> $signals
 */
function fireAction(string $host, int $port, bool $ssl, string $actionPath, array $signals, float $timeout): bool {
    $client = new Client($host, $port, $ssl);
    $client->set(['timeout' => $timeout]);

    $body = (string) json_encode($signals);
    $client->setHeaders([
        'Content-Type' => 'application/json',
        'Content-Length' => (string) strlen($body),
    ]);
    $client->post($actionPath, $body);

    $code = $client->statusCode ?? 0;
    $client->close();

    return $code >= 200 && $code < 300;
}

/**
 * Open a persistent SSE connection using a raw TCP client and push each
 * observed signal value into $patchChan.
 *
 * Why raw TCP instead of OpenSwoole\Coroutine\Http\Client:
 *   Client::get() blocks until the full response body is received. Since SSE
 *   never closes, get() never returns. A raw TCP socket lets us read chunks
 *   incrementally as they arrive.
 *
 * Signal key matching:
 *   Via namespaces scoped signals: "count__examples_counter__<hash>".
 *   We match any key that equals $signalName OR starts with "{$signalName}__".
 */
function openSseObserver(
    string $host,
    int $port,
    bool $ssl,
    string $sseQuery,
    Channel $patchChan,
    Channel $observerDone,
    string $signalName
): void {
    $sockType = $ssl ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
    $client = new Coroutine\Client($sockType);

    if (!$client->connect($host, $port, 5.0)) {
        fwrite(STDERR, "SSE observer: connect failed ({$client->errMsg})\n");

        return;
    }

    $path = '/_sse?datastar=' . $sseQuery;
    $headers = implode("\r\n", [
        "GET {$path} HTTP/1.1",
        "Host: {$host}:{$port}",
        'Accept: text/event-stream',
        'Cache-Control: no-cache',
        'Connection: keep-alive',
        '',
        '',
    ]);
    $client->send($headers);

    // Skip HTTP response headers (read until \r\n\r\n).
    $headerBuf = '';
    while (!str_contains($headerBuf, "\r\n\r\n")) {
        $chunk = $client->recv(5.0);
        if ($chunk === false || $chunk === '') {
            fwrite(STDERR, "SSE observer: connection closed before headers\n");

            return;
        }
        $headerBuf .= $chunk;
    }

    // Any bytes after the header separator belong to the SSE body.
    $buf = substr($headerBuf, strpos($headerBuf, "\r\n\r\n") + 4);

    while ($observerDone->isEmpty()) {
        $chunk = $client->recv(0.5);
        if ($chunk === false) {
            // Distinguish a read timeout (normal — SSE is idle) from a real disconnect.
            if ($client->errCode === SOCKET_ETIMEDOUT || $client->errCode === 11 /* EAGAIN */) {
                continue;
            }

            break; // actual connection error or close
        }
        if ($chunk === '') {
            continue;
        }

        $buf .= $chunk;
        $lines = explode("\n", $buf);
        $buf = array_pop($lines) ?? '';

        foreach ($lines as $line) {
            $line = rtrim($line);
            // Datastar wire format: "data: signals {JSON}"
            if (!str_starts_with($line, 'data: signals ')) {
                continue;
            }
            $signals = json_decode(substr($line, 14), true);
            if (!is_array($signals)) {
                continue;
            }
            // Match exact key or namespaced key (count__scope__hash).
            foreach ($signals as $key => $val) {
                if ($key === $signalName || str_starts_with($key, $signalName . '__')) {
                    $patchChan->push((string) $val, 0);

                    break;
                }
            }
        }
    }

    $client->close();
}
