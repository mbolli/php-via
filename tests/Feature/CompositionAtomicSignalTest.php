<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Table;

/*
 * The composition API's path to an atomic scoped mutation.
 *
 * PageMount hydrates each #[Signal] property from its signal before an #[Action] runs and syncs
 * it back afterwards, so `++$this->votes` on a shared signal is a read-modify-write with the
 * whole action body sitting in the gap. Two fixes meet here:
 *
 *  - #[Signal(Scope::GLOBAL, atomic: true)] makes syncBack() send the DIFFERENCE through
 *    Signal::increment() instead of assigning the whole value, so concurrent workers add up.
 *
 *  - syncBack() no longer writes a property back when the action did not change it. Before this,
 *    reaching the signal directly ($ctx->getSignal('votes')->increment()) had every write
 *    silently discarded: the stale hydrated property was assigned straight back over it.
 */

/** @return array{final: int, expected: int} */
function forkComposition(int $workers, int $each, string $mode): array {
    $fixture = dirname(__DIR__) . '/Fixtures/composition_atomic_fork.php';
    $out = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . $workers . ' ' . $each . ' ' . escapeshellarg($mode) . ' 2>&1'
    );

    expect($out)->toMatch('/final=\d+/', 'fixture output: ' . var_export($out, true));
    preg_match('/final=(\d+)/', $out, $f);
    preg_match('/expected=(\d+)/', $out, $e);

    return ['final' => (int) $f[1], 'expected' => (int) $e[1]];
}

/** Mount a page class on a bare Via and return its context, ready to fire actions. */
function mountPage(string $class): Context {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->mount($class, '/p');

    $ctx = new Context('/_/' . bin2hex(random_bytes(6)), '/p', $app, null, 'sess');
    $app->contexts[$ctx->getId()] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/p'], $ctx, []);

    return $ctx;
}

final class AtomicCounterPage {
    #[Signal(Scope::GLOBAL, atomic: true)]
    public int $votes = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => 'v=' . $this->votes);
    }

    #[Action]
    public function bump(Context $ctx): void {
        ++$this->votes;
    }

    #[Action]
    public function bumpBy5(Context $ctx): void {
        $this->votes += 5;
    }

    #[Action]
    public function untouched(Context $ctx): void {}
}

final class PlainCounterPage {
    #[Signal(Scope::GLOBAL)]
    public int $votes = 0;

    #[Signal]
    public string $draft = '';

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => 'v=' . $this->votes);
    }

    #[Action]
    public function bump(Context $ctx): void {
        ++$this->votes;
    }

    #[Action]
    public function viaSignal(Context $ctx): void {
        $ctx->getSignal('votes')->increment();
    }

    #[Action]
    public function mirrored(Context $ctx): void {
        $this->votes = $ctx->getSignal('votes')->increment();
    }

    #[Action]
    public function setDraft(Context $ctx): void {
        $this->draft = 'typed';
    }
}

test('a direct signal write inside an action is no longer clobbered by syncBack', function (): void {
    $ctx = mountPage(PlainCounterPage::class);
    $signal = $ctx->getSignal('votes');

    for ($i = 0; $i < 5; ++$i) {
        $ctx->executeAction('viaSignal');
    }

    // Before the syncBack fix this was 0: every increment was overwritten by the stale
    // hydrated property on the way out of the action.
    expect($signal->getValue())->toBe(5);
});

test('mirroring a direct increment onto the property does not double-count', function (): void {
    $ctx = mountPage(PlainCounterPage::class);
    $signal = $ctx->getSignal('votes');

    for ($i = 0; $i < 5; ++$i) {
        $ctx->executeAction('mirrored');
    }

    expect($signal->getValue())->toBe(5);
});

test('an atomic property increments the signal by the difference', function (): void {
    $ctx = mountPage(AtomicCounterPage::class);
    $signal = $ctx->getSignal('votes');

    $ctx->executeAction('bump');
    expect($signal->getValue())->toBe(1);

    $ctx->executeAction('bumpBy5');
    expect($signal->getValue())->toBe(6);
});

test('an atomic action that changes nothing writes nothing', function (): void {
    $ctx = mountPage(AtomicCounterPage::class);
    $signal = $ctx->getSignal('votes');
    $signal->setValue(9);

    $ctx->executeAction('untouched');

    expect($signal->getValue())->toBe(9);
});

test('a plain scoped property still assigns, not adjusts', function (): void {
    $ctx = mountPage(PlainCounterPage::class);
    $signal = $ctx->getSignal('votes');

    $ctx->executeAction('bump');

    expect($signal->getValue())->toBe(1);
});

test('a TAB signal still round-trips through the property', function (): void {
    $ctx = mountPage(PlainCounterPage::class);

    $ctx->executeAction('setDraft');

    expect($ctx->getSignal('draft')->getValue())->toBe('typed');
});

test('atomic: true is rejected on a non-integer property', function (): void {
    expect(fn () => mountPage(BadAtomicPage::class))->toThrow(InvalidArgumentException::class);
});

final class BadAtomicPage {
    #[Signal(Scope::GLOBAL, atomic: true)]
    public string $name = 'nope';

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => $this->name);
    }
}

describe('across real worker processes', function (): void {
    beforeEach(function (): void {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required to reproduce the worker fork');
        }
        if (!class_exists(Table::class)) {
            $this->markTestSkipped('OpenSwoole\Table required for shared memory');
        }
    });

    test('atomic: true keeps every increment under contention', function (): void {
        $r = forkComposition(workers: 6, each: 500, mode: 'atomic');

        expect($r['final'])->toBe(3000);
    });

    test('the escape hatch keeps every increment under contention', function (): void {
        $r = forkComposition(workers: 6, each: 500, mode: 'direct');

        expect($r['final'])->toBe(3000);
    });

    test('a plain scoped property still loses increments', function (): void {
        $r = forkComposition(workers: 6, each: 500, mode: 'plain');

        // The reason atomic: true exists. If this stops losing, the workers are no longer
        // overlapping and the two results above prove nothing. Measured over 8 runs the plain
        // property landed 2410-2514 of 3000, so the threshold has room without being toothless.
        expect($r['final'])->toBeLessThan(2900);
    });
});
