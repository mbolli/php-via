<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * The Components and Composition examples embed components in the page view. A broadcast that
 * reaches the page, a reconnect or a revival re-renders that view, and the frame it sends has to
 * carry the components as they are now, not as they were at page load.
 */

$compRenderAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$compRenderReady = false;

if (is_file($compRenderAutoload)) {
    require_once $compRenderAutoload;
    $compRenderReady = class_exists('PhpVia\\Website\\Examples\\ComponentsExample');
}

if (!$compRenderReady) {
    test('example components rendered with the page (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

function compRenderMount(string $example, string $route, string $id): Context {
    $app = new Via(
        (new Config())
            ->withLogLevel('error')
            ->withTemplateDir(dirname(__DIR__, 2) . '/website/templates')
    );
    foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
        $app->getTwig()->addGlobal($name, $value);
    }
    ('PhpVia\\Website\\Examples\\' . $example)::register($app);

    $ctx = new Context($id, $route, $app, null, 'sess_comp');
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($id, 'sess_comp');
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()[$route], $ctx, []);
    $app->buildHtmlDocument($ctx);

    return $ctx;
}

/**
 * Take every queued patch and return the page frame and the selectors of the component frames.
 *
 * @return array{0: string, 1: list<string>}
 */
function compRenderFrames(Context $ctx): array {
    $page = '';
    $selectors = [];
    while (($patch = $ctx->getPatch()) !== null) {
        if ($patch['type'] !== 'elements') {
            continue;
        }
        if (isset($patch['selector'])) {
            $selectors[] = $patch['selector'];
        } else {
            $page = (string) $patch['content'];
        }
    }

    return [$page, $selectors];
}

function compRenderComponent(Context $page, string $namespace): Context {
    foreach ($page->getComponentManager()->getComponents() as $component) {
        if ($component->getNamespace() === $namespace) {
            return $component;
        }
    }

    throw new RuntimeException("no component {$namespace}");
}

test('a page re-render keeps the count a Components counter reached', function (): void {
    $page = compRenderMount('ComponentsExample', '/examples/components', '/examples/components_/c1');
    $counter = compRenderComponent($page, 'counter1');
    $counter->executeAction($counter->getAction('increment')->id());
    $counter->executeAction($counter->getAction('increment')->id());
    compRenderFrames($page);

    $page->sync();
    [$html, $selectors] = compRenderFrames($page);

    expect($html)->toMatch('/Counter: counter1\s*<\/h3>\s*<p class="component-counter-value">\s*Count: 2\b/')
        ->and($selectors)->toBe([])
    ;
});

test('a page re-render keeps the votes a Composition widget reached', function (): void {
    $page = compRenderMount('CompositionDemo', '/examples/composition', '/examples/composition_/v1');
    $cats = compRenderComponent($page, 'cats');
    $cats->executeAction($cats->getAction('vote')->id());
    compRenderFrames($page);

    $page->sync();
    [$html, $selectors] = compRenderFrames($page);
    $votesId = $cats->getSignal('votes')->id();

    expect($html)->toContain('<span data-text="$' . $votesId . '">1</span>')
        ->and($selectors)->toBe([])
    ;
});
