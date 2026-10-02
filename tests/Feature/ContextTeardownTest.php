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
    return array_map(static fn (Context $c): string => $c->getId(), $app->getContextsByScope($scope));
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
