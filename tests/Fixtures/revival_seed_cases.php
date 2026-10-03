<?php

declare(strict_types=1);

/*
 * Fixture for ContextRevivalTest: a context an action revived without signals, seen by coalesced
 * broadcast flushes. Each case runs inside Coroutine::run, which cannot run in the Pest process
 * (see broadcast_coalescing_cases.php), and prints what it observed as one JSON line.
 *
 * argv[1] = case name. VIA_TEST_MODE stays on, so patch queues are plain arrays.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SessionTokens;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Http\Response;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/** The session cookie of the tab these cases revive. */
const REVIVE_COOKIE = 'a11ce000a11ce000a11ce000a11ce000';

/** The session that cookie names. */
function reviveOwner(): string {
    return SessionTokens::key(REVIVE_COOKIE);
}

final class SeedWorld {
    /** @var array<string, int> */
    public array $renders = [];

    /** @var array<string, SeedGate> context id => gate its next render waits at, after its reads */
    public array $gatesAfterReads = [];
}

/** A point a view waits at until the scenario opens it. */
final class SeedGate {
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

/** An SSE response that records what is written and stays open until hangUp(), yielding on every poll. */
final class SeedStream extends Response {
    public string $written = '';
    public bool $ended = false;
    private bool $open = true;

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        return true;
    }

    public function write(string $data): bool {
        $this->written .= $data;

        return true;
    }

    public function isWritable(): bool {
        Coroutine::usleep(1000);

        return $this->open;
    }

    public function end(mixed $data = null): bool {
        $this->ended = true;

        return true;
    }

