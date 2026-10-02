<?php

declare(strict_types=1);

/*
 * Fixture for SseReconnectTest: real SSE loops over real Channels and fake responses, no server.
 *
 * argv[1] picks the case:
 *   superseded  a second stream for a context arrives while the first is still parked
 *   reset       the client resets an idle HTTP/2 stream, then the worker's reset check runs
 *   returned    a patch is queued to a stream the client reset, then the tab reconnects
 *   gate        keep-alive 1000 ms: a wake 100 ms after the sync, then a quiet second
 *   off         keep-alive 0: a wake after the sync
 *   quiet       a page that has nothing to send on connect
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE');
unset($_ENV['VIA_TEST_MODE'], $_SERVER['VIA_TEST_MODE']);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;
use Tests\Support\FakeActionRequest;

/** A client that keeps reading until it resets the stream, as an HTTP/2 RST_STREAM does. */
final class StreamResponse extends Response {
    public string $body = '';
    public bool $gone = false;
    public int $writesAfterReset = 0;

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        return true;
    }

    public function write(string $data): bool {
        if ($this->gone) {
            ++$this->writesAfterReset;

            return false;
        }
        $this->body .= $data;

        return true;
    }

    public function isWritable(): bool {
        return !$this->gone;
    }

    public function end(mixed $data = null): bool {
        $this->gone = true;

        return true;
    }
}

final class Streams {
    /** @var list<string> */
    public array $events = [];

    /** @var array<int, bool> running flag by fd */
    public array $running = [];

    public function __construct(public Via $app, public SseHandler $handler) {
        $app->onClientConnect(fn (Context $c) => $this->events[] = 'connect');
        $app->onClientDisconnect(fn (Context $c) => $this->events[] = 'disconnect');
    }

    public function context(string $id): Context {
        $context = new Context($id, '/p', $this->app);
        $context->view(static fn (): string => '<div id="v">' . $id . ':N' . ($GLOBALS['bumps'] ?? 0) . '</div>');
        $this->app->contexts[$id] = $context;
        $this->app->getApp()->registerContext($context);

        return $context;
    }

    public function open(string $contextId, int $fd): StreamResponse {
        $request = new FakeActionRequest('unused', ['via_ctx' => $contextId]);
        $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET', 'remote_addr' => '127.0.0.1'];
        $response = new StreamResponse();
        $response->fd = $fd;
        $this->running[$fd] = true;
        Coroutine::create(function () use ($request, $response, $fd): void {
            $this->handler->handleSSE($request, $response);
            $this->running[$fd] = false;
        });
        Coroutine::usleep(30_000);

        return $response;
    }

    /** Milliseconds until the stream on $fd ended, or -1 if it still runs after 200 ms. */
    public function endsWithin(int $fd): int {
        $start = microtime(true);
        for ($i = 0; $i < 200 && $this->running[$fd]; ++$i) {
            Coroutine::usleep(1000);
        }

        return $this->running[$fd] ? -1 : (int) round((microtime(true) - $start) * 1000);
    }
}

$case = (string) ($argv[1] ?? 'superseded');

Coroutine::run(static function () use ($case): void {
    $keepAliveMs = match ($case) {
        'gate' => 1000,
        'off' => 0,
        default => 15_000,
    };
    $app = new Via((new Config())->withLogLevel('error')->withSseKeepAliveMs($keepAliveMs));
    $s = new Streams($app, new SseHandler($app));

    try {
        if ($case === 'superseded') {
            $context = $s->context('room');
            $context->scope('room:lobby');
            $s->open('room', 31);
            $second = $s->open('room', 32);

            echo 'first_running=', (int) $s->running[31], "\n";
            echo 'scope_members=', count($app->getContextsByScope('room:lobby')), "\n";
            echo 'clients=', count($app->getClients()), "\n";

            $GLOBALS['bumps'] = 1;
            $app->broadcast('room:lobby');
            Coroutine::usleep(50_000);
            echo 'broadcast_delivered=', (int) str_contains($second->body, 'room:N1'), "\n";

            $s->handler->onConnectionClose(32);
            $s->endsWithin(32);
            echo 'clients_after_close=', count($app->getClients()), "\n";
            echo 'events=', implode(',', $s->events), "\n";
        } elseif ($case === 'reset') {
            $s->context('tab');
            $response = $s->open('tab', 41);
            $response->gone = true;
            Coroutine::usleep(50_000);
            echo 'running_before_check=', (int) $s->running[41], "\n";

            $s->handler->endResetStreams();
            echo 'ended_ms=', $s->endsWithin(41), "\n";
            echo 'writes_after_reset=', $response->writesAfterReset, "\n";
            echo 'events=', implode(',', $s->events), "\n";
        } elseif ($case === 'returned') {
            $context = $s->context('tab');
            $first = $s->open('tab', 51);
            $first->gone = true;
            $context->execScript('console.log("POKE")');
            echo 'ended_ms=', $s->endsWithin(51), "\n";
            echo 'writes_after_reset=', $first->writesAfterReset, "\n";

            $second = $s->open('tab', 52);
            echo 'reconnected_got_patch=', (int) str_contains($second->body, 'POKE'), "\n";
        } elseif ($case === 'quiet') {
            $context = $s->context('static');
            $context->view(static fn (bool $isUpdate): string => $isUpdate ? '' : '<main>page</main>', cacheUpdates: false);
            $response = $s->open('static', 71);
            echo 'connect_event=', (int) str_contains($response->body, '"_disconnected":false'), "\n";
            echo 'resent_page=', (int) str_contains($response->body, '<main>'), "\n";
        } else {
            $context = $s->context('tab');
            $response = $s->open('tab', 61);
            Coroutine::usleep(70_000);
            $context->getPatchManager()->wakeConsumers();
            Coroutine::usleep(200_000);
            echo 'woken_running=', (int) $s->running[61], "\n";
            echo 'keepalives_after_wake=', substr_count($response->body, ': keep-alive'), "\n";

            if ($case === 'gate') {
                Coroutine::usleep(1_000_000);
                echo 'keepalives_after_interval=', substr_count($response->body, ': keep-alive'), "\n";
            }
        }
    } finally {
        foreach ($app->contexts as $context) {
            $context->getPatchManager()->closePatchChannel();
        }
        Timer::clearAll();
    }
});
