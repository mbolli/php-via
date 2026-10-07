<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use PhpVia\Website\Examples\LeaderboardExample;

/*
 * The Leaderboard example animates its updates: each update render asks for a document view transition, the
 * render a stream sends on connect does not, and every row has a view-transition-name of its own.
 */

$leaderboardAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$leaderboardReady = false;

if (is_file($leaderboardAutoload)) {
    require_once $leaderboardAutoload;
    $leaderboardReady = class_exists('PhpVia\\Website\\Examples\\LeaderboardExample');
}

if (!$leaderboardReady) {
    test('leaderboard view transitions (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

function leaderboardMount(string $id): Context {
    $app = new Via(
        (new Config())
            ->withLogLevel('error')
            ->withTemplateDir(dirname(__DIR__, 2) . '/website/templates')
    );
    foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
        $app->getTwig()->addGlobal($name, $value);
    }
    LeaderboardExample::register($app);

    $route = LeaderboardExample::ROUTE;
    $ctx = new Context($id, $route, $app, null, 'sess_lb');
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($id, 'sess_lb');
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()[$route], $ctx, []);
    $app->buildHtmlDocument($ctx);

    return $ctx;
}

/** @return list<array<string, mixed>> the element patches queued for $ctx */
function leaderboardElementPatches(Context $ctx): array {
    $patches = [];
    while (($patch = $ctx->getPatch()) !== null) {
        unset($patch['confirm']);
        if ($patch['type'] === 'elements') {
            $patches[] = $patch;
        }
    }

    return $patches;
}

test('an update render asks for a document view transition and the connect render does not', function (): void {
    $ctx = leaderboardMount('/examples/leaderboard_/lb1');
    leaderboardElementPatches($ctx);

    $ctx->syncWithoutViewTransition();
    $connect = leaderboardElementPatches($ctx);

    $ctx->sync();
    $update = leaderboardElementPatches($ctx);

    expect($connect)->toHaveCount(1)
        ->and($connect[0])->not->toHaveKey('viewTransition')
        ->and($update)->toHaveCount(1)
        ->and($update[0]['viewTransition'] ?? null)->toBeTrue()
    ;
});

test('every row has a view-transition-name of its own', function (): void {
    $ctx = leaderboardMount('/examples/leaderboard_/lb2');
    leaderboardElementPatches($ctx);
    $ctx->sync();
    $html = (string) leaderboardElementPatches($ctx)[0]['content'];

    preg_match_all('/<li class="lb-row[^"]*" id="(lb-\d+)" style="view-transition-name: (lb-\d+)"/', $html, $m);

    expect($m[1])->toHaveCount(8)
        ->and($m[2])->toBe($m[1])
        ->and(array_unique($m[2]))->toHaveCount(8)
    ;
});