    public function hangUp(): void {
        $this->open = false;
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

/** Record warnings and errors instead of echoing them: output buffers are per coroutine. */
function captureWarnings(Via $app): ArrayObject {
    $lines = new ArrayObject();
    $logger = new class($lines) extends Logger {
        public function __construct(private ArrayObject $lines) {
            parent::__construct('debug');
        }

        public function log(string $level, string $message, ?Context $context = null): void {
            if (in_array($level, ['warning', 'warn', 'error'], true)) {
                $this->lines[] = "[{$level}] {$message}";
            }
        }
    };
    (new ReflectionProperty(Via::class, 'logger'))->setValue($app, $logger);

    return $lines;
}

/** A page load of $route under $contextId, as RequestHandler::doHandlePage() registers it. */
function mintPage(Via $app, callable $handler, string $route, string $contextId): Context {
    $ctx = new Context($contextId, $route, $app, null, reviveOwner());
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, reviveOwner());
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($handler, $ctx, []);

    return $ctx;
}

/** Destroy a context past its cleanup delay, leaving the record a returning tab revives it from. */
function dropPage(Via $app, string $contextId): void {
    $app->getApp()->destroyContext($contextId);
    unset($app->contexts[$contextId]);
}

/**
 * POST an action of the owner's session through ActionHandler.
 *
 * @param array<string, mixed> $signals
 */
function postAction(Via $app, string $actionId, array $signals): int {
    $post = new FakeActionRequest($actionId, $signals);
    $post->cookie = ['via_session_id' => REVIVE_COOKIE];
    $response = new FakeStaticResponse();
    (new ActionHandler($app))->handleAction($post, $response, $actionId);

    return $response->statusCode;
}

/**
 * Open an SSE connect of the owner's session in a coroutine of its own; closeStream() ends it.
 *
 * @param array<string, mixed> $signals
 */
function openStream(Via $app, string $contextId, array $signals): SeedStream {
    $connect = new FakeActionRequest('unused', []);
    $connect->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
    $connect->get = ['datastar' => (string) json_encode(['via_ctx' => $contextId] + $signals)];
    $connect->cookie = ['via_session_id' => REVIVE_COOKIE];
    $stream = new SeedStream();

    Coroutine::create(static function () use ($app, $connect, $stream, $contextId): void {
        try {
            (new SseHandler($app))->handleSSE($connect, $stream);
        } finally {
            $app->getApp()->cancelContextCleanup($contextId);
            $stream->ended = true;
        }
    });

    return $stream;
}

function closeStream(SeedStream $stream): void {
    $stream->hangUp();
    waitFor(static fn (): bool => $stream->ended, 'the SSE loop to end');
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
 * The /counter page: a TAB count the tab holds, and a room:seed total that its bump action
 * raises, so the action's write reaches the scope through a coalesced flush.
 */
function counterPage(SeedWorld $world): Closure {
    return static function (Context $c) use ($world): void {
        $count = $c->signal(0, 'count');
        $total = $c->signal(0, 'total', 'room:seed');
        $c->addScope('room:seed');
        $c->action(static function () use ($total): void {
            $total->setValue($total->int() + 1);
        }, 'bump');
        $c->view(static function () use ($c, $count, $total, $world): string {
            $world->renders[$c->getId()] = ($world->renders[$c->getId()] ?? 0) + 1;

            return "<div id=\"page\">count={$count->int()} total={$total->int()}</div>";
        });
    };
}

$cases = [
    // A slim POST revives the page and bumps the total. The flush runs while the context waits for
    // its seed, then the SSE connect brings the tab's count.
    'flush-before-connect' => static function (): array {
        $app = new Via((new Config())->withLogLevel('error')->withBroadcastTickMs(0));
        $world = new SeedWorld();
        $handler = counterPage($world);
        $app->page('/counter', $handler);
        $observer = mintPage($app, $handler, '/counter', '/counter_/observer');
        frames($observer);
        $countId = mintPage($app, $handler, '/counter', '/counter_/held')->getSignal('count')->id();
        dropPage($app, '/counter_/held');

        return inCoroutine(static function () use ($app, $world, $observer, $countId): array {
            $status = postAction($app, 'bump', ['via_ctx' => '/counter_/held']);
            $held = $app->contexts['/counter_/held'];
            $awaiting = $held->isAwaitingSeed();
            waitFor(static fn (): bool => isset($world->renders['/counter_/observer']), 'the flush to render the observer');

            $whileHeld = [];
            while (($patch = $held->getPatch()) !== null) {
                $whileHeld[] = $patch['type'];
            }
            $rendersWhileHeld = $world->renders['/counter_/held'] ?? 0;

            $stream = openStream($app, '/counter_/held', [$countId => 42]);
            waitFor(static fn (): bool => str_contains($stream->written, 'room_seed_total'), 'the stream to send the initial sync');
            closeStream($stream);

            return [
                'status' => $status,
                'awaiting' => $awaiting,
                'observer' => frames($observer),
                'whileHeld' => $whileHeld,
                'rendersWhileHeld' => $rendersWhileHeld,
                'countId' => $countId,
                'written' => $stream->written,
            ];
        });
    },

    // The SSE connect arrives in the same turn as the slim POST, before the flush the POST's write
    // scheduled. The seed comes first, and the flush then renders the seeded context.
    'connect-before-flush' => static function (): array {
        $app = new Via((new Config())->withLogLevel('error')->withBroadcastTickMs(0));
        $world = new SeedWorld();
        $handler = counterPage($world);
        $app->page('/counter', $handler);
        $observer = mintPage($app, $handler, '/counter', '/counter_/observer');
        frames($observer);
        $countId = mintPage($app, $handler, '/counter', '/counter_/held')->getSignal('count')->id();
        dropPage($app, '/counter_/held');

        return inCoroutine(static function () use ($app, $world, $observer, $countId): array {
            postAction($app, 'bump', ['via_ctx' => '/counter_/held']);
            $awaiting = $app->contexts['/counter_/held']->isAwaitingSeed();
            $observerRenders = $world->renders['/counter_/observer'] ?? 0;

            $stream = openStream($app, '/counter_/held', [$countId => 42]);
            $seededBeforeFlush = !$app->contexts['/counter_/held']->isAwaitingSeed()
                && ($world->renders['/counter_/observer'] ?? 0) === $observerRenders;
            waitFor(static fn (): bool => ($world->renders['/counter_/held'] ?? 0) >= 2, 'the flush to render the seeded context');
            waitFor(static fn (): bool => substr_count($stream->written, 'data: elements') >= 2, 'the stream to send the flush frame');
            closeStream($stream);

            return [
                'awaiting' => $awaiting,
                'seededBeforeFlush' => $seededBeforeFlush,
                'observer' => frames($observer),
                'renders' => $world->renders['/counter_/held'],
                'countId' => $countId,
                'written' => $stream->written,
            ];
        });
    },

    // Flush F1 of room:a reads n under its epoch at g, whose view then waits on I/O. Another worker
    // writes n and broadcasts room:data, and flush F2 passes the held context h without a frame.
    // argv[2]: "seeded": an action that posts signals ends h's wait, then F1 renders h from its
    // older read of n. "held": F1 reaches h still held, and an SSE connect seeds h afterwards.
    'older-flush-after-held-skip' => static function (string $variant = 'seeded'): array {
        $store = new SharedSignalStore(maxRows: 64);
        $app = new Via((new Config())->withLogLevel('error')->withBroadcastTickMs(0));
        $app->setSharedSignalStore($store);
        $warnings = captureWarnings($app);
        $world = new SeedWorld();
        $handler = static function (Context $c) use ($world): void {
            $mine = $c->signal('', 'mine');
            $n = $c->signal(1, 'n', 'room:data');
            $c->addScope('room:a');
            $c->addScope('room:data');
            $c->action(static fn () => null, 'noop');
            $c->view(static function () use ($c, $mine, $n, $world): string {
                $world->renders[$c->getId()] = ($world->renders[$c->getId()] ?? 0) + 1;

                return "<div id=\"h\">n={$n->int()} mine={$mine->string()}</div>";
            });
        };
        $app->page('/r', $handler);
        $mineId = mintPage($app, $handler, '/r', '/r_/h')->getSignal('mine')->id();
        dropPage($app, '/r_/h');

        // g is in room:a only, ahead of h, so F2 does not read n and F1 reaches g first.
        $g = new Context('g', '/g', $app);
        $g->scope('room:a');
        $app->contexts['g'] = $g;
        $gN = $g->signal(1, 'n', 'room:data');
        // Declaring n joined g to room:data; leave it, so F2 does not reach g.
        $g->removeScope('room:data');
        $gate = new SeedGate();
        $world->gatesAfterReads['g'] = $gate;
        $g->view(static function () use ($gN, $world): string {
            $world->renders['g'] = ($world->renders['g'] ?? 0) + 1;
            $html = "<div id=\"g\">n={$gN->int()}</div>";
            if (isset($world->gatesAfterReads['g'])) {
                $gate = $world->gatesAfterReads['g'];
                unset($world->gatesAfterReads['g']);
                $gate->pass();
            }

            return $html;
        });

        $epoch = new ReflectionProperty(Context::class, 'fanOutEpoch');
        $epochs = (new ReflectionProperty(Via::class, 'readEpochs'))->getValue($app);

        return inCoroutine(static function () use ($app, $store, $world, $warnings, $gN, $gate, $mineId, $epoch, $epochs, $variant): array {
            postAction($app, 'noop', ['via_ctx' => '/r_/h']);
            $h = $app->contexts['/r_/h'];
            $awaiting = $h->isAwaitingSeed();

            $app->broadcast('room:a');
            waitFor(static fn (): bool => $gate->waiting, 'F1 to wait after g read n');
            $renewals = $epochs->renewals;

            $store->set($gN->sharedKey(), 2);
            (new ReflectionMethod($app, 'handlePipeMessage'))->invoke($app, 1, json_encode(['scope' => 'room:data', 'nodeId' => 'worker-1']));
            waitFor(static fn (): bool => $epoch->getValue($h) > 0, 'F2 to pass the held context');
            $skippedBy = $epoch->getValue($h);
            $queuedBySkip = $h->getPatch();

            if ($variant === 'seeded') {
                postAction($app, 'noop', ['via_ctx' => '/r_/h', $mineId => 'typed']);
            }
            $awaitingWhenF1Resumes = $h->isAwaitingSeed();
            $gate->open();
            waitFor(static fn (): bool => $epoch->getValue($h) > $skippedBy, 'F1 to finish h');

            $observed = [
                'awaiting' => $awaiting,
                'skippedBy' => $skippedBy,
                'queuedBySkip' => $queuedBySkip,
                'awaitingWhenF1Resumes' => $awaitingWhenF1Resumes,
                'frames' => frames($h),
                'renders' => $world->renders,
                'renewals' => $epochs->renewals - $renewals,
            ];

            if ($variant === 'held') {
                $stream = openStream($app, '/r_/h', [$mineId => 'typed']);
                waitFor(static fn (): bool => str_contains($stream->written, 'data: elements'), 'the stream to send the initial sync');
                closeStream($stream);
                $observed['written'] = $stream->written;
            }

            return $observed + ['warnings' => $warnings->getArrayCopy()];
        });
    },
];

$case = (string) ($argv[1] ?? '');

try {
    $result = isset($cases[$case]) ? $cases[$case](...array_slice($argv, 2)) : ['error' => "unknown case \"{$case}\""];
} catch (Throwable $e) {
    $result = ['error' => Logger::describe($e)];
}

echo json_encode($result), "\n";
