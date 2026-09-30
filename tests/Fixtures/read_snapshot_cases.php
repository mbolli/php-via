<?php

declare(strict_types=1);

/*
 * Fixture for ReadSnapshotTest: runs one scenario of the per-flush read snapshot of scoped signals
 * inside Coroutine::run and prints what it observed as one JSON line.
 *
 * The flush only runs inside a coroutine, and Coroutine::run cannot run in the Pest process (see
 * broadcast_coalescing_cases.php). One Via with a SharedSignalStore stands in for a worker; a direct
 * $store->set() stands in for another worker's write, and handlePipeMessage() for its broadcast.
 *
 * argv[1] = case name. VIA_TEST_MODE stays on, so patch queues are plain arrays.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\ReadEpochs;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\State\TicketLock;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Table;

/** Counts row reads; installed before any signal is attached, so it holds every row. */
final class ReadCountingTable extends Table {
    public int $reads = 0;

    /** @return array<string, mixed>|bool|float|int|string */
    public function get(string $key, ?string $column = null): array|bool|float|int|string {
        ++$this->reads;

        return $column === null ? parent::get($key) : parent::get($key, $column);
    }
}

final class SnapshotWorld {
    /** @var array<string, int> */
    public array $renders = [];

    /** @var array<string, mixed> */
    public array $seen = [];

    /** @var null|Closure(string): mixed runs once, inside the next view that renders, after its reads */
    public ?Closure $hook = null;

    /** @var array<string, Gate> context id => gate its next render waits at, before its reads */
    public array $gates = [];

    /** @var array<string, Gate> the same, after its reads */
    public array $gatesAfterReads = [];
}

/** A point a view waits at until the scenario opens it. */
final class Gate {
    public bool $waiting = false;
    private Channel $channel;

    public function __construct() {
        $this->channel = new Channel(1);
    }

    public function pass(): void {
        $this->waiting = true;
        $this->channel->pop(2.0);
        $this->waiting = false;
    }

    public function open(): void {
        $this->channel->push(true);
    }
}

/** Yield until $condition holds, or fail after a second. */
function waitFor(Closure $condition, string $what): void {
    $deadline = microtime(true) + 1.0;
    while (!$condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("timed out waiting for {$what}");
        }
        Coroutine::usleep(1000);
    }
}

function pipeBroadcast(Via $app, string $scope): void {
    (new ReflectionMethod($app, 'handlePipeMessage'))->invoke($app, 1, json_encode(['scope' => $scope, 'nodeId' => 'worker-1']));
}

function snapshotApp(?SharedSignalStore $store, ?Config $config = null): Via {
    $app = new Via(($config ?? new Config())->withLogLevel('error'));
    $app->setSharedSignalStore($store);

    return $app;
}

/**
 * A store whose row reads are counted, and the counter.
 *
 * @return array{0: SharedSignalStore, 1: ReadCountingTable}
 */
function countingStore(): array {
    $store = new SharedSignalStore(maxRows: 64);
    $table = new ReadCountingTable(64);
    $table->column('kind', Table::TYPE_INT, 1);
    $table->column('n', Table::TYPE_INT, 8);
    $table->column('s', Table::TYPE_STRING, 4096);
    $table->column('next', Table::TYPE_INT, 8);
    $table->column('serving', Table::TYPE_INT, 8);
    $table->column('lease', Table::TYPE_INT, 8);
    $table->create();
    (new ReflectionProperty(SharedSignalStore::class, 'table'))->setValue($store, $table);
    (new ReflectionProperty(SharedSignalStore::class, 'lock'))->setValue($store, new TicketLock($table, static fn (string $key): string => "lock timeout on {$key}"));

    return [$store, $table];
}

/**
 * A context in $scopes whose view renders the list and the number of the shared room:data signals.
 *
 * @param list<string> $scopes
 */
function reader(Via $app, string $id, array $scopes, SnapshotWorld $world): Context {
    $ctx = new Context($id, '/r', $app);
    $ctx->scope(array_shift($scopes));
    foreach ($scopes as $scope) {
        $ctx->addScope($scope);
    }
    $app->contexts[$id] = $ctx;
    $list = $ctx->signal(['a'], 'list', 'room:data');
    $n = $ctx->signal(1, 'n', 'room:data');
    $ctx->view(static function () use ($id, $list, $n, $world): string {
        $world->renders[$id] = ($world->renders[$id] ?? 0) + 1;
        if (isset($world->gates[$id])) {
            $gate = $world->gates[$id];
            unset($world->gates[$id]);
            $gate->pass();
        }
        $html = "<div id=\"{$id}\">" . implode(',', $list->array()) . ' n=' . $n->int() . '</div>';
        if (isset($world->gatesAfterReads[$id])) {
            $gate = $world->gatesAfterReads[$id];
            unset($world->gatesAfterReads[$id]);
            $gate->pass();
        }
        if ($world->hook !== null) {
            $hook = $world->hook;
            $world->hook = null;
            $hook($id);
        }

        return $html;
    }, cacheUpdates: false);

    return $ctx;
}

