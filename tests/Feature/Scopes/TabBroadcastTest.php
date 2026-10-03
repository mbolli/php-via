<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * Context::broadcast() on a TAB-primary context, the default, syncs that tab; it used to fan out
 * to every tab on the worker. Via::broadcast() has no context to resolve the bare TAB, ROUTE and
 * SESSION against, so it rejects them instead of reaching every route or nobody.
 */

/**
 * Three tabs on three routes, registered the way RequestHandler registers them.
 *
 * @return array<string, Context>
 */
function tabBroadcastTabs(Via $app): array {
    $tabs = [];
    foreach (['A' => '/a', 'B' => '/b', 'C' => '/c'] as $id => $route) {
        $ctx = new Context($id, $route, $app, null, 'abcd0000abcd0000abcd0000abcd0000');
        $ctx->view(fn (): string => "<div id='v'>{$id}</div>");
        $app->contexts[$id] = $ctx;
        $app->getApp()->registerContext($ctx);
        $app->registerContextInScope($ctx, Scope::TAB);
        while ($ctx->getPatch() !== null);

        $tabs[$id] = $ctx;
    }

    return $tabs;
}

/**
 * @param array<string, Context> $tabs
 *
 * @return array<string, int> patches queued per tab
 */
function tabBroadcastPatches(array $tabs): array {
    $counts = [];
    foreach ($tabs as $id => $ctx) {
        $counts[$id] = 0;
        while ($ctx->getPatch() !== null) {
            ++$counts[$id];
        }
    }

    return $counts;
}

function tabBroadcastOutput(callable $fn): string {
    ob_start();

    try {
        $fn();
    } finally {
        $out = (string) ob_get_clean();
    }

    return $out;
}

describe('Context::broadcast() on a TAB-primary context', function (): void {
    test('syncs only the calling tab', function (): void {
        $tabs = tabBroadcastTabs(createVia());

        $tabs['A']->broadcast();

        expect(tabBroadcastPatches($tabs))->toBe(['A' => 1, 'B' => 0, 'C' => 0]);
    });

    test('in dev mode warns once that it no longer reaches the scopes the tab joined', function (): void {
        $app = new Via((new Config())->withLogLevel('warn')->withDevMode(true));
        $tabs = tabBroadcastTabs($app);
        $tabs['A']->addScope('room:lobby');

        $out = tabBroadcastOutput(function () use ($tabs): void {
            $tabs['A']->broadcast();
            $tabs['A']->broadcast();
        });

        expect(substr_count($out, 'Context::broadcast() syncs only this tab'))->toBe(1)
            ->and($out)->toContain('(room:lobby)')
            ->and($out)->toContain('$app->broadcast()')
        ;
    });

    test('does not warn outside dev mode or without joined scopes', function (): void {
        $quiet = new Via((new Config())->withLogLevel('warn')->withDevMode(false));
        $joined = tabBroadcastTabs($quiet)['A'];
        $joined->addScope('room:lobby');
        $loud = new Via((new Config())->withLogLevel('warn')->withDevMode(true));
        $alone = tabBroadcastTabs($loud)['A'];

        $out = tabBroadcastOutput(function () use ($joined, $alone): void {
            $joined->broadcast();
            $alone->broadcast();
        });

        expect($out)->not->toContain('syncs only this tab');
    });
});

describe('Bare scopes outside a context', function (): void {
    test('Via::broadcast() rejects them and names the fix', function (string $scope, string $fix): void {
        $app = createVia();
        $tabs = tabBroadcastTabs($app);

        expect(fn () => $app->broadcast($scope))->toThrow(InvalidArgumentException::class, $fix)
            ->and(tabBroadcastPatches($tabs))->toBe(['A' => 0, 'B' => 0, 'C' => 0])
        ;
    })->with([
        'TAB' => [Scope::TAB, 'To update the calling tab use $c->sync()'],
        'ROUTE' => [Scope::ROUTE, "Pass Scope::routeScope('/path')"],
        'SESSION' => [Scope::SESSION, 'Pass Scope::sessionScope($sessionId)'],
    ]);

    test('Via::getScopedSignalByName() rejects them', function (string $scope): void {
        expect(fn () => createVia()->getScopedSignalByName($scope, 'count'))
            ->toThrow(InvalidArgumentException::class, 'Via::getScopedSignalByName()')
        ;
    })->with([Scope::TAB, Scope::ROUTE, Scope::SESSION]);

    test('resolved scopes still broadcast', function (): void {
        $app = createVia();
        $tabs = tabBroadcastTabs($app);

        $app->broadcast(Scope::routeScope('/b'));

        expect(tabBroadcastPatches($tabs))->toBe(['A' => 0, 'B' => 1, 'C' => 0]);
    });
});
