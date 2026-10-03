<?php

declare(strict_types=1);

/*
 * Fixture for ClientSnapshotPassTest: runs one scenario of getClients() inside a broadcast flush
 * in Coroutine::run and prints what it observed as one JSON line.
 *
 * The flush only runs inside a coroutine, and Coroutine::run cannot run in the Pest process (see
 * broadcast_coalescing_cases.php). A clone of the registry stands in for another worker.
 *
 * argv[1] = case name. VIA_TEST_MODE stays on, so patch queues are plain arrays.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

final class PresenceWorld {
    /** @var list<string> "context id=count" per render, in render order */
    public array $renders = [];

    /** @var array<string, mixed> */
    public array $seen = [];

    /** @var null|Closure(): mixed runs once, inside the next view that renders, after its read */
    public ?Closure $hook = null;
}

/**
 * @return array{0: Via, 1: SharedClientRegistry} a worker with two local clients, and another worker's registry
 */
function presenceApp(): array {
    $app = new Via((new Config())->withLogLevel('error'));
    $registry = new SharedClientRegistry(64);
    $app->getApp()->setClientRegistry($registry);
    foreach (['local-1', 'local-2'] as $id) {
        $app->getApp()->registerClient($id, ['id' => "client-{$id}", 'identicon' => '', 'connected_at' => 1000, 'ip' => '127.0.0.1']);
    }

    $remote = clone $registry;
    $remote->claimWorker(1);

    return [$app, $remote];
}

function presenceTab(Via $app, string $id, string $scope, PresenceWorld $world): void {
    $ctx = new Context($id, '/presence', $app);
    $ctx->scope($scope);
    $app->contexts[$id] = $ctx;
    $ctx->view(static function () use ($app, $id, $world): string {
        $n = count($app->getClients());
        $world->renders[] = "{$id}={$n}";
        if ($world->hook !== null) {
            $hook = $world->hook;
            $world->hook = null;
            $hook();
        }

        return "<div id=\"{$id}\">{$n}</div>";
    });
}

/** Run $fn in Coroutine::run, which returns once deferred flushes and timers are done. */
function inCoroutine(callable $fn): void {
    $error = null;

    Coroutine::run(static function () use ($fn, &$error): void {
        try {
            $fn();
        } catch (Throwable $e) {
            $error = $e;
        }
    });

    if ($error !== null) {
        throw $error;
    }
}

$cases = [
    // One flush of two scopes reads the list once; a client joining another worker meanwhile
    // shows up on the next flush.
    'flush-reads-once' => static function (): array {
        [$app, $remote] = presenceApp();
        $world = new PresenceWorld();
        foreach (['a1' => 'room:a', 'a2' => 'room:a', 'b1' => 'room:b', 'b2' => 'room:b'] as $id => $scope) {
            presenceTab($app, $id, $scope, $world);
        }
        $world->hook = static fn () => $remote->register('remote-1', 'client-remote-1', '10.0.0.1', 1000);

        inCoroutine(static function () use ($app, $world): void {
            $app->broadcast('room:a');
            $app->broadcast('room:b');
            $app->flushBroadcasts();
            $world->seen['first'] = $world->renders;
            $world->renders = [];

            $app->broadcast('room:a');
            $app->broadcast('room:b');
        });

        return [...$world->seen, 'second' => $world->renders, 'flushes' => $app->getStats()->getBroadcastStats()['flushes']];
    },

    // A view waits on I/O while a client joins another worker: the rest of the flush keeps its list.
    'waiting-view' => static function (): array {
        [$app, $remote] = presenceApp();
        $world = new PresenceWorld();
        foreach (['r1', 'r2', 'r3'] as $id) {
            presenceTab($app, $id, 'room:a', $world);
        }
        $world->hook = static fn () => Coroutine::usleep(20_000);

        inCoroutine(static function () use ($app, $remote): void {
            $app->broadcast('room:a');
            Coroutine::create(static function () use ($remote): void {
                Coroutine::usleep(5_000);
                $remote->register('remote-1', 'client-remote-1', '10.0.0.1', 1000);
            });
        });

        return ['renders' => $world->renders, 'after' => count($app->getClients())];
    },

    // The same, but a coroutine outside the flush reads the list while the view waits: it gets
    // the current list, and the flush reads again after it.
    'interleaved-reader' => static function (): array {
        [$app, $remote] = presenceApp();
        $world = new PresenceWorld();
        foreach (['r1', 'r2', 'r3'] as $id) {
            presenceTab($app, $id, 'room:a', $world);
        }
        $world->hook = static fn () => Coroutine::usleep(20_000);

        inCoroutine(static function () use ($app, $remote, $world): void {
            $app->broadcast('room:a');
            Coroutine::create(static function () use ($app, $remote, $world): void {
                Coroutine::usleep(5_000);
                $remote->register('remote-1', 'client-remote-1', '10.0.0.1', 1000);
                $world->seen['reader'] = count($app->getClients());
            });
        });

        return [...$world->seen, 'renders' => $world->renders];
    },
];

$case = (string) ($argv[1] ?? '');

try {
    $result = isset($cases[$case]) ? $cases[$case]() : ['error' => "unknown case \"{$case}\""];
} catch (Throwable $e) {
    $result = ['error' => Logger::describe($e)];
}

echo json_encode($result), "\n";