function shared(Via $app, string $name): Signal {
    $signal = $app->getScopedSignal('room:data', 'room_data_' . $name);
    if ($signal === null) {
        throw new RuntimeException("no scoped signal {$name}");
    }

    return $signal;
}

/** @return list<string> the content of each elements patch */
function frames(Context $ctx): array {
    $frames = [];
    while (($patch = $ctx->getPatch()) !== null) {
        if ($patch['type'] === 'elements') {
            $frames[] = (string) $patch['content'];
        }
    }

    return $frames;
}

/**
 * @param array<string, Context> $contexts
 *
 * @return array<string, null|string>
 */
function lastFrames(array $contexts): array {
    return array_map(static function (Context $ctx): ?string {
        $frames = frames($ctx);

        return $frames === [] ? null : end($frames);
    }, $contexts);
}

/** @return array<int, int> coroutine id => epoch still open */
function openEpochs(Via $app): array {
    $epochs = (new ReflectionProperty(Via::class, 'readEpochs'))->getValue($app);

    return (new ReflectionProperty(ReadEpochs::class, 'open'))->getValue($epochs);
}

/** Run $fn in Coroutine::run, which returns once deferred flushes and timers are done. */
function inCoroutine(callable $fn): mixed {
    $result = null;
    $error = null;

    Coroutine::run(static function () use ($fn, &$result, &$error): void {
        try {
            $result = $fn();
        } catch (Throwable $e) {
            $error = $e;
        }
    });

    if ($error !== null) {
        throw $error;
    }

    return $result;
}

