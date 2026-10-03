<?php

declare(strict_types=1);

/*
 * Fixture for BroadcastCoalescingTest: runs one broadcast scenario inside Coroutine::run and prints
 * what it observed as one JSON line.
 *
 * Inside a coroutine broadcast() coalesces, and plain Pest never runs one, so these scenarios need
 * Coroutine::run. That cannot happen in the Pest process: on ext-openswoole 26.2 Coroutine::run
 * segfaults in any process that armed a Timer outside a reactor before, and many tests do (context
 * cleanup, setInterval). Coroutine::run returns only once deferred flushes and tick timers are done.
 *
 * argv[1] = case name. VIA_TEST_MODE stays on, so patch queues are plain arrays.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Broker\MessageBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Timer;

final class CoalesceState {
    public int $value = 0;

    /** @var array<string, int> */
    public array $renders = [];

    /** @var list<int|string> */
    public array $order = [];
}

final class CoalesceBroker implements MessageBroker {
    /** @var list<string> */
    public array $events = [];

    /** @var null|callable(string): void */
    public $handler;

    public bool $throwOnPublish = false;
    public int $yieldUs = 0;
    public int $inFlight = 0;
    public int $maxInFlight = 0;

    public ?int $disconnectedAt = null;

    public function connect(): void {}

    public function disconnect(): void {
        $this->events[] = 'disconnect';
        $this->disconnectedAt = hrtime(true);
    }

    public function publish(string $scope): void {
        $this->maxInFlight = max($this->maxInFlight, ++$this->inFlight);

        try {
            if ($this->throwOnPublish) {
                throw new RuntimeException('broker down');
            }
            if ($this->yieldUs > 0) {
                Coroutine::usleep($this->yieldUs);
            }
            $this->events[] = 'publish ' . $scope;
        } finally {
            --$this->inFlight;
        }
    }

    public function subscribe(callable $handler): void {
        $this->handler = $handler;
    }

    public function getNodeId(): string {
        return 'coalesce-test';
    }

    public function isConnected(): bool {
        return true;
    }

    /** @return list<string> */
    public function published(): array {
        return array_values(array_filter($this->events, static fn (string $e): bool => str_starts_with($e, 'publish ')));
    }
}

final class CoalesceMountPage {
    #[Signal(Scope::GLOBAL)]
    public int $a = 0;

    #[Signal(Scope::GLOBAL)]
    public int $b = 0;

    #[Signal(Scope::GLOBAL)]
    public int $c = 0;

    #[Signal(Scope::GLOBAL)]
    public int $d = 0;

    #[Signal(Scope::GLOBAL)]
    public int $e = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => "<div id=\"page\">{$this->a}{$this->b}{$this->c}{$this->d}{$this->e}</div>");
    }

    #[Action]
    public function bumpAll(Context $ctx): void {
        ++$this->a;
        ++$this->b;
        ++$this->c;
        ++$this->d;
        ++$this->e;
    }
}

function app(?Config $config = null): Via {
    return new Via(($config ?? new Config())->withLogLevel('error'));
}

/** Run $fn in Coroutine::run and return its result; a throwable escaping a coroutine would end the process. */
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

/** Record log lines instead of echoing them: output buffers are per coroutine. */
function captureLogs(Via $app): ArrayObject {
    $lines = new ArrayObject();
    $logger = new class($lines) extends Logger {
        public function __construct(private ArrayObject $lines) {
            parent::__construct('debug');
        }

        public function log(string $level, string $message, ?Context $context = null): void {
            $this->lines[] = "[{$level}] {$message}";
        }
    };
    (new ReflectionProperty(Via::class, 'logger'))->setValue($app, $logger);

    return $lines;
}

/** A context in $scope whose view counts its renders and shows $state->value. */
function observer(Via $app, string $id, string $scope, CoalesceState $state): Context {
    $ctx = new Context($id, '/obs', $app);
    $ctx->scope($scope);
    $app->contexts[$id] = $ctx;
    $ctx->view(static function () use ($id, $state): string {
        $state->renders[$id] = ($state->renders[$id] ?? 0) + 1;

        return "<div id=\"{$id}\">v={$state->value}</div>";
    });

    return $ctx;
}

/** @return list<array{type: string, content: mixed}> */
function patches(Context $ctx): array {
    $patches = [];
    while (($patch = $ctx->getPatch()) !== null) {
        unset($patch['confirm']);
        $patches[] = $patch;
    }

    return $patches;
}

/** @return array<string, mixed> */
function flags(Via $app): array {
    $flags = [];
    foreach (['flushScheduled', 'flushTimerId', 'runningFlushes', 'publishing', 'dirtyScopes', 'unpublishedScopes'] as $name) {
        $flags[$name] = (new ReflectionProperty(Via::class, $name))->getValue($app);
    }

    return $flags;
}

/** @return list<string> warnings and errors only */
function problems(ArrayObject $logs): array {
    return array_values(array_filter((array) $logs, static fn (string $l): bool => str_starts_with($l, '[warning]') || str_starts_with($l, '[error]')));
}

/** $count contexts in $scope whose views each wait $ioMs on I/O, as a view querying a database would. */
function slowObservers(Via $app, string $scope, int $count, int $ioMs, CoalesceState $state): void {
    for ($i = 0; $i < $count; ++$i) {
        $id = "slow{$i}";
        $ctx = new Context($id, '/slow', $app);
        $ctx->scope($scope);
        $app->contexts[$id] = $ctx;
        $ctx->view(static function () use ($id, $ioMs, $state): string {
            Coroutine::usleep($ioMs * 1_000);
            $state->renders[$id] = ($state->renders[$id] ?? 0) + 1;

            return "<div id=\"{$id}\">x</div>";
        });
    }
}

/** @return list<string> the content of each elements patch */
function frames(Context $ctx): array {
    return array_values(array_map(
        static fn (array $p): string => (string) $p['content'],
        array_filter(patches($ctx), static fn (array $p): bool => $p['type'] === 'elements'),
    ));
}

/**
 * A context in $scope whose view records each render's [start, end] in $flushes; $render does the work.
 *
 * @param list<array{0: int, 1: int}> $flushes
 * @param callable(int): mixed        $render  gets the number of renders recorded so far
 */
