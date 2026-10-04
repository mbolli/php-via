<?php

declare(strict_types=1);

/*
 * Fixture for ScopedRowsWorkersTest: per-entity scopes on two workers, created and abandoned.
 *
 * Loads /room/{id} for many rooms in batches, spread over both workers, and opens no stream, so each context is
 * destroyed at the connect timeout. The table holds one batch but not all of them, so every room after the first
 * batches needs the rows of expired rooms deleted. Then reads the row count on both workers, and checks that a room
 * used afterwards still shares its value: increments land on both workers and page loads on both show the total.
 *
 * Prints rooms=<n>, failed=<page loads that did not answer 200>, page_workers=<workers that served them>,
 * rows=<pid>:<rows>:<scopes> for each worker once the contexts expired, hits=<increments>, hit_workers=<workers they
 * ran on> and final=<pid>:<count> for a page load of the later room on each worker.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

const ROOMS = 160;

const BATCH = 20;

$port = FixturePort::pick(4740, 40);
$app = new Via(
    (new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withWorkerNum(2)
        ->withScopedSignalTableSize(64, 256)->withContextTimeouts(connectMs: 300)
);

$app->page('/room/{id}', static function (Context $c, string $id): void {
    $count = $c->signal(0, 'count', "room:{$id}");
    $c->signal('', 'topic', "room:{$id}");
    $c->view(static fn (): string => '<p>COUNT:' . $count->int() . ':PID:' . getmypid() . ':END</p>');
});

$app->route('GET', '/rows', new class($app) implements RequestHandlerInterface {
    public function __construct(private Via $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $store = (new ReflectionProperty(Via::class, 'sharedSignalStore'))->getValue($this->app);

        return new Psr7Response(200, [], 'ROWS:' . getmypid() . ':' . $store->count() . ':' . $store->scopeCount());
    }
});

$app->route('POST', '/hit', new class($app) implements RequestHandlerInterface {
    public function __construct(private Via $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $value = $this->app->getScopedSignalByName('room:final', 'count')?->increment() ?? -1;

        return new Psr7Response(200, [], 'HIT:' . $value . ':PID:' . getmypid());
    }
});

/**
 * One request on a new connection. With $keep the connection stays open, so the next one gets another fd and the
 * server is free to dispatch it to the other worker.
 *
 * @param list<Client> $keep
 *
 * @return array{int, string}
 */
function fetch(int $port, string $method, string $path, ?array &$keep = null): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 5]);
    $method === 'POST' ? $client->post($path, '') : $client->get($path);
    $result = [(int) $client->statusCode, (string) $client->body];
    if ($keep === null) {
        $client->close();
    } else {
        $keep[] = $client;
    }

    return $result;
}

/**
 * Request $path until both workers answered, matching $pattern for the pid in group 1.
 *
 * @return array<int, string> body per pid
 */
function onBothWorkers(int $port, string $method, string $path, string $pattern): array {
    $bodies = [];
    $open = [];
    for ($i = 0; $i < 40 && count($bodies) < 2; ++$i) {
        [, $body] = fetch($port, $method, $path, $open);
        if (preg_match($pattern, $body, $m) === 1) {
            $bodies[(int) $m[1]] ??= $body;
        }
    }
    foreach ($open as $client) {
        $client->close();
    }

    return $bodies;
}

$app->setInterval(static function () use ($app, $port): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port): void {
        // Batches small enough for the table, which only holds them all if expired rooms free their rows.
        $failed = 0;
        $pagePids = [];
        for ($room = 0; $room < ROOMS; ++$room) {
            $open = [];
            foreach ([1, 2] as $_) {
                [$status, $body] = fetch($port, 'GET', "/room/{$room}", $open);
                $failed += $status === 200 ? 0 : 1;
                if (preg_match('/PID:(\d+):END/', $body, $m) === 1) {
                    $pagePids[$m[1]] = true;
                }
            }
            foreach ($open as $client) {
                $client->close();
            }
            if ($room % BATCH === BATCH - 1) {
                Coroutine::usleep(800_000);
            }
        }
        echo 'rooms=', ROOMS, "\n", 'failed=', $failed, "\n", 'page_workers=', count($pagePids), "\n";

        Coroutine::usleep(800_000);
        foreach (onBothWorkers($port, 'GET', '/rows', '/ROWS:(\d+):/') as $body) {
            preg_match('/ROWS:(\d+):(\d+):(\d+)/', $body, $m);
            echo 'rows=', $m[1], ':', $m[2], ':', $m[3], "\n";
        }

        // Both workers hold room:final, so increments on either reach page loads on either.
        onBothWorkers($port, 'GET', '/room/final', '/PID:(\d+):END/');
        $hits = 0;
        $hitPids = [];
        $open = [];
        for ($i = 0; $i < 40 && count($hitPids) < 2; ++$i) {
            [, $body] = fetch($port, 'POST', '/hit', $open);
            if (preg_match('/HIT:(\d+):PID:(\d+)/', $body, $m) === 1) {
                ++$hits;
                $hitPids[$m[2]] = true;
            }
        }
        foreach ($open as $client) {
            $client->close();
        }
        echo 'hits=', $hits, "\n", 'hit_workers=', count($hitPids), "\n";
        foreach (onBothWorkers($port, 'GET', '/room/final', '/PID:(\d+):END/') as $pid => $body) {
            preg_match('/COUNT:(\d+)/', $body, $m);
            echo 'final=', $pid, ':', $m[1] ?? -1, "\n";
        }

        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
