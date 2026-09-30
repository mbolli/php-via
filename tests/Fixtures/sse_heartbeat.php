<?php

declare(strict_types=1);

/*
 * Fixture for SseHeartbeatTest: real SSE loops over real Channels, no server.
 *
 * Four streams share one context directory: "busy" receives a patch every 5 ms and never parks
 * for the keep-alive interval, "idle" parks, "closed" loses its connection, and "orphan" keeps
 * running after its context left Via::$contexts. Every record is then expired, the idle one is
 * deleted, and the worker's heartbeat is run once, as its timer would.
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE');
unset($_ENV['VIA_TEST_MODE'], $_SERVER['VIA_TEST_MODE']);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;
use Tests\Support\FakeActionRequest;

/** Accepts every write, like a client that keeps reading. */
final class SinkResponse extends Response {
    public string $body = '';
    public bool $ended = false;

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        return true;
    }

    public function write(string $data): bool {
        $this->body .= $data;

        return !$this->ended;
    }

    public function isWritable(): bool {
        return !$this->ended;
    }

    public function end(mixed $data = null): bool {
        $this->ended = true;

        return true;
    }
}

Coroutine::run(static function (): void {
    $app = new Via((new Config())->withLogLevel('error')->withSseKeepAliveMs(5000));
    $directory = new SharedContextDirectory(64);
    $app->getApp()->setContextDirectory($directory);
    $handler = new SseHandler($app);

    $disconnects = [];
    $app->onClientDisconnect(static function (Context $c) use (&$disconnects): void {
        $disconnects[] = $c->getId();
    });

    $contexts = [];
    $running = [];
    foreach (['busy' => 11, 'idle' => 12, 'closed' => 13, 'orphan' => 14] as $id => $fd) {
        $context = new Context($id, '/p', $app);
        $context->view(static fn (): string => '<div id="v">' . $id . '</div>');
        $app->contexts[$id] = $context;
        $app->getApp()->registerContext($context);
        $contexts[$id] = $context;

        $request = new FakeActionRequest('unused', ['via_ctx' => $id]);
        $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET', 'remote_addr' => '127.0.0.1'];
        $response = new SinkResponse();
        $response->fd = $fd;
        $running[$id] = true;
        Coroutine::create(static function () use ($handler, $request, $response, $id, &$running): void {
            $handler->handleSSE($request, $response);
            $running[$id] = false;
        });
    }

    $busy = true;
    Coroutine::create(static function () use ($contexts, &$busy): void {
        while ($busy) {
            $contexts['busy']->execScript('void 0');
            Coroutine::usleep(5000);
        }
    });

    Coroutine::usleep(50_000);

    $closedAt = microtime(true);
    $handler->onConnectionClose(13);
    for ($i = 0; $i < 100 && $running['closed']; ++$i) {
        Coroutine::usleep(1000);
    }
    echo 'closed_ended_ms=', $running['closed'] ? -1 : (int) round((microtime(true) - $closedAt) * 1000), "\n";
    echo 'others_running=', (int) ($running['busy'] && $running['idle'] && $running['orphan']), "\n";
    echo 'disconnects=', implode(',', $disconnects), "\n";

    unset($app->contexts['orphan']);

    // Expired, as another worker's revival window leaves it; "idle" has lost its row, as get() or prune() on another worker leaves it.
    foreach (array_keys($contexts) as $id) {
        $directory->put($id, ['route' => '/p', 'params' => [], 'sessionId' => null, 'expiresAt' => time() - 1]);
    }
    $directory->forget('idle');
    Coroutine::usleep(100_000);
    $handler->heartbeatStreams();

    foreach (array_keys($contexts) as $id) {
        echo "{$id}_record=", $directory->get($id) === null ? '0' : '1', "\n";
    }

    $busy = false;
    foreach ($contexts as $context) {
        $context->getPatchManager()->closePatchChannel();
    }
    Timer::clearAll();
});
