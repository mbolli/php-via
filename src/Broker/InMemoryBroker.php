<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

/**
 * No-op broker for single-node deployments (default).
 *
 * All methods are no-ops. publish() does nothing because there are no other
 * nodes to notify. This is the correct default: single-node Via deployments
 * require zero configuration and have zero broker overhead.
 */
final class InMemoryBroker implements MessageBroker {
    private readonly string $nodeId;
    private bool $connected = false;

    public function __construct() {
        $this->nodeId = bin2hex(random_bytes(8));
    }

    public function connect(): void {
        $this->connected = true;
        // No-op: single-node, no backend to connect to.
    }

    public function disconnect(): void {
        $this->connected = false;
        // No-op.
    }

    public function publish(string $scope): void {
        // No-op: no other nodes exist to notify.
    }

    public function subscribe(callable $handler): void {
        // No-op: no foreign messages will ever arrive.
    }

    public function getNodeId(): string {
        return $this->nodeId;
    }

    public function isConnected(): bool {
        return $this->connected;
    }
}