$cases = [
    // One flush with two dirty scopes reads each scoped signal once for all of them.
    'flush-shares-reads' => static function (): array {
        [$store, $table] = countingStore();
        $app = snapshotApp($store);
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['a1' => ['room:a'], 'a2' => ['room:a'], 'b1' => ['room:b'], 'b2' => ['room:b'], 'ab' => ['room:a', 'room:b']] as $id => $scopes) {
            $contexts[$id] = reader($app, $id, $scopes, $world);
            frames($contexts[$id]);
        }
        $store->set(shared($app, 'list')->sharedKey(), ['x', 'y']);
        $store->set(shared($app, 'n')->sharedKey(), 7);
        $table->reads = 0;

        inCoroutine(static function () use ($app): void {
            $app->broadcast('room:a');
            $app->broadcast('room:b');
        });

        return [
            'reads' => $table->reads,
            'renders' => $world->renders,
            'flushes' => $app->getStats()->getBroadcastStats()['flushes'],
            'last' => lastFrames($contexts),
            'open' => openEpochs($app),
        ];
    },

    // The synchronous path (coalescing off) opens a snapshot per fan-out.
    'sync-reads' => static function (): array {
        [$store, $table] = countingStore();
        $app = snapshotApp($store, (new Config())->withBroadcastCoalescing(false));
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['a1', 'a2', 'a3'] as $id) {
            $contexts[$id] = reader($app, $id, ['room:a'], $world);
            frames($contexts[$id]);
        }
        $table->reads = 0;

        $readsPerBroadcast = inCoroutine(static function () use ($app, $table): array {
            $reads = [];
            for ($i = 0; $i < 2; ++$i) {
                $before = $table->reads;
                $app->broadcast('room:a');
                $reads[] = $table->reads - $before;
            }

            return $reads;
        });

        return ['reads' => $readsPerBroadcast, 'renders' => $world->renders, 'open' => openEpochs($app)];
    },

    // A coroutine that runs while a flush waits on I/O in a view reads shared memory, and the
    // flush reads again after it.
    'interleaved-reader' => static function (): array {
        $store = new SharedSignalStore(maxRows: 64);
        $app = snapshotApp($store);
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['r1', 'r2', 'r3'] as $id) {
            $contexts[$id] = reader($app, $id, ['room:a'], $world);
            frames($contexts[$id]);
        }
        $n = shared($app, 'n');
        $world->hook = static function () use ($store, $world): void {
            $world->seen['flushEpoch'] = $store->readEpoch();
            Coroutine::usleep(20_000);
        };

        inCoroutine(static function () use ($app, $store, $n, $world): void {
            $app->broadcast('room:a');
            Coroutine::create(static function () use ($store, $n, $world): void {
                // Runs while r1's view waits: another worker writes, and this coroutine reads.
                Coroutine::usleep(5_000);
                $store->set($n->sharedKey(), 42);
                $world->seen['readerEpoch'] = $store->readEpoch();
                $world->seen['readerValue'] = $n->int();
            });
        });

        return [...$world->seen, 'renders' => $world->renders, 'last' => lastFrames($contexts), 'open' => openEpochs($app)];
    },

    // A local write while the flush waits on I/O reaches every context by the next flush.
    'interleaved-local-write' => static function (): array {
        $store = new SharedSignalStore(maxRows: 64);
        $app = snapshotApp($store);
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['r1', 'r2', 'r3'] as $id) {
            $contexts[$id] = reader($app, $id, ['room:data'], $world);
            frames($contexts[$id]);
        }
        $list = shared($app, 'list');
        $world->hook = static fn () => Coroutine::usleep(20_000);

        inCoroutine(static function () use ($app, $list): void {
            $app->broadcast('room:data');
            Coroutine::create(static function () use ($list): void {
                Coroutine::usleep(5_000);
                $list->setValue(['local']); // auto-broadcasts room:data
            });
        });

        return ['renders' => $world->renders, 'last' => lastFrames($contexts), 'open' => openEpochs($app)];
    },

    // Another worker writes and broadcasts a scope of this flush that it has not reached yet:
    // the flush reads again for that scope.
    'foreign-write-mid-flush' => static function (): array {
        $store = new SharedSignalStore(maxRows: 64);
        $app = snapshotApp($store);
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['a1' => ['room:a'], 'b1' => ['room:b'], 'b2' => ['room:b']] as $id => $scopes) {
            $contexts[$id] = reader($app, $id, $scopes, $world);
            frames($contexts[$id]);
        }
        $n = shared($app, 'n');
        $gate = new Gate();
        $world->gatesAfterReads['a1'] = $gate;

        inCoroutine(static function () use ($app, $store, $n, $gate): void {
            $app->broadcast('room:a');
            $app->broadcast('room:b');
            waitFor(static fn (): bool => $gate->waiting, 'the flush to wait in a1');
            $store->set($n->sharedKey(), 99);
            pipeBroadcast($app, 'room:b');
            $gate->open();
        });

        return ['renders' => $world->renders, 'frames' => array_map(frames(...), $contexts), 'open' => openEpochs($app)];
    },

    // A flush of room:a renders c1 from an older read, and while c1's view waits after its reads, a
    // write and its room:data broadcast start a second flush that renders c1 first. argv[2]:
    // "snapshot": another worker writes after the flush read n at c0 and before c1 reads it.
    // "local": one worker without a store writes after c1 read n.
    // argv[3] = "next" adds c2, which both flushes render after c1.
    'overtaken-frame' => static function (string $variant = 'snapshot', string $next = ''): array {
        $store = $variant === 'snapshot' ? new SharedSignalStore(maxRows: 64) : null;
        $app = snapshotApp($store, (new Config())->withBroadcastTickMs(0));
        $world = new SnapshotWorld();
        $contexts = [];
        foreach ($next === 'next' ? ['c0', 'c1', 'c2'] : ['c0', 'c1'] as $id) {
            $contexts[$id] = reader($app, $id, ['room:a', 'room:data'], $world);
            frames($contexts[$id]);
        }
        $n = shared($app, 'n');
        $beforeReads = new Gate();
        $afterReads = new Gate();
        if ($store !== null) {
            $world->gates['c1'] = $beforeReads;
        }
        $world->gatesAfterReads['c1'] = $afterReads;

        inCoroutine(static function () use ($app, $store, $n, $world, $beforeReads, $afterReads): void {
            $app->broadcast('room:a');

            if ($store !== null) {
                waitFor(static fn (): bool => $beforeReads->waiting, 'the room:a flush to wait before c1 reads');
                $store->set($n->sharedKey(), 2);
                $beforeReads->open();
                waitFor(static fn (): bool => $afterReads->waiting, 'the room:a flush to wait after c1 read');
                pipeBroadcast($app, 'room:data');
            } else {
                waitFor(static fn (): bool => $afterReads->waiting, 'the room:a flush to wait after c1 read');
                $n->setValue(2);
            }
            waitFor(static fn (): bool => ($world->renders['c1'] ?? 0) >= 2, 'the room:data flush to render c1');
            Coroutine::usleep(2000);

            $afterReads->open();
        });

        return ['renders' => $world->renders, 'frames' => array_map(frames(...), $contexts), 'open' => openEpochs($app)];
    },

    // Flush G renders x1 in room:a, then waits in a2. Another worker writes and broadcasts room:c and
    // room:b, and flush F takes both and waits in c1, whose view reads nothing. G then renders room:b,
    // and F skips it because G is running it. G must neither reuse its older read nor skip x1, which
    // it rendered before the write.
    'mark-taken-by-another-flush' => static function (): array {
        $store = new SharedSignalStore(maxRows: 64);
        $app = snapshotApp($store, (new Config())->withBroadcastTickMs(0));
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['x1' => ['room:a', 'room:b'], 'a2' => ['room:a'], 'b1' => ['room:b']] as $id => $scopes) {
            $contexts[$id] = reader($app, $id, $scopes, $world);
            frames($contexts[$id]);
        }
        $gates = ['a2' => new Gate(), 'b1' => new Gate(), 'c1' => new Gate()];
        $world->gates = ['a2' => $gates['a2'], 'b1' => $gates['b1']];
        $c1 = new Context('c1', '/r', $app);
        $c1->scope('room:c');
        $app->contexts['c1'] = $c1;
        $c1->view(static function () use ($gates): string {
            $gates['c1']->pass();

            return '<div id="c1"></div>';
        }, cacheUpdates: false);
        $n = shared($app, 'n');

        inCoroutine(static function () use ($app, $store, $n, $gates): void {
            $app->broadcast('room:a');
            $app->broadcast('room:b');
            waitFor(static fn (): bool => $gates['a2']->waiting, 'flush G to wait in a2');

            $store->set($n->sharedKey(), 99);
            pipeBroadcast($app, 'room:c');
            pipeBroadcast($app, 'room:b');
            waitFor(static fn (): bool => $gates['c1']->waiting, 'flush F to wait in c1');

            $gates['a2']->open();
            waitFor(static fn (): bool => $gates['b1']->waiting, 'flush G to wait in b1');

            $gates['c1']->open();
            Coroutine::usleep(2000);
            $gates['b1']->open();
        });

        return ['renders' => $world->renders, 'frames' => array_map(frames(...), $contexts), 'open' => openEpochs($app)];
    },

    // A flush of room:a, room:b and room:c renders x in room:a, then another worker writes and
    // broadcasts room:c and room:b. room:b's pass reads again under a new epoch, and room:c's pass,
    // which comes after it, must still render x again: x was rendered before room:c's mark.
    'mark-after-renewal' => static function (): array {
        $store = new SharedSignalStore(maxRows: 64);
        $app = snapshotApp($store, (new Config())->withBroadcastTickMs(0));
        $world = new SnapshotWorld();
        $contexts = [];
        foreach (['x' => ['room:a', 'room:c'], 'b1' => ['room:b'], 'c1' => ['room:c']] as $id => $scopes) {
            $contexts[$id] = reader($app, $id, $scopes, $world);
            frames($contexts[$id]);
        }
        $n = shared($app, 'n');
        $gate = new Gate();
        $world->gatesAfterReads['x'] = $gate;

        inCoroutine(static function () use ($app, $store, $n, $gate): void {
            $app->broadcast('room:a');
            $app->broadcast('room:b');
            $app->broadcast('room:c');
            waitFor(static fn (): bool => $gate->waiting, 'the flush to wait in x');
            $store->set($n->sharedKey(), 99);
            pipeBroadcast($app, 'room:c');
            pipeBroadcast($app, 'room:b');
            $gate->open();
        });

        return ['renders' => $world->renders, 'frames' => array_map(frames(...), $contexts), 'open' => openEpochs($app)];
    },
];

$case = (string) ($argv[1] ?? '');

try {
    $result = isset($cases[$case]) ? $cases[$case](...array_slice($argv, 2)) : ['error' => "unknown case \"{$case}\""];
} catch (Throwable $e) {
    $result = ['error' => Logger::describe($e)];
}

echo json_encode($result), "\n";
