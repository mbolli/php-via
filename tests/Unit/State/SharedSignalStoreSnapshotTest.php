<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\ReadEpochs;
use Mbolli\PhpVia\State\SharedSignalStore;

/*
 * Read snapshots: while Via runs a flush or fan-out it holds a read epoch, and a scoped Signal
 * read under it is loaded from the Table once. Plain Pest runs outside a coroutine, so every
 * call here shares coroutine id -1, as the synchronous broadcast path does.
 */

/** A scoped signal backed by $store, as Via::registerScopedSignal() attaches one. */
function snapshotSignal(SharedSignalStore $store, string $id, mixed $initial): Signal {
    $signal = new Signal($id, $initial, 'room:snap');
    $store->attachTo($signal);

    return $signal;
}

describe('ReadEpochs', function (): void {
    test('there is no epoch outside a fan-out', function (): void {
        expect((new ReadEpochs())->current())->toBe(0);
        expect((new SharedSignalStore(maxRows: 16))->readEpoch())->toBe(0);
    });

    test('begin opens an epoch and end closes it', function (): void {
        $epochs = new ReadEpochs();

        expect($epochs->begin())->toBeFalse();
        expect($epochs->current())->toBeGreaterThan(0);

        $epochs->end(false);

        expect($epochs->current())->toBe(0);
    });

    test('a nested begin joins the open epoch and its end leaves it open', function (): void {
        $epochs = new ReadEpochs();
        $epochs->begin();
        $outer = $epochs->current();

        expect($epochs->begin())->toBeTrue();
        expect($epochs->current())->toBe($outer);

        $epochs->end(true);

        expect($epochs->current())->toBe($outer);

        $epochs->end(false);

        expect($epochs->current())->toBe(0);
    });

    test('epochs are never reused, renew moves an open epoch past every earlier number, next() too', function (): void {
        $epochs = new ReadEpochs();

        $epochs->renew();
        expect($epochs->current())->toBe(0, 'renew opens nothing');

        $epochs->begin();
        $first = $epochs->current();
        $mark = $epochs->next();
        $epochs->renew();
        $renewed = $epochs->current();
        $epochs->end(false);

        $epochs->begin();
        $next = $epochs->current();
        $epochs->end(false);

        expect($mark)->toBeGreaterThan($first);
        expect($renewed)->toBeGreaterThan($mark);
        expect($next)->toBeGreaterThan($renewed);
    });

    test('renewals counts the renewals of an open epoch and nothing else', function (): void {
        $epochs = new ReadEpochs();
        $epochs->renew();

        expect($epochs->renewals)->toBe(0);

        $epochs->begin();
        $epochs->next();
        $epochs->renew();
        $epochs->end(false);

        expect($epochs->renewals)->toBe(1);
    });

    test('skipPast() hands out only epochs above the one given, and never moves back', function (): void {
        $epochs = new ReadEpochs();
        $epochs->skipPast(41);
        $epochs->begin();

        expect($epochs->current())->toBe(42);

        $epochs->end(false);
        $epochs->skipPast(10);

        expect($epochs->next())->toBe(43);
    });

    test('a store reads under its own epochs', function (): void {
        $store = new SharedSignalStore(maxRows: 16);
        $store->readEpochs()->begin();

        expect($store->readEpoch())->toBe($store->readEpochs()->current());
        expect($store->readEpoch())->toBeGreaterThan(0);

        $store->readEpochs()->end(false);
    });
});

