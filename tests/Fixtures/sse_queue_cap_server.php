<?php

declare(strict_types=1);

/*
 * Fixture for SseQueueCapTest: starts a server and stops it at once, so the test reads what start() logged.
 *
 * argv[1] = withSseMaxQueuedBytes() bytes, or 'default'; argv[2] = socket_buffer_size, or 'default'
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$config = (new Config())->withHost('127.0.0.1')->withPort(FixturePort::pick(4760, 40))->withLogLevel('warn');
if (($argv[1] ?? 'default') !== 'default') {
    $config->withSseMaxQueuedBytes((int) $argv[1]);
}
if (($argv[2] ?? 'default') !== 'default') {
    $config->withSwooleSettings(['socket_buffer_size' => (int) $argv[2]]);
}
$app = new Via($config);

$app->setInterval(static function () use ($app): void {
    Timer::clearAll();
    echo "started\n";
    $app->getServer()?->shutdown();
}, 50);

$app->start();
