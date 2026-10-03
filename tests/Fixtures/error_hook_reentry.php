<?php

declare(strict_types=1);

/*
 * Runs onError() callbacks that start coroutines, tasks and broadcasts inside a real OpenSwoole reactor, which the
 * Pest process cannot host. Prints one line per check for ErrorHookTest.
 */

require __DIR__ . '/../../vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Support\ErrorHooks;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

Coroutine::set(['max_coroutine' => 200]);

/** @return ArrayObject<int, string> what $via logs from now on, one "[level] message" per line */
function reentryLogs(Via $via): ArrayObject {
    $lines = new ArrayObject();
    $logger = new class($lines) extends Logger {
        /** @param ArrayObject<int, string> $lines */
        public function __construct(private ArrayObject $lines) {
            parent::__construct('debug');
        }

        public function log(string $level, string $message, ?Context $context = null): void {
            $this->lines[] = "[{$level}] {$message}";
        }
    };
    (new ReflectionProperty(Via::class, 'logger'))->setValue($via, $logger);

    return $lines;
}

/** @param ArrayObject<int, string> $logs */
function reentryCount(ArrayObject $logs, string $needle): int {
    return count(array_filter((array) $logs, static fn (string $line): bool => str_contains($line, $needle)));
}

$failAction = static function (Via $via, string $id): void {
    $page = new Context($id, '/t', $via);
    $via->contexts[$id] = $page;
    $action = $page->action(static function (): void {
        throw new RuntimeException('save failed');
    }, 'save');
    $page->view(static fn (): string => '<p id="p">p</p>');
    (new ActionHandler($via))->handleAction(new FakeActionRequest($action->id(), ['via_ctx' => $id]), new FakeStaticResponse(), $action->id());
};

$waitForTasks = static function (Via $via): void {
    $deadline = microtime(true) + 3.0;
    while ($via->runningTasks > 0 && microtime(true) < $deadline) {
        Coroutine::usleep(5_000);
    }
};

Coroutine::run(static function () use ($failAction, $waitForTasks): void {
    // A callback that reports in a spawn() task whose tracker fails at once.
    $via = new Via((new Config())->withLogLevel('error'));
    $calls = 0;
    $via->onError(static function (Throwable $e, ?Context $c) use (&$calls): void {
        ++$calls;
        $c?->spawn(static function (): void {
            throw new RuntimeException('tracker: invalid DSN');
        });
    });
    $logs = reentryLogs($via);
    $failAction($via, 'ctx-instant');
    $waitForTasks($via);
    echo "task failing at once: calls={$calls} logged=" . reentryCount($logs, 'Task failed: RuntimeException: tracker: invalid DSN') . "\n";

    // The same with a tracker that fails after a wait, when the callback has returned.
    $via = new Via((new Config())->withLogLevel('error'));
    $calls = 0;
    $via->onError(static function (Throwable $e, ?Context $c) use (&$calls): void {
        ++$calls;
        $c?->spawn(static function (): void {
            Coroutine::usleep(5_000);

            throw new RuntimeException('tracker: connection refused');
        });
    });
    $logs = reentryLogs($via);
    $failAction($via, 'ctx-later');
    $waitForTasks($via);
    Coroutine::usleep(50_000);
    echo "task failing after a wait: calls={$calls} logged=" . reentryCount($logs, 'Task failed: RuntimeException: tracker: connection refused') . "\n";

    // A coroutine the callback creates reports while the callback waits for it, and after the callback returned.
    $hooks = new ErrorHooks(static function (): void {});
    $seen = [];
    $hooks->add(static function (Throwable $e) use ($hooks, &$seen): void {
        $seen[] = $e->getMessage();
        if ($e->getMessage() !== 'outer') {
            return;
        }
        $done = new Coroutine\Channel(1);
        Coroutine::create(static function () use ($hooks, $done): void {
            Coroutine::create(static function () use ($hooks): void {
                $hooks->report(new RuntimeException('grandchild, while the callback waits'), null, ErrorPhase::Render);
            });
            $done->push(true);
            Coroutine::usleep(20_000);
            $hooks->report(new RuntimeException('child, after the callback returned'), null, ErrorPhase::Render);
        });
        $done->pop(1.0);
    });
    $hooks->report(new RuntimeException('outer'), null, ErrorPhase::Task);
    Coroutine::usleep(60_000);
    echo 'coroutines of a callback: ' . implode(' | ', $seen) . "\n";

    // A callback that broadcasts into a scope whose view fails: the flush renders it later and fails again.
    $via = new Via((new Config())->withLogLevel('warn'));
    $calls = 0;
    $via->onError(static function () use ($via, &$calls): void {
        ++$calls;
        $via->broadcast('room:a');
    });
    $room = new Context('ctx-room', '/room', $via);
    $via->contexts['ctx-room'] = $room;
    $room->scope('room:a');
    $room->view(static function (): string {
        throw new RuntimeException('room view failed');
    });
    $logs = reentryLogs($via);
    $via->broadcast('room:a');
    Coroutine::usleep(1_500_000);
    echo "broadcast from a callback: calls={$calls}\n";
    echo 'limit warning names onError: ' . reentryCount($logs, 'Broadcast re-entrancy limit reached for scope "room:a"') . ' ' . reentryCount($logs, 'or an onError() callback does') . "\n";
});

echo "reactor done\n";
