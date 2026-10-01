<?php

declare(strict_types=1);

/*
 * Fixture for ComponentSyncTest: two syncs of one page overlap while its view waits on I/O,
 * as two broadcast fan-outs reaching the same tab do.
 *
 * Prints "renders=N" (component view renders) and "component_frames=N" (#c- frames queued).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

$page = new Context('page-overlap', '/test', new Via((new Config())->withLogLevel('error')));
$renders = 0;
$counter = $page->component(function (Context $c) use (&$renders): void {
    $count = $c->signal(0, 'count');
    $c->view(function () use ($count, &$renders): string {
        ++$renders;

        return '<span>' . $count->int() . '</span>';
    });
}, 'counter');
$page->view(function () use ($counter): string {
    $html = $counter();
    Coroutine::usleep(2000); // the rest of the page waits on a query

    return '<main id="page">' . $html . '</main>';
});

$frames = 0;
Coroutine::run(static function () use ($page, &$frames): void {
    $done = new Channel(2);
    for ($i = 0; $i < 2; ++$i) {
        Coroutine::create(static function () use ($page, $done): void {
            $page->sync();
            $done->push(true);
        });
    }
    $done->pop();
    $done->pop();

    while (($patch = $page->getPatch()) !== null) {
        if (str_starts_with($patch['selector'] ?? '', '#c-')) {
            ++$frames;
        }
    }
});

echo "renders={$renders}\ncomponent_frames={$frames}\n";
