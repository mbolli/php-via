<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * The Via a TestApp runs: a Via that records the scopes broadcast() is called with, and the lines it logs
 * inside a coroutine, such as a spawn() task's.
 *
 * @internal
 */
final class RecordingVia extends Via {
    /** @var list<string> */
    private array $broadcasts = [];

    /** @var null|\Closure(string): void */
    private ?\Closure $coroutineLog = null;

    public function broadcast(string $scope): void {
        // Resolved as Via::broadcast() resolves it, and throws for the same scopes.
        $this->broadcasts[] = Scope::resolve($scope, null, 'Via::broadcast()');
        parent::broadcast($scope);
    }

    public function log(string $level, string $message, ?Context $context = null): void {
        if ($this->coroutineLog === null || Coroutine::getCid() <= 0) {
            parent::log($level, $message, $context);

            return;
        }

        // A coroutine has an output buffer of its own, which the TestApp's does not see.
        ob_start();

        try {
            parent::log($level, $message, $context);
        } finally {
            ($this->coroutineLog)((string) ob_get_clean());
        }
    }

    /**
     * @param \Closure(string): void $sink receives what log() prints inside a coroutine
     */
    public function logCoroutinesTo(\Closure $sink): void {
        $this->coroutineLog = $sink;
    }

    /**
     * @return list<string> the scopes recorded since the last call, in call order
     */
    public function takeBroadcasts(): array {
        $broadcasts = $this->broadcasts;
        $this->broadcasts = [];

        return $broadcasts;
    }
}