function timedObserver(Via $app, string $scope, array &$flushes, callable $render): void {
    $ctx = new Context('obs', '/obs', $app);
    $ctx->scope($scope);
    $app->contexts['obs'] = $ctx;
    $ctx->view(static function () use (&$flushes, $render): string {
        // Flushes run in a coroutine; the warm-up render below does not.
        if (Coroutine::getCid() > 0) {
            $start = hrtime(true);
            $render(count($flushes));
            $flushes[] = [$start, hrtime(true)];
        }

        return '<div id="obs">x</div>';
    });

    // A first render loads classes, which would delay the first timed flush and shorten its gap.
    $app->broadcast($scope);
    patches($ctx);
}

/**
 * One context whose every render reads the value, then waits on I/O while another action writes and
 * broadcasts, for the first $writes renders. With coalescing off that re-runs the pass every time.
 *
 * @param bool $writerInAction the writes come from a coroutine the first broadcaster started before it broadcast
 * @param bool $shutdown       the worker is shutting down before the first broadcast
 *
 * @return array{renders: int, lastSeen: int, final: int, logs: list<string>, flags: array<string, mixed>}
 */
function writesDuringRenders(bool $coalescing, bool $selfBroadcast, int $writes = 8, bool $writerInAction = false, bool $shutdown = false): array {
    $app = app((new Config())->withBroadcastCoalescing($coalescing)->withBroadcastTickMs(5));
    $logs = captureLogs($app);
    $scope = 'room:load';
    $value = 1;
    $renders = 0;
    $lastSeen = 0;
    $rendering = new Channel(1);
    $written = new Channel(1);

    $ctx = new Context('load', '/load', $app);
    $ctx->scope($scope);
    $app->contexts['load'] = $ctx;
    $ctx->view(static function () use ($app, $scope, $selfBroadcast, &$value, &$renders, &$lastSeen, $rendering, $written): string {
        $seen = $value;
        $rendering->push(++$renders, 1.0);
        $written->pop(1.0);
        $lastSeen = $seen;
        if ($selfBroadcast) {
            $app->broadcast($scope);
        }

        return "<div id=\"load\">{$seen}</div>";
    });

    inCoroutine(static function () use ($app, $scope, $writes, $writerInAction, $shutdown, &$value, $rendering, $written): void {
        if ($shutdown) {
            (new ReflectionMethod($app, 'runWorkerShutdown'))->invoke($app);
        }

        $writer = static function (callable $send) use ($writes, &$value, $rendering, $written): void {
            while (($render = $rendering->pop(0.2)) !== false) {
                if ($render <= $writes) {
                    ++$value;
                    $send();
                }
                $written->push(true);
            }
        };

        if ($writerInAction) {
            Coroutine::create(static function () use ($app, $scope, $writer): void {
                Coroutine::create(static fn () => $writer(static fn () => $app->broadcast($scope)));
                $app->broadcast($scope);
            });

            return;
        }

        // Each write is an action of its own.
        $send = static fn () => Coroutine::create(static fn () => $app->broadcast($scope));
        $send();
        $writer($send);
    });

    return ['renders' => $renders, 'lastSeen' => $lastSeen, 'final' => $value, 'logs' => problems($logs), 'flags' => flags($app)];
}

/**
 * @param callable(bool): array<string, mixed> $scenario gets whether coalescing is on
 *
 * @return array{off: array<string, mixed>, on: array<string, mixed>}
 */
function bothModes(callable $scenario): array {
    return ['off' => $scenario(false), 'on' => $scenario(true)];
}

/** Hold the worker for $ms without yielding, as a view rendering many contexts does. */
function busy(int $ms): void {
    $start = hrtime(true);
    while (hrtime(true) - $start < $ms * 1_000_000);
}

/**
 * Start gaps run from one flush start to the next, idle gaps from one flush end to the next start.
 *
 * @param list<array{0: int, 1: int}> $flushes [start, end] of each flush
 *
 * @return array{flushes: int, minStartGapMs: ?float, medianStartGapMs: ?float, minIdleGapMs: ?float, medianIdleGapMs: ?float}
 */
function flushGaps(array $flushes): array {
    $starts = [];
    $idle = [];
    for ($i = 1; $i < count($flushes); ++$i) {
        $starts[] = ($flushes[$i][0] - $flushes[$i - 1][0]) / 1e6;
        $idle[] = ($flushes[$i][0] - $flushes[$i - 1][1]) / 1e6;
    }
    $median = static function (array $values): ?float {
        sort($values);

        return $values === [] ? null : $values[intdiv(count($values), 2)];
    };

    return [
        'flushes' => count($flushes),
        'minStartGapMs' => $starts === [] ? null : min($starts),
        'medianStartGapMs' => $median($starts),
        'minIdleGapMs' => $idle === [] ? null : min($idle),
        'medianIdleGapMs' => $median($idle),
    ];
}

