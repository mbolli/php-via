<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * The shopping cart and file upload examples keep a scope per session. The session id is the
 * HttpOnly cookie, and scopes reach the Dev Bar, traces, broker messages and signal ids in the
 * page, so those scopes are built from the hashed session scope.
 */

$sessionScopeAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$sessionScopeReady = false;

if (is_file($sessionScopeAutoload)) {
    require_once $sessionScopeAutoload;
    $sessionScopeReady = class_exists('PhpVia\\Website\\Examples\\ShoppingCartExample');
}

if (!$sessionScopeReady) {
    test('website session scopes (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

const WEBSITE_SESSION_ID = 'cafe0000cafe0000cafe0000cafe0000';

function websiteSessionScopeMount(string $example, string $route): Context {
    $app = new Via(
        (new Config())
            ->withLogLevel('error')
            ->withTemplateDir(dirname(__DIR__, 2) . '/website/templates')
    );
    foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
        $app->getTwig()->addGlobal($name, $value);
    }
    ('PhpVia\\Website\\Examples\\' . $example)::register($app);

    $ctx = new Context('ctx-' . $example, $route, $app, null, WEBSITE_SESSION_ID);
    $app->contexts[$ctx->getId()] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()[$route], $ctx, []);

    return $ctx;
}

describe('Website scopes kept per session', function (): void {
    test('carry no session id in their name, signal ids or page', function (string $example, string $route): void {
        $ctx = websiteSessionScopeMount($example, $route);

        expect(implode(' ', $ctx->getScopes()))->toContain(Scope::sessionScope(WEBSITE_SESSION_ID))
            ->and(implode(' ', $ctx->getScopes()))->not->toContain(WEBSITE_SESSION_ID)
            ->and(implode(' ', array_keys($ctx->getSignals())))->not->toContain(WEBSITE_SESSION_ID)
            ->and($ctx->renderView())->not->toContain(WEBSITE_SESSION_ID)
        ;
    })->with([
        'shopping cart' => ['ShoppingCartExample', '/examples/shopping-cart'],
        'file upload' => ['FileUploadExample', '/examples/file-upload'],
    ]);
});
