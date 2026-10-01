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

    test('component with no signals and cacheUpdates=true is NOT skipped', function (): void {
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

        // This test previously asserted the opposite. An empty signal set makes
        // hasChangedSignals() permanently false, so skipping on it froze any
        // signal-less component that read external state (a PHP static, GlobalState)
        // on its first-render value for the life of the process — silently, with
        // nothing above debug level, and cacheUpdates=true is the DEFAULT, so authors
        // opted in without declaring anything.
        //
        // The framework cannot tell a genuinely static component from one reading
        // external state, and the costs are asymmetric: skipping wrongly freezes the
        // UI permanently, while syncing wrongly costs one render of a component whose
        // output is constant (and a client-side morph that is a no-op). Correctness wins.
        // The skip still applies whenever a component declares signals and none are dirty.
        expect($compRenders)->toBe(1, 'Signal-less component must sync — purity cannot be proven');
    });
});

describe('Components with no signals', function (): void {
    /*
     * Regression: a component declaring no signals at all was skipped forever.
     *
     * The skip condition is `shouldCacheUpdates() && !hasChangedSignals()`.
     * hasChangedSignals() iterates the component's own signals and returns false
     * for an EMPTY set, so a signal-less component satisfied the skip on every
     * broadcast for the life of the process — silently freezing the client on its
     * first-render value, with no exception and nothing above debug level.
     *
     * An empty signal set means "cannot prove this view is a pure function of
     * signals", which must fall back to syncing, not to skipping.
     */
    test('a component with no signals is re-rendered on every page sync', function (): void {
        $app = createVia();
        $page = new Context('page-nosig', '/test', $app);
        $page->view(fn (): string => '<div>page</div>');

        // Reads external state, declares no signals, and does NOT opt out of
        // update caching — the shape an author writing a stateless component
        // that reads GlobalState would naturally produce.
        $external = 'first';
        $renders = 0;
        $page->component(function (Context $c) use (&$renders, &$external): void {
            $c->view(function () use (&$renders, &$external): string {
                ++$renders;

                return '<span>' . $external . '</span>';
            });
        }, 'stateless');

        $page->sync();
        $rendersAfterFirstSync = $renders;
        expect($rendersAfterFirstSync)->toBeGreaterThan(0);

        $external = 'second';
        $page->sync();
        $page->sync();

        expect($renders)->toBeGreaterThan($rendersAfterFirstSync);
    });

    test('the skip still applies to a component whose signals are all clean', function (): void {
        $app = createVia();
        $page = new Context('page-clean', '/test', $app);
        $page->view(fn (): string => '<div>page</div>');

        $renders = 0;
        $page->component(function (Context $c) use (&$renders): void {
            $label = $c->signal('hello', 'label');
            $label->markSynced();
            $c->view(function () use ($label, &$renders): string {
                ++$renders;

                return '<span>' . $label->getValue() . '</span>';
            });
        }, 'pure');

        $page->sync();
        $baseline = $renders;

        $page->sync();
        $page->sync();

        // Declared signals, none dirty — the optimisation must still fire.
        expect($renders)->toBe($baseline);
    });
});

describe('Components rendered with the page', function (): void {
    /**
     * @return list<array{type: string, content: mixed, selector?: string}>
     */
    $drain = static function (Context $page): array {
        $patches = [];
        while (($patch = $page->getPatchManager()->getPatch()) !== null) {
            $patches[] = $patch;
        }

        return $patches;
    };

    $mount = static function (Context $page, int &$renders): callable {
        return $page->component(function (Context $c) use (&$renders): void {
            $count = $c->signal(0, 'count');
            $c->view(function () use ($count, &$renders): string {
                ++$renders;

                return '<span>' . $count->int() . '</span>';
            });
        }, 'counter');
    };

    test('a component the page frame carries is rendered once and sends only its signals', function () use ($drain, $mount): void {
        $page = new Context('page-embed', '/test', createVia());
        $renders = 0;
        $counter = $mount($page, $renders);
        $page->view(fn (): string => '<main id="page">' . $counter() . '</main>');
        $component = array_values($page->getComponentManager()->getComponents())[0];
        $component->getSignal('count')->setValue(5);

        $page->sync();
        $patches = $drain($page);

        $elements = array_values(array_filter($patches, fn (array $p): bool => $p['type'] === 'elements'));
        $signals = array_merge(...array_map(
            fn (array $p): array => $p['content'],
            array_values(array_filter($patches, fn (array $p): bool => $p['type'] === 'signals')),
        ));

        expect($renders)->toBe(1)
            ->and($elements)->toHaveCount(1)
            ->and($elements[0])->not->toHaveKey('selector')
            ->and($elements[0]['content'])->toContain('<span>5</span>')
            ->and($signals)->toHaveKey($component->getSignal('count')->id())
        ;
    });

    test('a component the page frame leaves out still gets its own frame', function () use ($drain, $mount): void {
        $page = new Context('page-omit', '/test', createVia());
        $renders = 0;
        $counter = $mount($page, $renders);
        // Rendered but not in the frame, like a component outside the block an update sends.
        $page->view(function () use ($counter): string {
            $counter();

            return '<main id="page"></main>';
        });

        $page->sync();
        $selectors = array_column($drain($page), 'selector');

        expect($selectors)->toHaveCount(1)
            ->and($selectors[0])->toStartWith('#c-')
        ;
    });

    test('two overlapping syncs of one page each find the components they rendered', function (): void {
        // Coroutine::run cannot run in the Pest process.
        $fixture = dirname(__DIR__) . '/Fixtures/component_overlap_sync.php';
        $out = (string) shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1');

        expect($out)->toContain("renders=2\n")
            ->and($out)->toContain("component_frames=0\n")
        ;
    });
});
