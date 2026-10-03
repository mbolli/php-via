<?php

declare(strict_types=1);

/*
 * Fixture for BroadcastCacheInvalidationTest: update-cache cases that need coroutines.
 *
 * argv[1] picks the case:
 *   flush       four contexts share primary 'route', each in its own team scope; one flush
 *               broadcasts all four team scopes
 *   concurrent  a fan-out whose view waits on I/O overlaps a newer broadcast of the same primary scope
 *   revival     two revivals of one context ID whose page handler yields
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

/** @return list<string> element patches queued for $c */
function drainElements(Context $c): array {
    $out = [];
    while (($p = $c->getPatch()) !== null) {
        if ($p['type'] === 'elements') {
            $out[] = (string) $p['content'];
        }
    }

    return $out;
}

$case = (string) ($argv[1] ?? 'flush');
$GLOBALS['renders'] = 0;
$GLOBALS['v'] = 1;

Coroutine::run(static function () use ($case): void {
    if ($case === 'flush') {
        $app = new Via((new Config())->withLogLevel('error'));
        $contexts = [];
        foreach (['red', 'blue', 'green', 'yellow'] as $i => $team) {
            $c = new Context("/board_/{$i}", '/board', $app);
            $c->scope('route');
            $c->addScope("team:{$team}");
            $c->view(static function (): string {
                ++$GLOBALS['renders'];

                return '<p>v=' . $GLOBALS['v'] . '</p>';
            }, shareRender: true);
            $app->contexts["/board_/{$i}"] = $c;
            $app->getApp()->registerContext($c);
            $contexts[] = $c;
        }
        $app->broadcast('route:/board');
        $app->flushBroadcasts();
        array_map(drainElements(...), $contexts);

        $GLOBALS['renders'] = 0;
        $GLOBALS['v'] = 2;
        foreach (['red', 'blue', 'green', 'yellow'] as $team) {
            $app->broadcast("team:{$team}");
        }
        $app->flushBroadcasts();

        $fresh = 0;
        foreach ($contexts as $c) {
            $fresh += str_contains(implode('', drainElements($c)), 'v=2') ? 1 : 0;
        }
        echo 'renders=', $GLOBALS['renders'], "\n";
        echo 'fresh=', $fresh, "\n";

        return;
    }

    if ($case === 'concurrent') {
        $app = new Via((new Config())->withLogLevel('error')->withBroadcastCoalescing(false));
        $make = static function (string $id, string $extra) use ($app): Context {
            $c = new Context($id, '/r', $app);
            $c->scope('room:main');
            $c->addScope($extra);
            $c->view(static function () use ($id): string {
                $v = $GLOBALS['v'];
                if ($id === '/r_/1' && !empty($GLOBALS['slow'])) {
                    Coroutine::usleep(30_000);
                }

                return '<p>v=' . $v . '</p>';
            }, shareRender: true);
            $app->contexts[$id] = $c;
            $app->getApp()->registerContext($c);

            return $c;
        };
        $make('/r_/1', 'a');
        $x2 = $make('/r_/2', 'b');
        $make('/r_/3', 'b');
        $GLOBALS['slow'] = true;
        $done = new Coroutine\Channel(2);
        Coroutine::create(static function () use ($app, $done): void {
            $app->broadcast('a');
            $done->push(1);
        });
        Coroutine::create(static function () use ($app, $done): void {
            Coroutine::usleep(5_000);
            $GLOBALS['v'] = 2;
            $app->broadcast('b');
            $done->push(1);
        });
        $done->pop();
        $done->pop();
        $GLOBALS['slow'] = false;
        drainElements($x2);
        // An action's sync() before the next broadcast reads the cache.
        $x2->sync();
        echo 'sync_fresh=', (int) str_contains(implode('', drainElements($x2)), 'v=2'), "\n";

        return;
    }

    // revival
    $app = new Via((new Config())->withLogLevel('error'));
    $GLOBALS['cleaned'] = 0;
    $GLOBALS['ticks'] = 0;
    $app->page('/p', static function (Context $c): void {
        if (!empty($GLOBALS['yield'])) {
            Coroutine::usleep(10_000);
        }
        $c->onDisconnect(static function (): void { ++$GLOBALS['cleaned']; });
        $c->setInterval(static function (): void { ++$GLOBALS['ticks']; }, 5);
        $c->view(static fn (): string => '<div id="p">x</div>');
    });
    $handler = $app->getRouter()->getRoutes()['/p'];
    $id = '/p_/abc';
    $ctx = new Context($id, '/p', $app, null, 'sess');
    $app->contexts[$id] = $ctx;
    $app->contextSessions[$id] = 'sess';
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($handler, $ctx, []);
    $app->getApp()->destroyContext($id);
    unset($app->contexts[$id]);

    $GLOBALS['yield'] = true;
    $GLOBALS['cleaned'] = 0;
    $got = [];
    $done = new Coroutine\Channel(2);
    for ($i = 0; $i < 2; ++$i) {
        Coroutine::create(static function () use ($app, $id, $done, &$got, $i): void {
            $got[$i] = $app->reviveContextFromClient($id, 'sess', [], [], byConnect: $i === 1);
            $done->push(1);
        });
    }
    $done->pop();
    $done->pop();
    echo 'same_context=', (int) ($got[0] !== null && $got[0] === $got[1]), "\n";

    $app->getApp()->destroyContext($id);
    $GLOBALS['ticks'] = 0;
    Coroutine::usleep(30_000);
    echo 'ticks_after_destroy=', $GLOBALS['ticks'], "\n";
    Timer::clearAll();
});
