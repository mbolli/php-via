<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Regression: concurrent fan-outs for the same scope interleaved, splitting one
 * logical frame across two states.
 *
 * doSyncLocally() renders each context in a loop. A render can suspend — a
 * first-ever Twig compile, or any hooked file I/O in a view, yields under
 * SWOOLE_HOOK_ALL — and a second broadcast can then run its ENTIRE fan-out before
 * the first resumes. The first loop's remaining contexts are then rendered against
 * the newer state, so some clients see frame N and others never see it at all.
 * Measured with 6 SSE clients: two distinct sequences, clients 2-5 skipped frame 11.
 *
 * It cannot be fixed by rendering once and pushing that value to every context:
 * a view that does not pass shareRender may differ per context
 * (LoginExample renders per-user session state), so sharing one render across
 * contexts would leak one user's view to another.
 *
 * Instead a fan-out for a scope is serialized. A broadcast arriving while one is
 * in flight is coalesced into a single re-run after it completes, which also
 * collapses broadcast storms into one extra pass.
 *
 * These tests model the interleave with re-entrancy — a view triggering a nested
 * broadcast — which drives the same nested-doSyncLocally path deterministically,
 * without needing a coroutine scheduler.
 *
 * Note the guarantee is bounded: it serializes fan-outs against each other, not
 * mutation of application state during one. A view reading a PHP static that an
 * action changes mid-loop will still observe the change partway through, which is
 * beyond what the framework can snapshot.
 */

test('a nested broadcast does not interleave with the fan-out in progress', function (): void {
    $app = createVia();
    $scope = 'room:split';

    $order = [];
    $tripped = false;

    foreach (range(0, 5) as $i) {
        $context = new Context('ctx' . $i, '/test', $app);
        $context->scope($scope);
        $context->view(function () use ($i, &$order, &$tripped, $app, $scope): string {
            $order[] = $i;

            // Stand-in for a render that suspends: partway through the fan-out a
            // second broadcast is triggered while this one is still looping.
            if ($i === 1 && !$tripped) {
                $tripped = true;
                $app->broadcast($scope);
            }

            return '<div id="ctx' . $i . '">x</div>';
        });
    }

    $app->broadcast($scope);

    // Two complete passes, back to back. Before the fix the inner broadcast ran its
    // whole fan-out from inside context 1's render, giving 0,1,0,1,2,3,4,5,2,3,4,5 —
    // contexts 2-5 rendered for the inner pass before the outer one had reached them.
    expect($order)->toBe([0, 1, 2, 3, 4, 5, 0, 1, 2, 3, 4, 5]);
});

test('broadcasts arriving during a fan-out coalesce into one re-run', function (): void {
    $app = createVia();
    $scope = 'room:coalesce';

    $renders = 0;
    $storm = 0;

    $context = new Context('only', '/test', $app);
    $context->scope($scope);
    $context->view(function () use (&$renders, &$storm, $app, $scope): string {
        ++$renders;

        // Ten broadcasts land while this fan-out is in flight.
        if ($storm === 0) {
            $storm = 1;
            for ($i = 0; $i < 10; ++$i) {
                $app->broadcast($scope);
            }
        }

        return '<div id="only">x</div>';
    });

    $app->broadcast($scope);

    // One initial pass plus a single coalesced re-run — not eleven.
    expect($renders)->toBe(2);
});

// Inside a coroutine they coalesce into one fan-out instead; see BroadcastCoalescingTest.
test('sequential broadcasts outside a coroutine still each fan out', function (): void {
    $app = createVia();
    $scope = 'room:sequential';

    $renders = 0;
    $context = new Context('seq', '/test', $app);
    $context->scope($scope);
    $context->view(function () use (&$renders): string {
        ++$renders;

        return '<div id="seq">x</div>';
    });

    $app->broadcast($scope);
    $app->broadcast($scope);
    $app->broadcast($scope);

    expect($renders)->toBe(3);
});

test('serialization is per scope, not global', function (): void {
    $app = createVia();

    $other = new Context('other', '/test', $app);
    $other->scope('room:b');
    $otherRenders = 0;
    $other->view(function () use (&$otherRenders): string {
        ++$otherRenders;

        return '<div id="other">x</div>';
    });

    $first = new Context('first', '/test', $app);
    $first->scope('room:a');
    $nested = false;
    $first->view(function () use (&$nested, $app): string {
        if (!$nested) {
            $nested = true;
            // A different scope must not be blocked by room:a being in flight.
            $app->broadcast('room:b');
        }

        return '<div id="first">x</div>';
    });

    $app->broadcast('room:a');

    expect($otherRenders)->toBe(1);
});
