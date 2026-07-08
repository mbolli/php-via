<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

/*
 * Cross-example revival (end-to-end, in-process)
 *
 * Drives every real website example route through the full revival cycle — initial load →
 * user edit → destroy (records the revival snapshot) → reconnect/revive — and asserts that the
 * rebuilt context keeps the invariants the already-loaded DOM depends on:
 *   - same context ID,
 *   - TAB signal IDs regenerate byte-identical (data-bind / data-text keep resolving),
 *   - named action IDs stay stable (baked action URLs keep dispatching),
 *   - the client's edited signal value is seeded back into the fresh context.
 *
 * The example handlers live in the website sub-project (its own PSR-4 root: PhpVia\Website\), so we
 * load its autoloader and drive the REAL registered handlers — not reconstructions. If the website
 * dependencies aren't installed (library-only checkout), the whole group skips cleanly.
 */

/** @return list<string> The example classes, mirroring website/routes.php. */
function reviveExampleClasses(): array {
    return [
        'CounterExample', 'CompositionDemo', 'GreeterExample', 'TodoExample', 'ComponentsExample',
        'PathParamsExample', 'StockTickerExample', 'ChatRoomExample', 'ClientMonitorExample', 'AllScopesExample',
        'GameOfLifeExample', 'SpreadsheetExample', 'LiveSearchExample', 'ShoppingCartExample', 'ThemeBuilderExample',
        'WizardExample', 'LoginExample', 'ContactFormExample', 'FileUploadExample', 'LiveAuctionExample',
        'TypeRaceExample', 'MissionControlExample',
    ];
}

/** Build a Via app with every example route registered, exactly as the website does. */
function reviveBuildWebsiteApp(): Via {
    $config = (new Config())
        ->withLogLevel('error')
        ->withTemplateDir(dirname(__DIR__, 2) . '/website/templates')
    ;
    $app = new Via($config);

    foreach (reviveExampleClasses() as $short) {
        $cls = 'PhpVia\\Website\\Examples\\' . $short;
        $cls::register($app);
        if (method_exists($cls, 'registerHooks')) {
            $cls::registerHooks($app);
        }
    }

    return $app;
}

/** Synthetic values for a route pattern's path parameters (e.g. /stock/{symbol}). */
function reviveSynthParams(string $route): array {
    $params = [];
    if (preg_match_all('/\{(\w+)\}/', $route, $m)) {
        foreach ($m[1] as $name) {
            $params[$name] = match ($name) {
                'year' => '2024',
                'month' => '03',
                'symbol' => 'AAPL',
                'room' => 'lobby',
                default => 'sample',
            };
        }
    }

    return $params;
}

// ── Load website handlers & enumerate example routes at collection time ────────────────────────
$reviveAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$reviveRoutes = [];
$reviveReady = false;

// Routes whose handler needs external infrastructure to mount and so can't run in-process:
// mission-control lazy-connects to NATS via a background Coroutine::create() on first mount,
// which hangs (asleep coroutine → scheduler deadlock at exit) with no NATS server present.
$reviveSkip = [
    '/examples/mission-control',
];

if (is_file($reviveAutoload)) {
    require_once $reviveAutoload;
    if (class_exists('PhpVia\\Website\\Examples\\CounterExample')) {
        $reviveReady = true;
        foreach (array_keys(reviveBuildWebsiteApp()->getRouter()->getRoutes()) as $route) {
            if (str_starts_with($route, '/examples/') && !in_array($route, $reviveSkip, true)) {
                $reviveRoutes[$route] = [$route, reviveSynthParams($route)];
            }
        }
    }
}

if (!$reviveReady) {
    test('example revival (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing — run composer install in website/ to enable')
    ;

    return;
}

// Named dataset so the per-route rows are visible inside the describe()/it() closures.
dataset('exampleRevivalRoutes', $reviveRoutes);

// Some example handlers register interval timers via $c->setInterval() (StockTicker,
// MissionControl, …). registerTimer() has no VIA_TEST_MODE guard, so any timer left running
// makes OpenSwoole's scheduler report a deadlock at process exit. Clear every timer once this
// file's tests finish so the suite shuts down cleanly.
afterAll(function (): void {
    if (class_exists(Timer::class) && method_exists(Timer::class, 'clearAll')) {
        Timer::clearAll();
    }
});

describe('Every example route revives without a reload', function (): void {
    it('rebuilds the context with stable signal/action IDs and seeded state', function (string $route, array $params): void {
        $app = reviveBuildWebsiteApp();
        $handler = $app->getRouter()->getRoutes()[$route] ?? null;
        expect($handler)->not->toBeNull();

        $sessionId = 'sess_owner';
        $contextId = $route . '_/rev';

        // 1. Initial load: mint a context, register it, run the real example handler —
        //    mirroring RequestHandler::doHandlePage(), including injecting the route params
        //    (so the revival snapshot captures them and can replay a parameterised route).
        $ctx = new Context($contextId, $route, $app, null, $sessionId);
        $app->contexts[$contextId] = $ctx;
        $app->getApp()->registerContext($ctx);
        $app->getApp()->setContextSession($contextId, $sessionId);
        $ctx->injectRouteParams($params);
        $app->registerContextInScope($ctx, Scope::TAB);
        $app->invokeHandlerWithParams($handler, $ctx, $params);

        // Snapshot the invariants the loaded DOM depends on.
        $tabIdsBefore = array_keys($ctx->getSignalFactory()->getTabSignals());
        sort($tabIdsBefore);
        $actionIdsBefore = [];
        foreach ($ctx->getNamedActions() as $name => $action) {
            $actionIdsBefore[$name] = $action->id();
        }

        // The client still holds its signal values; simulate one edit to the first TAB signal.
        $clientSignals = [];
        foreach ($ctx->getSignalFactory()->getTabSignals() as $id => $signal) {
            $clientSignals[$id] = $signal->getValue();
        }
        $editedId = $tabIdsBefore[0] ?? null;
        if ($editedId !== null) {
            $clientSignals[$editedId] = 'revived-value';
        }

        // 2. Tab backgrounded past the cleanup delay → destroyed (records a revival snapshot).
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);
        expect($app->contexts[$contextId] ?? null)->toBeNull();

        // 3. Tab returns: reconnect revives the context from the client's held signals.
        $revived = $app->reviveContextFromClient($contextId, $sessionId, $clientSignals);

        try {
            expect($revived)->not->toBeNull();
            expect($revived->getId())->toBe($contextId);

            $tabIdsAfter = array_keys($revived->getSignalFactory()->getTabSignals());
            sort($tabIdsAfter);
            expect($tabIdsAfter)->toEqual($tabIdsBefore); // signal IDs regenerate identically

            foreach ($actionIdsBefore as $name => $id) {
                expect($revived->getAction($name)?->id())->toBe($id); // action URLs stay valid
            }

            if ($editedId !== null) {
                expect($revived->getSignalFactory()->getTabSignals()[$editedId]->getValue())
                    ->toBe('revived-value') // client edit seeded back
                ;
            }
        } finally {
            // Clear any interval timers the handler registered so the process doesn't
            // deadlock at exit (registerTimer() has no VIA_TEST_MODE guard).
            if ($revived !== null) {
                $app->getApp()->destroyContext($contextId);
            }
        }
    })->with('exampleRevivalRoutes');
});
