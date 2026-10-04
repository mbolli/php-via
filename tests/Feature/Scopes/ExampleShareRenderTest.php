<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

/*
 * The website examples whose view is identical for every tab share their update render, and
 * each of them has the shared primary scope that sharing needs (shareRender: true on a TAB-primary
 * context throws at render). Every other example renders per tab, the default.
 *
 * Drives the REAL website handlers. Skips cleanly on a library-only checkout.
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

test('the examples that share their update render are the ones with a view identical for every tab', function (): void {
    $app = cacheDeclBuildApp();
    $sharing = [];
    $tabPrimary = [];

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
            // Handlers needing external infrastructure (NATS) cannot mount in-process.
            $app->getApp()->destroyContext($contextId);
            unset($app->contexts[$contextId]);

            continue;
        }

        foreach (['' => $ctx] + array_combine(
            array_map(static fn (Context $c): string => (string) $c->getNamespace(), array_values($ctx->getComponentRegistry())),
            array_values($ctx->getComponentRegistry()),
        ) as $namespace => $view) {
            if (!$view->shouldShareRender()) {
                continue;
            }
            $label = $namespace === '' ? $route : "{$route}#{$namespace}";
            $sharing[] = $label;
            if ($view->getPrimaryScope() === Scope::TAB) {
                $tabPrimary[] = $label;
            }
        }

        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);
    }

    sort($sharing);

    expect($tabPrimary)->toBe([])
        ->and($sharing)->toBe([
            '/examples/all-scopes#global',
            '/examples/all-scopes#route',
            '/examples/all-scopes/page-a#global',
            '/examples/all-scopes/page-a#route',
            '/examples/all-scopes/page-b#global',
            '/examples/all-scopes/page-b#route',
            '/examples/client-monitor',
            '/examples/game-of-life',
            '/examples/stock-ticker',
        ])
    ;
});
