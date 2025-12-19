<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * SSE Updates Tests
 *
 * Tests Server-Sent Events (SSE) updates and patch generation.
 * Verifies that patches are generated correctly for signal and view updates.
 *
 * KNOWN ISSUE: sync() always sends element patches even when view is cached.
 * This causes unnecessary datastar-patch-elements events in SSE responses
 * when only signals change (e.g., stock ticker example sends empty element
 * patches along with signal patches on every update).
 */

describe('Patch Queue Behavior', function (): void {
    test('signals marked as changed generate patches', function (): void {
        $app = createVia();

        $context = new Context('ctx1', '/test', $app);
        $counter = $context->signal(0, 'counter');

        // Signal is initially changed (new signal)
        expect($counter->hasChanged())->toBeTrue();
    });

    test('signals marked as synced do not generate duplicate patches', function (): void {
        $app = createVia();

        $context = new Context('ctx1', '/test', $app);
        $counter = $context->signal(0, 'counter');

        // Mark as synced
        $counter->markSynced();
        expect($counter->hasChanged())->toBeFalse();

        // Update the signal
        $counter->setValue(10);
        expect($counter->hasChanged())->toBeTrue();
    });

    test('scoped signals share values across contexts', function (): void {
        $app = createVia();

        $ctx1 = new Context('ctx1', '/stock/AAPL', $app);
        $ctx1->scope(Scope::ROUTE);
        $price1 = $ctx1->signal(100.0, 'price');

        $ctx2 = new Context('ctx2', '/stock/AAPL', $app);
        $ctx2->scope(Scope::ROUTE);
        $price2 = $ctx2->signal(100.0, 'price');

        // Signals are the same object
        expect($price1)->toBe($price2);

        // Update through one context
        $price1->setValue(105.0);

        // Other context sees the update
        expect($price2->getValue())->toBe(105.0);
    });
});

describe('View Caching and Patches', function (): void {
    test('route-scoped views are cached across contexts', function (): void {
        $app = createVia();
        $renderCount = 0;

        $ctx1 = new Context('ctx1', '/game', $app);
        $ctx1->scope(Scope::ROUTE);
        $ctx1->action(function (Context $ctx): void {}, 'action');
        $ctx1->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Game ' . $renderCount . '</div>';
        });

        $ctx2 = new Context('ctx2', '/game', $app);
        $ctx2->scope(Scope::ROUTE);
        $ctx2->action(function (Context $ctx): void {}, 'action');
        $ctx2->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Game ' . $renderCount . '</div>';
        });

        // First render
        $html1 = $ctx1->renderView();
        expect($renderCount)->toBe(1);

        // Second context uses cached view
        $html2 = $ctx2->renderView();
        expect($renderCount)->toBe(1); // Still 1 - view was cached
        expect($html2)->toBe($html1);
    });

    test('DOCUMENTED BUG: sync sends element patch even when view is cached', function (): void {
        $app = createVia();

        // This test documents the inefficiency where sync() always sends
        // element patches even when the view is cached and unchanged.
        //
        // In the stock ticker example, this means:
        // 1. Client 1 connects to /stock/NFLX - gets view + signals
        // 2. Client 2 connects to same route - view is cached
        // 3. Price updates trigger sync() which sends:
        //    - element patch (with cached HTML - unnecessary!)
        //    - signal patch (with new price - needed)
        //
        // Expected behavior: Only signal patches should be sent when
        // view is cached and signals change.

        expect(true)->toBeTrue(); // Placeholder - documents the issue
    });
});
