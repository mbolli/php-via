<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

/**
 * Fork-safe node identity for brokers.
 *
 * Every broker tags outgoing messages with its nodeId and drops incoming messages
 * carrying that same id ("skip own messages: loop prevention").
 *
 * Brokers are constructed in user code *before* `$server->start()`, so generating
 * the id in the constructor means every worker forked afterwards inherits it, and
 * therefore discards 100% of its siblings' messages. Same-machine cross-worker
 * broadcast dies silently; cross-machine keeps working because separate process
 * trees produce different ids, which is exactly what masked the bug.
 *
 * The id is therefore generated lazily and re-generated whenever the owning PID
 * changes. That is fork-safe by construction and needs no lifecycle hook, so it
 * cannot be defeated by a broker that is connected, reconnected, or never
 * connected at all.
 */
trait NodeIdentity {
    private ?string $nodeId = null;

    /** PID that generated the current nodeId; 0 until first generation. */
    private int $nodeIdPid = 0;

    /**
     * Stable within a process, distinct across forks.
     */
    public function getNodeId(): string {
        $pid = getmypid();
        $pid = $pid === false ? 0 : $pid;

        if ($this->nodeId === null || $this->nodeIdPid !== $pid) {
            $this->nodeId = bin2hex(random_bytes(8));
            $this->nodeIdPid = $pid;
        }

        return $this->nodeId;
    }
}
