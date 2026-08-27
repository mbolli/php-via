<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Regression: non-idempotent patches were destroyed without being resent.
 *
 * prepareSignalsForPatch() called markSynced() at QUEUE time, so any patch
 * destroyed before transmission was never retried:
 *   - queue overflow evicted the oldest entry (drop-oldest, 50 slots), and
 *   - recreatePatchChannel() discarded the ENTIRE pending queue on every SSE
 *     reconnect, which needs no overflow at all.
 *
 * Element patches are idempotent full-fragment morphs and survive this. Signal
 * patches are deltas and did not: the client stayed stale until that signal
 * changed again, and because ActionHandler injects client values back into TAB
 * signals with markChanged:false, the stale client then overwrote the
 * authoritative server value on the user's next action.
 *
 * These tests drive the real Context/PatchManager and compare a simulated client
 * store against server state.
 */

/**
 * Drain every queued patch into a simulated client, the way SseHandler would.
 *
 * @return array<string, mixed> the client's signal store after applying them
 */
function drainInto(Context $context, array $clientStore = []): array {
    $pm = $context->getPatchManager();

    while (($patch = $pm->getPatch()) !== null) {
        if ($patch['type'] === 'signals') {
            $clientStore = array_replace($clientStore, $patch['content']);
        }

        // A real client acknowledges only what it actually received.
        if (isset($patch['confirm']) && is_callable($patch['confirm'])) {
            ($patch['confirm'])();
        }
    }

    return $clientStore;
}

test('a signal survives queue overflow and reaches the client', function (): void {
    $app = createVia();
    $context = new Context('overflow', '/test', $app);
    $status = $context->signal('idle', 'status');
    $context->view(fn (): string => '<div id="overflow">x</div>');

    // Each sync queues an elements patch plus a signals patch, so ~26 syncs
    // pushes past the 50-slot queue. Nothing drains in between: this models a
    // client whose socket is not being read.
    for ($i = 0; $i < 30; ++$i) {
        $status->setValue('run-' . $i);
        $context->sync();
    }

    $status->setValue('RUNNING');
    $context->sync();

    $client = drainInto($context);

    expect($client[$status->id()] ?? null)->toBe('RUNNING');
});

test('a signal survives an SSE reconnect that recreates the channel', function (): void {
    $app = createVia();
    $context = new Context('reconnect', '/test', $app);
    $turn = $context->signal('waiting', 'turn');
    $context->view(fn (): string => '<div id="reconnect">x</div>');

    // Server advances state while the client is briefly gone. Only two patches
    // are pending — far below the 50-slot cap.
    $turn->setValue('YOUR_TURN');
    $context->sync();

    // SSE reconnect: SseHandler recreates the channel, then calls sync().
    $context->getPatchManager()->recreatePatchChannel();
    $context->sync();

    $client = drainInto($context);

    expect($client[$turn->id()] ?? null)->toBe('YOUR_TURN');
});

test('an undelivered signal is not marked synced', function (): void {
    $app = createVia();
    $context = new Context('unacked', '/test', $app);
    $flag = $context->signal('off', 'flag');
    $context->view(fn (): string => '<div id="unacked">x</div>');

    $flag->setValue('on');
    $context->sync();

    // Queued but never written to a socket — the signal must still be dirty,
    // otherwise the next sync will not re-send it.
    expect($flag->hasChanged())->toBeTrue();
});

test('a delivered signal is marked synced and not resent', function (): void {
    $app = createVia();
    $context = new Context('acked', '/test', $app);
    $flag = $context->signal('off', 'flag');
    $context->view(fn (): string => '<div id="acked">x</div>');

    $flag->setValue('on');
    $context->sync();
    drainInto($context);

    expect($flag->hasChanged())->toBeFalse();

    // A sync with nothing dirty must not queue a signals patch.
    $context->sync();
    $types = [];
    while (($patch = $context->getPatchManager()->getPatch()) !== null) {
        $types[] = $patch['type'];
    }

    expect($types)->not->toContain('signals');
});

/*
 * Second half of the fix: the drop-oldest eviction policy was type-blind.
 *
 * 'script' patches (execScript) are non-idempotent and, unlike signals, have no
 * re-send mechanism to fall back on — a dropped redirect is a broken login flow
 * (LoginExample uses execScript for post-login navigation). Eviction must prefer
 * element patches, which are idempotent full-fragment morphs where latest wins.
 */

test('script patches are never evicted to make room', function (): void {
    $app = createVia();
    $context = new Context('scripts', '/test', $app);
    $context->view(fn (): string => '<div id="scripts">x</div>');

    $context->execScript('window.location.href = "/dashboard"');

    // Flood well past the 50-slot cap with idempotent element patches.
    for ($i = 0; $i < 80; ++$i) {
        $context->sync();
    }

    $scripts = [];
    while (($patch = $context->getPatchManager()->getPatch()) !== null) {
        if ($patch['type'] === 'script') {
            $scripts[] = $patch['content'];
        }
    }

    expect($scripts)->toContain('window.location.href = "/dashboard"');
});

test('element patches are still evicted so the queue stays bounded', function (): void {
    $app = createVia();
    $context = new Context('bounded', '/test', $app);
    $context->view(fn (): string => '<div id="bounded">x</div>');

    for ($i = 0; $i < 200; ++$i) {
        $context->sync();
    }

    $count = 0;
    while (($patch = $context->getPatchManager()->getPatch()) !== null) {
        ++$count;
    }

    expect($count)->toBeLessThanOrEqual(50);
});

test('pending patches survive an SSE reconnect instead of being discarded', function (): void {
    $app = createVia();
    $context = new Context('drain', '/test', $app);
    $context->view(fn (): string => '<div id="drain">x</div>');

    $context->execScript('alert("queued before the reconnect")');

    // SseHandler recreates the channel on every reconnect.
    $context->getPatchManager()->recreatePatchChannel();

    $scripts = [];
    while (($patch = $context->getPatchManager()->getPatch()) !== null) {
        if ($patch['type'] === 'script') {
            $scripts[] = $patch['content'];
        }
    }

    expect($scripts)->toContain('alert("queued before the reconnect")');
});
