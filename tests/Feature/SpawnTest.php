<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;
use OpenSwoole\Coroutine\Http\Client;

/*
 * Context::spawn() runs per-tab background work in a coroutine. Once a coroutine has run in a process,
 * OpenSwoole disables pcntl_fork(), which other tests need, so tasks run in tests/Fixtures/task_reactor.php
 * and spawn_shutdown_server.php, and the Pest process only checks what needs no coroutine.
 */

/**
 * @return list<string> the fixture's output lines
 */
function spawnRunFixture(string $fixture, string ...$args): array {
    $command = 'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/' . $fixture);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $output = [];
    exec($command . ' 2>&1', $output);

    return $output;
}

/**
 * One run of spawn_shutdown_server.php, shared by the tests that read it.
 *
 * @return array{out: string, marker: list<string>}
 */
function spawnShutdownRun(): array {
    static $run = null;
    if ($run !== null) {
        return $run;
    }

    $marker = sys_get_temp_dir() . '/via_spawn_' . bin2hex(random_bytes(6));

    try {
        $out = implode("\n", spawnRunFixture('spawn_shutdown_server.php', $marker));
        $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($marker))));
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
    }

    return $run = ['out' => $out, 'marker' => $lines];
}

describe('in the Pest process', function (): void {
    test('once the context is destroyed, sends do nothing and spawn() does not run the task', function (): void {
        $via = createVia();
        $page = new Context('ctx-destroyed', '/spawn', $via);
        $count = $page->signal(0, 'count');
        $page->view(fn (): string => '<div id="n">' . $count->int() . '</div>');
        expect($page->isDestroyed())->toBeFalse();

        $page->cleanup();
        $count->setValue(1);
        $page->sync();
        $page->syncSignals();
        $page->patchElements('<div id="toast">late</div>');
        $page->patchElements(selector: '#toast', mode: PatchMode::Remove);
        $page->execScript('console.log(1)');
        $ran = false;
        $page->spawn(function () use (&$ran): void {
            $ran = true;
        });

        expect($page->isDestroyed())->toBeTrue()
            ->and($page->getPatch())->toBeNull()
            ->and($ran)->toBeFalse()
        ;
    });

    test('patchElements() still rejects a call that could never work on a destroyed context', function (): void {
        $page = new Context('ctx-destroyed-args', '/spawn', createVia());
        $page->cleanup();

        expect(fn () => $page->patchElements())->toThrow(InvalidArgumentException::class);
    });

    test('a component is destroyed with its page', function (): void {
        $via = createVia();
        $page = new Context('ctx-parent', '/spawn', $via);
        $component = null;
        $page->component(function (Context $c) use (&$component): void {
            $component = $c;
            $c->view(fn (): string => '<p>c</p>');
        }, 'child');

        $page->cleanup();

        expect($component?->isDestroyed())->toBeTrue();
    });
});

/**
 * @return list<string> one run of task_reactor.php, shared by the tests that read it
 */
function spawnReactorRun(): array {
    static $out = null;

    return $out ??= spawnRunFixture('task_reactor.php');
}

describe('in a reactor', function (): void {
    test('the task receives its context and runs in a coroutine of its own', function (): void {
        expect(implode("\n", spawnReactorRun()))->toContain(
            "task got its context: yes\n",
            "task ran in a coroutine of its own: yes\n",
        );
    });

    test('a throw is logged and goes to onError with ErrorPhase::Task, and spawn() returns normally', function (): void {
        expect(implode("\n", spawnReactorRun()))->toContain(
            '[ERROR] [ctx-instant] Task failed: RuntimeException: task failed at once at ',
            "spawn returned after a task threw at once\n",
            "report: task ctx-instant null task failed at once\n",
            'reactor done',
        );
    });

    test('a task started by an action fails on its own: the action still answers 200 and onError says Task', function (): void {
        $out = implode("\n", spawnReactorRun());

        expect($out)->toContain("action answered: 200\n", "report: task ctx-action null background step failed\n")
            ->and($out)->not->toContain('report: action')
        ;
    });

    test('a task that waits runs after spawn() returns, sends what it syncs, and its throw reaches onError', function (): void {
        expect(implode("\n", spawnReactorRun()))->toContain(
            "spawn returned before the task ended: yes\n",
            "sender patches: 2\n",
            "running tasks at the end: 0\n",
            "report: task ctx-thrower null task failed after a wait\n",
        );
    });

    test('a task whose context is destroyed while it waits sees isDestroyed(), and its sends and subtasks do nothing', function (): void {
        $out = implode("\n", spawnReactorRun());

        expect($out)->toContain(
            "gone task: destroyed\n",
            "gone patches: 0\n",
            "task spawned on the destroyed context: not run\n",
        );
    });
});

describe('a stopping worker', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class) || !function_exists('posix_kill')) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client and ext-posix required');
        }
    });

    test('waits for tasks before the onWorkerStop callbacks and again after them', function (): void {
        ['out' => $out, 'marker' => $lines] = spawnShutdownRun();

        $context = 'fixture output: ' . var_export($out, true) . ' marker: ' . var_export($lines, true);
        expect(in_array('page 200', $lines, true))->toBeTrue($context);
        $order = array_values(array_intersect($lines, ['task-quick', 'stop-callback', 'task-told']));
        expect($order)->toBe(['task-quick', 'stop-callback', 'task-told'], $context)
            ->and($out)->not->toContain('still running after shutdown')
        ;
    });

    test('reports a throwing Via::setInterval() callback with ErrorPhase::Timer and no context', function (): void {
        ['out' => $out, 'marker' => $lines] = spawnShutdownRun();

        expect(in_array('error timer null server interval failed', $lines, true))->toBeTrue(var_export([$out, $lines], true))
            ->and($out)->toContain('Interval callback failed: RuntimeException: server interval failed')
        ;
    });
});
