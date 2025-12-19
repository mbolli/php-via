<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Scope Caching Tests - The Most Important Tests
 *
 * Tests that view caching works correctly based on scope:
 * - ROUTE scope: Views are cached and shared across all contexts on the same route
 * - TAB scope: Views are rendered fresh each time (no caching)
 * - GLOBAL scope: Views are cached globally across all routes
 */

describe('Route Scope Caching', function (): void {
    test('route scope caches views', function (): void {
        $app = createVia();
        $renderCount = 0;

        $context = new Context('ctx1', '/game', $app);
        $context->scope(Scope::ROUTE);
        $context->action(function (Context $ctx): void {}, 'toggle');

        $context->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Render ' . $renderCount . '</div>';
        });

        // First render
        $html1 = $context->renderView();
        expect($renderCount)->toBe(1);
        expect($html1)->toContain('Render 1');

        // Second render should use cache
        $html2 = $context->renderView();
        expect($renderCount)->toBe(1, 'Cache used - no re-render');
        expect($html2)->toBe($html1);
    });

    test('route scope shares cache across multiple contexts on same route', function (): void {
        $app = createVia();
        $renderCount = 0;

        // Context 1
        $ctx1 = new Context('ctx1', '/game', $app);
        $ctx1->scope(Scope::ROUTE);
        $ctx1->action(function (Context $ctx): void {}, 'action');
        $ctx1->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Game ' . $renderCount . '</div>';
        });

        // Context 2 - same route
        $ctx2 = new Context('ctx2', '/game', $app);
        $ctx2->scope(Scope::ROUTE);
        $ctx2->action(function (Context $ctx): void {}, 'action');
        $ctx2->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Game ' . $renderCount . '</div>';
        });

        // First context renders
        $html1 = $ctx1->renderView();
        expect($renderCount)->toBe(1);

        // Second context uses cached HTML
        $html2 = $ctx2->renderView();
        expect($renderCount)->toBe(1, 'Both contexts share route cache');
        expect($html2)->toBe($html1);
    });

    test('broadcast invalidates route cache', function (): void {
        $app = createVia();
        $renderCount = 0;

        $context = new Context('ctx1', '/game', $app);
        $context->scope(Scope::ROUTE);
        $context->action(function (Context $ctx): void {}, 'action');

        $context->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Game ' . $renderCount . '</div>';
        });

        // First render
        $context->renderView();
        expect($renderCount)->toBe(1);

        // Second render uses cache
        $context->renderView();
        expect($renderCount)->toBe(1);

        // Broadcast invalidates cache
        $app->broadcast(Scope::routeScope('/game'));

        // Third render re-renders
        $context->renderView();
        expect($renderCount)->toBe(2, 'Cache invalidated after broadcast');
    });
});

describe('Tab Scope Caching', function (): void {
    test('tab scope does not cache views', function (): void {
        $app = createVia();
        $renderCount = 0;

        $context = new Context('ctx1', '/profile', $app);
        // No scope() call = TAB scope by default
        $context->signal('World', 'name');

        $context->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Render ' . $renderCount . '</div>';
        });

        // First render
        $html1 = $context->renderView();
        expect($renderCount)->toBe(1);

        // Second render re-executes (no cache)
        $html2 = $context->renderView();
        expect($renderCount)->toBe(2, 'No caching - renders each time');
        expect($html1)->not->toBe($html2);
    });

    test('tab scope - each context renders independently', function (): void {
        $app = createVia();
        $render1Count = 0;
        $render2Count = 0;

        $ctx1 = new Context('ctx1', '/profile', $app);
        $ctx1->signal('Alice', 'name');
        $ctx1->view(function () use (&$render1Count) {
            ++$render1Count;

            return '<div>User1 ' . $render1Count . '</div>';
        });

        $ctx2 = new Context('ctx2', '/profile', $app);
        $ctx2->signal('Bob', 'name');
        $ctx2->view(function () use (&$render2Count) {
            ++$render2Count;

            return '<div>User2 ' . $render2Count . '</div>';
        });

        // Each context renders independently
        $html1 = $ctx1->renderView();
        expect($render1Count)->toBe(1);
        expect($render2Count)->toBe(0);

        $html2 = $ctx2->renderView();
        expect($render1Count)->toBe(1);
        expect($render2Count)->toBe(1);

        expect($html1)->not->toBe($html2);
    });
});

