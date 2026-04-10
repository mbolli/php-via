<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Tests\Support\TestBroker;

/*
 * Multi-Node Scaling Tests
 *
 * Uses TestBroker — a synchronous in-process broker that simulates two
 * isolated Via instances (nodes) communicating via a shared registry.
 *
 * No real Redis or NATS required; TestBroker::publish() is synchronous,
 * so assertions can be made immediately after broadcast().
 */

beforeEach(function (): void {
    TestBroker::reset();
});

afterEach(function (): void {
    TestBroker::reset();
});

describe('Multi-node broadcast via TestBroker', function (): void {
    test('broadcast on nodeA syncs contexts registered only on nodeB', function (): void {
        [$brokerA, $brokerB] = TestBroker::createLinked();

        $viaA = createVia((new Config())->withBroker($brokerA));
        $viaB = createVia((new Config())->withBroker($brokerB));

        // Register a context on nodeB only
        $rendered = 0;
        $ctxB = new Context('ctx-b-1', '/test', $viaB);
        $ctxB->scope(Scope::ROUTE);
        $viaB->contexts['ctx-b-1'] = $ctxB;
        $ctxB->view(function () use (&$rendered): string {
            ++$rendered;

            return '<div id="out">rendered:' . $rendered . '</div>';
        });
        $ctxB->renderView(); // initial render, no patch queued

        // Broadcast from nodeA (has no local contexts matching this scope)
        $viaA->broadcast('route:/test');

        // NodeB must have received the invalidation and synced its context
        $patch = $ctxB->getPatch();
        expect($patch)->not->toBeNull('nodeB context must receive a patch from nodeA broadcast');
        expect($patch['type'])->toBe('elements');
        expect($patch['content'])->toContain('rendered:2');
    });

    test('own contexts are NOT double-synced after broadcast', function (): void {
        [$brokerA, $brokerB] = TestBroker::createLinked();

        $viaA = createVia((new Config())->withBroker($brokerA));
        $viaB = createVia((new Config())->withBroker($brokerB));

        $renderCount = 0;
        $ctxA = new Context('ctx-a-1', '/test', $viaA);
        $ctxA->scope(Scope::ROUTE);
        $viaA->contexts['ctx-a-1'] = $ctxA;
        $ctxA->view(function () use (&$renderCount): string {
            ++$renderCount;

            return '<div id="out">count:' . $renderCount . '</div>';
        });
        $ctxA->renderView(); // initial render

        // broadcast() calls syncLocally (1 patch) then broker->publish() which
        // calls nodeB->syncLocally() — NOT nodeA->syncLocally() again.
        $viaA->broadcast('route:/test');

        // Exactly one patch queued, not two
        $patch1 = $ctxA->getPatch();
        $patch2 = $ctxA->getPatch();

        expect($patch1)->not->toBeNull('one patch must be queued');
        expect($patch2)->toBeNull('must NOT be a second patch — double-sync prevented by nodeId filter');
    });

    test('wildcard scope propagates across nodes', function (): void {
        [$brokerA, $brokerB] = TestBroker::createLinked();

        $viaA = createVia((new Config())->withBroker($brokerA));
        $viaB = createVia((new Config())->withBroker($brokerB));

        // NodeB has a context on "room:lobby"
        $ctxB = new Context('ctx-b-room', '/chat', $viaB);
        $ctxB->scope('room:lobby');
        $viaB->contexts['ctx-b-room'] = $ctxB;
        $ctxB->view(fn () => '<div id="room">hello</div>');
        $ctxB->renderView();

        // NodeA broadcasts to wildcard "room:*"
        $viaA->broadcast('room:*');

        $patch = $ctxB->getPatch();
        expect($patch)->not->toBeNull('wildcard scope must reach nodeB context in room:lobby');
        expect($patch['type'])->toBe('elements');
    });

    test('TAB scope is never published to broker (no cross-node loopback)', function (): void {
        [$brokerA, $brokerB] = TestBroker::createLinked();

        $viaA = createVia((new Config())->withBroker($brokerA));
        $viaB = createVia((new Config())->withBroker($brokerB));

        $brokerBCalled = false;
        $brokerB->subscribe(function (string $scope) use (&$brokerBCalled): void {
            $brokerBCalled = true;
        });

        // Broadcasting TAB scope should not reach nodeB
        $viaA->broadcast(Scope::TAB);

        expect($brokerBCalled)->toBeFalse('TAB scope must not be published to broker');
    });

    test('disconnected broker receives no messages', function (): void {
        [$brokerA, $brokerB] = TestBroker::createLinked();

        $viaA = createVia((new Config())->withBroker($brokerA));
        $viaB = createVia((new Config())->withBroker($brokerB));

        $received = false;
        $brokerB->subscribe(function (string $scope) use (&$received): void {
            $received = true;
        });

        // Disconnect brokerB before broadcast
        $brokerB->disconnect();

        $viaA->broadcast('global');

        expect($received)->toBeFalse('disconnected broker must not receive messages');
    });
});
