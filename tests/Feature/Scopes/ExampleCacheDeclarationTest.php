<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

/*
 * Shared-scope examples must declare how their updates are rendered.
 *
 * A context that broadcasts to a shared scope while keeping TAB as its primary scope gets no
 * update caching, so every client in that scope re-renders. That is correct whenever the view
 * embeds per-client data — but nothing in the code says so, and the primary scope is one call
 * away from being "optimised" into a cross-client HTML leak (b0b8dda fixed exactly that once).
 *
 * `cacheUpdates: false` is the declaration. It is a no-op for a TAB-primary context today, which
 * is the point: it records the intent so promoting the scope later cannot silently start sharing
 * one client's HTML with another.
 *
 * Drives the REAL website handlers, so it fails if a new example picks up the idiom without the
 * declaration. Skips cleanly on a library-only checkout.
 */

$cacheDeclAutoload = dirname(__DIR__, 3) . '/website/vendor/autoload.php';
$cacheDeclReady = false;

if (is_file($cacheDeclAutoload)) {
    require_once $cacheDeclAutoload;
    $cacheDeclReady = class_exists('PhpVia\\Website\\Examples\\CounterExample');
}

if (!$cacheDeclReady) {
    test('shared-scope cache declarations (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing — run composer install in website/ to enable')
    ;

    return;
}

/** @return list<string> Mirrors website/routes.php. */
function cacheDeclExampleClasses(): array {
    return [
        'CounterExample', 'CompositionDemo', 'GreeterExample', 'TodoExample', 'ComponentsExample',
        'PathParamsExample', 'StockTickerExample', 'ChatRoomExample', 'ClientMonitorExample', 'AllScopesExample',
        'GameOfLifeExample', 'SpreadsheetExample', 'LiveSearchExample', 'ShoppingCartExample', 'ThemeBuilderExample',
        'WizardExample', 'LoginExample', 'ContactFormExample', 'FileUploadExample', 'LiveAuctionExample',
        'TypeRaceExample',
    ];
}

function cacheDeclBuildApp(): Via {
    $config = (new Config())
        ->withLogLevel('error')
        ->withTemplateDir(dirname(__DIR__, 3) . '/website/templates')
    ;
    $app = new Via($config);

    foreach (cacheDeclExampleClasses() as $short) {
        $cls = 'PhpVia\\Website\\Examples\\' . $short;
        $cls::register($app);
        if (method_exists($cls, 'registerHooks')) {
            $cls::registerHooks($app);
        }
    }

    return $app;
}

/** @return array<string, string> */
function cacheDeclSynthParams(string $route): array {
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

afterAll(function (): void {
    if (class_exists(Timer::class) && method_exists(Timer::class, 'clearAll')) {
        Timer::clearAll();
    }
});

test('every example that broadcasts from a TAB-primary context declares cacheUpdates: false', function (): void {
    $app = cacheDeclBuildApp();
    $offenders = [];
    $checked = 0;

    foreach ($app->getRouter()->getRoutes() as $route => $handler) {
        if (!str_starts_with($route, '/examples/')) {
            continue;
        }

        $params = cacheDeclSynthParams($route);
        $contextId = $route . '_/cachedecl';

        $ctx = new Context($contextId, $route, $app, null, 'sess_cachedecl');
        $app->contexts[$contextId] = $ctx;
        $app->getApp()->registerContext($ctx);
        $app->getApp()->setContextSession($contextId, 'sess_cachedecl');
        $ctx->injectRouteParams($params);
        $app->registerContextInScope($ctx, Scope::TAB);

        try {
            $app->invokeHandlerWithParams($handler, $ctx, $params);
        } catch (Throwable) {
            // Handlers needing external infrastructure (NATS) or website-only Twig globals
            // cannot mount in-process. Their scope wiring is out of reach here.
            $app->getApp()->destroyContext($contextId);
            unset($app->contexts[$contextId]);

            continue;
        }

        // Read the scope wiring BEFORE teardown — destroyContext() unregisters every scope.
        $shared = array_values(array_filter($ctx->getScopes(), static fn (string $s): bool => $s !== Scope::TAB));
        $isTabPrimary = $ctx->getPrimaryScope() === Scope::TAB;
        $cacheable = $ctx->shouldCacheUpdates();
        $hasView = $ctx->hasView();

        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);

        if ($shared === [] || !$isTabPrimary || !$hasView) {
            continue;
        }

        ++$checked;
        if ($cacheable) {
            $offenders[$route] = implode(', ', $shared);
        }
    }

    expect($checked)->toBeGreaterThan(0, 'no shared-scope example route was reachable — the guard is not running');
    expect($offenders)->toBe(
        [],
        'TAB-primary routes broadcasting to a shared scope without cacheUpdates: false — '
        . json_encode($offenders)
    );
});