describe('Global Scope Caching', function (): void {
    test('global scope caches views', function (): void {
        $app = createVia();
        $renderCount = 0;

        $context = new Context('ctx1', '/notifications', $app);
        $context->scope(Scope::GLOBAL);
        $context->action(function (Context $ctx): void {}, 'add');

        $context->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Global ' . $renderCount . '</div>';
        });

        // First render
        $html1 = $context->renderView();
        expect($renderCount)->toBe(1);

        // Second render uses cache
        $html2 = $context->renderView();
        expect($renderCount)->toBe(1, 'Global cache used');
        expect($html2)->toBe($html1);
    });

    test('global scope shares cache across ALL routes', function (): void {
        $app = createVia();
        $renderCount = 0;

        // Context on route 1
        $ctx1 = new Context('ctx1', '/page1', $app);
        $ctx1->scope(Scope::GLOBAL);
        $ctx1->action(function (Context $ctx): void {}, 'action');
        $ctx1->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Global ' . $renderCount . '</div>';
        });

        // Context on route 2
        $ctx2 = new Context('ctx2', '/page2', $app);
        $ctx2->scope(Scope::GLOBAL);
        $ctx2->action(function (Context $ctx): void {}, 'action');
        $ctx2->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Global ' . $renderCount . '</div>';
        });

        // First context renders
        $html1 = $ctx1->renderView();
        expect($renderCount)->toBe(1);

        // Second context (different route!) uses same global cache
        $html2 = $ctx2->renderView();
        expect($renderCount)->toBe(1, 'Global cache shared across routes');
        expect($html2)->toBe($html1);
    });

    test('broadcastGlobal invalidates global cache', function (): void {
        $app = createVia();
        $renderCount = 0;

        $context = new Context('ctx1', '/notifications', $app);
        $context->scope(Scope::GLOBAL);
        $context->action(function (Context $ctx): void {}, 'action');

        $context->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Global ' . $renderCount . '</div>';
        });

        // First render
        $context->renderView();
        expect($renderCount)->toBe(1);

        // Second render uses cache
        $context->renderView();
        expect($renderCount)->toBe(1);

        // Broadcast globally
        $app->broadcast(Scope::GLOBAL);

        // Third render re-renders
        $context->renderView();
        expect($renderCount)->toBe(2, 'Global cache invalidated');
    });
});

/*
 * Signal Sharing Tests
 *
 * These tests verify that signals are properly shared across contexts based on their scope.
 *
 * Signals inherit their context's scope when no explicit scope is provided. This means:
 * - Contexts with ROUTE scope create signals that are shared across all clients on that route
 * - Contexts with TAB scope (default) create isolated signals per context
 * - Explicit scope parameter overrides the inherited scope
 */
