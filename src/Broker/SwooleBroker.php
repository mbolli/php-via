<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

use OpenSwoole\Http\Server;

/**
 * Intra-process broker for single-machine multi-worker deployments.
 *
 * Uses OpenSwoole's built-in inter-worker pipe (`Server::sendMessage` /
 * `onPipeMessage`) to fan-out scope invalidations across all worker processes
 * on the same machine. No external infrastructure required.
 *
 * The receive path is handled directly in Via.php via the `onPipeMessage`
 * server event (registered before `$server->start()`). Via calls
 * `syncLocally($scope)` on each receiving worker, identical to Redis/NATS.
 *
 * Lifecycle:
 *   1. Constructor: generates nodeId.
 *   2. subscribe($handler): stores handler — Via wires syncLocally() here.
 *   3. connect(): marks connected. No coroutine loop needed (receive is event-driven).
 *   4. setServer(): called by Via in workerStart; injects server ref + worker identity.
 *   5. publish($scope): fans out sendMessage() to all sibling workers.
 *
 * Limitations:
 * - Single machine only. For multi-server deployments use RedisBroker or NatsBroker.
 * - Session data is NOT shared across workers. Use a sticky-session load balancer.
 * - Worker restarts (SIGUSR1) temporarily disconnect workers — brief broadcast gaps
 *   are possible during hot reload. Acceptable for most use cases.
 */
final class SwooleBroker implements MessageBroker, ServerAwareBroker {
    private readonly string $nodeId;

    /** @var null|callable(string): void */
    private $handler;

    private bool $connected = false;

    /** @var null|Server */
    private ?object $server = null;
    private int $workerId = 0;
    private int $workerNum = 1;

    public function __construct() {
        $this->nodeId = bin2hex(random_bytes(8));
    }

    public function connect(): void {
        $this->connected = true;
        // No-op: receive path is event-driven via onPipeMessage.
    }

    public function disconnect(): void {
        $this->connected = false;
        $this->server = null;
    }

    /**
     * Fan-out the scope invalidation to all sibling workers via OpenSwoole pipes.
     *
     * The current worker's own syncLocally() is called directly by Via::broadcast()
     * before publish() is invoked, so we only send to OTHER workers.
     */
    public function publish(string $scope): void {
        if ($this->server === null || $this->workerNum <= 1) {
            return;
        }

        $payload = json_encode(['scope' => $scope, 'nodeId' => $this->nodeId]);

        if ($payload === false) {
            return;
        }

        for ($id = 0; $id < $this->workerNum; ++$id) {
            if ($id === $this->workerId) {
                continue; // skip self — Via::broadcast() already called syncLocally()
            }

            $this->server->sendMessage($payload, $id);
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

    public function setServer(object $server, int $workerId, int $workerNum): void {
        $this->server = $server;
        $this->workerId = $workerId;
        $this->workerNum = $workerNum;
    }
}
