<?php

declare(strict_types=1);

/*
 * Runs Context::spawn() tasks inside a real OpenSwoole reactor: a coroutine in the Pest process would
 * disable pcntl_fork() for the tests after it. Prints one line per check for SpawnTest and ErrorHookTest.
 */

require __DIR__ . '/../../vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

$via = new Via((new Config())->withLogLevel('error'));

/** @var list<string> $reports */
$reports = [];
$via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$reports): void {
    $reports[] = $phase->value . ' ' . ($c?->getId() ?? 'null') . ' ' . ($action ?? 'null') . ' ' . $e->getMessage();
});

$drain = static function (Context $c): int {
    $patches = 0;
    while ($c->getPatch() !== null) {
        ++$patches;
    }

    return $patches;
};

Coroutine::run(static function () use ($via, $drain, &$reports): void {
    // The task gets its context and runs in a coroutine of its own.
    $owner = new Context('ctx-owner', '/t', $via);
    $got = null;
    $taskCid = 0;
    $owner->spawn(static function (Context $c) use (&$got, &$taskCid): void {
        $got = $c;
        $taskCid = Coroutine::getCid();
    });
    echo 'task got its context: ' . ($got === $owner ? 'yes' : 'no') . "\n";
    echo 'task ran in a coroutine of its own: ' . ($taskCid > 0 && $taskCid !== Coroutine::getCid() ? 'yes' : 'no') . "\n";

    // A task that throws at once: spawn() still returns normally.
    (new Context('ctx-instant', '/t', $via))->spawn(static function (): void {
        throw new RuntimeException('task failed at once');
    });
    echo "spawn returned after a task threw at once\n";

    // A task an action starts fails on its own: the action still answers 200.
    $page = new Context('ctx-action', '/t', $via);
    $via->contexts[$page->getId()] = $page;
    $action = $page->action(static function (Context $c): void {
        $c->spawn(static function (): void {
            Coroutine::usleep(10_000);

            throw new RuntimeException('background step failed');
        });
    }, 'start');
    $response = new FakeStaticResponse();
    (new ActionHandler($via))->handleAction(new FakeActionRequest($action->id(), ['via_ctx' => $page->getId()]), $response, $action->id());
    echo "action answered: {$response->statusCode}\n";

    // A task that suspends, then throws: the worker keeps running and onError sees it.
    $thrower = new Context('ctx-thrower', '/t', $via);
    $runningBefore = $via->runningTasks;
    $thrower->spawn(static function (): void {
        Coroutine::usleep(20_000);

        throw new RuntimeException('task failed after a wait');
    });
    echo 'spawn returned before the task ended: ' . ($via->runningTasks === $runningBefore + 1 ? 'yes' : 'no') . "\n";

    // A task that suspends and then syncs what it changed.
    $sender = new Context('ctx-sender', '/t', $via);
    $count = $sender->signal(0, 'count');
    $sender->view(static fn (): string => '<div id="n">' . $count->int() . '</div>');
    $sender->spawn(static function (Context $c) use ($count): void {
        Coroutine::usleep(20_000);
        $count->setValue(5);
        $c->sync();
    });

    // A task whose context is destroyed while it waits: its sends do nothing, and it sees isDestroyed().
    $gone = new Context('ctx-gone', '/t', $via);
    $gone->view(static fn (): string => '<div id="g">g</div>');
    $seen = 'not run';
    $nested = 'not run';
    $gone->spawn(static function (Context $c) use (&$seen, &$nested): void {
        Coroutine::usleep(40_000);
        $c->sync();
        $c->syncSignals();
        $c->patchElements('<div id="toast">late</div>');
        $c->execScript('console.log(1)');
        $c->spawn(static function () use (&$nested): void {
            $nested = 'ran';
        });
        $seen = $c->isDestroyed() ? 'destroyed' : 'alive';
    });
    Coroutine::usleep(10_000);
    $gone->cleanup();

    $deadline = microtime(true) + 3.0;
    while ($via->runningTasks > 0 && microtime(true) < $deadline) {
        Coroutine::usleep(5_000);
    }

    echo "running tasks at the end: {$via->runningTasks}\n";
    echo 'sender patches: ' . $drain($sender) . "\n";
    echo "gone task: {$seen}\n";
    echo "task spawned on the destroyed context: {$nested}\n";
    echo 'gone patches: ' . $drain($gone) . "\n";
    foreach (array_unique($reports) as $report) {
        echo "report: {$report}\n";
    }
});

echo "reactor done\n";
