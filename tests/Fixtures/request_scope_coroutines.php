<?php

declare(strict_types=1);

/*
 * Fixture for RequestScopeTest: which request a Context finds from coroutines, run in a process of its own because
 * a coroutine disables pcntl_fork() in the process for good.
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Context\RequestScope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

$app = new Via((new Config())->withLogLevel('error'));
$page = new Context('/_/scope-a', '/', $app);
$page->setPageInput(['v' => 'page']);
$other = new Context('/_/scope-b', '/', $app);
$component = null;
$page->component(function (Context $c) use (&$component): void {
    $component = $c;
    $c->view(static fn (): string => 'c');
}, 'w');

$request = static fn (string $v): RequestScope => new RequestScope($page, ['v' => $v], ['f' => ['name' => 'a', 'type' => 'text/plain', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK, 'size' => 1]], [], []);

Coroutine::run(static function () use ($page, $other, &$component, $request): void {
    $seen = [];
    $done = new Coroutine\Channel(2);
    foreach (['1', '2'] as $v) {
        Coroutine::create(static function () use ($page, $component, $request, $v, $done, &$seen): void {
            $scope = $request($v);
            $scope->bind();
            $before = $page->input('v');
            Coroutine::usleep($v === '1' ? 20_000 : 1_000);
            $seen[$v] = $before . '>' . $page->input('v') . '>' . $component->input('v');
            $scope->unbind();
            $done->push(true);
        });
    }
    $done->pop(1);
    $done->pop(1);
    echo 'interleaved=', $seen['1'], ',', $seen['2'], "\n";

    $scope = $request('3');
    $scope->bind();
    echo 'other_page=', var_export($other->input('v'), true), "\n";
    $child = new Coroutine\Channel(1);
    Coroutine::create(static function () use ($page, $child): void {
        $during = $page->input('v');
        Coroutine::usleep(10_000);
        $child->push($during . '>' . $page->input('v'));
    });
    $page->spawn(static function (Context $c) use ($child): void {
        Coroutine::usleep(10_000);
        $child->push($c->input('v') . '>' . var_export($c->file('f'), true));
    });
    $scope->answer();
    $scope->unbind();
    echo 'after_unbind=', $page->input('v'), "\n";
    echo 'child=', $child->pop(1), "\n";
    echo 'task=', $child->pop(1), "\n";
    echo 'queue_after_answer=', var_export($scope->queueCookie(['name' => 'n', 'value' => 'v', 'expires' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httpOnly' => true, 'sameSite' => 'Lax']), true), "\n";
});

$scope = $request('outside');
$scope->bind();
$fiber = new Fiber(static fn () => $page->input('v'));
$fiber->start();
echo 'outside=', $page->input('v'), '>', $fiber->getReturn(), "\n";
$scope->unbind();
echo 'outside_after=', $page->input('v'), "\n";
