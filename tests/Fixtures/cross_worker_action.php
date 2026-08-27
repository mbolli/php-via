<?php

declare(strict_types=1);

/*
 * Fixture for CrossWorkerActionTest: a real multi-worker server, driven from inside.
 *
 * Loads one page (pinning the context to one worker), then fires N actions over fresh
 * connections so OpenSwoole's fd dispatch spreads them across every worker. Reports how many
 * were served and what the shared counter ended up at.
 *
 * Prints ok=<n> failed=<n> final=<n> expected=<n>.
 *
 * argv[1] = worker count, argv[2] = action count, argv[3] = "increment" | "setValue"
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;

$workers = (int) ($argv[1] ?? 4);
$actions = (int) ($argv[2] ?? 200);
$mode = (string) ($argv[3] ?? 'increment');

// Derived from the PID rather than fixed: see client_registry_workers.php.
$port = 3550 + (getmypid() % 150);
$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withDevMode(true)
        ->withWorkerNum($workers)->withBroker(new SwooleBroker())
);

$app->page('/probe', function (Context $c) use ($mode): void {
    $c->scope(Scope::ROUTE);
    $count = $c->signal(0, 'count');

    $bump = $c->action(function (Context $ctx) use ($mode): void {
        $signal = $ctx->getSignal('count');
        if ($mode === 'increment') {
            $signal->increment(broadcast: false);
        } else {
            $signal->setValue($signal->int() + 1, broadcast: false);
        }
    }, 'bump');

    $c->view(fn (): string => 'COUNT:' . $count->int() . ':CTX:' . $c->getId() . ':URL:' . $bump->url() . ':END');
});

/** One request on a brand-new connection, so dispatch is free to pick any worker. */
function request(int $port, string $method, string $path, string $body = '', string $cookie = ''): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 20]);

    $headers = [];
    if ($cookie !== '') {
        // The session-ownership gate is not optional: only the session that owns a context may
        // act on it, so the driver has to behave like a browser and send the cookie back.
        $headers['Cookie'] = $cookie;
    }
    if ($body !== '') {
        $headers['Content-Type'] = 'application/json';
        $client->setHeaders($headers);
        $client->post($path, $body);
    } else {
        if ($headers !== []) {
            $client->setHeaders($headers);
        }
        $client->get($path);
    }

    $status = $client->statusCode;
    $data = (string) $client->body;
    $setCookie = $client->set_cookie_headers ?? [];
    $client->close();

    return [$status, $data, $setCookie];
}

// Via::setInterval() is armed on the leader worker only (see ServerIntervalTest), which is
// exactly the "exactly one of the N workers drives this" election the fixture needs. The
// callback clears its own timer so it runs once.
$app->setInterval(static function () use ($app, $port, $actions): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, $actions): void {
        [, $page, $setCookie] = request($port, 'GET', '/probe');

        $cookie = '';
        foreach ((array) $setCookie as $raw) {
            $cookie .= explode(';', (string) $raw)[0] . '; ';
        }

        preg_match('/CTX:(.+?):URL:(.+?):END/', $page, $m);
        $ctxId = $m[1] ?? '';
        $actionUrl = $m[2] ?? '/_action/missing';

        $ok = 0;
        $failed = 0;
        $errs = [];
        $done = new Coroutine\Channel($actions);

        for ($i = 0; $i < $actions; ++$i) {
            Coroutine::create(static function () use ($port, $ctxId, $actionUrl, $cookie, $done): void {
                [$status, $body] = request($port, 'POST', $actionUrl, json_encode(['via_ctx' => $ctxId]), $cookie);
                $done->push($status === 200 ? '200' : $status . '|' . substr((string) $body, 0, 40));
            });
        }
        for ($i = 0; $i < $actions; ++$i) {
            $r = (string) $done->pop(15);
            if ($r === '200') {
                ++$ok;
            } else {
                ++$failed;
                $errs[$r] = ($errs[$r] ?? 0) + 1;
            }
        }

        // Read the counter back over a fresh connection, i.e. through whatever worker that
        // connection lands on — the value has to be visible from all of them.
        [, $after] = request($port, 'GET', '/probe', '', $cookie);
        preg_match('/COUNT:(\d+)/', $after, $c);

        echo 'ok=', $ok, "\n", 'failed=', $failed, "\n";
        echo 'final=', $c[1] ?? -1, "\n", 'expected=', $actions, "\n";

        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
