<?php

declare(strict_types=1);

/*
 * Real-server fixture for ActionRequestScopeTest: two actions of one tab at once, a spawn() task that outlives its
 * action, and a timer, each reading the request through the Context, and session rotations in an action and a task.
 *
 * Action 1 reads its request, sleeps while action 2 (a multipart upload from another cookie) runs, and reads again.
 * Prints key=value lines: first, second (what each action read before and after its sleep), first_cookies,
 * second_cookies, task, next_cookies, timer, login_cookies, during_login_cookies, task_login_cookies,
 * after_task_login_cookies.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$port = FixturePort::pick(4440, 20);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error'));

$app->middleware(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $v = $request->getQueryParams()['v'] ?? null;

        return $v === null ? $handler->handle($request) : $handler->handle($request->withAttribute('tag', 'tag' . $v));
    }
});

/** @var array<string, string> $seen */
$seen = [];

function described(Context $c): string {
    return implode('/', [
        (string) $c->input('v'),
        (string) $c->cookie('who'),
        (string) $c->getRequestAttribute('tag'),
        $c->file('f') !== null ? 'file' : 'nofile',
    ]);
}

$app->page('/', function (Context $c) use (&$seen): void {
    $c->action(function (Context $c) use (&$seen): void {
        $before = described($c);
        $c->setCookie('probe' . $c->input('v'), 'x');
        Coroutine::usleep((int) $c->input('sleep', 0));
        $seen['probe' . $c->input('v')] = $before . ' ' . described($c);
    }, 'probe');

    $c->action(function (Context $c) use (&$seen): void {
        $c->spawn(static function (Context $c) use (&$seen): void {
            Coroutine::usleep(150_000);
            $seen['task'] = described($c);
            $c->setCookie('late', 'x');
        });
    }, 'task');

    $c->action(function (Context $c): void {
        $c->regenerateSession();
        Coroutine::usleep((int) $c->input('sleep', 0));
    }, 'login');

    $c->action(function (Context $c): void {
        $c->spawn(static function (Context $c): void {
            Coroutine::usleep(150_000);
            $c->regenerateSession();
        });
    }, 'logintask');

    $c->setInterval(static function () use ($c, &$seen): void {
        $seen['timer'] = (string) $c->input('q') . '/' . (string) $c->input('v');
    }, 50);

    $c->view(static fn (): string => '<main id="m">CTX:' . $c->getId() . ':END</main>');
});

/**
 * @param array<string, string> $files field => path, sent as multipart/form-data with via_ctx as a field
 * @param array<string, string> $set   receives the cookies the response sets, name => value
 *
 * @return list<string> the names of the cookies the response sets
 */
function post(int $port, string $path, string $ctx, string $cookie, array $files = [], array &$set = []): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 10]);
    $headers = ['Origin' => "http://127.0.0.1:{$port}", 'Cookie' => $cookie];
    if ($files === []) {
        $client->setHeaders($headers + ['Content-Type' => 'application/json']);
        $client->post($path, json_encode(['via_ctx' => $ctx]));
    } else {
        $client->setHeaders($headers);
        foreach ($files as $field => $file) {
            $client->addFile($file, $field);
        }
        $client->post($path, ['via_ctx' => $ctx]);
    }
    $names = array_map(static fn (string $raw): string => explode('=', $raw, 2)[0], (array) ($client->set_cookie_headers ?? []));
    $set = (array) ($client->cookies ?? []);
    $status = $client->statusCode;
    $client->close();

    return $status === 200 ? $names : ["status{$status}"];
}

$app->setInterval(static function () use ($app, $port, &$seen): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, &$seen): void {
        $upload = sys_get_temp_dir() . '/via-request-scope-' . getmypid() . '.txt';
        file_put_contents($upload, 'upload');

        $client = new Client('127.0.0.1', $port);
        $client->get('/?q=page');
        $page = (string) $client->body;
        $session = explode(';', (string) ($client->set_cookie_headers ?? [''])[0])[0];
        $client->close();
        preg_match('/CTX:(.+?):END/', $page, $m);
        $ctx = $m[1] ?? '';

        $first = [];
        $done = new Coroutine\Channel(1);
        Coroutine::create(static function () use ($port, $ctx, $session, $done, &$first): void {
            $first = post($port, '/_action/probe?v=1&sleep=300000', $ctx, "{$session}; who=alice");
            $done->push(true);
        });
        Coroutine::usleep(100_000);
        $second = post($port, '/_action/probe?v=2', $ctx, "{$session}; who=bob", ['f' => $upload]);
        $done->pop(5);

        echo 'first=', $seen['probe1'] ?? '', "\n";
        echo 'second=', $seen['probe2'] ?? '', "\n";
        echo 'first_cookies=', implode(',', $first), "\n";
        echo 'second_cookies=', implode(',', $second), "\n";

        post($port, '/_action/task?v=7', $ctx, "{$session}; who=carol", ['f' => $upload]);
        Coroutine::usleep(300_000);
        echo 'task=', $seen['task'] ?? '', "\n";
        echo 'next_cookies=', implode(',', post($port, '/_action/probe?v=3', $ctx, "{$session}; who=dave")), "\n";
        echo 'timer=', $seen['timer'] ?? '', "\n";

        $login = [];
        $set = [];
        Coroutine::create(static function () use ($port, $ctx, $session, $done, &$login, &$set): void {
            $login = post($port, '/_action/login?sleep=300000', $ctx, $session, set: $set);
            $done->push(true);
        });
        Coroutine::usleep(100_000);
        echo 'during_login_cookies=', implode(',', post($port, '/_action/probe?v=4', $ctx, $session)), "\n";
        $done->pop(5);
        echo 'login_cookies=', implode(',', $login), "\n";

        $rotated = 'via_session_id=' . ($set['via_session_id'] ?? '');
        echo 'task_login_cookies=', implode(',', post($port, '/_action/logintask', $ctx, $rotated)), "\n";
        Coroutine::usleep(300_000);
        echo 'after_task_login_cookies=', implode(',', post($port, '/_action/probe?v=5', $ctx, $rotated)), "\n";

        @unlink($upload);
        $app->getServer()?->shutdown();
    });
}, 200);

$app->start();
