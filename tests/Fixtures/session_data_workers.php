<?php

declare(strict_types=1);

/*
 * Fixture for SharedSessionDataTest: session data on a real multi-worker server.
 *
 * One page load gets a session cookie. N actions then each store their own key in that session,
 * concurrently and over fresh connections, so OpenSwoole's fd dispatch spreads them across every
 * worker. The page is loaded again R times, each over a fresh connection, and reports how many of
 * the N keys it sees.
 *
 * Prints "ok=<n> failed=<n> expected=<n> seen=<n>,<n>,...".
 *
 * argv[1] = worker count, argv[2] = action count, argv[3] = read-back count
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$workers = (int) ($argv[1] ?? 4);
$actions = (int) ($argv[2] ?? 40);
$reads = (int) ($argv[3] ?? 8);

$port = FixturePort::pick(4800, 150);
$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withDevMode(true)
        ->withWorkerNum($workers)->withBroker(new SwooleBroker())
);

$app->page('/probe', function (Context $c) use ($actions): void {
    $put = $c->action(function (Context $ctx): void {
        $i = (int) $ctx->input('i', -1);
        $ctx->setSessionData("k{$i}", $i);
    }, 'put');

    $c->view(function () use ($c, $put, $actions): string {
        $seen = 0;
        for ($i = 0; $i < $actions; ++$i) {
            if ($c->sessionData("k{$i}") === $i) {
                ++$seen;
            }
        }

        return 'SEEN:' . $seen . ':CTX:' . $c->getId() . ':URL:' . $put->url() . ':END';
    });
});

/** One request on a brand-new connection, so dispatch is free to pick any worker. */
function request(int $port, string $method, string $path, string $body = '', string $cookie = ''): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 20]);

    $headers = $cookie !== '' ? ['Cookie' => $cookie] : [];
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

    $result = [$client->statusCode, (string) $client->body, $client->set_cookie_headers ?? []];
    $client->close();

    return $result;
}

// setInterval() runs on the leader worker only, so exactly one worker drives the run.
$app->setInterval(static function () use ($app, $port, $actions, $reads): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, $actions, $reads): void {
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
        $done = new Coroutine\Channel($actions);
        for ($i = 0; $i < $actions; ++$i) {
            Coroutine::create(static function () use ($port, $ctxId, $actionUrl, $cookie, $done, $i): void {
                [$status] = request($port, 'POST', $actionUrl . '?i=' . $i, json_encode(['via_ctx' => $ctxId]), $cookie);
                $done->push($status === 200);
            });
        }
        for ($i = 0; $i < $actions; ++$i) {
            $done->pop(15) === true ? ++$ok : ++$failed;
        }

        $seen = [];
        for ($r = 0; $r < $reads; ++$r) {
            [, $after] = request($port, 'GET', '/probe', '', $cookie);
            preg_match('/SEEN:(\d+)/', $after, $s);
            $seen[] = $s[1] ?? -1;
        }

        echo 'ok=', $ok, ' failed=', $failed, ' expected=', $actions, ' seen=', implode(',', $seen), "\n";

        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
