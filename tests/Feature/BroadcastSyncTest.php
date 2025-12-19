<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Broadcast and Sync Tests
 *
 * Tests that broadcast() properly syncs all contexts and that patches
 * are generated correctly for both the triggering context and observers.
 */

describe('Broadcast Sync Behavior', function (): void {
    test('broadcast to ROUTE scope generates patches for all contexts', function (): void {
        $app = createVia();
        $sharedCount = 0;

        // Create 3 contexts on the same route
        $ctx1 = new Context('ctx-1', '/test', $app);
        $ctx1->scope(Scope::ROUTE);
        $ctx2 = new Context('ctx-2', '/test', $app);
        $ctx2->scope(Scope::ROUTE);
        $ctx3 = new Context('ctx-3', '/test', $app);
        $ctx3->scope(Scope::ROUTE);

        // Register contexts
        $app->contexts['ctx-1'] = $ctx1;
        $app->contexts['ctx-2'] = $ctx2;
        $app->contexts['ctx-3'] = $ctx3;

        // Set up views for each context
        foreach ([$ctx1, $ctx2, $ctx3] as $ctx) {
            $ctx->view(function () use (&$sharedCount): string {
                return '<div id="counter">Count: ' . $sharedCount . '</div>';
            });
        }

        // Initial render for all contexts (simulates page load)
        $ctx1->renderView();
        $ctx2->renderView();
        $ctx3->renderView();

        // Verify all contexts are registered in ROUTE scope
        $routeContexts = $app->getContextsByScope('route:/test');
        expect($routeContexts)->toHaveCount(3);

        // Simulate data change and broadcast
        expect($sharedCount)->toBe(0);
        $sharedCount = 1;
        $app->broadcast(Scope::ROUTE);

        // Now check that ALL three contexts have patches queued
        $patch1 = $ctx1->getPatch();
        $patch2 = $ctx2->getPatch();
        $patch3 = $ctx3->getPatch();

        // All contexts should receive element patches
        expect($patch1)->not->toBeNull('Context 1 should receive patch');
        expect($patch2)->not->toBeNull('Context 2 should receive patch');
        expect($patch3)->not->toBeNull('Context 3 should receive patch');

        expect($patch1['type'])->toBe('elements');
        expect($patch2['type'])->toBe('elements');
        expect($patch3['type'])->toBe('elements');

        // All should contain the updated count
        expect($patch1['content'])->toContain('Count: 1');
        expect($patch2['content'])->toContain('Count: 1');
        expect($patch3['content'])->toContain('Count: 1');
    });

    test('all contexts on route receive updates including triggering one', function (): void {
        $app = createVia();
        $items = [];

        // Create two contexts
        $ctx1 = new Context('ctx-1', '/todo', $app);
        $ctx1->scope(Scope::ROUTE);
        $ctx2 = new Context('ctx-2', '/todo', $app);
        $ctx2->scope(Scope::ROUTE);

        $app->contexts['ctx-1'] = $ctx1;
        $app->contexts['ctx-2'] = $ctx2;

        // Setup view for both contexts
        foreach ([$ctx1, $ctx2] as $ctx) {
            $ctx->view(function () use (&$items): string {
                $html = '<ul id="items">';
                foreach ($items as $item) {
                    $html .= '<li>' . $item . '</li>';
                }
                $html .= '</ul>';

                return $html;
            });
        }

        // Simulate adding an item and broadcasting
        $items[] = 'New Item';
        $app->broadcast(Scope::ROUTE);

        // Both contexts should have patches
        $patch1 = $ctx1->getPatch();
        $patch2 = $ctx2->getPatch();

        expect($patch1)->not->toBeNull('Context 1 should receive update');
        expect($patch2)->not->toBeNull('Context 2 should receive update');

        expect($patch1['type'])->toBe('elements');
        expect($patch2['type'])->toBe('elements');

        expect($patch1['content'])->toContain('New Item');
        expect($patch2['content'])->toContain('New Item');
    });

    test('multiple broadcasts queue patches correctly', function (): void {
        $app = createVia();
        $value = 0;

        $ctx1 = new Context('ctx-1', '/multi', $app);
        $ctx1->scope(Scope::ROUTE);
        $app->contexts['ctx-1'] = $ctx1;

        $ctx1->view(function () use (&$value): string {
            return '<div id="value">' . $value . '</div>';
        });

        // Trigger multiple rapid broadcasts
        $value = 1;
        $app->broadcast(Scope::ROUTE);
        $value = 2;
        $app->broadcast(Scope::ROUTE);
        $value = 3;
        $app->broadcast(Scope::ROUTE);

        // Check that patches are queued (might have dropped some if channel is full)
        $patches = [];
        while ($patch = $ctx1->getPatch()) {
            $patches[] = $patch;
        }

        // Should have at least one patch (might drop older ones due to channel size)
        expect($patches)->not->toBeEmpty();

        // The last patch should have the final value
        $lastPatch = end($patches);
        expect($lastPatch['content'])->toContain('3');
    });
});
