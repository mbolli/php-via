<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * A destroyed context must leave the scope registry, or the registry keeps it, its components
 * and whatever they reference (request attributes, Brotli encoders) until the worker stops.
 */

/** Mint a page context the way RequestHandler does: handler first, then the TAB registration. */
function teardownMintPage(Via $app, string $route, callable $handler): Context {
    $contextId = $route . '_/' . bin2hex(random_bytes(8));
    $ctx = new Context($contextId, $route, $app, null, 'session-1');
    $app->invokeHandlerWithParams($handler, $ctx, []);
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, 'session-1');
    $app->registerContextInScope($ctx, Scope::TAB);

    return $ctx;
}

/** @return list<string> IDs registered under $scope */
function teardownIdsIn(Via $app, string $scope): array {
    return array_map(static fn (Context $c): string => $c->getId(), $app->getLocalContexts($scope));
}

describe('Context teardown', function (): void {
    test('a page whose handler called scope() leaves the TAB registry when destroyed', function (): void {
        $app = createVia();
        $ctx = teardownMintPage($app, '/docs', function (Context $c): void {
            $c->scope(Scope::routeScope('/docs'));
            $c->view(fn (): string => 'docs');
        });
        $id = $ctx->getId();

        expect(teardownIdsIn($app, Scope::TAB))->toContain($id)
            ->and(teardownIdsIn($app, 'route:/docs'))->toContain($id)
        ;

        $app->getApp()->destroyContext($id);

        expect(teardownIdsIn($app, Scope::TAB))->not->toContain($id)
            ->and(teardownIdsIn($app, 'route:/docs'))->toBe([])
        ;
    });

    test('a destroyed page context is freed', function (): void {
        $app = createVia();
        $ctx = teardownMintPage($app, '/docs', function (Context $c): void {
            $c->scope(Scope::routeScope('/docs'));
            $c->view(fn (): string => 'docs');
        });
        $id = $ctx->getId();
        $ref = WeakReference::create($ctx);
        unset($ctx);

        $app->getApp()->destroyContext($id);
        gc_collect_cycles();

        expect($ref->get())->toBeNull();
    });

    test('a component that joined a scope is released and freed with its page', function (): void {
        $app = createVia();
        $ctx = teardownMintPage($app, '/docs', function (Context $c): void {
            $c->scope(Scope::routeScope('/docs'));
            $widget = $c->component(function (Context $w): void {
                $w->scope('widgets');
                $w->view(fn (): string => 'widget');
            }, 'widget');
            $c->view(fn (): string => $widget());
        });
        $id = $ctx->getId();
        $components = array_values($ctx->getComponentRegistry());
        expect($components)->toHaveCount(1);
        $componentRef = WeakReference::create($components[0]);
        expect(teardownIdsIn($app, 'widgets'))->toBe([$components[0]->getId()]);
        unset($ctx, $components);

        $app->getApp()->destroyContext($id);
        gc_collect_cycles();

        expect(teardownIdsIn($app, 'widgets'))->toBe([])
            ->and($componentRef->get())->toBeNull()
        ;
    });

    test('releasing a component leaves the scope to the contexts still in it', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->component(function (Context $w): void {
                $w->scope('widgets');
                $w->view(fn (): string => 'widget');
            }, 'widget');
            $c->view(fn (): string => 'page');
        };
        $first = teardownMintPage($app, '/a', $handler);
        $second = teardownMintPage($app, '/a', $handler);
        $secondWidget = array_values($second->getComponentRegistry())[0]->getId();

        $app->getApp()->destroyContext($first->getId());

        expect(teardownIdsIn($app, 'widgets'))->toBe([$secondWidget]);
    });
});

