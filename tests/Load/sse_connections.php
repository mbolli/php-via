<?php

declare(strict_types=1);

/**
 * sse_connections.php — SSE connection capacity load test.
 *
 * Ramps up persistent SSE connections (default: 1k → 5k → 10k) against a
 * running Via server and measures RSS memory per connection, patch delivery
 * latency, and connection success/failure rates at each milestone.
 *
 * Designed to run against the php-via website with zero modifications.
 * Defaults target the CounterExample at /examples/counter.
 *
 * How it works:
 *   1. For each of N connections:
 *      a. GET --route → parse via_ctx + current signals from <meta data-signals>
 *      b. GET /_sse?datastar={"via_ctx":"..."} → hold SSE connection open
 *   2. At each milestone, optionally read server RSS from /proc/{pid}/status.
 *      The server PID is discovered from GET /_via_pid (add this debug endpoint
 *      temporarily — see below). If not available, RSS is reported as "N/A".
 *   3. At peak: fire one /_action/{action} on the first established context,
 *      measure how long until the patch arrives on that SSE connection.
 *   4. Shut down all SSE connections and print the summary.
 *
 * What is tested:
 *   - How many coroutine SSE connections the server sustains simultaneously
 *   - RSS memory growth per connection (target: < 50 KB/connection)
 *   - Single-context patch delivery latency under peak connection load
 *   - Connection success rate (failed = HTTP non-200 or connect timeout)
 *
 * What is NOT tested:
 *   - Action throughput or patch delivery rate (see action_hammer.php)
 *   - Cross-node delivery (see tests/Feature/ScalingTest.php)
 *
 * Usage:
 *   php tests/Load/sse_connections.php [options]
 *
 * Options:
 *   --url=URL          Base URL of the Via app (default: http://127.0.0.1:3000)
 *   --route=PATH       Route to connect SSE on  (default: /examples/counter)
 *   --action=NAME      Action for latency test  (default: increment)
 *   --milestones=N,N   Connection counts        (default: 1000,5000,10000)
 *   --ramp-delay=MS    ms between connections   (default: 2)
 *   --timeout=SEC      Connect timeout (s)      (default: 10)
 *   --help             Show this help
 *
 * Optional server PID endpoint (add temporarily for RSS tracking):
 *   Via::page('/_via_pid', fn() => (string) getmypid());
 *
 * Metrics output:
 *   [milestone   1000]  connected:  998  failed:   2  RSS: 142 MB  growth:  98 MB  per-conn:  101 KB
 *   [milestone   5000]  connected: 4991  failed:   9  RSS: 580 MB  growth: 536 MB  per-conn:  107 KB
 *   [milestone  10000]  connected: 9977  failed:  23  RSS: 1.1 GB  growth: 1.0 GB  per-conn:  103 KB
 *   [latency @10000]  patch received in 47ms
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

$opts = getopt('', ['url:', 'route:', 'action:', 'milestones:', 'ramp-delay:', 'timeout:', 'help']);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__);

    exit(0);
}

$baseUrl = (string) ($opts['url'] ?? 'http://127.0.0.1:3000');
$route = (string) ($opts['route'] ?? '/examples/counter');
$actionName = (string) ($opts['action'] ?? 'increment');
$milestonesRaw = (string) ($opts['milestones'] ?? '1000,5000,10000');
$rampDelayMs = (int) ($opts['ramp-delay'] ?? 2);
$connectTimeout = (float) ($opts['timeout'] ?? 10.0);

$milestones = array_map('intval', array_filter(explode(',', $milestonesRaw)));
sort($milestones);
$maxConnections = end($milestones);

$parsedUrl = parse_url($baseUrl);
$host = $parsedUrl['host'] ?? '127.0.0.1';
$port = $parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'http') === 'https' ? 443 : 80);
$ssl = ($parsedUrl['scheme'] ?? 'http') === 'https';

echo "sse_connections.php\n";
echo "  URL         : {$baseUrl}\n";
echo "  Route       : {$route}\n";
echo "  Action      : {$actionName}  (latency test only)\n";
echo '  Milestones  : ' . implode(', ', $milestones) . "\n";
echo "  Ramp delay  : {$rampDelayMs} ms between connections\n";
echo "  Peak        : {$maxConnections} connections\n";
echo "\n";

// ── Main coroutine entry ──────────────────────────────────────────────────────

Coroutine::run(function () use (
    $host,
    $port,
    $ssl,
    $route,
    $actionName,
    $milestones,
    $maxConnections,
    $rampDelayMs,
    $connectTimeout
): void {
    // Discover server PID for RSS tracking.
    $serverPid = discoverServerPid($host, $port, $ssl);
    $baseRss = $serverPid !== null ? readRssKb($serverPid) : null;

    if ($serverPid !== null) {
        printf("  Server PID  : %d  (base RSS: %s)\n\n", $serverPid, formatKb($baseRss));
    } else {
        echo "  Server PID  : unknown — add GET /_via_pid for RSS tracking\n\n";
    }

    $connected = 0;
    $failed = 0;
    $shutdownChan = new Channel(1);
    $milestoneIdx = 0;
    $milestoneData = [];

    // The first SSE connection's context — used for the latency test.
    $firstCtxId = null;
    $firstCtxSignals = [];
    $firstActionPath = null;
    $latencyPatch = new Channel(1); // receives timestamp-ms of first patch at peak

    $wallStart = microtime(true);

    for ($i = 0; $i < $maxConnections; ++$i) {
        $connId = $i;

        Coroutine::create(function () use (
            $host,
            $port,
            $ssl,
            $route,
            $actionName,
            $connectTimeout,
            $shutdownChan,
            $latencyPatch,
            $connId,
            &$connected,
            &$failed,
            &$firstCtxId,
            &$firstCtxSignals,
            &$firstActionPath
        ): void {
            // Each connection independently loads the page to get its own via_ctx.
            [$ctxId, $signals, $actionPath] = sseLoadPage($host, $port, $ssl, $route, $actionName);

            if ($ctxId === null) {
                ++$failed;
                if ($connId < 5) {
                    fwrite(STDERR, "conn#{$connId}: sseLoadPage returned null ctxId\n");
                }

                return;
            }

            // Record the first ctx for the latency test.
            if ($firstCtxId === null) {
                $firstCtxId = $ctxId;
                $firstCtxSignals = $signals;
                $firstActionPath = $actionPath;
            }

            // Open SSE connection via raw TCP (HTTP Client::get() blocks on SSE
            // because it waits for the full response body, which never arrives).
            $sockType = $ssl ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
            $client = new Coroutine\Client($sockType);

            if (!$client->connect($host, $port, $connectTimeout)) {
                ++$failed;
                if ($connId < 5) {
                    fwrite(STDERR, "conn#{$connId}: TCP connect failed errCode={$client->errCode} errMsg={$client->errMsg}\n");
                }

                return;
            }

            $query = urlencode((string) json_encode(['via_ctx' => $ctxId]));
            $path = '/_sse?datastar=' . $query;
            $request = implode("\r\n", [
                "GET {$path} HTTP/1.1",
                "Host: {$host}:{$port}",
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
                '',
                '',
            ]);
            $client->send($request);

            // Read until we have the response headers to validate 200 OK.
            // Use a generous timeout per chunk but check shutdownChan between
            // receives so we don't block cleanup for up to 30s.
            $headerBuf = '';
            while (!str_contains($headerBuf, "\r\n\r\n")) {
                if (!$shutdownChan->isEmpty()) {
                    $client->close();

                    return; // shutting down mid-handshake — don't count as failure
                }
                $chunk = $client->recv(1.0);
                if ($chunk === false) {
                    if ($client->errCode === SOCKET_ETIMEDOUT || $client->errCode === 11) {
                        continue; // server is slow but still connected, keep waiting
                    }
                    ++$failed;
                    if ($connId < 5) {
                        fwrite(STDERR, "conn#{$connId}: header recv failed errCode={$client->errCode}\n");
                    }
                    $client->close();

                    return;
                }
                if ($chunk === '') {
                    continue;
                }
                $headerBuf .= $chunk;
            }

            // Check status line (e.g. "HTTP/1.1 200 OK").
            if (!str_contains($headerBuf, 'HTTP/1.1 200') && !str_contains($headerBuf, 'HTTP/1.0 200')) {
                ++$failed;
                if ($connId < 5) {
                    fwrite(STDERR, "conn#{$connId}: bad status: " . substr($headerBuf, 0, 60) . "\n");
                }
                $client->close();

                return;
            }

            ++$connected;
            $isFirst = ($connId === 0);
            // Carry over any body bytes that arrived with the headers.
            $buf = substr($headerBuf, strpos($headerBuf, "\r\n\r\n") + 4);

            while ($shutdownChan->isEmpty()) {
                $chunk = $client->recv(1.0);
                if ($chunk === false) {
                    // Distinguish an idle timeout (normal — SSE can be quiet)
                    // from a real disconnect.
                    if ($client->errCode === SOCKET_ETIMEDOUT || $client->errCode === 11 /* EAGAIN */) {
                        continue;
                    }

                    break; // actual connection error or server close
                }
                if ($chunk === '') {
                    continue;
                }

                // Only parse SSE for the first connection (latency test observer).
                if ($isFirst) {
                    $buf .= $chunk;
                    $lines = explode("\n", $buf);
                    $buf = array_pop($lines) ?? '';

                    $now = (int) (microtime(true) * 1000);
                    foreach ($lines as $line) {
                        if (str_starts_with(rtrim($line), 'data: signals ')) {
                            $latencyPatch->push($now, 0); // non-blocking; first wins
                        }
                    }
                }
            }

            --$connected;
            $client->close();
        });

        if ($rampDelayMs > 0) {
            usleep($rampDelayMs * 1000);
        } elseif ($i % 50 === 0) {
            usleep(1); // yield to scheduler
        }

        // Check milestone.
        if ($milestoneIdx < count($milestones) && ($i + 1) >= $milestones[$milestoneIdx]) {
            // Poll until connected + failed accounts for all spawned connections
            // (i.e. every coroutine has either established or given up), or until
            // 15s elapses. This avoids a fixed sleep that's too short for slow ramps.
            $target = $milestones[$milestoneIdx];
            $deadline = microtime(true) + 15.0;
            $prev = -1;
            while (microtime(true) < $deadline) {
                usleep(200_000);
                $total = $connected + $failed;
                // Stable when count stopped changing AND accounts for all spawned.
                if ($total >= $target && $total === $prev) {
                    break;
                }
                $prev = $total;
            }

            $rssKb = $serverPid !== null ? readRssKb($serverPid) : null;
            $growthKb = ($rssKb !== null && $baseRss !== null) ? $rssKb - $baseRss : null;
            $perConnKb = ($growthKb !== null && $connected > 0) ? (int) ($growthKb / $connected) : null;

            $milestoneData[$milestoneIdx] = compact('target', 'connected', 'failed', 'rssKb', 'growthKb', 'perConnKb');

            printf(
                "[milestone %6d]  connected: %5d  failed: %4d  RSS: %-10s growth: %-10s per-conn: %s\n",
                $target,
                $connected,
                $failed,
                formatKb($rssKb),
                formatKb($growthKb),
                $perConnKb !== null ? "{$perConnKb} KB" : 'N/A'
            );

            ++$milestoneIdx;
        }
    }

    // ── Latency test at peak ─────────────────────────────────────────────────

    $resolvedAction = $firstActionPath ?? "/_action/{$actionName}";
    printf("\n[latency test]  Firing %s against first context...\n", $resolvedAction);

    if ($firstCtxId === null) {
        echo "[latency test]  SKIP — no context established.\n";
    } else {
        // Clear any SSE events already buffered before the action.
        while (!$latencyPatch->isEmpty()) {
            $latencyPatch->pop(0);
        }

        $sentMs = (int) (microtime(true) * 1000);
        $body = array_merge($firstCtxSignals, ['via_ctx' => $firstCtxId]);
        $bodyStr = (string) json_encode($body);

        $actionClient = new Client($host, $port, $ssl);
        $actionClient->set(['timeout' => 5.0, 'ssl_verify_peer' => false, 'ssl_verify_host' => false]);
        $actionClient->setHeaders(['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($bodyStr)]);
        $actionClient->post($resolvedAction, $bodyStr);
        $actionStatus = $actionClient->statusCode;
        $actionClient->close();

        if ($actionStatus < 200 || $actionStatus >= 300) {
            printf(
                "[latency test]  Action returned HTTP %d (errCode=%d) — is %s registered?\n",
                $actionStatus,
                $actionClient->errCode ?? 0,
                $resolvedAction
            );
        } else {
            // Wait up to 3s for the patch on the first SSE connection.
            $arrivedMs = $latencyPatch->pop(3.0);
            if ($arrivedMs !== false) {
                printf(
                    "[latency test]  Patch arrived in %d ms (at %d active connections)\n",
                    $arrivedMs - $sentMs,
                    $connected
                );
            } else {
                echo "[latency test]  No patch received within 3s (first SSE connection may have dropped).\n";
            }
        }
    }

    // Shut down all SSE coroutines.
    $shutdownChan->push(1);
    usleep(500_000);

    // ── Summary ───────────────────────────────────────────────────────────────

    $wallTime = microtime(true) - $wallStart;
    echo "\n=== SUMMARY ===\n";
    printf("Wall time       : %.1fs\n", $wallTime);

    foreach ($milestoneData as $md) {
        printf(
            "  @ %6d conns : connected=%-5d failed=%-4d RSS=%-10s growth=%-10s per-conn=%s\n",
            $md['target'],
            $md['connected'],
            $md['failed'],
            formatKb($md['rssKb']),
            formatKb($md['growthKb']),
            $md['perConnKb'] !== null ? "{$md['perConnKb']} KB" : 'N/A'
        );
    }

    echo "\n";
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Load the page and parse via_ctx + all signals from <meta data-signals='...'>.
 * Also scrapes the action URL, preferring the hashless (route/global) form.
 *
 * @return array{null|string, array<string, mixed>, null|string}
 */
function sseLoadPage(string $host, int $port, bool $ssl, string $route, string $actionName = ''): array {
    $client = new Client($host, $port, $ssl);
    $client->set(['timeout' => 10.0, 'ssl_verify_peer' => false, 'ssl_verify_host' => false]);
    $client->get($route);
    $html = $client->body ?? '';
    $client->close();

    if (preg_match("/data-signals='([^']+)'/", $html, $m)) {
        $signals = json_decode(html_entity_decode($m[1]), true);
    } elseif (preg_match('/data-signals="([^"]+)"/', $html, $m)) {
        $signals = json_decode(html_entity_decode($m[1]), true);
    } else {
        $signals = null;
    }

    if (!is_array($signals) || !isset($signals['via_ctx'])) {
        return [null, [], null];
    }

    $actionPath = null;
    if ($actionName !== '') {
        $pattern = '/@post\(\'(\/\_action\/' . preg_quote($actionName, '/') . '(?:-[a-f0-9]+)?)\'\)/i';
        if (preg_match_all($pattern, $html, $am)) {
            foreach ($am[1] as $candidate) {
                if ($candidate === '/_action/' . $actionName) {
                    $actionPath = $candidate;

                    break;
                }
                $actionPath ??= $candidate;
            }
        }
    }

    return [(string) $signals['via_ctx'], $signals, $actionPath];
}

function discoverServerPid(string $host, int $port, bool $ssl): ?int {
    $client = new Client($host, $port, $ssl);
    $client->set(['timeout' => 3.0, 'ssl_verify_peer' => false, 'ssl_verify_host' => false]);
    $client->get('/_via_pid');
    $body = trim($client->body ?? '');
    $client->close();

    if ($client->statusCode === 200 && ctype_digit($body)) {
        return (int) $body;
    }

    return null;
}

function readRssKb(int $pid): ?int {
    $contents = @file_get_contents("/proc/{$pid}/status");
    if ($contents === false) {
        return null;
    }
    if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $contents, $m)) {
        return (int) $m[1];
    }

    return null;
}

function formatKb(?int $kb): string {
    if ($kb === null) {
        return 'N/A';
    }
    if ($kb < 1024) {
        return "{$kb} KB";
    }
    if ($kb < 1024 * 1024) {
        return round($kb / 1024, 1) . ' MB';
    }

    return round($kb / 1024 / 1024, 2) . ' GB';
}
