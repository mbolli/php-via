<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

beforeEach(function (): void {
    $this->app = new Via(new Config());
});

test('scoped view is cached for multiple initial page loads', function (): void {
    $renderCount = 0;

    $handler = function (Context $c, string $symbol) use (&$renderCount): void {
        $c->scope(Scope::build('stock', $symbol));

        $c->view(function (bool $isUpdate = false) use ($symbol, &$renderCount): string {
            ++$renderCount;

            return $isUpdate ? '' : "<div>Stock: {$symbol} (render #{$renderCount})</div>";
        });
    };

    $this->app->page('/stock/{symbol}', $handler);

    // Simulate 10 different contexts (tabs) loading the same stock page
    $contexts = [];
    for ($i = 1; $i <= 10; ++$i) {
        $ctx = new Context("ctx{$i}", '/stock/AAPL', $this->app);
        $ctx->injectRouteParams(['symbol' => 'AAPL']);
        $handler($ctx, 'AAPL');
        $contexts[] = $ctx;
    }

    // All 10 contexts render their initial view (page load)
    $htmlResults = [];
    foreach ($contexts as $ctx) {
        $htmlResults[] = $ctx->renderView(isUpdate: false);
    }

    // Should only render once and cache the result
    expect($renderCount)->toBe(1)
        ->and('View should only be rendered once, then cached for all 10 contexts')
    ;

    // All contexts should get the same cached HTML
    foreach ($htmlResults as $html) {
        expect($html)->toBe('<div>Stock: AAPL (render #1)</div>')
            ->and('All contexts should receive the same cached HTML')
        ;
    }
});

test('scoped view is NOT cached for SSE updates', function (): void {
    $renderCount = 0;

    $handler = function (Context $c, string $symbol) use (&$renderCount): void {
        $c->scope(Scope::build('stock', $symbol));

        $c->view(function (bool $isUpdate = false) use ($symbol, $c, &$renderCount): string {
            ++$renderCount;

            // Simulate signal updates
            $price = 100 + $renderCount;
            $c->signal($price, 'price');

            return $isUpdate ? '' : "<div>Stock: {$symbol}</div>";
        }, cacheUpdates: false); // Opt-out of update caching for this test
    };

    $this->app->page('/stock/{symbol}', $handler);

    // Create one context
    $ctx = new Context('ctx1', '/stock/AAPL', $this->app);
    $ctx->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx, 'AAPL');

    // Initial page load
    $html = $ctx->renderView(isUpdate: false);
    expect($html)->toBe('<div>Stock: AAPL</div>');
    expect($renderCount)->toBe(1);

    // Simulate 10 SSE updates (broadcasts)
    for ($i = 0; $i < 10; ++$i) {
        $updateHtml = $ctx->renderView(isUpdate: true);
        expect($updateHtml)->toBe('')->and('Update renders should return empty string');
    }

    // Should have rendered 11 times total (1 initial + 10 updates)
    expect($renderCount)->toBe(11)
        ->and('View function should be called on every SSE update, not cached')
    ;
});

