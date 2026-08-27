<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\NatsBroker;
use Mbolli\PhpVia\Broker\RedisBroker;
use Mbolli\PhpVia\Broker\SwooleBroker;

/*
 * Regression: every broker generated its nodeId in the constructor.
 *
 * Brokers are constructed in user code BEFORE $server->start(), so the workers
 * forked afterwards all inherit one nodeId. Each broker drops any incoming message
 * whose nodeId equals its own ("skip own messages — loop prevention"), so sibling
 * workers on the same machine discarded 100% of each other's messages.
 *
 * SwooleBroker: Via's pipeMessage guard dropped every pipe message.
 * Redis/NATS:   the filter is the ONLY self-skip, so same-machine cross-worker
 *               broadcast was silently dead. Cross-MACHINE still worked, because
 *               separate process trees generate different ids — which is why this
 *               survived: the multi-server path masked the multi-worker one.
 *
 * The identity must therefore be per-process, not per-object.
 */

/** @return array{0: string, 1: string} parent and forked-child nodeId */
function forkNodeIds(string $shortClass): array {
    $fixture = dirname(__DIR__, 2) . '/Fixtures/broker_fork_nodeid.php';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($shortClass));

    $parts = explode(' ', trim((string) $out));
    expect($parts)->toHaveCount(2, 'fixture output was: ' . var_export($out, true));

    return [$parts[0], $parts[1]];
}

beforeEach(function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl required to reproduce the fork');
    }
});

test('SwooleBroker gets a distinct nodeId in a forked worker', function (): void {
    [$parent, $child] = forkNodeIds('SwooleBroker');

    expect($parent)->not->toBe('');
    expect($child)->not->toBe('');
    expect($child)->not->toBe($parent);
});

test('RedisBroker gets a distinct nodeId in a forked worker', function (): void {
    [$parent, $child] = forkNodeIds('RedisBroker');

    expect($child)->not->toBe($parent);
});

test('NatsBroker gets a distinct nodeId in a forked worker', function (): void {
    [$parent, $child] = forkNodeIds('NatsBroker');

    expect($child)->not->toBe($parent);
});

test('nodeId is stable within a single process', function (): void {
    foreach ([new SwooleBroker(), new RedisBroker(), new NatsBroker()] as $broker) {
        $first = $broker->getNodeId();
        expect($broker->getNodeId())->toBe($first);
        expect($broker->getNodeId())->toBe($first);
    }
});

test('two brokers in the same process still get distinct nodeIds', function (): void {
    expect((new SwooleBroker())->getNodeId())->not->toBe((new SwooleBroker())->getNodeId());
});
