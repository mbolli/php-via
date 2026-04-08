<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

/**
 * Interface for pluggable message brokers enabling multi-node broadcasting.
 *
 * When Via runs on more than one process (multiple workers or multiple servers),
 * calling broadcast() on one node must also trigger re-renders on all other nodes
 * that hold connections in the same scope. A MessageBroker carries the scope
 * invalidation message across process boundaries.
 *
 * What crosses the wire: {scope, nodeId} only.
 * What does NOT cross: rendered HTML, signal values, or any shared state.
 * Each receiving node re-renders its own local contexts from its own state.
 * Shared mutable state must live in an external store (Redis, DB, NATS KV)
 * for cross-node consistency.
 *
 * The nodeId is managed internally by each broker implementation. Messages
 * published by this node are filtered out on receive to prevent double-syncs.
 */
interface MessageBroker {
    /**
     * Connect to the broker backend and start any background subscription loops.
     *
     * For async brokers (Redis, NATS), this spawns the receive coroutine.
     * Must be called from within an OpenSwoole coroutine context (e.g. workerStart).
     * For InMemoryBroker this is a no-op.
     */
    public function connect(): void;

    /**
     * Disconnect from the broker backend and stop any background loops.
     */
    public function disconnect(): void;

    /**
     * Publish a scope invalidation to all other nodes.
     *
     * The broker appends its nodeId to the message automatically.
     * TAB-scoped broadcasts should not be published (no cross-node recipients).
     *
     * @param string $scope The scope string to invalidate (e.g. "route:/game", "global", "room:lobby")
     */
    public function publish(string $scope): void;

    /**
     * Register a handler to be called when a foreign node publishes a scope invalidation.
     *
     * The handler receives only the scope string — own-node messages are silently
     * filtered before the handler is invoked.
     *
     * @param callable(string $scope): void $handler
     */
    public function subscribe(callable $handler): void;

    /**
     * Return a unique identifier for this node/broker instance.
     * Used to filter own messages on receive.
     */
    public function getNodeId(): string;
}