$cases = [
    'one-turn' => static function (): array {
        $app = app();
        $state = new CoalesceState();
        $ctx = observer($app, 'obs', 'room:turn', $state);

        $before = inCoroutine(static function () use ($app, $state, $ctx): ?array {
            foreach ([1, 2, 3] as $value) {
                $state->value = $value;
                $app->broadcast('room:turn');
            }

            return $ctx->getPatch();
        });

        return ['before' => $before, 'renders' => $state->renders, 'patches' => patches($ctx)];
    },

    'signal-writes' => static function (): array {
        $app = app();
        $state = new CoalesceState();
        $actor = new Context('actor', '/actor', $app);
        $shared = $actor->signal(0, 'shared', 'room:sig');
        $actor->action(static function () use ($shared): void {
            for ($i = 1; $i <= 5; ++$i) {
                $shared->setValue($i);
            }
        }, 'bump');
        $observers = ['o1' => observer($app, 'o1', 'room:sig', $state), 'o2' => observer($app, 'o2', 'room:sig', $state)];

        inCoroutine(static fn () => $actor->executeAction('bump'));

        return ['renders' => $state->renders, 'patches' => array_map(patches(...), $observers)];
    },

    'mount-writes' => static function (): array {
        $app = app();
        $app->mount(CoalesceMountPage::class, '/page');
        $page = new Context('/_/page', '/page', $app, null, 'sess');
        $app->contexts[$page->getId()] = $page;
        $app->getApp()->registerContext($page);
        $app->registerContextInScope($page, Scope::TAB);
        $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/page'], $page, []);

        $state = new CoalesceState();
        observer($app, 'o1', 'room:other', $state);
        observer($app, 'o2', 'room:other', $state);

        inCoroutine(static fn () => $page->executeAction('bumpAll'));

        return ['renders' => $state->renders];
    },

    'many-coroutines' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:many', $state);
        $tab = new Context('tab', '/t', $app);
        $tab->view(static fn (): string => '<p>tab</p>');

        inCoroutine(static function () use ($app, $tab): void {
            for ($i = 0; $i < 5; ++$i) {
                Coroutine::create(static fn () => $app->broadcast('room:many'));
            }
            $tab->broadcast();
        });

        return ['renders' => $state->renders, 'published' => $broker->published()];
    },

    'received' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:recv', $state);

        inCoroutine(static function () use ($app, $broker): void {
            $pipe = new ReflectionMethod($app, 'handlePipeMessage');
            for ($i = 0; $i < 5; ++$i) {
                ($broker->handler)('room:recv');
                $pipe->invoke($app, 1, json_encode(['scope' => 'room:recv', 'nodeId' => 'worker-1']));
            }
        });

        return ['renders' => $state->renders, 'published' => $broker->published()];
    },

    'idle-leading-edge' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(300));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:idle', $state);

        $seen = inCoroutine(static function () use ($app, $state): array {
            $app->broadcast('room:idle');
            $idle = flags($app);
            Coroutine::usleep(1_000);
            $afterTurn = $state->renders['obs'] ?? 0;

            // Right after a flush the next one waits for the tick.
            $app->broadcast('room:idle');
            $busy = flags($app);
            Coroutine::usleep(100_000);

            return [
                'idleDeferred' => $idle['flushScheduled'] && $idle['flushTimerId'] === null,
                'afterTurn' => $afterTurn,
                'busyTimer' => $busy['flushTimerId'] !== null,
                'after100ms' => $state->renders['obs'] ?? 0,
            ];
        });

        return [...$seen, 'final' => $state->renders['obs'] ?? 0];
    },

    'tick-gap' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $flushes = [];
        timedObserver($app, 'room:tick', $flushes, static fn () => busy(8));

        $sent = 0;
        inCoroutine(static function () use ($app, &$sent): void {
            $until = hrtime(true) + 200_000_000;
            while (hrtime(true) < $until) {
                $app->broadcast('room:tick');
                ++$sent;
                Coroutine::usleep(2_000);
            }
        });

        return ['sent' => $sent, ...flushGaps($flushes), 'stats' => $app->getStats()->getBroadcastStats()];
    },

    'tick-gap-yielding' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $flushes = [];
        // A fan-out that waits 10 ms on I/O, while the broadcasts keep coming.
        timedObserver($app, 'room:tick', $flushes, static fn () => Coroutine::usleep(10_000));

        inCoroutine(static function () use ($app): void {
            $until = hrtime(true) + 200_000_000;
            while (hrtime(true) < $until) {
                $app->broadcast('room:tick');
                Coroutine::usleep(2_000);
            }
        });

        return flushGaps($flushes);
    },

    'tick-floor' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $flushes = [];
        timedObserver($app, 'room:floor', $flushes, static fn () => busy(15));

        inCoroutine(static function () use ($app): void {
            $until = hrtime(true) + 200_000_000;
            while (hrtime(true) < $until) {
                $app->broadcast('room:floor');
                Coroutine::usleep(2_000);
            }
        });

        return flushGaps($flushes);
    },

    'tick-overrun' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $flushes = [];
        timedObserver($app, 'room:over', $flushes, static fn () => busy(30));

        $sent = 0;
        $wakes = 0;
        inCoroutine(static function () use ($app, &$sent, &$wakes): void {
            $until = hrtime(true) + 300_000_000;
            // Other work on the worker, such as a request or a timer, that wants to run every 1 ms.
            Coroutine::create(static function () use ($until, &$wakes): void {
                while (hrtime(true) < $until) {
                    Coroutine::usleep(1_000);
                    ++$wakes;
                }
            });
            while (hrtime(true) < $until) {
                $app->broadcast('room:over');
                ++$sent;
                Coroutine::usleep(2_000);
            }
        });

        return ['sent' => $sent, 'wakes' => $wakes, ...flushGaps($flushes)];
    },

    'overrun-follow-up' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(50));
        $flushes = [];
        // Only the first flush overruns the tick.
        timedObserver($app, 'room:follow', $flushes, static fn (int $i) => $i === 0 ? busy(60) : null);

        $seen = inCoroutine(static function () use ($app): array {
            $app->broadcast('room:follow');
            // Wakes once the 60 ms flush is done.
            Coroutine::usleep(1_000);

            $markedAt = hrtime(true);
            $app->broadcast('room:follow');
            $flags = flags($app);
            Coroutine::usleep(100_000);

            return ['markedAt' => $markedAt, 'timer' => $flags['flushTimerId'] !== null];
        });

        return [
            'flushes' => count($flushes),
            'timer' => $seen['timer'],
            'waitMs' => isset($flushes[1]) ? ($flushes[1][0] - $seen['markedAt']) / 1e6 : null,
            'flags' => flags($app),
        ];
    },

    'overrun-pending' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $flushes = [];
        timedObserver($app, 'room:pending', $flushes, static fn (int $i) => $i === 0 ? Coroutine::usleep(40_000) : null);

        inCoroutine(static function () use ($app): void {
            $app->broadcast('room:pending');
            Coroutine::usleep(5_000);
            // Marked while the 40 ms pass waits on I/O, so still pending when it ends.
            $app->broadcast('room:pending');
            Coroutine::usleep(150_000);
        });

        return [...flushGaps($flushes), 'flags' => flags($app)];
    },

    'invalidate-after-pass' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(200));
        $value = 1;
        $waited = false;
        $contexts = [];
        foreach (['c1', 'c2', 'late'] as $id) {
            $ctx = new Context($id, '/after', $app);
            $ctx->scope(Scope::ROUTE);
            $app->contexts[$id] = $ctx;
            $ctx->view(static function () use (&$value, &$waited): string {
                // The first render reads, then waits 30 ms on I/O; its HTML is cached for the route.
                $seen = $value;
                if (!$waited) {
                    $waited = true;
                    Coroutine::usleep(30_000);
                }

                return "<div id=\"after\">v={$seen}</div>";
            });
            $contexts[$id] = $ctx;
        }

        $patch = inCoroutine(static function () use ($app, &$value, $contexts): ?array {
            Coroutine::create(static fn () => $app->broadcast(Scope::routeScope('/after')));
            Coroutine::usleep(10_000);
            // Mid-pass: must not invalidate yet.
            $value = 2;
            $app->broadcast(Scope::routeScope('/after'));

            // The pass has ended, the next flush is 200 ms away, and another tab syncs.
            Coroutine::usleep(40_000);
            patches($contexts['late']);
            $contexts['late']->sync();

            return $contexts['late']->getPatch();
        });

        return ['content' => $patch['content'] ?? null];
    },

    'tick-zero' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(0));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:zero', $state);

        return inCoroutine(static function () use ($app, $state): array {
            $app->broadcast('room:zero');
            Coroutine::usleep(1_000);
            $app->broadcast('room:zero');
            $timer = flags($app)['flushTimerId'] !== null;
            Coroutine::usleep(1_000);

            return ['timer' => $timer, 'renders' => $state->renders['obs'] ?? 0];
        });
    },

    'clear-all' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(50));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:clear', $state);

        $seen = inCoroutine(static function () use ($app, $state): array {
            $app->broadcast('room:clear');
            Coroutine::usleep(1_000);
            $app->broadcast('room:clear');
            $armed = flags($app)['flushTimerId'] !== null;

            // As workerExit and some fixtures do: the tick timer is gone without a word.
            Timer::clearAll();
            Coroutine::usleep(100_000);
            $stranded = $state->renders['obs'] ?? 0;

            $app->broadcast('room:clear');

            return ['armed' => $armed, 'stranded' => $stranded];
        });

        return [...$seen, 'renders' => $state->renders['obs'] ?? 0, 'flags' => flags($app)];
    },

    'shutdown-drain' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker)->withBroadcastTickMs(5_000));
        $state = new CoalesceState();
        $ctx = new Context('obs', '/obs', $app);
        $ctx->scope('room:drain');
        $app->contexts['obs'] = $ctx;
        $ctx->view(static function () use ($state): string {
            $state->order[] = $state->value;

            return "<div id=\"obs\">v={$state->value}</div>";
        });
        // Runs after the channels close.
        $app->onWorkerStop(static function () use ($state): void {
            $state->order[] = 'callback';
        });

        $start = hrtime(true);
        $seen = inCoroutine(static function () use ($app, $state, $broker): array {
            $state->value = 1;
            $app->broadcast('room:drain');
            Coroutine::usleep(1_000);
            $state->value = 2;
            $app->broadcast('room:drain');
            $armed = flags($app)['flushTimerId'] !== null;

            // workerExit: start the shutdown coroutine, then clear every timer.
            Coroutine::create(static fn () => (new ReflectionMethod($app, 'runWorkerShutdown'))->invoke($app));
            Timer::clearAll();

            return ['armed' => $armed, 'rendered' => $state->order, 'events' => $broker->events];
        });

        return [
            ...$seen,
            'elapsedMs' => (hrtime(true) - $start) / 1e6,
            'flags' => flags($app),
        ];
    },

    'metrics' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(5));
        $ctx = new Context('slow', '/slow', $app);
        $ctx->scope('room:slow');
        $app->contexts['slow'] = $ctx;
        $ctx->view(static function (): string {
            $start = hrtime(true);
            while (hrtime(true) - $start < 8_000_000);

            return '<div id="slow">x</div>';
        });

        inCoroutine(static function () use ($app): void {
            for ($i = 0; $i < 3; ++$i) {
                $app->broadcast('room:slow');
            }
        });

        return ['stats' => $app->getStats()->getBroadcastStats()];
    },

    'several-scopes' => static function (): array {
        $app = app();
        $state = new CoalesceState();
        $both = observer($app, 'both', 'room:a', $state);
        $both->addScope('room:b');
        observer($app, 'onlyB', 'room:b', $state);

        inCoroutine(static function () use ($app): void {
            $app->broadcast('room:a');
            $app->broadcast('room:b');
            $app->broadcast(Scope::GLOBAL);
        });

        return ['renders' => $state->renders];
    },

    'eager-invalidation' => static function (): array {
        $app = app();
        $value = 1;
        $contexts = [];
        foreach (['c1', 'c2'] as $id) {
            $ctx = new Context($id, '/cached', $app);
            $ctx->scope(Scope::ROUTE);
            $app->contexts[$id] = $ctx;
            $ctx->view(static function () use (&$value, $id): string {
                return "<div id=\"{$id}\">v={$value}</div>";
            });
            $contexts[$id] = $ctx;
        }
        $contexts['c1']->sync();
        patches($contexts['c1']);

        $patch = inCoroutine(static function () use ($app, &$value, $contexts): ?array {
            $value = 2;
            $app->broadcast(Scope::routeScope('/cached'));
            $contexts['c2']->sync();

            return $contexts['c2']->getPatch();
        });

        return ['content' => $patch['content'] ?? null];
    },

    'released' => static function (): array {
        $app = app();
        $state = new CoalesceState();
        $ctx = observer($app, 'gone', 'room:gone', $state);

        inCoroutine(static function () use ($app, $ctx): void {
            $app->broadcast('room:gone');
            $app->unregisterContextInScope($ctx, 'room:gone');
        });

        return ['renders' => $state->renders];
    },

    'flush-order' => static function (): array {
        $result = [];
        foreach (['without' => false, 'with' => true] as $label => $flush) {
            $app = app();
            $state = new CoalesceState();
            $ctx = observer($app, 'obs', 'room:flush', $state);

            inCoroutine(static function () use ($app, $ctx, $state, $flush): void {
                $state->value = 7;
                $app->broadcast('room:flush');
                if ($flush) {
                    $app->flushBroadcasts();
                }
                $ctx->execScript('scrollToBottom()');
            });

            $result[$label] = ['types' => array_column(patches($ctx), 'type'), 'renders' => $state->renders];
        }

        return $result;
    },

    'coalescing-off' => static function (): array {
        $app = app((new Config())->withBroadcastCoalescing(false));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:off', $state);

        $seen = inCoroutine(static function () use ($app, $state): array {
            $seen = [];
            for ($i = 0; $i < 3; ++$i) {
                $app->broadcast('room:off');
                $seen[] = $state->renders['obs'] ?? 0;
            }

            return $seen;
        });

        return ['seen' => $seen];
    },

    'split' => static function (): array {
        $result = [];
        foreach (['nested', 'suspended'] as $trigger) {
            // No tick, so nothing but a running pass of the same scope can hold a flush back.
            $app = app((new Config())->withBroadcastTickMs(0));
            $scope = 'room:split';
            $state = new CoalesceState();
            $tripped = false;

            foreach (range(0, 5) as $i) {
                $ctx = new Context('ctx' . $i, '/split', $app);
                $ctx->scope($scope);
                $ctx->view(static function () use ($i, $state, &$tripped, $app, $scope, $trigger): string {
                    $state->order[] = $i;
                    if ($i === 1 && !$tripped) {
                        $tripped = true;
                        // Nested: the view itself broadcasts. Suspended: the render yields while
                        // another coroutine broadcasts, as a Twig compile or hooked I/O would.
                        $trigger === 'nested' ? $app->broadcast($scope) : Coroutine::usleep(20_000);
                    }

                    return '<div id="ctx' . $i . '">x</div>';
                });
            }

            $other = new Context('other', '/split', $app);
            $other->scope('room:elsewhere');
            $other->view(static function () use ($state): string {
                $state->order[] = 'other';

                return '<div id="other">x</div>';
            });

            inCoroutine(static function () use ($app, $scope, $trigger): void {
                $app->broadcast($scope);
                if ($trigger === 'suspended') {
                    Coroutine::create(static function () use ($app, $scope): void {
                        Coroutine::usleep(5_000);
                        $app->broadcast($scope);
                        $app->broadcast('room:elsewhere');
                    });
                }
            });

            $result[$trigger] = $state->order;
        }

        return $result;
    },

    'self-broadcast' => static function (): array {
        $app = app();
        $logs = captureLogs($app);
        $renders = 0;
        $ctx = new Context('self', '/self', $app);
        $ctx->scope('room:self');
        $ctx->view(static function () use (&$renders, $app): string {
            ++$renders;
            $app->broadcast('room:self');

            return '<div id="self">x</div>';
        });

        inCoroutine(static fn () => $app->broadcast('room:self'));

        return ['renders' => $renders, 'logs' => (array) $logs, 'flags' => flags($app)];
    },

    'self-broadcast-sync' => static function (): array {
        $app = app((new Config())->withBroadcastCoalescing(false));
        $logs = captureLogs($app);
        $renders = 0;
        $ctx = new Context('self', '/self', $app);
        $ctx->scope('room:self');
        $app->contexts['self'] = $ctx;
        $ctx->view(static function () use (&$renders, $app): string {
            ++$renders;
            $app->broadcast('room:self');

            return '<div id="self">x</div>';
        });

        inCoroutine(static fn () => $app->broadcast('room:self'));

        return ['renders' => $renders, 'logs' => problems($logs), 'flags' => flags($app)];
    },

    'writes-at-cap' => static fn (): array => [
        'off' => writesDuringRenders(coalescing: false, selfBroadcast: false),
        'on' => writesDuringRenders(coalescing: true, selfBroadcast: false),
    ],

    'self-broadcast-under-load' => static fn (): array => [
        'off' => writesDuringRenders(coalescing: false, selfBroadcast: true),
        'on' => writesDuringRenders(coalescing: true, selfBroadcast: true),
    ],

    'writer-started-by-action' => static fn (): array => bothModes(
        static fn (bool $coalescing): array => writesDuringRenders($coalescing, selfBroadcast: false, writerInAction: true),
    ),

    'shutdown-at-cap' => static fn (): array => bothModes(
        static fn (bool $coalescing): array => writesDuringRenders($coalescing, selfBroadcast: false, shutdown: true),
    ),

    'self-broadcast-once' => static fn (): array => bothModes(static function (bool $coalescing): array {
        $app = app((new Config())->withBroadcastCoalescing($coalescing)->withBroadcastTickMs(5));
        $logs = captureLogs($app);
        $renders = 0;
        $ctx = new Context('lazy', '/lazy', $app);
        $ctx->scope('room:lazy');
        $app->contexts['lazy'] = $ctx;
        // Loads what it shows on its first render and broadcasts that.
        $ctx->view(static function () use (&$renders, $app): string {
            if (++$renders === 1) {
                $app->broadcast('room:lazy');
            }

            return '<div id="lazy">x</div>';
        });

        inCoroutine(static fn () => $app->broadcast('room:lazy'));

        return ['renders' => $renders, 'logs' => problems($logs), 'flags' => flags($app)];
    }),

    'self-broadcast-spawned' => static fn (): array => bothModes(static function (bool $coalescing): array {
        $app = app((new Config())->withBroadcastCoalescing($coalescing)->withBroadcastTickMs(5));
        $logs = captureLogs($app);
        $renders = 0;
        $ctx = new Context('spawn', '/spawn', $app);
        $ctx->scope('room:spawn');
        $app->contexts['spawn'] = $ctx;
        $ctx->view(static function () use (&$renders, $app): string {
            // Bounded, so a loop that is not stopped still ends the fixture.
            if (++$renders < 40) {
                Coroutine::create(static fn () => $app->broadcast('room:spawn'));
            }

            return '<div id="spawn">x</div>';
        });

        inCoroutine(static fn () => $app->broadcast('room:spawn'));

        return ['renders' => $renders, 'logs' => problems($logs), 'flags' => flags($app)];
    }),

    'self-broadcast-after-outside-rerun' => static fn (): array => bothModes(static function (bool $coalescing): array {
        $app = app((new Config())->withBroadcastCoalescing($coalescing)->withBroadcastTickMs(5));
        $logs = captureLogs($app);
        $scope = 'room:steps';
        $value = 0;
        $renders = 0;
        $lastSeen = -1;
        $rendering = new Channel(1);
        $written = new Channel(1);
        $ctx = new Context('steps', '/steps', $app);
        $ctx->scope($scope);
        $app->contexts['steps'] = $ctx;
        // The first render waits on I/O while another action writes; renders 2 to 8 each load one more step and broadcast it.
        $ctx->view(static function () use ($app, $scope, &$value, &$renders, &$lastSeen, $rendering, $written): string {
            $seen = $value;
            if (++$renders === 1) {
                $rendering->push(true, 1.0);
                $written->pop(1.0);
            } elseif ($renders <= 8) {
                ++$value;
                $app->broadcast($scope);
            }
            $lastSeen = $seen;

            return "<div id=\"steps\">{$seen}</div>";
        });

        inCoroutine(static function () use ($app, $scope, &$value, $rendering, $written): void {
            Coroutine::create(static fn () => $app->broadcast($scope));
            if ($rendering->pop(1.0) !== false) {
                ++$value;
                Coroutine::create(static fn () => $app->broadcast($scope));
                $written->push(true);
            }
        });

        return ['renders' => $renders, 'lastSeen' => $lastSeen, 'final' => $value, 'logs' => problems($logs), 'flags' => flags($app)];
    }),

    'overtaken-at-cap' => static fn (): array => bothModes(static function (bool $coalescing): array {
        $app = app((new Config())->withBroadcastCoalescing($coalescing)->withBroadcastTickMs(5));
        $logs = captureLogs($app);
        $value = 0;
        $slowCid = null;
        $waiting = new Channel(1);
        $overtaken = new Channel(1);
        $ctx = new Context('c', '/overtaken', $app);
        $ctx->scope('room:slow');
        $ctx->addScope('room:fast');
        $app->contexts['c'] = $ctx;
        // Waits on I/O only in the first fan-out of room:slow.
        $ctx->view(static function () use (&$value, &$slowCid, $waiting, $overtaken): string {
            $seen = $value;
            $slowCid ??= Coroutine::getCid();
            if (Coroutine::getCid() === $slowCid) {
                $waiting->push(true, 1.0);
                $overtaken->pop(1.0);
            }

            return "<div id=\"c\">{$seen}</div>";
        });

        inCoroutine(static function () use ($app, &$value, $waiting, $overtaken): void {
            Coroutine::create(static fn () => $app->broadcast('room:slow'));
            // Every time it waits, another action writes and a fan-out of room:fast renders the context first.
            while ($waiting->pop(0.2) !== false) {
                if ($value < 8) {
                    ++$value;
                    $app->broadcast('room:fast');
                    $app->flushBroadcasts();
                }
                $overtaken->push(true);
            }
        });

        return ['final' => $value, 'frames' => frames($ctx), 'logs' => problems($logs), 'flags' => flags($app)];
    }),

    'ping-pong' => static function (): array {
        $app = app();
        $logs = captureLogs($app);
        $state = new CoalesceState();

        foreach (['ping' => 'pong', 'pong' => 'ping'] as $mine => $theirs) {
            $ctx = new Context($mine, '/pp', $app);
            $ctx->scope('room:' . $mine);
            $ctx->view(static function () use ($mine, $theirs, $state, $app): string {
                $state->renders[$mine] = ($state->renders[$mine] ?? 0) + 1;
                $app->broadcast('room:' . $theirs);

                return "<div id=\"{$mine}\">x</div>";
            });
        }
        observer($app, 'calm', 'room:calm', $state);

        inCoroutine(static fn () => $app->broadcast('room:ping'));
        $chain = $state->renders;

        inCoroutine(static fn () => $app->broadcast('room:calm'));

        return ['chain' => $chain, 'calm' => $state->renders['calm'] ?? 0, 'logs' => (array) $logs, 'flags' => flags($app)];
    },

    'failures' => static function (): array {
        $broker = new CoalesceBroker();
        $broker->throwOnPublish = true;
        $app = app((new Config())->withBroker($broker));
        $logs = captureLogs($app);
        $state = new CoalesceState();
        $obs = observer($app, 'obs', 'room:fail', $state);

        // A failure outside the per-context guard, which would otherwise escape the flush.
        $requestLogger = new ReflectionProperty(Via::class, 'requestLogger');
        $original = $requestLogger->getValue($app);
        $requestLogger->setValue($app, new class(false) extends RequestLogger {
            public function logBroadcast(string $scope, int $contextCount): void {
                throw new LogicException('fan-out bookkeeping failed');
            }
        });

        $returned = inCoroutine(static function () use ($app, $state): string {
            $state->value = 1;
            $app->broadcast('room:fail');

            return 'action finished';
        });
        $flagsAfterFailure = flags($app);

        $broker->throwOnPublish = false;
        $requestLogger->setValue($app, $original);
        patches($obs);

        inCoroutine(static function () use ($app, $state): void {
            $state->value = 2;
            $app->broadcast('room:fail');
        });

        return [
            'returned' => $returned,
            'logs' => (array) $logs,
            'flagsAfterFailure' => $flagsAfterFailure,
            'renders' => $state->renders['obs'] ?? 0,
            'patches' => patches($obs),
            'published' => $broker->published(),
            'flags' => flags($app),
        ];
    },

    'single-publisher' => static function (): array {
        $result = [];
        foreach (['coalescing' => true, 'synchronous' => false] as $label => $coalescing) {
            $broker = new CoalesceBroker();
            $broker->yieldUs = 2_000;
            $app = app((new Config())->withBroker($broker)->withBroadcastCoalescing($coalescing));

            inCoroutine(static function () use ($app): void {
                for ($i = 0; $i < 20; ++$i) {
                    Coroutine::create(static function () use ($app, $i): void {
                        $app->broadcast("room:p{$i}");
                        Coroutine::usleep(($i % 5 + 1) * 1_000);
                        $app->broadcast("room:p{$i}");
                    });
                }
            });

            $result[$label] = ['maxInFlight' => $broker->maxInFlight, 'published' => $broker->published(), 'flags' => flags($app)];
        }

        return $result;
    },

    'shutdown' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker));
        $app->onWorkerStop(static fn () => $app->broadcast('room:bye'));

        inCoroutine(static fn () => (new ReflectionMethod($app, 'runWorkerShutdown'))->invoke($app));

        return ['events' => $broker->events];
    },

    'head-of-line' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker));
        $state = new CoalesceState();
        slowObservers($app, 'room:slow', 5, 100, $state);
        observer($app, 'fast', 'room:fast', $state);

        $seen = inCoroutine(static function () use ($app, $broker, $state): array {
            // Another action's flush, now waiting on its views' I/O for 500 ms.
            Coroutine::create(static fn () => $app->broadcast('room:slow'));
            Coroutine::usleep(10_000);

            $app->broadcast('room:fast');
            Coroutine::usleep(60_000);

            return ['fast' => $state->renders['fast'] ?? 0, 'published' => $broker->published()];
        });

        return [...$seen, 'slow' => array_sum($state->renders) - ($state->renders['fast'] ?? 0), 'flags' => flags($app)];
    },

    'flush-beside-slow' => static function (): array {
        $app = app();
        $state = new CoalesceState();
        slowObservers($app, 'room:slow', 5, 100, $state);
        observer($app, 'fast', 'room:fast', $state);

        return inCoroutine(static function () use ($app, $state): array {
            Coroutine::create(static fn () => $app->broadcast('room:slow'));
            Coroutine::usleep(10_000);

            $start = hrtime(true);
            $app->broadcast('room:fast');
            $app->flushBroadcasts();

            return ['returnedMs' => (hrtime(true) - $start) / 1e6, 'fast' => $state->renders['fast'] ?? 0];
        });
    },

    'flush-own-scope' => static function (): array {
        $app = app();
        $state = new CoalesceState();
        $waited = false;
        $slow = new Context('slow', '/own', $app);
        $slow->scope('room:own');
        $app->contexts['slow'] = $slow;
        $slow->view(static function () use (&$waited, $state): string {
            if (!$waited) {
                $waited = true;
                Coroutine::usleep(50_000);
            }

            return "<div id=\"slow\">v={$state->value}</div>";
        });
        $obs = observer($app, 'obs', 'room:own', $state);

        inCoroutine(static function () use ($app, $state, $obs): void {
            $state->value = 1;
            // Another action's flush of the same scope, now waiting in the first view.
            Coroutine::create(static fn () => $app->broadcast('room:own'));
            Coroutine::usleep(10_000);

            $state->value = 2;
            $app->broadcast('room:own');
            $app->flushBroadcasts();
            $obs->execScript('scrollToBottom()');
        });

        $patches = patches($obs);

        return [
            'types' => array_column($patches, 'type'),
            'beforeScript' => (string) ($patches[count($patches) - 2]['content'] ?? ''),
        ];
    },

    'flush-gives-up' => static function (): array {
        $app = app();
        $logs = captureLogs($app);
        $reply = new Channel(1);
        $renders = 0;
        $hung = new Context('hung', '/hung', $app);
        $hung->scope('room:hung');
        $app->contexts['hung'] = $hung;
        $hung->view(static function () use ($reply, &$renders): string {
            // The first render waits for a database reply that does not come.
            if (++$renders === 1) {
                $reply->pop();
            }

            return '<div id="hung">x</div>';
        });

        $seen = inCoroutine(static function () use ($app, $reply, &$renders): array {
            Coroutine::create(static fn () => $app->broadcast('room:hung'));
            Coroutine::usleep(10_000);

            $start = hrtime(true);
            $app->broadcast('room:hung');
            $app->flushBroadcasts();
            $returnedMs = (hrtime(true) - $start) / 1e6;
            $rendersThen = $renders;

            // Its fan-out has now been running for over a second.
            $app->broadcast('room:hung');
            $reply->push(true);
            Coroutine::usleep(50_000);

            return ['returnedMs' => $returnedMs, 'rendersWhenReturned' => $rendersThen];
        });

        return [...$seen, 'renders' => $renders, 'logs' => problems($logs), 'flags' => flags($app)];
    },

    'yielding-steady' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $logs = captureLogs($app);
        $value = 0;
        $renders = 0;
        $lastSeen = [];

        for ($i = 0; $i < 3; ++$i) {
            $ctx = new Context("y{$i}", '/y', $app);
            $ctx->scope('room:y');
            $app->contexts["y{$i}"] = $ctx;
            $ctx->view(static function () use ($i, &$value, &$renders, &$lastSeen): string {
                $seen = $value;
                // A 1 ms database call.
                Coroutine::usleep(1_000);
                ++$renders;
                $lastSeen[$i] = $seen;

                return "<div id=\"y{$i}\">{$seen}</div>";
            });
        }

        inCoroutine(static function () use ($app, &$value): void {
            $until = hrtime(true) + 300_000_000;
            while (hrtime(true) < $until) {
                ++$value;
                // Each write is an action of its own.
                Coroutine::create(static fn () => $app->broadcast('room:y'));
                Coroutine::usleep(2_000);
            }
        });

        return [
            'passes' => intdiv($renders, 3),
            'flushes' => $app->getStats()->getBroadcastStats()['flushes'],
            'final' => $value,
            'lastSeen' => array_values(array_unique($lastSeen)),
            'logs' => problems($logs),
            'flags' => flags($app),
        ];
    },

    'chain-no-cycle' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(20));
        $logs = captureLogs($app);
        $value = 0;
        $bSeen = [];

        $a = new Context('a', '/a', $app);
        $a->scope('room:a');
        $app->contexts['a'] = $a;
        $a->view(static function () use ($app, &$value): string {
            // A view that refreshes a summary another scope shows.
            $app->broadcast('room:b');

            return "<div id=\"a\">{$value}</div>";
        });

        $b = new Context('b', '/b', $app);
        $b->scope('room:b');
        $app->contexts['b'] = $b;
        $b->view(static function () use (&$value, &$bSeen): string {
            $bSeen[] = $value;

            return "<div id=\"b\">{$value}</div>";
        });

        inCoroutine(static function () use ($app, &$value): void {
            $until = hrtime(true) + 400_000_000;
            while (hrtime(true) < $until) {
                ++$value;
                $app->broadcast('room:a');
                Coroutine::usleep(2_000);
            }
        });

        return ['final' => $value, 'bLast' => end($bSeen), 'logs' => problems($logs), 'flags' => flags($app)];
    },

    'pre-invalidation' => static function (): array {
        $app = app();
        $value = 1;
        $contexts = [];
        // Both share the cached view of route:/pre; "multi" is also in room:x.
        foreach (['multi', 'plain'] as $id) {
            $ctx = new Context($id, '/pre', $app);
            $ctx->scope(Scope::ROUTE);
            $app->contexts[$id] = $ctx;
            $ctx->view(static function () use (&$value): string {
                return "<div id=\"pre\">v={$value}</div>";
            });
            $contexts[$id] = $ctx;
        }
        $contexts['multi']->addScope('room:x');

        inCoroutine(static function () use ($app, &$value, $contexts): void {
            $app->broadcast('room:x');
            $app->broadcast(Scope::routeScope('/pre'));
            // Refills the route:/pre cache between the mark and the flush.
            $contexts['plain']->sync();

            $value = 2;
            $app->broadcast('room:x');
        });

        $frames = frames($contexts['multi']);

        return ['multiLast' => end($frames)];
    },

    'shutdown-publisher' => static function (): array {
        $broker = new CoalesceBroker();
        $broker->yieldUs = 50_000;
        $app = app((new Config())->withBroker($broker));
        $app->onWorkerStop(static fn () => $app->broadcast('room:bye'));

        inCoroutine(static function () use ($app): void {
            $app->broadcast('room:busy');
            // The flush starts the publisher, which is now waiting on the broker.
            Coroutine::usleep(1_000);

            (new ReflectionMethod($app, 'runWorkerShutdown'))->invoke($app);
        });

        return ['events' => $broker->events];
    },

    'shutdown-budget' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker));
        $state = new CoalesceState();
        slowObservers($app, 'room:io', 10, 50, $state);
        $start = 0;
        $callbackAt = null;
        $app->onWorkerStop(static function () use (&$callbackAt): void {
            $callbackAt = hrtime(true);
        });

        inCoroutine(static function () use ($app, &$start): void {
            // Flushed at once, and now waiting on its views' I/O for 500 ms.
            Coroutine::create(static fn () => $app->broadcast('room:io'));
            Coroutine::usleep(5_000);
            // Waits for the tick.
            $app->broadcast('room:io');

            $start = hrtime(true);
            Coroutine::create(static fn () => (new ReflectionMethod($app, 'runWorkerShutdown'))->invoke($app));
            Timer::clearAll();
        });

        return [
            'callbackMs' => $callbackAt === null ? null : ($callbackAt - $start) / 1e6,
            'disconnectMs' => $broker->disconnectedAt === null ? null : ($broker->disconnectedAt - $start) / 1e6,
            'events' => $broker->events,
        ];
    },

    'trace-schedule' => static function (): array {
        $app = app((new Config())->withDevBar(true));
        $state = new CoalesceState();
        observer($app, 'obs', 'room:trace', $state);

        inCoroutine(static function () use ($app): void {
            $tracer = $app->getTracer();
            $tracer?->startTrace('POST /_action/send');
            $app->broadcast('room:trace');
            $tracer?->endTrace();
        });

        $traces = [];
        foreach ($app->getTraceStore()?->recent() ?? [] as $trace) {
            $traces[$trace['label']] = array_map(static fn (array $span): array => [$span['name'], $span['attributes']], $trace['spans']);
        }

        return ['traces' => $traces];
    },

    'throttle-burst' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(5)->withBroadcastThrottle('import:*', 100));
        $value = 0;
        $starts = [];
        $seen = [];
        $ctx = new Context('imp', '/imp', $app);
        $ctx->scope('import:42');
        $app->contexts['imp'] = $ctx;
        $ctx->view(static function () use (&$value, &$starts, &$seen): string {
            if (Coroutine::getCid() > 0) {
                $starts[] = hrtime(true);
                $seen[] = $value;
            }

            return "<div id=\"imp\">{$value}</div>";
        });
        $state = new CoalesceState();
        observer($app, 'free', 'room:free', $state);

        inCoroutine(static function () use ($app, &$value): void {
            $until = hrtime(true) + 400_000_000;
            while (hrtime(true) < $until) {
                ++$value;
                $app->broadcast('import:42');
                $app->broadcast('room:free');
                Coroutine::usleep(5_000);
            }
        });

        $gaps = [];
        for ($i = 1; $i < count($starts); ++$i) {
            $gaps[] = ($starts[$i] - $starts[$i - 1]) / 1e6;
        }

        return [
            'renders' => count($starts),
            'minGapMs' => $gaps === [] ? null : min($gaps),
            'lastSeen' => end($seen),
            'final' => $value,
            'freeRenders' => $state->renders['free'] ?? 0,
            'flags' => flags($app),
            'throttleTimer' => (new ReflectionProperty(Via::class, 'throttleTimerId'))->getValue($app),
            'throttledAt' => (new ReflectionProperty(Via::class, 'throttledAt'))->getValue($app),
        ];
    },

    'throttle-trailing' => static function (): array {
        $app = app((new Config())->withBroadcastThrottle('import:1', 100));
        $starts = [];
        $ctx = new Context('imp', '/imp', $app);
        $ctx->scope('import:1');
        $app->contexts['imp'] = $ctx;
        $ctx->view(static function () use (&$starts): string {
            $starts[] = hrtime(true);

            return '<div id="imp">x</div>';
        });

        $sentAt = inCoroutine(static function () use ($app): int {
            $app->broadcast('import:1');
            Coroutine::usleep(10_000);
            $sentAt = hrtime(true);
            $app->broadcast('import:1');

            return $sentAt;
        });

        return [
            'renders' => count($starts),
            'leadingMs' => isset($starts[0]) ? ($sentAt - $starts[0]) / 1e6 : null,
            'trailingAfterMs' => isset($starts[1]) ? ($starts[1] - $starts[0]) / 1e6 : null,
        ];
    },

    'throttle-flush' => static function (): array {
        $app = app((new Config())->withBroadcastThrottle('import:*', 1000));
        $state = new CoalesceState();
        observer($app, 'imp', 'import:9', $state);

        $renders = inCoroutine(static function () use ($app, $state): array {
            $app->broadcast('import:9');
            Coroutine::usleep(5_000);
            $first = $state->renders['imp'] ?? 0;
            $app->broadcast('import:9');
            Coroutine::usleep(20_000);
            $held = $state->renders['imp'] ?? 0;
            $app->flushBroadcasts();

            return ['first' => $first, 'held' => $held, 'flushed' => $state->renders['imp'] ?? 0];
        });

        return [...$renders, 'final' => $state->renders['imp'] ?? 0];
    },

    'throttle-received' => static function (): array {
        $broker = new CoalesceBroker();
        $app = app((new Config())->withBroker($broker)->withBroadcastTickMs(5)->withBroadcastThrottle('import:*', 100));
        $state = new CoalesceState();
        observer($app, 'imp', 'import:7', $state);

        inCoroutine(static function () use ($broker): void {
            $until = hrtime(true) + 250_000_000;
            while (hrtime(true) < $until) {
                ($broker->handler)('import:7');
                Coroutine::usleep(5_000);
            }
        });

        return ['renders' => $state->renders['imp'] ?? 0];
    },

    'throttle-signal-writes' => static function (): array {
        $app = app((new Config())->withBroadcastTickMs(5)->withBroadcastThrottle('import:*', 100));
        $state = new CoalesceState();
        $actor = new Context('actor', '/actor', $app);
        $progress = $actor->signal(0, 'progress', 'import:5');
        observer($app, 'o1', 'import:5', $state);

        inCoroutine(static function () use ($progress): void {
            for ($i = 1; $i <= 5; ++$i) {
                $progress->setValue($i);
                Coroutine::usleep(10_000);
            }
        });

        return ['renders' => $state->renders['o1'] ?? 0];
    },

    'throttle-coalescing-off' => static function (): array {
        $app = app((new Config())->withBroadcastCoalescing(false)->withBroadcastThrottle('import:*', 100));
        $state = new CoalesceState();
        observer($app, 'imp', 'import:3', $state);
        observer($app, 'free', 'room:free', $state);

        $sync = inCoroutine(static function () use ($app, $state): array {
            for ($i = 0; $i < 5; ++$i) {
                $app->broadcast('import:3');
                $app->broadcast('room:free');
            }

            return $state->renders;
        });

        return ['sync' => $sync, 'final' => $state->renders];
    },
];

$case = (string) ($argv[1] ?? '');

try {
    $result = isset($cases[$case]) ? $cases[$case]() : ['error' => "unknown case \"{$case}\""];
} catch (Throwable $e) {
    $result = ['error' => Logger::describe($e)];
}

echo json_encode($result), "\n";
