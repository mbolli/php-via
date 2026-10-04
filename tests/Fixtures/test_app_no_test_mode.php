<?php

declare(strict_types=1);

/*
 * Fixture for TestAppTest: Testing\TestApp in a process without VIA_TEST_MODE, as an app's own
 * test suite runs it.
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE');
unset($_ENV['VIA_TEST_MODE'], $_SERVER['VIA_TEST_MODE']);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;

$stopped = 0;
$app = new TestApp((new Config())->withLogLevel('error'), static function (Via $via) use (&$stopped): void {
    $via->page('/counter', static function (Context $c): void {
        $count = $c->signal(0, 'count');
        $c->action(static function () use ($c, $count): void {
            $count->setValue($count->int() + 1);
            $c->sync();
        }, 'increment');
        $c->view(static fn (): string => '<div id="counter">' . $count->int() . '</div>');
    });
    $via->onWorkerStop(static function () use (&$stopped): void {
        ++$stopped;
    });
});

$tab = $app->open('/counter');
$tab->patches();
$tab->action('increment')->action('increment');

echo 'test_mode=', getenv('VIA_TEST_MODE') === '1' ? 1 : 0, "\n";
echo 'count=', $tab->signal('count'), "\n";
echo 'connected=', (int) $tab->context()->isConnected(), "\n";
echo 'patches=', implode(',', array_column($tab->patches(), 'type')), "\n";

$app->shutdown();
echo 'stopped=', $stopped, "\n";