describe('Context teardown with a revival under the same ID', function (): void {
    test('a revival that registers while cleanup runs keeps its registrations', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->scope('room:1');
            $c->view(fn (): string => 'room');
        };
        $old = teardownMintPage($app, '/room', $handler);
        $id = $old->getId();
        $app->contexts[$id] = $old;
        $app->scheduleContextCleanup($id, 60_000);
        $revived = null;
        // A cleanup callback that yields lets a returning tab revive the ID mid-teardown.
        $old->onCleanup(function () use ($app, $id, $handler, &$revived): void {
            $revived = new Context($id, '/room', $app, null, 'session-1');
            $app->invokeHandlerWithParams($handler, $revived, []);
            $app->contexts[$id] = $revived;
            $app->getApp()->registerContext($revived);
            $app->registerContextInScope($revived, Scope::TAB);
        });

        $app->getApp()->destroyContext($id);

        expect($app->getApp()->getContext($id))->toBe($revived)
            ->and($app->contexts[$id] ?? null)->toBe($revived)
            ->and($app->getLocalContexts('room:1'))->toBe([$revived])
            ->and($app->getLocalContexts(Scope::TAB))->toBe([$revived])
        ;
    });

    test('teardown drops the context\'s session binding in both maps', function (): void {
        $app = createVia();
        $ctx = teardownMintPage($app, '/docs', function (Context $c): void {
            $c->scope(Scope::routeScope('/docs'));
            $c->view(fn (): string => 'docs');
        });
        $id = $ctx->getId();
        $app->contexts[$id] = $ctx;
        $app->contextSessions[$id] = 'session-1';
        $app->scheduleContextCleanup($id, 60_000);

        $app->getApp()->destroyContext($id);

        expect($app->getContextSessionId($id))->toBeNull()
            ->and($app->getApp()->getContextSessionId($id))->toBeNull()
            ->and($app->contexts)->not->toHaveKey($id)
        ;
    });

    test('a revival whose handler throws leaves no scope entry or session binding', function (): void {
        $app = createVia();
        $app->page('/flaky', function (Context $c): void {
            $c->scope('room:flaky');
            $c->view(fn (): string => 'flaky');
        });
        $ctx = teardownMintPage($app, '/flaky', function (Context $c): void {
            $c->scope('room:flaky');
            $c->view(fn (): string => 'flaky');
        });
        $id = $ctx->getId();
        $app->getApp()->destroyContext($id);
        // The route now throws after joining its scope, as a handler does when its database is down.
        $app->page('/flaky', function (Context $c): void {
            $c->scope('room:flaky');

            throw new RuntimeException('database down');
        });

        ob_start(); // the failed revival logs an error
        $revived = $app->reviveContextFromClient($id, 'session-1', []);
        $log = (string) ob_get_clean();

        expect($revived)->toBeNull()
            ->and($log)->toContain('database down')
            ->and($app->getLocalContexts('room:flaky'))->toBe([])
            ->and($app->getContextSessionId($id))->toBeNull()
        ;
    });
});

describe('Component IDs', function (): void {
    test('a named component gets the same ID each time its page is built', function (): void {
        $app = createVia();
        $build = function (string $pageId) use ($app): string {
            $page = new Context($pageId, '/p', $app);
            $page->component(fn (Context $w) => $w->view(fn (): string => 'w'), 'widget');

            return array_keys($page->getComponentRegistry())[0];
        };

        expect($build('/p_/abc'))->toBe($build('/p_/abc'))
            ->and($build('/p_/abc'))->not->toBe($build('/p_/xyz'))
        ;
    });

    test('a second component with one name on a page throws and leaves the first in place', function (): void {
        $app = createVia();
        $page = new Context('/p_/abc', '/p', $app);
        $page->component(fn (Context $w) => $w->view(fn (): string => 'a'), 'widget');

        expect(fn () => $page->component(fn (Context $w) => $w->view(fn (): string => 'b'), 'widget'))
            ->toThrow(InvalidArgumentException::class, "A component named 'widget' is already on this page")
            ->and($page->getComponentRegistry())->toHaveCount(1)
        ;
    });
});