describe('Route Scope Signal Sharing', function (): void {
    test('route-scoped signals with explicit scope are shared across clients', function (): void {
        $app = createVia();

        // First client connects to /stock/NFLX
        $client1 = new Context('client1', '/stock/NFLX', $app);
        $client1->scope(Scope::ROUTE);
        $price1 = $client1->signal(100.0, 'price', Scope::routeScope('/stock/NFLX'));
        $client1->view(fn () => '<div>Price: ' . $price1->getValue() . '</div>');

        // Second client connects to same route
        $client2 = new Context('client2', '/stock/NFLX', $app);
        $client2->scope(Scope::ROUTE);
        $price2 = $client2->signal(100.0, 'price', Scope::routeScope('/stock/NFLX'));
        $client2->view(fn () => '<div>Price: ' . $price2->getValue() . '</div>');

        // Initial render - both see $100
        $html1 = $client1->renderView();
        $html2 = $client2->renderView();
        expect($html1)->toContain('Price: 100');
        expect($html2)->toContain('Price: 100');

        // Update signal through first client
        $price1->setValue(105.5);

        // Clear view cache to force re-render
        $app->broadcast(Scope::routeScope('/stock/NFLX'));

        // Both clients should see the updated value
        $html1Updated = $client1->renderView();
        $html2Updated = $client2->renderView();
        expect($html1Updated)->toContain('Price: 105.5'); // Client 1 sees update
        expect($html2Updated)->toContain('Price: 105.5'); // Client 2 sees update
    });

    test('signals without explicit scope inherit context scope', function (): void {
        $app = createVia();

        // First client connects with ROUTE scope
        $client1 = new Context('client1', '/stock/NFLX', $app);
        $client1->scope(Scope::ROUTE);
        // Signal inherits context's ROUTE scope
        $price1 = $client1->signal(100.0, 'price');

        // Second client connects with ROUTE scope
        $client2 = new Context('client2', '/stock/NFLX', $app);
        $client2->scope(Scope::ROUTE);
        $price2 = $client2->signal(100.0, 'price');

        // These should be the same Signal object (shared)
        expect($price1)->toBe($price2);

        // Update through client 1
        $price1->setValue(105.5);

        // Client 2's signal should reflect the update
        expect($price2->getValue())->toBe(105.5);
    });

    test('TAB-scoped signals are isolated per context', function (): void {
        $app = createVia();

        // Client 1 with TAB scope (default)
        $client1 = new Context('client1', '/stock/NFLX', $app);
        // No scope() call = TAB scope
        $price1 = $client1->signal(100.0, 'price');

        // Client 2 with TAB scope
        $client2 = new Context('client2', '/stock/NFLX', $app);
        $price2 = $client2->signal(100.0, 'price');

        // Verify the signals are different objects (not shared)
        expect($price1)->not->toBe($price2);

        // Update through client 1
        $price1->setValue(105.5);

        // Client 2's signal should NOT change
        expect($price2->getValue())->toBe(100.0);
    });

    test('multiple signals on same route are shared across clients', function (): void {
        $app = createVia();

        // Client 1 creates signals
        $ctx1 = new Context('ctx1', '/stock/AAPL', $app);
        $ctx1->scope(Scope::ROUTE);
        $price1 = $ctx1->signal(150.0, 'price', Scope::routeScope('/stock/AAPL'));
        $volume1 = $ctx1->signal(1000000, 'volume', Scope::routeScope('/stock/AAPL'));
        $ctx1->view(fn () => '<div>' . $price1->getValue() . ' @ ' . $volume1->getValue() . '</div>');

        // Client 2 on same route
        $ctx2 = new Context('ctx2', '/stock/AAPL', $app);
        $ctx2->scope(Scope::ROUTE);
        $price2 = $ctx2->signal(150.0, 'price', Scope::routeScope('/stock/AAPL'));
        $volume2 = $ctx2->signal(1000000, 'volume', Scope::routeScope('/stock/AAPL'));
        $ctx2->view(fn () => '<div>' . $price2->getValue() . ' @ ' . $volume2->getValue() . '</div>');

        // Update both signals
        $price1->setValue(155.0);
        $volume1->setValue(2000000);

        // Broadcast to clear cache
        $app->broadcast(Scope::routeScope('/stock/AAPL'));

        // Both contexts should see both updates
        $html1 = $ctx1->renderView();
        $html2 = $ctx2->renderView();
        expect($html1)->toContain('155');
        expect($html1)->toContain('2000000');
        expect($html2)->toContain('155');
        expect($html2)->toContain('2000000');
    });

    test('signals on different routes remain isolated', function (): void {
        $app = createVia();

        // NFLX route
        $ctxNFLX = new Context('ctx1', '/stock/NFLX', $app);
        $ctxNFLX->scope(Scope::ROUTE);
        $priceNFLX = $ctxNFLX->signal(100.0, 'price', Scope::routeScope('/stock/NFLX'));
        $ctxNFLX->view(fn () => '<div>NFLX: ' . $priceNFLX->getValue() . '</div>');

        // AAPL route
        $ctxAAPL = new Context('ctx2', '/stock/AAPL', $app);
        $ctxAAPL->scope(Scope::ROUTE);
        $priceAAPL = $ctxAAPL->signal(150.0, 'price', Scope::routeScope('/stock/AAPL'));
        $ctxAAPL->view(fn () => '<div>AAPL: ' . $priceAAPL->getValue() . '</div>');

        // Update NFLX price
        $priceNFLX->setValue(105.0);
        $app->broadcast(Scope::routeScope('/stock/NFLX'));

        // NFLX sees update, AAPL unchanged
        $htmlNFLX = $ctxNFLX->renderView();
        $htmlAAPL = $ctxAAPL->renderView();
        expect($htmlNFLX)->toContain('105');
        expect($htmlAAPL)->toContain('150'); // AAPL price unchanged
    });
});
