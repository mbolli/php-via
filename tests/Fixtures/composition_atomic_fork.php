<?php

declare(strict_types=1);

/*
 * Fixture for CompositionAtomicSignalTest.
 *
 * The composition API round-trips a scoped signal through a plain PHP property: PageMount
 * hydrates the property before the action and syncs it back afterwards. That makes
 * `++$this->votes` a read-modify-write across workers unless the property is declared
 * #[Signal(..., atomic: true)], which routes the difference through Signal::increment().
 *
 * The shared store is allocated in the master process before the fork, exactly as Via::start()
 * does it, and each forked worker mounts the same page class and fires the same #[Action].
 *
 * Prints "final=<value> expected=<value>".
 *
 * argv[1] = worker count
 * argv[2] = actions per worker
 * argv[3] = "atomic"   (#[Signal(Scope::GLOBAL, atomic: true)] + ++$this->votes)
 *           "plain"    (#[Signal(Scope::GLOBAL)] + ++$this->votes — the lossy baseline)
 *           "direct"   (escape hatch: $ctx->getSignal('votes')->increment())
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Event;

$workers = (int) ($argv[1] ?? 4);
$each = (int) ($argv[2] ?? 500);
$mode = (string) ($argv[3] ?? 'atomic');

final class AtomicPage {
    #[Signal(Scope::GLOBAL, atomic: true)]
    public int $votes = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => 'v=' . $this->votes);
    }

    #[Action]
    public function bump(Context $ctx): void {
        ++$this->votes;
    }
}

final class PlainPage {
    #[Signal(Scope::GLOBAL)]
    public int $votes = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => 'v=' . $this->votes);
    }

    #[Action]
    public function bump(Context $ctx): void {
        ++$this->votes;
    }
}

final class DirectPage {
    #[Signal(Scope::GLOBAL)]
    public int $votes = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => 'v=' . $this->votes);
    }

    #[Action]
    public function bump(Context $ctx): void {
        // The escape hatch. syncBack() must leave this alone rather than writing the stale
        // hydrated property back over it.
        $ctx->getSignal('votes')->increment(broadcast: false);
    }
}

const CLASSES = ['atomic' => AtomicPage::class, 'plain' => PlainPage::class, 'direct' => DirectPage::class];
const START = 'barrier_start';
const READY = 'barrier_ready';

// Master process: allocated before any fork, exactly as Via::start() does it.
$store = new SharedSignalStore(maxRows: 64);

function mountWorker(SharedSignalStore $store, string $contextId, string $class): Context {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->setSharedSignalStore($store);
    $app->mount($class, '/probe');

    $ctx = new Context($contextId, '/probe', $app, null, 'sess');
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/probe'], $ctx, []);

    return $ctx;
}

// Mount once in the master purely to learn the generated signal ID (scope-qualified and
// sanitised), so the read-back below does not have to guess it.
$signalId = mountWorker($store, '/probe_/master', CLASSES[$mode])->getSignal('votes')->id();

$pids = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $ctx = mountWorker($store, '/probe_/w' . $w, CLASSES[$mode]);

        // Start barrier: without it the first worker can finish its whole loop before the last
        // is forked, and the run measures nothing.
        $store->increment(READY, 1);
        while ((int) $store->get(START, 0) !== 1) {
            usleep(200);
        }

        // The action's syncBack auto-broadcasts scoped signals, which reaches PatchManager and
        // needs a scheduler.
        Coroutine::create(static function () use ($ctx, $each): void {
            for ($i = 0; $i < $each; ++$i) {
                $ctx->executeAction('bump');
            }
        });
        Event::wait();

        posix_kill(posix_getpid(), SIGKILL);
    }
    $pids[] = $pid;
}

// Release every worker at once, so they contend for the whole run rather than by accident.
$deadline = microtime(true) + 10;
while ((int) $store->get(READY, 0) < $workers) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timed out: only {$store->get(READY, 0)} of {$workers} workers arrived\n");

        break;
    }
    usleep(200);
}
$store->set(START, 1);

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Read back from the master, which never fired the action at all.
echo 'final=', (int) $store->get($signalId, 0), "\n";
echo 'expected=', $workers * $each, "\n";
