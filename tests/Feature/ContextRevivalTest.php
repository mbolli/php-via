<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * Context Revival (end-to-end, in-process)
 *
 * When a backgrounded tab's context is destroyed past the cleanup delay, a returning tab should
 * rebuild an equivalent context (same ID → same signal/action IDs) and re-seed the values the
 * client still holds — instead of hard-reloading. This exercises the full server-side cycle:
 * initial load → user interaction → destroy (records revival snapshot) → reconnect → revive.
 *
 * OpenSwoole Request/Response are final C-extension classes (not constructible in tests), so we
 * drive Via::reviveContextFromClient() — the Request-free core of reviveContext() — directly, the
 * same way ActionAuthorizationTest reaches handlers without a live server.
 */

/**
 * Register the counter route and return [Via, handler]. The handler is returned so the test can
 * replay it for the simulated initial load (mimicking RequestHandler::doHandlePage()).
 *
 * @return array{Via, callable}
 */
function reviveCounterApp(?Config $config = null): array {
    $app = createVia($config);
    $handler = function (Context $c): void {
        $count = $c->signal(0, 'count');
        $c->action(function () use ($count): void {
            $count->setValue($count->int() + 1);
        }, 'increment');
        $c->view(fn (): string => (string) $count->int());
    };
    $app->page('/counter', $handler);

    return [$app, $handler];
}

/**
 * Simulate an initial page load: mint a context with the route-encoded ID, register it in both
 * the Via and Application layers, and run the handler — mirroring RequestHandler::doHandlePage().
 */
function reviveMintContext(Via $app, callable $handler, string $contextId, string $sessionId): Context {
    $ctx = new Context($contextId, '/counter', $app, null, $sessionId);
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, $sessionId);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($handler, $ctx, []);

    return $ctx;
}

describe('Deterministic (revival-stable) action IDs', function (): void {
    test('named TAB action IDs are the name, stable across a re-run with the same context ID', function (): void {
        $app = createVia();

        $first = (new Context('/counter_/x', '/counter', $app))->action(fn () => null, 'increment')->id();
        // A revived context re-runs the same handler under the same ID — the ID must be identical.
        $second = (new Context('/counter_/x', '/counter', $app))->action(fn () => null, 'increment')->id();

        expect($first)->toBe('increment');
        expect($second)->toBe('increment');
    });

    test('component actions are namespaced so they never collide with the parent page', function (): void {
        $app = createVia();

        $parentId = (new Context('/p_/x', '/p', $app))->action(fn () => null, 'increment')->id();
        // Component contexts carry a namespace (e.g. "a") — its actions are prefixed with it.
        $componentId = (new Context('/p_/x/_component/y', '/p', $app, 'a'))->action(fn () => null, 'increment')->id();

        expect($parentId)->toBe('increment');
        expect($componentId)->toBe('a-increment');
        expect($componentId)->not->toBe($parentId);
    });

    test('anonymous TAB actions get deterministic sequential IDs', function (): void {
        $app = createVia();
        $ctx = new Context('ctx', '/t', $app);

        expect($ctx->action(fn () => null)->id())->toBe('action0');
        expect($ctx->action(fn () => null)->id())->toBe('action1');
        // A revived context registering in the same order regenerates the same IDs.
        expect((new Context('ctx', '/t', $app))->action(fn () => null)->id())->toBe('action0');
    });
});

describe('Context revival', function (): void {
    test('a returning tab revives to the same view with seeded state and a working button', function (): void {
        [$app, $handler] = reviveCounterApp();
        $sessionId = 'sess_owner';
        $contextId = '/counter_/init1';

        // Initial load.
        $ctx = reviveMintContext($app, $handler, $contextId, $sessionId);
        $signalId = $ctx->getSignal('count')->id();
        $actionId = $ctx->getAction('increment')->id();

        // User clicked a few times; count is now 42 client-side.
        $ctx->getSignal('count')->setValue(42);

        // Tab backgrounded past the cleanup delay → destroyed (captures a revival snapshot).
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);
        expect($app->contexts[$contextId] ?? null)->toBeNull();

        // Tab returns: the /_sse reconnect carries the same via_ctx and the client's live signals.
        $revived = $app->reviveContextFromClient($contextId, $sessionId, [$signalId => 42]);

        expect($revived)->not->toBeNull();
        expect($revived->getId())->toBe($contextId);                      // same ID → DOM stays wired
        expect($revived->getSignal('count')->id())->toBe($signalId);       // signal ID regenerated identically
        expect($revived->getSignal('count')->int())->toBe(42);             // seeded from the client
        expect($revived->getAction('increment')->id())->toBe($actionId);   // action URL is stable

        // The already-loaded DOM's button (baked with $actionId) still dispatches.
        $revived->executeAction($actionId);
        expect($revived->getSignal('count')->int())->toBe(43);
    });

    test('revival is denied when the requester session does not own the context', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/init2';

        reviveMintContext($app, $handler, $contextId, 'sess_owner');
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);

        expect($app->reviveContextFromClient($contextId, 'sess_attacker', []))->toBeNull();
    });

    test('revival is disabled when the window is 0 (reconnect falls back to reload)', function (): void {
        [$app, $handler] = reviveCounterApp((new Config())->withContextRevivalWindow(0));
        $contextId = '/counter_/init3';

        reviveMintContext($app, $handler, $contextId, 'sess_owner');
        $app->getApp()->destroyContext($contextId); // window 0 → no snapshot recorded
        unset($app->contexts[$contextId]);

        expect($app->reviveContextFromClient($contextId, 'sess_owner', []))->toBeNull();
    });

    test('an unknown / never-recorded context ID cannot be revived', function (): void {
        [$app] = reviveCounterApp();

        expect($app->reviveContextFromClient('/counter_/never', 'sess_owner', []))->toBeNull();
    });
});
