<?php

declare(strict_types=1);

/*
 * Fixture for SpawnTest: a real one-worker server whose page spawns two tasks, then SIGTERMs its own master.
 *
 * Appends to the marker file:
 * - "page <status>" once the page that spawns the tasks answered
 * - "task-quick" when the task that ends 200 ms after the stop began is done
 * - "stop-callback" when onWorkerStop runs
 * - "task-told" when the task that waits for onWorkerStop to tell it to stop is done
 * - "error <phase> <ctx|null> <message>" per onError report
 *
 * argv[1] = marker file path
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The real SSE loop needs the Channel-backed PatchManager, not the test-mode array.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use Tests\Support\FixturePort;

$marker = (string) ($argv[1] ?? sys_get_temp_dir() . '/via_spawn_marker');
$write = static function (string $line) use ($marker): void {
    file_put_contents($marker, $line . "\n", FILE_APPEND | LOCK_EX);
};

$config = (new Config())->withHost('127.0.0.1')->withPort(FixturePort::pick(5100, 150))->withLogLevel('warn');
$port = $config->freeze()->port;
$app = new Via($config);

$app->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase) use ($write): void {
    $write('error ' . $phase->value . ' ' . ($c === null ? 'null' : 'ctx') . ' ' . $e->getMessage());
});

$told = false;

$app->page('/spawn', static function (Context $c) use ($app, $write, &$told): void {
    $c->spawn(static function () use ($app, $write): void {
        while (!$app->isShuttingDown()) {
            Coroutine::usleep(10_000);
        }
        Coroutine::usleep(200_000);
        $write('task-quick');
    });
    $c->spawn(static function () use ($write, &$told): void {
        while (!$told) {
            Coroutine::usleep(10_000);
        }
        $write('task-told');
    });
    $c->view(static fn (): string => '<div id="p">spawned</div>');
});

$app->onWorkerStop(static function () use ($write, &$told): void {
    $write('stop-callback');
    $told = true;
});

$app->setInterval(static function (): void {
    static $thrown = false;
    if (!$thrown) {
        $thrown = true;

        throw new RuntimeException('server interval failed');
    }
}, 50);

$app->setInterval(static function () use ($app, $port, $write): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port, $write): void {
        $client = new Client('127.0.0.1', $port);
        $client->set(['timeout' => 5]);
        $client->get('/spawn');
        $write('page ' . $client->statusCode);
        $client->close();

        posix_kill((int) $app->getServer()?->master_pid, SIGTERM);
    });
}, 300);

$app->start();
