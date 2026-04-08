<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

use OpenSwoole\Coroutine;

/**
 * Redis pub/sub broker for multi-node broadcasting.
 *
 * Uses ext-redis (phpredis) with OpenSwoole's coroutine hook (SWOOLE_HOOK_ALL),
 * which makes all blocking ext-redis calls coroutine-compatible automatically.
 * Via already sets hook_flags => SWOOLE_HOOK_ALL in its server configuration.
 *
 * Note: OpenSwoole\Coroutine\Redis is deprecated since OpenSwoole v4.3+.
 * The recommended approach is ext-redis + HOOK_TCP (included in HOOK_ALL).
 * See: https://openswoole.com/docs/modules/swoole-coroutine-redis
 *
 * Two connections are required because subscribing blocks the connection for
 * reads — the publish connection remains free for sending messages.
 *
 * What crosses the wire: JSON {"scope":"...","nodeId":"..."} only.
 * State is NOT serialised — each receiving node re-renders from its own
 * local state. Shared mutable state must live in Redis or another external
 * store for cross-node reads to be consistent.
 *
 * Usage:
 *   (new Config())->withBroker(new RedisBroker())
 *   (new Config())->withBroker(new RedisBroker('redis-host', 6379))
 *
 * Requirements:
 *   - ext-redis PHP extension (php8.x-redis package)
 *   - Redis server running and reachable
 *   - Must be started from within an OpenSwoole coroutine context (workerStart)
 *
 * Limitation: Redis pub/sub has no message history. Messages published before
 * a node subscribes are lost. Use NatsBroker with JetStream for durability.
 */
final class RedisBroker implements MessageBroker {
    private const string CHANNEL = 'via:broadcast';

    private readonly string $nodeId;

    private ?\Redis $pubConn = null;
    private ?\Redis $subConn = null;

    /** @var callable(string $scope): void|null */
    private $handler = null;

    private bool $running = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
    ) {
        $this->nodeId = bin2hex(random_bytes(8));
    }

    public function connect(): void {
        $this->pubConn = new \Redis();
        if (!$this->pubConn->connect($this->host, $this->port)) {
            throw new \RuntimeException("RedisBroker: failed to connect publish connection to {$this->host}:{$this->port}");
        }

        $this->subConn = new \Redis();
        if (!$this->subConn->connect($this->host, $this->port)) {
            throw new \RuntimeException("RedisBroker: failed to connect subscribe connection to {$this->host}:{$this->port}");
        }

        $this->running = true;
        $this->startReadLoop();
    }

    public function disconnect(): void {
        $this->running = false;

        if ($this->pubConn !== null) {
            try {
                $this->pubConn->close();
            } catch (\Throwable) {
                // Ignore close errors on shutdown.
            }
            $this->pubConn = null;
        }

        if ($this->subConn !== null) {
            try {
                // Closing the sub connection unblocks the subscribe callback loop.
                $this->subConn->close();
            } catch (\Throwable) {
                // Ignore close errors on shutdown.
            }
            $this->subConn = null;
        }
    }

    public function publish(string $scope): void {
        if ($this->pubConn === null) {
            return;
        }

        $payload = (string) json_encode(['scope' => $scope, 'nodeId' => $this->nodeId]);
        $this->pubConn->publish(self::CHANNEL, $payload);
    }

    public function subscribe(callable $handler): void {
        $this->handler = $handler;
    }

    public function getNodeId(): string {
        return $this->nodeId;
    }

    /**
     * Spawn a coroutine that blocks on Redis SUBSCRIBE and dispatches incoming messages.
     *
     * ext-redis subscribe() accepts a callback invoked for every received message.
     * With SWOOLE_HOOK_ALL this yields the coroutine (not the worker) while waiting.
     */
    private function startReadLoop(): void {
        $subConn = $this->subConn;

        Coroutine::create(function () use ($subConn): void {
            if ($subConn === null) {
                return;
            }

            try {
                $subConn->subscribe([self::CHANNEL], function (\Redis $redis, string $channel, string $payload): void {
                    if (!$this->running) {
                        return;
                    }

                    $data = json_decode($payload, true);

                    if (!\is_array($data) || !isset($data['scope'], $data['nodeId'])) {
                        return;
                    }

                    // Skip own messages — loop prevention.
                    if ($data['nodeId'] === $this->nodeId) {
                        return;
                    }

                    if ($this->handler !== null) {
                        ($this->handler)($data['scope']);
                    }
                });
            } catch (\RedisException) {
                // Connection was closed (e.g. by disconnect()); exit the read loop silently.
            }
        });
    }
}
