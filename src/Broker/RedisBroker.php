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
 *   (new Config())->withBroker(new RedisBroker('redis-host', 6379, password: 'secret'))
 *   (new Config())->withBroker(new RedisBroker('redis-host', 6379, username: 'via', password: 'secret'))
 *   (new Config())->withBroker(new RedisBroker('redis-host', 6380, tls: true))             // TLS, system CA
 *   (new Config())->withBroker(new RedisBroker('redis-host', 6380, tls: true, tlsCaFile: '/etc/ssl/ca.pem'))
 *   (new Config())->withBroker(new RedisBroker(channel: 'myapp:broadcast'))  // isolates from other apps on same Redis
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
    private const int MAX_BACKOFF = 30;

    private readonly string $nodeId;

    private ?\Redis $pubConn = null;
    private ?\Redis $subConn = null;

    /** @var null|callable(string): void */
    private $handler;

    /** @var null|callable(\Throwable): void */
    private $errorHandler;

    private bool $running = false;
    private bool $connected = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        #[\SensitiveParameter]
        private readonly ?string $password = null,
        private readonly ?string $username = null,
        private readonly string $channel = 'via:broadcast',
        private readonly bool $tls = false,
        private readonly ?string $tlsCaFile = null,
    ) {
        $this->nodeId = bin2hex(random_bytes(8));
    }

    public function connect(): void {
        $this->pubConn = $this->createRedisConnection();
        $this->subConn = $this->createRedisConnection();

        $this->connected = true;
        $this->running = true;
        $this->startReadLoop();
    }

    public function disconnect(): void {
        $this->running = false;
        $this->connected = false;

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

        try {
            $this->pubConn->publish($this->channel, $payload);
        } catch (\RedisException) {
            // Publish connection dropped — attempt one reconnect and retry.
            try {
                $this->pubConn->close();
            } catch (\Throwable) {
            }
            $this->pubConn = null;

            try {
                $this->pubConn = $this->createRedisConnection();
                $this->pubConn->publish($this->channel, $payload);
            } catch (\Throwable) {
                // Swallow — best-effort; subscribe loop will reconnect independently.
            }
        }
    }

    public function subscribe(callable $handler): void {
        $this->handler = $handler;
    }

    public function setErrorHandler(callable $handler): void {
        $this->errorHandler = $handler;
    }

    public function getNodeId(): string {
        return $this->nodeId;
    }

    public function isConnected(): bool {
        return $this->connected;
    }

    /**
     * Spawn a coroutine that blocks on Redis SUBSCRIBE and dispatches incoming messages.
     *
     * On connection loss the loop reconnects with exponential backoff (1 s → 2 s → … → 30 s cap).
     * ext-redis subscribe() accepts a callback invoked for every received message.
     * With SWOOLE_HOOK_ALL this yields the coroutine (not the worker) while waiting.
     */
    private function startReadLoop(): void {
        Coroutine::create(function (): void {
            $backoff = 1;

            while ($this->running) {
                // Reconnect if the subscribe connection was lost.
                if ($this->subConn === null) {
                    try {
                        $this->subConn = $this->createRedisConnection();
                        $this->connected = true;
                        $backoff = 1; // Reset on successful reconnect.
                    } catch (\Throwable $e) {
                        $this->notifyError($e);
                        Coroutine::sleep($backoff);
                        $backoff = min($backoff * 2, self::MAX_BACKOFF);

                        continue;
                    }
                }

                $conn = $this->subConn;

                try {
                    $conn->subscribe([$this->channel], function (\Redis $redis, string $channel, string $payload): void {
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

                    // subscribe() returned cleanly — intentional disconnect().
                    break;
                } catch (\RedisException) {
                    // $this->running may be false if disconnect() was called concurrently
                    // (coroutine context) while subscribe() was blocking.
                    /** @phpstan-ignore booleanNot.alwaysFalse */
                    if (!$this->running) {
                        break;
                    }

                    // Unexpected drop — null out and retry with backoff.
                    $this->subConn = null;
                    $this->connected = false;
                    $this->notifyError(new \RuntimeException('RedisBroker: subscribe connection dropped, reconnecting'));
                    Coroutine::sleep($backoff);
                    $backoff = min($backoff * 2, self::MAX_BACKOFF);
                }
            }
        });
    }

    private function createRedisConnection(): \Redis {
        $conn = new \Redis();

        // Build connection host: prefix with tls:// for encrypted connections.
        $connectHost = $this->tls ? 'tls://' . $this->host : $this->host;

        // SSL stream context: verify peer by default; optionally pin a CA file.
        $sslContext = $this->tls ? [
            'stream' => array_filter([
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => $this->tlsCaFile,
            ]),
        ] : [];

        if (!$conn->connect($connectHost, $this->port, 3.0, null, 0, 0, $sslContext)) {
            throw new \RuntimeException("RedisBroker: failed to connect to {$this->host}:{$this->port}");
        }

        if ($this->password !== null) {
            // Redis 6+ ACL: pass [username, password]; older Redis: pass password string.
            // auth() throws \RedisException on failure in phpredis >= 5.x.
            $credentials = $this->username !== null
                ? [$this->username, $this->password]
                : $this->password;

            try {
                $conn->auth($credentials);
            } catch (\RedisException $e) {
                throw new \RuntimeException("RedisBroker: authentication failed for {$this->host}:{$this->port}: {$e->getMessage()}", previous: $e);
            }
        }

        return $conn;
    }

    private function notifyError(\Throwable $e): void {
        if ($this->errorHandler !== null) {
            ($this->errorHandler)($e);
        }
    }
}