describe('Signal reads under a snapshot', function (): void {
    test('a scoped signal is read once per snapshot', function (): void {
        $store = new SharedSignalStore(maxRows: 16);
        $signal = snapshotSignal($store, 'room_snap_list', ['a']);

        $store->readEpochs()->begin();
        expect($signal->array())->toBe(['a']);
        $store->set("room:snap\0room_snap_list", ['b']); // another worker writes

        expect($signal->array())->toBe(['a'], 'the snapshot keeps the value it loaded');

        $store->readEpochs()->renew();
        expect($signal->array())->toBe(['b'], 'a renewed snapshot reads again');
        $store->readEpochs()->end(false);
    });

    test('outside a snapshot every read goes to shared memory', function (): void {
        $store = new SharedSignalStore(maxRows: 16);
        $signal = snapshotSignal($store, 'room_snap_n', 1);

        $store->readEpochs()->begin();
        expect($signal->int())->toBe(1);
        $store->readEpochs()->end(false);

        $store->set("room:snap\0room_snap_n", 2);
        expect($signal->int())->toBe(2);
        $store->set("room:snap\0room_snap_n", 3);
        expect($signal->int())->toBe(3);
    });

    test('a local write inside a snapshot goes to shared memory and reads back its own value', function (string $how, mixed $expected): void {
        $store = new SharedSignalStore(maxRows: 16);
        $signal = snapshotSignal($store, 'room_snap_w', 1);

        $store->readEpochs()->begin();
        expect($signal->getValue())->toBe(1);
        $store->set("room:snap\0room_snap_w", 10); // another worker writes after the snapshot loaded 1

        match ($how) {
            'setValue' => $signal->setValue(5),
            'increment' => $signal->increment(4),
            'mutate' => $signal->mutate(static fn (mixed $v): int => (int) $v + 100),
            default => throw new LogicException($how),
        };

        expect($signal->getValue())->toBe($expected);
        $store->readEpochs()->end(false);
    })->with([
        'setValue' => ['setValue', 5],
        'increment' => ['increment', 14],
        'mutate' => ['mutate', 110],
    ]);

    test('a local write drops the cached value, so the next read sees a later write from another worker', function (string $how): void {
        $store = new SharedSignalStore(maxRows: 16);
        $signal = snapshotSignal($store, 'room_snap_d', 1);

        $store->readEpochs()->begin();
        expect($signal->getValue())->toBe(1);

        match ($how) {
            'setValue' => $signal->setValue(5),
            'increment' => $signal->increment(4),
            'mutate' => $signal->mutate(static fn (mixed $v): int => (int) $v + 100),
            default => throw new LogicException($how),
        };
        $store->set("room:snap\0room_snap_d", 10); // another worker writes after this one

        expect($signal->getValue())->toBe(10);
        $store->readEpochs()->end(false);
    })->with(['setValue', 'increment', 'mutate']);

    test('a write that does not fit is not served from the snapshot', function (): void {
        $store = new SharedSignalStore(maxRows: 16, maxValueSize: 64);
        $signal = snapshotSignal($store, 'room_snap_big', ['a']);

        $store->readEpochs()->begin();
        expect($signal->array())->toBe(['a']);

        expect(static fn () => $signal->setValue([str_repeat('x', 200)]))->toThrow(OverflowException::class);
        expect($signal->array())->toBe(['a'], 'what is stored, not the value that failed to store');
        $store->readEpochs()->end(false);
    });

    test('attaching another store drops the value cached under the first one', function (): void {
        $first = new SharedSignalStore(maxRows: 16);
        $signal = snapshotSignal($first, 'room_snap_a', 1);
        $first->readEpochs()->begin();
        expect($signal->int())->toBe(1);
        $first->readEpochs()->end(false);

        $second = new SharedSignalStore(maxRows: 16);
        $second->attachTo($signal);
        $second->readEpochs()->begin(); // the same epoch number as the first store's
        $second->set("room:snap\0room_snap_a", 2);

        expect($signal->int())->toBe(2);
        $second->readEpochs()->end(false);
    });

    test('the setValue() change check compares with shared memory, not the snapshot', function (): void {
        $app = createVia();
        $store = new SharedSignalStore(maxRows: 16);
        $app->setSharedSignalStore($store);
        $renders = 0;
        $observer = new Context(testContextId(), '/snap', $app);
        $observer->scope('room:snap');
        $app->contexts[$observer->getId()] = $observer;
        $observer->view(static function () use (&$renders): string {
            ++$renders;

            return '<div id="snap"></div>';
        }, cacheUpdates: false);

        $signal = new Signal('room_snap_c', 1, 'room:snap', app: $app);
        $store->attachTo($signal);

        $store->readEpochs()->begin();
        expect($signal->int())->toBe(1);
        $store->set("room:snap\0room_snap_c", 2); // another worker writes

        // Equal to the snapshot but not to what is stored, so clients showing 2 must hear about it.
        $signal->setValue(1);
        $store->readEpochs()->end(false);

        expect($renders)->toBe(1);
        expect($store->get("room:snap\0room_snap_c"))->toBe(1);
    });
});

describe('Frame epochs', function (): void {
    test('a store installed after fan-outs does not make the next fan-out look older than their frames', function (): void {
        $app = createVia();
        $renders = 0;
        $ctx = new Context(testContextId(), '/snap', $app);
        $ctx->scope('room:snap');
        $app->contexts[$ctx->getId()] = $ctx;
        $ctx->view(static function () use (&$renders): string {
            ++$renders;

            return '<div id="snap"></div>';
        }, cacheUpdates: false);

        foreach (range(1, 3) as $_) {
            $app->broadcast('room:snap');
        }
        $app->setSharedSignalStore(new SharedSignalStore(maxRows: 16));
        $app->broadcast('room:snap');

        expect($renders)->toBe(4);
    });
});
