<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

beforeEach(function (): void {
    $this->app = new Via(new Config());
});

test('isUpdate flag is false on initial page load', function (): void {
    $renderCount = 0;
    $receivedIsUpdate = [];

    $handler = function (Context $c) use (&$renderCount, &$receivedIsUpdate): void {
        $c->view(function (bool $isUpdate = false) use (&$renderCount, &$receivedIsUpdate): string {
            ++$renderCount;
            $receivedIsUpdate[] = $isUpdate;

            return $isUpdate ? '' : '<div>Initial render</div>';
        });
    };

    $this->app->page('/test', $handler);

    // Simulate initial page load
    $context = new Context('ctx1', '/test', $this->app);
    $handler($context);

    $html = $context->renderView();

    expect($html)->toBe('<div>Initial render</div>');
    expect($renderCount)->toBe(1);
    expect($receivedIsUpdate)->toBe([false]);
});

test('isUpdate flag is true on subsequent renders', function (): void {
    $renderCount = 0;
    $receivedIsUpdate = [];

    $handler = function (Context $c) use (&$renderCount, &$receivedIsUpdate): void {
        $c->view(function (bool $isUpdate = false) use (&$renderCount, &$receivedIsUpdate): string {
            ++$renderCount;
            $receivedIsUpdate[] = $isUpdate;

            return $isUpdate ? '' : '<div>Initial render</div>';
        });
    };

    $this->app->page('/test', $handler);

    $context = new Context('ctx1', '/test', $this->app);
    $handler($context);

    // First render
    $html1 = $context->renderView();
    expect($html1)->toBe('<div>Initial render</div>');

    // Second render (update via SSE)
    $html2 = $context->renderView(isUpdate: true);
    expect($html2)->toBe('');

    expect($renderCount)->toBe(2);
    expect($receivedIsUpdate)->toBe([false, true]);
});

test('isUpdate flag is false for new context even with scoped caching', function (): void {
    $renderCount = 0;
    $receivedIsUpdate = [];

    $handler = function (Context $c, string $symbol) use (&$renderCount, &$receivedIsUpdate): void {
        // Use a scoped signal (like in stock example)
        $c->scope(Scope::build('stock', $symbol));

        $c->view(function (bool $isUpdate = false) use ($symbol, &$renderCount, &$receivedIsUpdate): string {
            ++$renderCount;
            $receivedIsUpdate[] = $isUpdate;

            return $isUpdate ? '' : "<div>Stock: {$symbol}</div>";
        });
    };

    $this->app->page('/stock/{symbol}', $handler);

    // First context (simulating first tab)
    $ctx1 = new Context('ctx1', '/stock/AAPL', $this->app);
    $ctx1->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx1, 'AAPL');

    $html1 = $ctx1->renderView();
    expect($html1)->toBe('<div>Stock: AAPL</div>');
    expect($receivedIsUpdate[0])->toBe(false)->and('First context should receive isUpdate=false');

    // Second context (simulating duplicate tab or new window)
    $ctx2 = new Context('ctx2', '/stock/AAPL', $this->app);
    $ctx2->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx2, 'AAPL');

    // This should also return full HTML for initial page load
    $html2 = $ctx2->renderView();
    expect($html2)->toBe('<div>Stock: AAPL</div>')
        ->and('Second context should also get full HTML on initial load')
    ;

    // The view function might be called once (cache) or twice (no cache)
    // But both contexts should get the full HTML
    expect($renderCount)->toBeGreaterThanOrEqual(1);
});

test('cached view should not be empty string from update render', function (): void {
    $renderCount = 0;
    $receivedIsUpdate = [];

    $handler = function (Context $c, string $symbol) use (&$renderCount, &$receivedIsUpdate): void {
        $c->scope(Scope::build('stock', $symbol));

        $c->view(function (bool $isUpdate = false) use ($symbol, $c, &$renderCount, &$receivedIsUpdate): string {
            ++$renderCount;
            $receivedIsUpdate[] = $isUpdate;

            // Simulate what stock ticker does
            $price = 100.0;
            $c->signal($price, 'price');

            if ($isUpdate) {
                return '';  // Don't re-render HTML on updates
            }

            return "<div>Stock: {$symbol} - Price: \$<span data-text='price'></span></div>";
        }, cacheUpdates: false); // Opt-out since we return empty string on updates
    };

    $this->app->page('/stock/{symbol}', $handler);

    // Context 1: Initial load
    $ctx1 = new Context('ctx1', '/stock/AAPL', $this->app);
    $ctx1->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx1, 'AAPL');

    $html1 = $ctx1->renderView();
    expect($html1)->toContain('Stock: AAPL')->and('First render should return full HTML');

    // Simulate an SSE update (broadcast)
    $updateHtml = $ctx1->renderView(isUpdate: true);
    expect($updateHtml)->toBe('')->and('Update renders should return empty string');

    // Context 2: New tab/window opened (initial load for this context)
    $ctx2 = new Context('ctx2', '/stock/AAPL', $this->app);
    $ctx2->injectRouteParams(['symbol' => 'AAPL']);
    $handler($ctx2, 'AAPL');

    $html2 = $ctx2->renderView();
    expect($html2)->toContain('Stock: AAPL')
        ->and('New context should get full HTML, not empty string from cached update render')
    ;
});

test('TAB scope always renders fresh, never cached', function (): void {
    $renderCount = 0;

    $handler = function (Context $c) use (&$renderCount): void {
        // TAB scope (default) - no caching
        $c->view(function (bool $isUpdate = false) use (&$renderCount): string {
            ++$renderCount;

            return $isUpdate ? '' : "<div>Render #{$renderCount}</div>";
        });
    };

    $this->app->page('/test', $handler);

    $ctx1 = new Context('ctx1', '/test', $this->app);
    $handler($ctx1);
    $html1 = $ctx1->renderView();
    expect($html1)->toBe('<div>Render #1</div>');

    $ctx2 = new Context('ctx2', '/test', $this->app);
    $handler($ctx2);
    $html2 = $ctx2->renderView();
    expect($html2)->toBe('<div>Render #2</div>')->and('TAB scope should not use cache');

    expect($renderCount)->toBe(2);
});

test('ROUTE scope uses cache for update renders only', function (): void {
    $renderCount = 0;

    $handler = function (Context $c) use (&$renderCount): void {
        $c->scope(Scope::ROUTE);

        $c->view(function (bool $isUpdate = false) use (&$renderCount): string {
            ++$renderCount;

            return $isUpdate ? "<div>Update #{$renderCount}</div>" : "<div>Initial #{$renderCount}</div>";
        });
    };

    $this->app->page('/dashboard', $handler);

    // First context - initial render (NOT cached)
    $ctx1 = new Context('ctx1', '/dashboard', $this->app);
    $handler($ctx1);
    $html1 = $ctx1->renderView(isUpdate: false);
    expect($html1)->toBe('<div>Initial #1</div>');

    // Second context - initial render also NOT cached (unique context IDs)
    $ctx2 = new Context('ctx2', '/dashboard', $this->app);
    $handler($ctx2);
    $html2 = $ctx2->renderView(isUpdate: false);
    expect($html2)->toBe('<div>Initial #2</div>')->and('Initial renders not cached');

    expect($renderCount)->toBe(2);

    // UPDATE renders ARE cached
    $html3 = $ctx1->renderView(isUpdate: true);
    expect($html3)->toBe('<div>Update #3</div>');

    $html4 = $ctx2->renderView(isUpdate: true);
    expect($html4)->toBe('<div>Update #3</div>')->and('Update renders use cache');

    expect($renderCount)->toBe(3)->and('Update render only called once');
});
