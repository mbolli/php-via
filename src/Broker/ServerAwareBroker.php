<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

/**
 * Marker interface for brokers that need access to the OpenSwoole server instance.
 *
 * Via calls setServer() in workerStart, after connect(), so the server reference
 * and worker identity are available before any publish() calls can occur.
 */
interface ServerAwareBroker {
    /**
     * Inject the OpenSwoole server reference and current worker identity.
     *
     * @param object $server    The running OpenSwoole HTTP server (OpenSwoole\Http\Server)
     * @param int    $workerId  This worker's ID (0-indexed)
     * @param int    $workerNum Total number of workers
     */
    public function setServer(object $server, int $workerId, int $workerNum): void;
}
