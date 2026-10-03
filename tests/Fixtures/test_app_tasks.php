<?php

declare(strict_types=1);

/*
 * Fixture for TestAppAdditionsTest: Testing\TestApp with Context::spawn() tasks and runTasks(). Once a
 * coroutine has run, OpenSwoole disables pcntl_fork() in the process, which the Pest process needs.
 *
 * Prints one JSON object per line: {"scenario": what it saw}.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

function tasksApp(callable $routes, ?Config $config = null): TestApp {
    return new TestApp(($config ?? new Config())->withLogLevel('error'), $routes);
}

/**
 * @param list<array<string, mixed>> $patches
 *
 * @return list<string>
 */
function tasksElements(array $patches): array {
    $html = [];
    foreach ($patches as $patch) {
        if ($patch['type'] === 'elements') {
            $html[] = (string) $patch['html'];
        }
    }

    return $html;
}

/**
 * @param list<string> $reports
 */
function tasksReportErrors(Via $via, array &$reports): void {
    $via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase) use (&$reports): void {
        $reports[] = $phase->value . ' ' . ($c === null ? 'null' : 'ctx') . ' ' . $e->getMessage();
    });
}

/**
 * Six broadcasts of room:a from a task, 60 ms apart.
 *
 * @return array{renders: list<string>, broadcasts: list<string>}
 */
function tasksThrottleRun(Config $config): array {
    $app = tasksApp(static function (Via $via): void {
        $via->page('/room', static function (Context $c) use ($via): void {
            $c->addScope('room:a');
            $c->action(static function () use ($c, $via): void {
                $c->spawn(static function () use ($via): void {
                    for ($i = 1; $i <= 6; ++$i) {
                        $via->setGlobalState('n', $i);
                        $via->broadcast('room:a');
                        Coroutine::usleep(60_000);
                    }
                });
            }, 'go');
            $c->view(static fn (): string => '<p id="n">' . $via->globalState('n', 0) . '</p>');
        });
    }, $config);
    $tab = $app->open('/room');
    $tab->patches();
    $tab->action('go');
    $app->runTasks();

    return ['renders' => tasksElements($tab->patches()), 'broadcasts' => $app->broadcasts()];
}

$scenarios = [
    'progress' => static function (): array {
        $app = tasksApp(static function (Via $via): void {
            $via->page('/query', static function (Context $c): void {
                $progress = $c->signal(0, 'progress');
                $c->action(static function () use ($c, $progress): void {
                    $c->spawn(static function (Context $c) use ($progress): void {
                        foreach ([50, 100] as $percent) {
                            Coroutine::usleep(2000);
                            $progress->setValue($percent);
                            $c->sync();
                        }
                    });
                }, 'run');
                $c->view(static fn (): string => '<p id="q">' . $progress->int() . '%</p>');
            });
        });
        $tab = $app->open('/query');
        $tab->patches();
        $tab->action('run');
        $before = ['renders' => tasksElements($tab->patches()), 'running' => $app->via()->runningTasks];
        $app->runTasks();

        return [
            'before' => $before,
            'renders' => tasksElements($tab->patches()),
            'signal' => $tab->signal('progress'),
            'running' => $app->via()->runningTasks,
            'logs' => $app->logs(),
        ];
    },

    'throw' => static function (): array {
        $reports = [];
        $app = tasksApp(static function (Via $via) use (&$reports): void {
            tasksReportErrors($via, $reports);
            $via->page('/t', static function (Context $c): void {
                $c->action(static function () use ($c): void {
                    $c->spawn(static function (): void {
                        Coroutine::usleep(1000);

                        throw new RuntimeException('the import failed');
                    });
                }, 'start');
                $c->view(static fn (): string => '<p id="t">t</p>');
            });
        });
        $app->open('/t')->action('start');
        $app->runTasks();

        return ['reports' => $reports, 'logs' => $app->logs()];
    },

    'destroyed' => static function (): array {
        $steps = [];
        $app = tasksApp(static function (Via $via) use (&$steps): void {
            $via->page('/poll', static function (Context $c) use (&$steps): void {
                $c->action(static function () use ($c, &$steps): void {
                    $c->spawn(static function (Context $c) use (&$steps): void {
                        while (!$c->isDestroyed()) {
                            Coroutine::usleep(1000);
                        }
                        $c->sync();
                        $steps[] = 'stopped';
                    });
                }, 'poll');
                $c->view(static fn (): string => '<p id="p">p</p>');
            });
        });
        $tab = $app->open('/poll')->action('poll');
        $tab->disconnect(expire: true);
        $app->runTasks();

        return ['steps' => $steps, 'running' => $app->via()->runningTasks, 'logs' => $app->logs()];
    },

    'shutdown' => static function (): array {
        $events = [];
        $app = tasksApp(static function (Via $via) use (&$events): void {
            $via->page('/daemon', static function (Context $c) use ($via, &$events): void {
                $c->spawn(static function () use ($via, &$events): void {
                    while (!$via->isShuttingDown()) {
                        Coroutine::usleep(1000);
                    }
                    $events[] = 'task stopped';
                });
                $c->view(static fn (): string => '<p id="d">d</p>');
            });
            $via->onWorkerStop(static function () use (&$events): void {
                $events[] = 'onWorkerStop';
            });
        });
        $app->open('/daemon');
        $running = $app->via()->runningTasks;
        $app->shutdown();

        return ['runningBefore' => $running, 'events' => $events, 'running' => $app->via()->runningTasks, 'logs' => $app->logs()];
    },

    'timeout' => static function (): array {
        $app = tasksApp(static function (Via $via): void {
            $via->page('/slow', static function (Context $c): void {
                $c->spawn(static fn () => Coroutine::usleep(300_000));
                $c->view(static fn (): string => '<p id="s">s</p>');
            });
        });
        $app->open('/slow');

        try {
            $app->runTasks(0.02);
            $error = null;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        $app->runTasks();

        return ['error' => $error, 'running' => $app->via()->runningTasks];
    },

    'throttle' => static fn (): array => [
        'free' => tasksThrottleRun(new Config()),
        'throttled' => tasksThrottleRun((new Config())->withBroadcastThrottle('room:*', 250)),
    ],
];

foreach ($scenarios as $name => $scenario) {
    try {
        $result = $scenario();
    } catch (Throwable $e) {
        $result = ['fixture_error' => $e::class . ': ' . $e->getMessage()];
    }
    gc_collect_cycles();
    echo json_encode([$name => $result], JSON_THROW_ON_ERROR), "\n";
}
