<?php

declare(strict_types=1);

use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\SharedSignalStore;

// The rows of a scope live while some process holds the scope, and a full table says so.

function scopeSignal(SharedSignalStore $store, string $scope, string $name, mixed $initial): Signal {
    $signal = new Signal("{$scope}_{$name}", $initial, $scope);
    $store->attachTo($signal);

    return $signal;
}

describe('SharedSignalStore scope holds', function (): void {
    test('the rows of a scope go when its last holder releases it', function (): void {
        $store = new SharedSignalStore(maxRows: 64);
        $store->holdScope('room:1');
        $store->holdScope('room:1');
        scopeSignal($store, 'room:1', 'a', 1);
        scopeSignal($store, 'room:1', 'b', 'x');
        $store->holdScope('room:2');
        scopeSignal($store, 'room:2', 'a', 2);

        $store->releaseScope('room:1');
        expect($store->count())->toBe(3, 'one holder is left');

        $store->releaseScope('room:1');
        expect($store->count())->toBe(1)
            ->and($store->scopeCount())->toBe(1)
            ->and($store->get("room:2\0room:2_a"))->toBe(2)
        ;
    });

    test('the holds of a process that died are swept with its rows', function (): void {
        $store = new SharedSignalStore(maxRows: 64);
        $store->holdScope('room:kept');
        scopeSignal($store, 'room:kept', 'a', 1);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $store->holdScope('room:crashed');
            $store->holdScope('room:kept');
            scopeSignal($store, 'room:crashed', 'a', 1);
            posix_kill(getmypid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);

        expect($store->count())->toBe(2)
            ->and($store->removeDeadHolders())->toBe(1)
            ->and($store->count())->toBe(1)
            ->and($store->has("room:kept\0room:kept_a"))->toBeTrue()
        ;
        $store->releaseScope('room:kept');
        expect($store->count())->toBe(0, 'the dead process no longer holds room:kept');
    });

    test('a signal attached without a hold is swept', function (): void {
        $store = new SharedSignalStore(maxRows: 64);
        scopeSignal($store, 'room:loose', 'a', 1);

        expect($store->removeDeadHolders())->toBe(1)
            ->and($store->count())->toBe(0)
        ;
    });

    test('a full table reports the dropped write once per interval', function (): void {
        $store = new SharedSignalStore(maxRows: 16);
        $reports = [];
        $store->onTableFull(static function (string $message) use (&$reports): void {
            $reports[] = $message;
        });

        for ($i = 0; $i < 2000 && $reports === []; ++$i) {
            $store->set("fill\0{$i}", $i);
        }
        $store->set("fill\0late", 'dropped');

        expect($reports)->toHaveCount(1)
            ->and($reports[0])->toContain('Scoped signal table is full')
            ->and($reports[0])->toContain('withScopedSignalTableSize()')
            ->and($store->has("fill\0late"))->toBeFalse()
        ;
    });
});
