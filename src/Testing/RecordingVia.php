<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * The Via a TestApp runs: a Via that records the scopes broadcast() is called with.
 *
 * @internal
 */
final class RecordingVia extends Via {
    /** @var list<string> */
    private array $broadcasts = [];

    public function broadcast(string $scope): void {
        // Resolved as Via::broadcast() resolves it, and throws for the same scopes.
        $this->broadcasts[] = Scope::resolve($scope, null, 'Via::broadcast()');
        parent::broadcast($scope);
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
