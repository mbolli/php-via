<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\NatsBroker;
use OpenSwoole\Coroutine;

/**
 * Integration test for NatsBroker.
 *
 * Requires a NATS server reachable on 127.0.0.1:4222 (see NATS_INSTALL.md).
 * Skipped automatically when NATS is unavailable (CI without NATS, local dev).
 *
 * Must run inside an OpenSwoole coroutine context because NatsBroker::connect()
 * spawns a read-loop coroutine via Coroutine::create().
 */

function natsAvailable(): bool {
    $sock = @fsockopen('127.0.0.1', 4222, $errno, $errstr, 0.5);
    if ($sock !== false) {
        fclose($sock);

        return true;
    }

    return false;
}

describe('NatsBroker', function (): void {
    beforeEach(function (): void {
        if (!natsAvailable()) {
            $this->markTestSkipped('NATS not available on 127.0.0.1:4222');
        }
    });

    test('foreign message reaches subscriber on other broker instance', function (): void {
        $received = null;

        Coroutine::run(function () use (&$received): void {
            $brokerA = new NatsBroker('127.0.0.1', 4222);
            $brokerB = new NatsBroker('127.0.0.1', 4222);

            $brokerB->subscribe(function (string $scope) use (&$received): void {
                $received = $scope;
            });

            $brokerA->connect();
            $brokerB->connect();

            // Allow the read-loop coroutines to start before publishing.
            usleep(50000);

            $brokerA->publish('route:/test');

            // Poll for the message (up to 2 seconds) so the test isn't flaky.
            $deadline = microtime(true) + 2.0;
            while ($received === null && microtime(true) < $deadline) {
                usleep(10000);
            }

            $brokerA->disconnect();
            $brokerB->disconnect();
        });

        expect($received)->toBe('route:/test');
    });

    test('own messages are filtered (nodeId check)', function (): void {
        $selfReceived = false;

        Coroutine::run(function () use (&$selfReceived): void {
            $broker = new NatsBroker('127.0.0.1', 4222);

            $broker->subscribe(function (string $scope) use (&$selfReceived): void {
                $selfReceived = true;
            });

            $broker->connect();
            usleep(50000);

            $broker->publish('global');

            usleep(100000);
            $broker->disconnect();
        });

        expect($selfReceived)->toBeFalse('own messages must be filtered by nodeId');
    });
});
