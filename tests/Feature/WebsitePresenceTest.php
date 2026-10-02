<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use PhpVia\Website\PresenceDemo;

/*
 * The presence line in the website's homepage hero counts the tabs whose stream is connected, and
 * never fewer than the one viewer. As a tab loads, the line must not jump down and back up.
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
        $presence = $c->component($demo->component(...), 'presence');
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

/** What SseHandler does when the tab's stream connects, before its first sync. */
function presenceConnect(Via $app, string $id): void {
    $app->getApp()->registerClient($id, ['id' => 'client-' . md5($id), 'identicon' => '', 'connected_at' => time(), 'ip' => '127.0.0.1']);
}

/**
 * A homepage tab's stream connecting, as SseHandler runs it: the client is registered, the initial
 * sync renders the line, and the onClientConnect broadcast renders it again.
 *
 * @return array{0: ?string, 1: ?string} the line the sync showed and the one the broadcast showed
 */
function presenceStream(Via $app, Context $page): array {
    presenceConnect($app, $page->getId());
    $page->sync();
    $synced = presenceFramed($page);
    $app->broadcast(PresenceDemo::SCOPE);

    return [$synced, presenceFramed($page)];
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

describe('The presence line as a tab loads', function (): void {
    test('the first visitor sees 1 person throughout, also after an earlier visitor left', function (): void {
        $app = presenceApp();
        [$first, $html] = presenceOpen($app, '/_/tab1');
        expect(presenceLine($html))->toBe('1 person')
            ->and(presenceStream($app, $first))->toBe(['1 person', '1 person'])
        ;

        $app->getApp()->unregisterClient('/_/tab1');
        $app->broadcast(PresenceDemo::SCOPE);
        [$second, $html] = presenceOpen($app, '/_/tab2');

        expect(presenceLine($html))->toBe('1 person')
            ->and(presenceStream($app, $second))->toBe(['1 person', '1 person'])
        ;
    });

    test('a later visitor sees the count only go up', function (): void {
        $app = presenceApp();
        [$first] = presenceOpen($app, '/_/tab1');
        presenceStream($app, $first);
        [$second, $html] = presenceOpen($app, '/_/tab2');

        expect(presenceLine($html))->toBe('1 person')
            ->and(presenceStream($app, $second))->toBe(['1 person', '2 people'])
        ;
    });

    test('going to the homepage from another page of the site does not count the page left', function (): void {
        $app = presenceApp();
        presenceConnect($app, '/docs_/left');
        $app->broadcast(PresenceDemo::SCOPE);
        [$home, $html] = presenceOpen($app, '/_/home');
        $app->getApp()->unregisterClient('/docs_/left');

        expect(presenceLine($html))->toBe('1 person')
            ->and(presenceStream($app, $home))->toBe(['1 person', '1 person'])
        ;
    });
});

describe('The presence line', function (): void {
    test('a broadcast counts the connected tabs, as streams connect and leave', function (): void {
        $app = presenceApp();
        [$first] = presenceOpen($app, '/_/tab1');
        [$second] = presenceOpen($app, '/_/tab2');
        presenceFramed($first);
        presenceFramed($second);

        presenceConnect($app, '/_/tab2');
        $app->broadcast(PresenceDemo::SCOPE);
        // One render for the whole scope, also sent to the first tab, which has not connected.
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
