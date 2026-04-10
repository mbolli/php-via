<?php

declare(strict_types=1);

namespace Tests\Support;

use Mbolli\PhpVia\Broker\MessageBroker;

/**
 * Synchronous in-process broker for tests.
 *
 * All instances share a static registry. When one instance publishes,
 * all other registered instances call their handler synchronously,
 * simulating a cross-node fanout without any network or coroutine overhead.
 *
 * Usage:
 *   [$brokerA, $brokerB] = TestBroker::createLinked();
 *   $viaA = createVia((new Config())->withBroker($brokerA));
 *   $viaB = createVia((new Config())->withBroker($brokerB));
 *   // broadcast on viaA now also invokes syncLocally on viaB
 */
final class TestBroker implements MessageBroker {
    /** @var array<string, self> All connected instances, keyed by nodeId */
    private static array $registry = [];

    private readonly string $nodeId;

    /** @var null|callable(string): void */
    private $handler;

    private bool $connected = false;

    public function __construct() {
        $this->nodeId = bin2hex(random_bytes(8));
    }

    /**
     * Create two pre-linked TestBroker instances (simulates two nodes).
     * Both are already connected; just pass them to Config::withBroker().
     *
     * @return array{TestBroker, TestBroker}
     */
    public static function createLinked(): array {
        $a = new self();
        $b = new self();
        $a->connect();
        $b->connect();

        return [$a, $b];
    }

    /**
     * Reset the shared registry. Call in tearDown or afterEach to prevent
     * state leaking between tests.
     */
    public static function reset(): void {
        self::$registry = [];
    }

    public function connect(): void {
        $this->connected = true;
        self::$registry[$this->nodeId] = $this;
    }

    public function disconnect(): void {
        $this->connected = false;
        unset(self::$registry[$this->nodeId]);
    }

    public function publish(string $scope): void {
        if (!$this->connected) {
            return;
        }

        foreach (self::$registry as $nodeId => $broker) {
            if ($nodeId === $this->nodeId) {
                continue; // Skip self — own messages must not loop back.
            }

            if ($broker->handler !== null) {
                ($broker->handler)($scope);
            }
        }
    }

    public function subscribe(callable $handler): void {
        $this->handler = $handler;
    }

    public function getNodeId(): string {
        return $this->nodeId;
    }

    public function isConnected(): bool {
        return $this->connected;
    }
}
