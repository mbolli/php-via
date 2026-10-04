<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Via;

/*
 * A leader-only timer reaches a scoped signal through getScopedSignalByName(). With worker_num > 1 the
 * contexts that declared it may all live on another worker; the lookup then hands out a detached handle
 * on the shared value instead of null, and never creates or registers a signal on the leader.
 */

function handleWorker(SharedSignalStore $store): Via {
    $app = new Via((new Config())->withLogLevel('error')->withBroadcastCoalescing(false));
    $app->setSharedSignalStore($store);

    return $app;
}

describe('getScopedSignalByName() on a worker without the signal', function (): void {
    test('returns a handle on the shared value that another worker declared', function (): void {
        $store = new SharedSignalStore(maxRows: 64);
        $leader = handleWorker($store);
        $other = handleWorker($store);
        $declared = (new Context('O', '/chat', $other, null, 'sess-o'))
            ->signal(['user' => '', 'until' => 0], 'typingIndicator', 'room:lobby', false, true)
        ;
        $declared->setValue(['user' => 'ann', 'until' => 5]);

        $handle = $leader->getScopedSignalByName('room:lobby', 'typingIndicator');

        expect($handle)->not->toBeNull()
            ->and($handle?->id())->toBe($declared->id())
            ->and($handle?->getValue())->toBe(['user' => 'ann', 'until' => 5])
            ->and($leader->getScopedSignals('room:lobby'))->toBe([])
        ;

        $handle?->setValue(['user' => '', 'until' => 0]);

        expect($declared->getValue())->toBe(['user' => '', 'until' => 0]);
    });

    test('leaves a later declaration on that worker its own default and flags', function (): void {
        $store = new SharedSignalStore(maxRows: 64);
        $leader = handleWorker($store);
        $other = handleWorker($store);
        (new Context('O', '/chat', $other, null, 'sess-o'))->signal('live', 'note', 'room:lobby', false, true);

        $leader->getScopedSignalByName('room:lobby', 'note');
        $local = (new Context('L', '/chat', $leader, null, 'sess-l'))->signal('default', 'note', 'room:lobby', false, true);

        expect($local->getValue())->toBe('live')
            ->and($local->isClientWritable())->toBeTrue()
            ->and((new ReflectionProperty($local, 'autoBroadcast'))->getValue($local))->toBeFalse()
            ->and($leader->getScopedSignalByName('room:lobby', 'note'))->toBe($local)
        ;
    });

    test('is still null when no worker declared the signal', function (): void {
        $leader = handleWorker(new SharedSignalStore(maxRows: 64));

        expect($leader->getScopedSignalByName('room:lobby', 'nobody'))->toBeNull();
    });
});