test('scoped view: initial loads use cache, SSE updates do not', function (): void {
    $renderCount = 0;
    $lastPrice = 100;

    $handler = function (Context $c, string $symbol) use (&$renderCount, &$lastPrice): void {
        $c->scope(Scope::build('stock', $symbol));

        $c->view(function (bool $isUpdate = false) use ($symbol, $c, &$renderCount, &$lastPrice): string {
            ++$renderCount;

            // Update price on each render
            $lastPrice += 10;
            $c->signal($lastPrice, 'price');

            return $isUpdate ? '' : "<div>Stock: {$symbol} - Last render: {$renderCount}</div>";
        }, cacheUpdates: false); // Opt-out of update caching for this test
    };

    $this->app->page('/stock/{symbol}', $handler);

    // First tab opens (initial page load)
    $ctx1 = new Context('ctx1', '/stock/AAPL', $this->app);
    $ctx1->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx1, 'AAPL');
    $html1 = $ctx1->renderView(isUpdate: false);

    expect($html1)->toBe('<div>Stock: AAPL - Last render: 1</div>');
    expect($renderCount)->toBe(1);

    // Second tab opens (initial page load) - should use cache
    $ctx2 = new Context('ctx2', '/stock/AAPL', $this->app);
    $ctx2->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx2, 'AAPL');
    $html2 = $ctx2->renderView(isUpdate: false);

    expect($html2)->toBe('<div>Stock: AAPL - Last render: 1</div>')
        ->and('Second tab should get cached HTML from first tab')
    ;
    expect($renderCount)->toBe(1)
        ->and('View should not be re-rendered for second tab (cached)')
    ;

    // Third tab opens (initial page load) - should also use cache
    $ctx3 = new Context('ctx3', '/stock/AAPL', $this->app);
    $ctx3->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx3, 'AAPL');
    $html3 = $ctx3->renderView(isUpdate: false);

    expect($html3)->toBe('<div>Stock: AAPL - Last render: 1</div>');
    expect($renderCount)->toBe(1);

    // Now simulate SSE updates (stock price ticks) on first context
    $ctx1->renderView(isUpdate: true);
    expect($renderCount)->toBe(2)->and('First SSE update should render');

    $ctx1->renderView(isUpdate: true);
    expect($renderCount)->toBe(3)->and('Second SSE update should render');

    $ctx1->renderView(isUpdate: true);
    expect($renderCount)->toBe(4)->and('Third SSE update should render');

    // Fourth tab opens (initial page load after updates) - should still use original cached HTML
    $ctx4 = new Context('ctx4', '/stock/AAPL', $this->app);
    $ctx4->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx4, 'AAPL');
    $html4 = $ctx4->renderView(isUpdate: false);

    expect($html4)->toBe('<div>Stock: AAPL - Last render: 1</div>')
        ->and('New tab should still get original cached HTML, not affected by SSE updates')
    ;
    expect($renderCount)->toBe(4)
        ->and('Opening new tab should not trigger a new render')
    ;
});

test('different scopes have separate caches', function (): void {
    $renderCount = 0;

    $handler = function (Context $c, string $symbol) use (&$renderCount): void {
        $c->scope(Scope::build('stock', $symbol));

        $c->view(function (bool $isUpdate = false) use ($symbol, &$renderCount): string {
            ++$renderCount;

            return $isUpdate ? '' : "<div>Stock: {$symbol}</div>";
        });
    };

    $this->app->page('/stock/{symbol}', $handler);

    // Load AAPL 3 times
    for ($i = 1; $i <= 3; ++$i) {
        $ctx = new Context("ctx_aapl_{$i}", '/stock/AAPL', $this->app);
        $ctx->injectRouteParams(['symbol' => 'AAPL']);
        $handler($ctx, 'AAPL');
        $ctx->renderView(isUpdate: false);
    }

    expect($renderCount)->toBe(1)->and('AAPL should be rendered once and cached');

    // Load GOOGL 3 times (different scope)
    for ($i = 1; $i <= 3; ++$i) {
        $ctx = new Context("ctx_googl_{$i}", '/stock/GOOGL', $this->app);
        $ctx->injectRouteParams(['symbol' => 'GOOGL']);
        $handler($ctx, 'GOOGL');
        $ctx->renderView(isUpdate: false);
    }

    expect($renderCount)->toBe(2)->and('GOOGL should be rendered once (separate from AAPL cache)');

    // Load TSLA 3 times (another different scope)
    for ($i = 1; $i <= 3; ++$i) {
        $ctx = new Context("ctx_tsla_{$i}", '/stock/TSLA', $this->app);
        $ctx->injectRouteParams(['symbol' => 'TSLA']);
        $handler($ctx, 'TSLA');
        $ctx->renderView(isUpdate: false);
    }

    expect($renderCount)->toBe(3)->and('TSLA should be rendered once (separate from AAPL and GOOGL caches)');
});

test('TAB scope never caches, even for initial page loads', function (): void {
    $renderCount = 0;

    $handler = function (Context $c) use (&$renderCount): void {
        // TAB scope (default) - no caching
        $c->view(function (bool $isUpdate = false) use (&$renderCount): string {
            ++$renderCount;

            return $isUpdate ? '' : "<div>Render #{$renderCount}</div>";
        });
    };

    $this->app->page('/test', $handler);

    // Create 10 contexts with TAB scope
    for ($i = 1; $i <= 10; ++$i) {
        $ctx = new Context("ctx{$i}", '/test', $this->app);
        $handler($ctx);
        $html = $ctx->renderView(isUpdate: false);
        expect($html)->toBe("<div>Render #{$i}</div>");
    }

    expect($renderCount)->toBe(10)
        ->and('TAB scope should render every time, never use cache')
    ;
});
