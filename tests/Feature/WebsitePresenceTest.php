<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use PhpVia\Website\PresenceDemo;

/*
 * The presence line in the website's homepage hero counts the tabs whose stream is connected. A
 * tab's first render comes before its own stream connects, so that render counts the tab too.
 */

$presenceAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$presenceReady = false;

if (is_file($presenceAutoload)) {
    require_once $presenceAutoload;
    $presenceReady = class_exists(PresenceDemo::class);
}

if (!$presenceReady) {
    test('website presence line (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

/**
 * A Via with the website templates and a stand-in homepage that mounts the component the way app.php does.
 */
function presenceApp(): Via {
    $app = createVia((new Config())->withTemplateDir(dirname(__DIR__, 2) . '/website/templates'));
    $demo = new PresenceDemo($app);
    $app->page('/', function (Context $c) use ($demo): void {
        $presence = $c->component(fn (Context $presence) => $demo->component($presence, $c->getId()), 'presence');
        // Like StaticPage::view(): updates render nothing, and the component patches itself.
        $c->view(fn (bool $isUpdate): string => $isUpdate ? '' : '<main id="home">' . $presence() . '</main>', cacheUpdates: false);
    });

    return $app;
}

/**
 * Mint a homepage tab the way RequestHandler does and return it with its first HTML.
 *
 * @return array{Context, string}
 */
function presenceOpen(Via $app, string $id): array {
    $ctx = new Context($id, '/', $app, null, 'sess_' . md5($id));
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/'], $ctx, []);
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);

    return [$ctx, $app->buildHtmlDocument($ctx)];
}

/** What SseHandler does when the tab's stream connects. */
function presenceConnect(Via $app, string $id): void {
    $app->getApp()->registerClient($id, ['id' => 'client-' . md5($id), 'identicon' => '', 'connected_at' => time(), 'ip' => '127.0.0.1']);
}

/** The count and the noun the line shows, e.g. "2 people". */
function presenceLine(string $html): ?string {
    return preg_match('#<sb-odometer value="(\d+)">\1</sb-odometer> (person|people) on this website right now#', $html, $m) === 1 ? $m[1] . ' ' . $m[2] : null;
}

/** The line in the frames queued for a tab since the last call. */
function presenceFramed(Context $page): ?string {
    $html = '';
    while (($patch = $page->getPatch()) !== null) {
        if ($patch['type'] === 'elements') {
            $html .= (string) $patch['content'];
        }
    }

    return presenceLine($html);
}

describe('The presence line', function (): void {
    test('the first tab counts itself before its stream connects', function (): void {
        $app = presenceApp();
        [, $html] = presenceOpen($app, '/_/tab1');

        expect($app->getClients())->toBe([])
            ->and(presenceLine($html))->toBe('1 person')
        ;
    });

    test('a later tab counts the connected tabs and itself', function (): void {
        $app = presenceApp();
        presenceOpen($app, '/_/tab1');
        presenceConnect($app, '/_/tab1');
        [, $html] = presenceOpen($app, '/_/tab2');

        expect(presenceLine($html))->toBe('2 people');
    });

    test('a broadcast counts the connected tabs, as streams connect and leave', function (): void {
        $app = presenceApp();
        [$first] = presenceOpen($app, '/_/tab1');
        [$second] = presenceOpen($app, '/_/tab2');
        presenceFramed($first);
        presenceFramed($second);

        presenceConnect($app, '/_/tab2');
        $app->broadcast(PresenceDemo::SCOPE);
        // One render for the whole scope: the first tab's, which must not count itself, since it has not connected.
        expect(presenceFramed($first))->toBe('1 person')
            ->and(presenceFramed($second))->toBe('1 person')
        ;

        presenceConnect($app, '/_/tab1');
        $app->broadcast(PresenceDemo::SCOPE);
        expect(presenceFramed($first))->toBe('2 people');

        $app->getApp()->unregisterClient('/_/tab2');
        $app->broadcast(PresenceDemo::SCOPE);
        expect(presenceFramed($first))->toBe('1 person');
    });
});