describe('Scope state after a component is released', function (): void {
    test('a released component leaves its scope\'s signals while another context uses the scope', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->component(function (Context $w): void {
                $w->scope('widgets');
                $w->signal(0, 'clicks', 'widgets');
                $w->view(fn (): string => 'widget');
            }, 'widget');
            $c->view(fn (): string => 'page');
        };
        $first = teardownMintPage($app, '/a', $handler);
        $app->getScopedSignalByName('widgets', 'clicks', 'widget')?->setValue(7);
        teardownMintPage($app, '/a', $handler);

        $app->getApp()->destroyContext($first->getId());

        expect($app->getScopedSignalByName('widgets', 'clicks', 'widget')?->int())->toBe(7);
    });

    test('the last component in a scope takes the scope\'s signals with it', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->component(function (Context $w): void {
                $w->signal(0, 'clicks', 'widgets');
                $w->view(fn (): string => 'widget');
            }, 'widget');
            $c->view(fn (): string => 'page');
        };
        $first = teardownMintPage($app, '/a', $handler);
        $app->getScopedSignalByName('widgets', 'clicks', 'widget')?->setValue(7);

        $app->getApp()->destroyContext($first->getId());

        expect($app->getScopedSignalByName('widgets', 'clicks', 'widget'))->toBeNull();
        teardownMintPage($app, '/a', $handler);
        expect($app->getScopedSignalByName('widgets', 'clicks', 'widget')?->int())->toBe(0);
    });
});

describe('Cycle-free teardown', function (): void {
    test('a destroyed context is freed by its last reference and leaves no garbage for the cycle collector', function (): void {
        $app = createVia();
        $handlers = [
            '/counter' => function (Context $c): void {
                $count = $c->signal(0, 'count');
                $inc = $c->action(function (Context $ctx) use ($count): void {
                    $count->setValue($count->int() + 1);
                    $ctx->sync();
                }, 'inc');
                $c->view(fn (): string => '<div id="counter">' . $count->int() . $inc->url() . '</div>');
            },
            '/components' => function (Context $c): void {
                $widget = $c->component(function (Context $w): void {
                    $n = $w->signal(1, 'n');
                    $inner = $w->component(fn (Context $x) => $x->view(fn (): string => 'inner'), 'inner');
                    $w->action(fn () => $n->setValue(2), 'bump');
                    $w->view(fn (): string => 'widget ' . $n->int() . $inner());
                }, 'widget');
                $shared = $c->component(function (Context $w) use ($c): void {
                    $w->scope('widgets');
                    $w->view(fn (): string => 'shared on ' . $c->getId());
                }, 'shared');
                $c->view(fn (): string => '<div id="page">' . $widget() . $shared() . '</div>');
            },
            '/room' => function (Context $c): void {
                $c->scope('room:1');
                $c->onCleanup(fn () => $c->getId());
                $c->view(fn (): string => '<div id="room">room</div>');
            },
        ];
        $wasEnabled = gc_enabled();
        // No collector run may free what refcounting alone has to.
        gc_disable();

        try {
            gc_collect_cycles();
            $collectedBefore = gc_status()['collected'];
            $refs = [];
            foreach ($handlers as $route => $handler) {
                for ($i = 0; $i < 50; ++$i) {
                    $ctx = teardownMintPage($app, $route, $handler);
                    $id = $ctx->getId();
                    $app->contexts[$id] = $ctx;
                    $app->buildHtmlDocument($ctx);
                    $app->scheduleContextCleanup($id, 60_000);
                    foreach ([$ctx, ...array_values($ctx->getComponentRegistry())] as $owner) {
                        foreach ($owner->getNamedActions() as $action) {
                            $ctx->executeAction($action->id());
                        }
                        $refs[] = WeakReference::create($owner);
                    }
                    $ctx->sync();
                    while ($ctx->getPatch() !== null);
                    unset($ctx, $owner, $action);

                    $app->getApp()->destroyContext($id);
                }
            }

            expect(array_filter($refs, static fn (WeakReference $ref): bool => $ref->get() !== null))->toBe([])
                ->and(gc_collect_cycles())->toBe(0)
                ->and(gc_status()['collected'] - $collectedBefore)->toBe(0)
            ;
        } finally {
            if ($wasEnabled) {
                gc_enable();
            }
        }
    });
});
