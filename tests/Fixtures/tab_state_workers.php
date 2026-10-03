<?php

declare(strict_types=1);

/*
 * Fixture for TabStateWorkersTest: tab state and the page query on a real multi-worker server.
 *
 * Loads /tab?q=hello once, then posts actions that OpenSwoole's fd dispatch sends to every worker,
 * where each rebuilds the context from the shared directory:
 *   - `bump` one at a time, in turn over one kept-alive connection per worker: reads tabState('n')
 *     and writes n + 1, so the final n counts every bump only if every worker reads what the one
 *     before wrote;
 *   - `mark` all at once over fresh connections, each with its own key from the action's query, so
 *     a lost write under the tab's lock shows as a missing key.
 * Every handler run records input('q') under its process id. `report` copies the result into
 * globalState, which /report prints.
 *
 * Prints n=<int> marks=<int> handlers=<int> queries=<comma-separated input('q') values>.
 *
 * argv[1] = worker count, argv[2] = bump count, argv[3] = mark count
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$workers = (int) ($argv[1] ?? 4);
$bumps = (int) ($argv[2] ?? 24);
$marks = (int) ($argv[3] ?? 40);

$port = FixturePort::pick(5100, 150);
$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withDevMode(true)
        ->withWorkerNum($workers)->withContextDirectorySize(4096, maxTabStateBytes: 8192)
);

$app->page('/tab', function (Context $c) use ($app): void {
    $c->setTabState('q@' . getmypid(), $c->input('q'));

    $c->action(function (Context $c): void {
        $c->setTabState('n', $c->tabState('n', 0) + 1);
    }, 'bump');
    $c->action(function (Context $c): void {
        $c->setTabState('mark' . $c->input('k'), true);
    }, 'mark');
    $c->action(function (Context $c) use ($app): void {
        $marks = 0;
        $queries = [];
        // Keys are known: the handler wrote q@<pid>, and mark0..mark99 at most.
        for ($i = 0; $i < 100; ++$i) {
            $marks += $c->tabState('mark' . $i) === true ? 1 : 0;
        }
        foreach ((array) $c->input('pids') as $pid) {
            $q = $c->tabState('q@' . $pid);
            if ($q !== null) {
                $queries[] = $q;
            }
        }
        $app->setGlobalState('report', json_encode(['n' => $c->tabState('n', 0), 'marks' => $marks, 'queries' => $queries]));
    }, 'report');

    $c->view(fn (): string => 'CTX:' . $c->getId() . ':END');
});

$app->page('/report', function (Context $c) use ($app): void {
    $c->view(fn (): string => 'REPORT:' . $app->globalState('report', '{}') . ':END');
});

$app->page('/pid', function (Context $c): void {
    $c->view(fn (): string => 'PID:' . getmypid() . ':END');
});

function client(int $port): Client {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 20, 'keep_alive' => true]);

    return $client;
}

/**
 * One request on a brand-new connection, so dispatch is free to pick any worker.
 *
 * @return array{0: int, 1: string, 2: list<string>}
 */
function request(int $port, string $method, string $path, string $body = '', string $cookie = ''): array {
    $client = client($port);
    $result = send($client, $method, $path, $body, $cookie);
    $client->close();

    return $result;
}

/**
 * @return array{0: int, 1: string, 2: list<string>}
 */
function send(Client $client, string $method, string $path, string $body = '', string $cookie = ''): array {
    $headers = $cookie !== '' ? ['Cookie' => $cookie] : [];
    if ($method === 'POST') {
        $headers['Content-Type'] = 'application/json';
        $client->setHeaders($headers);
        $client->post($path, $body);
    } else {
        if ($headers !== []) {
            $client->setHeaders($headers);
        }
        $client->get($path);
    }

    return [(int) $client->statusCode, (string) $client->body, array_values((array) ($client->set_cookie_headers ?? []))];
}

$app->setInterval(static function () use ($app, $port, $bumps, $marks, $workers): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, $bumps, $marks, $workers): void {
        [, $page, $setCookie] = request($port, 'GET', '/tab?q=hello');
        $cookie = '';
        foreach ($setCookie as $raw) {
            $cookie .= explode(';', $raw)[0] . '; ';
        }
        preg_match('/CTX:(.+?):END/', $page, $m);
        $body = (string) json_encode(['via_ctx' => $m[1] ?? '']);

        // Opened together, the connections get consecutive fds, which dispatch maps to distinct workers.
        $pool = [];
        $pids = [];
        $opened = new Coroutine\Channel($workers);
        for ($i = 0; $i < $workers; ++$i) {
            $pool[$i] = client($port);
            // With the tab's cookie: a new session's Set-Cookie would stay in the client for the actions.
            Coroutine::create(static function () use ($pool, $i, $opened, $cookie): void {
                [, $pidPage] = send($pool[$i], 'GET', '/pid', '', $cookie);
                $opened->push(preg_match('/PID:(\d+):END/', $pidPage, $p) === 1 ? $p[1] : '');
            });
        }
        for ($i = 0; $i < $workers; ++$i) {
            $pids[(string) $opened->pop(15)] = true;
        }
        unset($pids['']);

        $failed = 0;
        for ($i = 0; $i < $bumps; ++$i) {
            [$status] = send($pool[$i % $workers], 'POST', '/_action/bump', $body, $cookie);
            $failed += $status === 200 ? 0 : 1;
        }

        $done = new Coroutine\Channel($marks);
        for ($i = 0; $i < $marks; ++$i) {
            Coroutine::create(static function () use ($port, $body, $cookie, $done, $i): void {
                [$status] = request($port, 'POST', '/_action/mark?k=' . $i, $body, $cookie);
                $done->push($status);
            });
        }
        for ($i = 0; $i < $marks; ++$i) {
            $failed += $done->pop(15) === 200 ? 0 : 1;
        }

        echo 'workers=', count($pids), "\n";
        $query = http_build_query(['pids' => array_keys($pids)]);
        request($port, 'POST', '/_action/report?' . $query, $body, $cookie);
        [, $reportPage] = request($port, 'GET', '/report');
        preg_match('/REPORT:(.+?):END/', $reportPage, $r);
        $report = json_decode($r[1] ?? '{}', true);

        echo 'failed=', $failed, "\n";
        echo 'n=', $report['n'] ?? -1, "\n";
        echo 'marks=', $report['marks'] ?? -1, "\n";
        echo 'handlers=', count($report['queries'] ?? []), "\n";
        echo 'queries=', implode(',', $report['queries'] ?? []), "\n";

        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
