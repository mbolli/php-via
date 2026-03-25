<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use PhpVia\Website\Support\NatsClient;

final class MissionControlExample {
    public const string SLUG = 'mission-control';

    private const string SCOPE = 'example:mission-control';

    /**
     * Services whose browser consumer uses a JetStream durable push consumer.
     * Killing these pauses the consumer only — simulator keeps publishing, JetStream buffers.
     * Reviving re-subscribes and the buffered events arrive in a burst.
     *
     * @var list<string>
     */
    private const array GUARANTEED_SERVICES = ['orders', 'payments'];

    /** @var array<string, array{emoji: string, label: string, color: string, events: list<string>}> */
    private const array SERVICES = [
        'orders' => ['emoji' => '📦', 'label' => 'Orders', 'color' => '#6366f1', 'events' => ['order.placed', 'order.shipped', 'order.cancelled']],
        'payments' => ['emoji' => '💳', 'label' => 'Payments', 'color' => '#f59e0b', 'events' => ['payment.authorized', 'payment.failed', 'payment.refunded']],
        'auth' => ['emoji' => '🔐', 'label' => 'Auth', 'color' => '#10b981', 'events' => ['login.success', 'login.failure', 'token.refresh']],
        'inventory' => ['emoji' => '📊', 'label' => 'Inventory', 'color' => '#ef4444', 'events' => ['stock.restocked', 'stock.depleted', 'stock.reserved']],
    ];

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>OpenSwoole-native NATS client</strong> — a thin coroutine-native TCP client replaces third-party NATS libraries that require incompatible event loops. The read loop runs in a dedicated coroutine and yields cooperatively on every <code>recv()</code>.',
        '<strong>Core pub/sub (best-effort)</strong> — Auth and Inventory subscribe via wildcard Core pub/sub (<code>via.events.*</code>). Fire-and-forget: if you kill one of these services its simulator stops and any events published while it is offline are gone forever.',
        '<strong>Guaranteed delivery (JetStream durable consumers)</strong> — Orders and Payments subscribe via named durable push consumers with explicit ACK. Kill = pause the consumer: the simulator keeps publishing, JetStream buffers un-ACKed messages. Revive = re-subscribe: the burst of missed events arrives immediately.',
        '<strong>JetStream persistence</strong> — the <code>VIAEVENTS</code> stream captures every message in memory. The durable consumer tracks its last-delivered sequence across subscribe/unsubscribe cycles — a revived subscriber never misses a message.',
        '<strong>KV health heartbeats</strong> — each service publishes a heartbeat to <code>$KV.viahealth.{service}</code> every 2 s. Guaranteed services always heartbeat (only the consumer is paused); best-effort services stop heartbeating when killed. The health grid reads KV age.',
        '<strong>Lazy init</strong> — the NATS connection is established on the first page load inside the HTTP coroutine, where coroutine APIs are always safe. A double-init guard (flag set before <code>Coroutine::create()</code>) prevents race conditions.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [],
        'actions' => [
            ['name' => 'kill-orders / kill-payments', 'desc' => 'Pauses the JetStream durable consumer (unsubscribes from the deliver inbox). Simulator keeps publishing; NATS buffers un-ACKed messages. Health tile stays green — the service is still alive, only the consumer is paused.'],
            ['name' => 'kill-auth / kill-inventory', 'desc' => 'Stops the event simulator entirely. Events are silently dropped — Core pub/sub has no persistence. Health tile flips to DOWN after 8 s of silence.'],
            ['name' => 'revive-{service} (×4)', 'desc' => 'Guaranteed services: re-subscribes to the deliver inbox; JetStream immediately delivers all buffered events in a burst. Best-effort: restarts the simulator and publishes a fresh KV heartbeat.'],
        ],
        'views' => [
            ['name' => 'mission_control.html.twig', 'desc' => 'Flow diagram piping services → NATS → browser, service kill/revive cards with delivery-type badges, NATS topology with health tiles, and a live event stream.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'Example handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/MissionControlExample.php'],
        ['label' => 'NatsClient', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Support/NatsClient.php'],
        ['label' => 'Template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/mission_control.html.twig'],
    ];

    // ── Shared state (static for demo; all mutated inside coroutines) ─────────

    private static ?NatsClient $nats = null;

    private static bool $initializing = false;

    private static bool $connected = false;

    /**
     * Per-service UI state.
     *
     * @var array<string, array{killed: bool, count: int, lastType: string, isEmitting: bool, emitId: int}>
     */
    private static array $serviceState = [];

    /**
     * Live event log, newest-first, capped at 30 entries.
     *
     * @var list<array{id: string, service: string, type: string, payload: array<string, mixed>, tsDisplay: string, isReplay: bool, replayOrder: int}>
     */
    private static array $eventLog = [];

    /**
     * Approximate count of events persisted in the VIAEVENTS stream.
     * Incremented on each received event; updated by replay.
     */
    private static int $streamCount = 0;

    /**
     * Last-seen KV heartbeat timestamp per service.
     *
     * @var array<string, float>
     */
    private static array $kvHealth = [];

    /** Timer ID for KV heartbeat publishing, or -1 if not started. */
    private static int $kvTimerId = -1;

    /** Timer ID for the health broadcast tick, or -1 if not started. */
    private static int $healthTimerId = -1;

    /**
     * JetStream subscription SIDs for guaranteed-delivery services.
     * Kept so we can unsubscribe (kill) and re-subscribe (revive) per service.
     *
     * @var array<string, int>
     */
    private static array $jsConsumerSids = [];

    /**
     * Audit logger: independent NATS subscriber counting events per type.
     *
     * @var array<string, int>
     */
    private static array $auditLog = [];

    private static int $auditTotal = 0;

    // ── Registration ───────────────────────────────────────────────────────────

    public static function register(Via $app): void {
        $app->onShutdown(static function (): void {
            self::cleanup();
        });

        $app->page('/examples/mission-control', function (Context $c) use ($app): void {
            // Lazy connect on first page load — inside HTTP coroutine, always safe.
            if (self::$nats === null && !self::$initializing) {
                self::$initializing = true;
                Coroutine::create(fn () => self::init($app));
            }

            $c->addScope(self::SCOPE);

            // ── Actions ───────────────────────────────────────────────────────

            /** @var array<string, string> $killUrls */
            $killUrls = [];

            /** @var array<string, string> $reviveUrls */
            $reviveUrls = [];

            foreach (array_keys(self::SERVICES) as $serviceKey) {
                $killUrls[$serviceKey] = $c->action(
                    function () use ($serviceKey, $app): void {
                        if (!isset(self::$serviceState[$serviceKey])) {
                            return;
                        }

                        self::$serviceState[$serviceKey]['killed'] = true;
                        self::$serviceState[$serviceKey]['isEmitting'] = false;

                        if (\in_array($serviceKey, self::GUARANTEED_SERVICES, true)) {
                            // Pause the JetStream consumer: unsubscribe from the delivery inbox.
                            // The durable consumer persists in NATS and buffers new events;
                            // JetStream redelivers them when we re-subscribe on revive.
                            if (isset(self::$jsConsumerSids[$serviceKey]) && self::$nats !== null) {
                                self::$nats->unsubscribe(self::$jsConsumerSids[$serviceKey]);
                                unset(self::$jsConsumerSids[$serviceKey]);
                            }
                            // Note: simulator keeps running — events accumulate in JetStream.
                        }
                        // Best-effort services: simulator is skipped in runSimulator() when killed.

                        $app->broadcast(self::SCOPE);
                    },
                    'kill-' . $serviceKey,
                )->url();

                $reviveUrls[$serviceKey] = $c->action(
                    function () use ($serviceKey, $app): void {
                        if (!isset(self::$serviceState[$serviceKey])) {
                            return;
                        }

                        self::$serviceState[$serviceKey]['killed'] = false;

                        if (\in_array($serviceKey, self::GUARANTEED_SERVICES, true)) {
                            // Re-subscribe to the delivery inbox.
                            // JetStream immediately delivers all buffered (un-ACKed) events.
                            if (!isset(self::$jsConsumerSids[$serviceKey]) && self::$nats !== null) {
                                self::$jsConsumerSids[$serviceKey] = self::$nats->subscribeJs(
                                    "_via.ui.{$serviceKey}",
                                    function (string $subject, string $payload) use ($app): void {
                                        self::handleEvent($subject, $payload, $app);
                                    },
                                );
                            }
                        } else {
                            // Best-effort: publish an immediate heartbeat so the tile flips back quickly
                            if (self::$nats !== null) {
                                self::$nats->publish(
                                    "\$KV.viahealth.{$serviceKey}",
                                    (string) json_encode(['ts' => microtime(true), 'service' => $serviceKey]),
                                );
                            }
                        }

                        $app->broadcast(self::SCOPE);
                    },
                    'revive-' . $serviceKey,
                )->url();
            }

            // ── View ──────────────────────────────────────────────────────────

            $c->view(function () use ($c, $killUrls, $reviveUrls): string {
                $now = microtime(true);
                $serviceData = [];

                foreach (self::SERVICES as $key => $svc) {
                    $state = self::$serviceState[$key] ?? [
                        'killed' => false,
                        'count' => 0,
                        'lastType' => '',
                        'isEmitting' => false,
                        'emitId' => 0,
                    ];

                    $serviceData[$key] = [
                        'emoji' => $svc['emoji'],
                        'label' => $svc['label'],
                        'color' => $svc['color'],
                        'guaranteed' => \in_array($key, self::GUARANTEED_SERVICES, true),
                        'killed' => $state['killed'],
                        'count' => $state['count'],
                        'lastType' => $state['lastType'],
                        'isEmitting' => $state['isEmitting'],
                        'emitId' => $state['emitId'],
                        'killUrl' => $killUrls[$key],
                        'reviveUrl' => $reviveUrls[$key],
                    ];
                }

                $health = [];

                foreach (self::SERVICES as $key => $svc) {
                    $lastTs = self::$kvHealth[$key] ?? 0.0;
                    $age = $now - $lastTs;
                    $status = match (true) {
                        $lastTs === 0.0 => 'down',
                        $age > 8.0 => 'down',
                        $age > 4.0 => 'warn',
                        default => 'up',
                    };

                    $health[$key] = [
                        'emoji' => $svc['emoji'],
                        'label' => $svc['label'],
                        'status' => $status,
                        'age' => $lastTs > 0.0 ? (string) round($age, 1) : null,
                    ];
                }

                $anyEmitting = \count(array_filter($serviceData, fn (array $s) => $s['isEmitting'])) > 0;

                return $c->render('examples/mission_control.html.twig', [
                    'title' => '🛰 NATS Visualizer',
                    'description' => 'Four simulated microservices publish events over NATS. Watch Core pub/sub, JetStream persistence, and KV health heartbeats update in real time.',
                    'summary' => self::SUMMARY,
                    'anatomy' => self::ANATOMY,
                    'githubLinks' => self::GITHUB_LINKS,
                    'connected' => self::$connected,
                    'services' => $serviceData,
                    'health' => $health,
                    'events' => self::$eventLog,
                    'streamCount' => self::$streamCount,
                    'anyEmitting' => $anyEmitting,
                    'auditLog' => self::$auditLog,
                    'auditTotal' => self::$auditTotal,
                ]);
            }, block: 'demo', cacheUpdates: false);
        });
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    public static function cleanup(): void {
        self::$connected = false;

        if (self::$kvTimerId !== -1) {
            Timer::clear(self::$kvTimerId);
            self::$kvTimerId = -1;
        }

        if (self::$healthTimerId !== -1) {
            Timer::clear(self::$healthTimerId);
            self::$healthTimerId = -1;
        }

        self::$nats?->close();
        self::$nats = null;
    }

    // ── Initialisation (runs inside a coroutine) ───────────────────────────────

    private static function init(Via $app): void {
        try {
            error_log('[MissionControl] init() starting, connecting to NATS...');
            $nats = new NatsClient();
            $nats->connect();
            error_log('[MissionControl] TCP connected, starting read loop...');
            $nats->startReadLoop();
            error_log('[MissionControl] Read loop running, setting up JetStream stream...');
            $nats->ensureStream('VIAEVENTS', 'via.events.>', 500);
            error_log('[MissionControl] Stream ready, setting up KV bucket...');
            $nats->ensureKvBucket('viahealth');
            error_log('[MissionControl] KV ready — fully connected.');

            self::$nats = $nats;
            self::$connected = true;

            // Push the connected status to any clients already watching
            $app->broadcast(self::SCOPE);

            // Initialise per-service state if not already set
            foreach (array_keys(self::SERVICES) as $key) {
                self::$serviceState[$key] ??= [
                    'killed' => false,
                    'count' => 0,
                    'lastType' => '',
                    'isEmitting' => false,
                    'emitId' => 0,
                ];
            }

            // Best-effort services: Core pub/sub (fire-and-forget)
            foreach (array_keys(self::SERVICES) as $key) {
                if (!\in_array($key, self::GUARANTEED_SERVICES, true)) {
                    $nats->subscribe("via.events.{$key}", function (string $subject, string $payload) use ($app): void {
                        self::handleEvent($subject, $payload, $app);
                    });
                }
            }

            // Guaranteed delivery services: JetStream durable push consumers.
            // A named durable consumer persists in NATS; unsubscribing (kill) causes JetStream
            // to buffer messages and redeliver when we re-subscribe (revive).
            foreach (self::GUARANTEED_SERVICES as $key) {
                $deliverSubject = "_via.ui.{$key}";
                $nats->ensureDurableConsumer('VIAEVENTS', "ui-{$key}", "via.events.{$key}", $deliverSubject);
                self::$jsConsumerSids[$key] = $nats->subscribeJs(
                    $deliverSubject,
                    function (string $subject, string $payload) use ($app): void {
                        self::handleEvent($subject, $payload, $app);
                    },
                );
            }

            // Subscribe Core: KV heartbeats (KV puts are visible as Core messages)
            $nats->subscribe('$KV.viahealth.*', static function (string $subject, string $payload): void {
                $parts = explode('.', $subject);
                $service = end($parts);

                if (isset(self::SERVICES[$service])) {
                    self::$kvHealth[$service] = microtime(true);
                }
            });

            // Second independent subscriber: Audit Logger
            // A separate NATS subscription (new sid) on the same subject — NATS
            // fans out every published message to all matching subscribers independently.
            $nats->subscribe('via.events.*', static function (string $subject, string $payload): void {
                $data = json_decode($payload, true);
                $type = \is_array($data) ? (string) ($data['type'] ?? 'unknown') : 'unknown';
                self::$auditLog[$type] = (self::$auditLog[$type] ?? 0) + 1;
                ++self::$auditTotal;
            });

            // Publish KV heartbeats every 2 s.
            // Guaranteed services always heartbeat (only their consumer is paused, not the service).
            // Best-effort services stop heartbeating when killed.
            self::$kvTimerId = Timer::tick(2000, static function () use ($nats): void {
                foreach (array_keys(self::SERVICES) as $key) {
                    $killed = self::$serviceState[$key]['killed'] ?? false;
                    $isGuaranteed = \in_array($key, self::GUARANTEED_SERVICES, true);

                    if (!$killed || $isGuaranteed) {
                        $nats->publish(
                            "\$KV.viahealth.{$key}",
                            (string) json_encode(['ts' => microtime(true), 'service' => $key]),
                        );
                    }
                }
            });

            // Run event simulator in its own coroutine
            Coroutine::create(fn () => self::runSimulator($app));

            // Broadcast health state every 3 s so tiles flip to DOWN even when all services
            // are killed (no events fire, so without this tick the tiles freeze)
            self::$healthTimerId = Timer::tick(3000, static function () use ($app): void {
                $app->broadcast(self::SCOPE);
            });
        } catch (\Throwable $e) {
            error_log('[MissionControl] init() FAILED: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            self::$initializing = false;
            self::$connected = false;
        }
    }

    // ── Event handler (called from read-loop coroutine) ────────────────────────

    private static function handleEvent(string $subject, string $payload, Via $app): void {
        $data = json_decode($payload, true);

        if (!\is_array($data)) {
            return;
        }

        // subject pattern: via.events.{service}
        $parts = explode('.', $subject);
        $service = $parts[2] ?? '';

        if (!isset(self::SERVICES[$service])) {
            return;
        }

        $eventType = (string) ($data['type'] ?? 'unknown');
        $ts = microtime(true);
        $ms = str_pad((string) (int) (fmod($ts, 1) * 1000), 3, '0', STR_PAD_LEFT);

        // Update service state
        if (isset(self::$serviceState[$service])) {
            ++self::$serviceState[$service]['count'];
            self::$serviceState[$service]['lastType'] = $eventType;
            self::$serviceState[$service]['isEmitting'] = true;
            ++self::$serviceState[$service]['emitId'];
        }

        // Prepend to event log (newest-first), cap at 30
        array_unshift(self::$eventLog, [
            'id' => bin2hex(random_bytes(4)),
            'service' => $service,
            'type' => $eventType,
            'payload' => $data,
            'tsDisplay' => date('H:i:s', (int) $ts) . '.' . $ms,
            'isReplay' => false,
            'replayOrder' => 0,
        ]);

        if (\count(self::$eventLog) > 30) {
            self::$eventLog = \array_slice(self::$eventLog, 0, 30);
        }

        ++self::$streamCount;

        $app->broadcast(self::SCOPE);

        // Clear the emit ring after the CSS animation completes (~850 ms)
        $capturedEmitId = self::$serviceState[$service]['emitId'];
        Timer::after(850, function () use ($service, $capturedEmitId, $app): void {
            if (!isset(self::$serviceState[$service])) {
                return;
            }

            if (self::$serviceState[$service]['emitId'] !== $capturedEmitId) {
                return; // a newer event arrived — leave isEmitting=true
            }

            self::$serviceState[$service]['isEmitting'] = false;
            $app->broadcast(self::SCOPE);
        });
    }

    // ── Event simulator (dedicated coroutine) ─────────────────────────────────

    private static function runSimulator(Via $app): void {
        while (self::$connected) {
            /** @var list<string> $active */
            $active = array_keys(array_filter(
                self::$serviceState,
                // Guaranteed services always publish (consumer paused ≠ service down)
                static fn (array $state, string $key): bool => !$state['killed'] || \in_array($key, self::GUARANTEED_SERVICES, true),
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($active === []) {
                usleep(1_000_000);

                continue;
            }

            $serviceKey = $active[array_rand($active)];
            $svc = self::SERVICES[$serviceKey];
            $eventType = $svc['events'][array_rand($svc['events'])];

            self::$nats?->publish(
                "via.events.{$serviceKey}",
                (string) json_encode(self::buildPayload($serviceKey, $eventType)),
            );

            usleep(random_int(800_000, 2_500_000));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPayload(string $serviceKey, string $eventType): array {
        $base = ['service' => $serviceKey, 'type' => $eventType, 'ts' => microtime(true)];

        return match ($serviceKey) {
            'orders' => $base + ['orderId' => 'ORD-' . strtoupper(bin2hex(random_bytes(3))), 'amount' => random_int(10, 499)],
            'payments' => $base + ['orderId' => 'ORD-' . strtoupper(bin2hex(random_bytes(3))), 'amount' => random_int(10, 499), 'currency' => 'USD'],
            'auth' => $base + ['userId' => random_int(1000, 9999), 'user' => 'user' . random_int(10, 99)],
            'inventory' => $base + ['sku' => 'SKU-' . strtoupper(bin2hex(random_bytes(2))), 'qty' => random_int(1, 100)],
            default => $base,
        };
    }
}
