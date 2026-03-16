<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Component Sync Optimization Tests
 *
 * Verifies that page-level sync() only re-renders components whose state
 * has actually changed, rather than blindly syncing all registered components.
 *
 * Key invariant: components with cacheUpdates=false always sync (they may
 * read external state outside the signal system).
 */

describe('Selective Component Sync', function (): void {
    test('page sync skips components with no dirty signals', function (): void {
        $app = createVia();
        $page = new Context('page1', '/test', $app);

        $compARenders = 0;
        $compBRenders = 0;

        // Component A: has a signal, will be dirtied
        $page->component(function (Context $c) use (&$compARenders): void {
            $counter = $c->signal(0, 'count');
            $c->view(function () use ($counter, &$compARenders) {
                ++$compARenders;

                return '<span>' . $counter->getValue() . '</span>';
            });
        }, 'compA');

        // Component B: has a signal, will NOT be dirtied
        $page->component(function (Context $c) use (&$compBRenders): void {
            $label = $c->signal('hello', 'label');
            // Mark synced so it's no longer dirty
            $label->markSynced();
            $c->view(function () use ($label, &$compBRenders) {
                ++$compBRenders;

                return '<span>' . $label->getValue() . '</span>';
            });
        }, 'compB');

        $page->view(fn () => '<div>page</div>');

        // Reset render counters after initial registration
        $compARenders = 0;
        $compBRenders = 0;

        // Sync the page — compA has a dirty signal (initial), compB was marked synced
        $page->sync();

        expect($compARenders)->toBe(1, 'Component A should render (dirty signal)');
        expect($compBRenders)->toBe(0, 'Component B should be skipped (no dirty signals)');
    });

    test('page sync always re-renders components with cacheUpdates=false', function (): void {
        $app = createVia();
        $page = new Context('page1', '/test', $app);

        $externalState = 'initial';
        $compRenders = 0;

        // Component with cacheUpdates=false reads external state (no signals)
        $page->component(function (Context $c) use (&$externalState, &$compRenders): void {
            $c->view(function () use (&$externalState, &$compRenders) {
                ++$compRenders;

                return '<span>' . $externalState . '</span>';
            }, cacheUpdates: false);
        }, 'extComp');

        $page->view(fn () => '<div>page</div>');

        $compRenders = 0;

        // Even with no dirty signals, cacheUpdates=false forces sync
        $page->sync();

        expect($compRenders)->toBe(1, 'Component with cacheUpdates=false must always sync');
    });

    test('only the component with a changed signal re-renders', function (): void {
        $app = createVia();
        $page = new Context('page1', '/test', $app);

        $renders = ['a' => 0, 'b' => 0, 'c' => 0];
        $signals = [];

        foreach (['a', 'b', 'c'] as $name) {
            $page->component(function (Context $c) use ($name, &$renders, &$signals): void {
                $sig = $c->signal(0, 'val');
                $signals[$name] = $sig;
                $c->view(function () use ($name, $sig, &$renders) {
                    ++$renders[$name];

                    return '<span>' . $sig->getValue() . '</span>';
                });
            }, $name);
        }

        $page->view(fn () => '<div>page</div>');

        // Mark all signals synced (simulating post-initial-render state)
        foreach ($signals as $sig) {
            $sig->markSynced();
        }

        // Reset counters
        $renders = ['a' => 0, 'b' => 0, 'c' => 0];

        // Dirty only component B's signal
        $signals['b']->setValue(42);

        $page->sync();

        expect($renders['a'])->toBe(0, 'Component A should not re-render');
        expect($renders['b'])->toBe(1, 'Component B should re-render (dirty signal)');
        expect($renders['c'])->toBe(0, 'Component C should not re-render');
    });

    test('component patches are forwarded to parent page channel', function (): void {
        $app = createVia();
        $page = new Context('page1', '/test', $app);

        $page->component(function (Context $c): void {
            $counter = $c->signal(0, 'count');
            $c->view(fn () => '<span>' . $counter->getValue() . '</span>');
        }, 'child');

        $page->view(fn () => '<div>page</div>');

        $page->sync();

        // All patches (page + component) should be readable from the page's channel
        $patches = [];
        while ($patch = $page->getPatchManager()->getPatch()) {
            $patches[] = $patch;
        }

        // Expect: page elements patch, page signals patch, component elements patch, component signals patch
        $elementPatches = array_filter($patches, fn ($p) => $p['type'] === 'elements');
        $hasComponentPatch = false;
        foreach ($elementPatches as $patch) {
            // Component patches have a '#c-' selector; page patches have no selector
            if (isset($patch['selector']) && str_starts_with($patch['selector'], '#c-')) {
                $hasComponentPatch = true;
            }
        }

        expect($hasComponentPatch)->toBeTrue('Component element patch should be in parent channel');
    });

    test('multiple dirty components all re-render', function (): void {
        $app = createVia();
        $page = new Context('page1', '/test', $app);

        $renders = ['x' => 0, 'y' => 0];
        $signals = [];

        foreach (['x', 'y'] as $name) {
            $page->component(function (Context $c) use ($name, &$renders, &$signals): void {
                $sig = $c->signal(0, 'val');
                $signals[$name] = $sig;
                $c->view(function () use ($name, $sig, &$renders) {
                    ++$renders[$name];

                    return '<span>' . $sig->getValue() . '</span>';
                });
            }, $name);
        }

        $page->view(fn () => '<div>page</div>');

        foreach ($signals as $sig) {
            $sig->markSynced();
        }
        $renders = ['x' => 0, 'y' => 0];

        // Dirty both
        $signals['x']->setValue(1);
        $signals['y']->setValue(2);

        $page->sync();

        expect($renders['x'])->toBe(1);
        expect($renders['y'])->toBe(1);
    });

    test('component with no signals and cacheUpdates=true is skipped', function (): void {
        $app = createVia();
        $page = new Context('page1', '/test', $app);

        $compRenders = 0;

        // Component with no signals at all, default cacheUpdates=true
        $page->component(function (Context $c) use (&$compRenders): void {
            $c->view(function () use (&$compRenders) {
                ++$compRenders;

                return '<span>static</span>';
            });
        }, 'static');

        $page->view(fn () => '<div>page</div>');
        $compRenders = 0;

        $page->sync();

        // No signals means hasChangedSignals()=false, cacheUpdates=true → skip
        expect($compRenders)->toBe(0, 'Static component (no signals, cacheable) should be skipped');
    });
});
