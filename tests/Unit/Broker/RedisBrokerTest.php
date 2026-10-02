<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\RedisBroker;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

/**
 * Integration test for RedisBroker.
 *
 * Requires a Redis server reachable on 127.0.0.1:6379.
 * Skipped automatically when Redis is unavailable (CI without Redis, local dev).
 *
 * Must run inside an OpenSwoole coroutine context because RedisBroker::connect()
 * spawns a receive-loop coroutine via Coroutine::create().
 *
 * SWOOLE_HOOK_TCP must be enabled so ext-redis (phpredis) socket calls are
 * intercepted and made coroutine-compatible. Via's default hook flags include
 * it at server startup; in tests we enable them explicitly.
 */
function redisAvailable(): bool {
    $sock = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);
    if ($sock !== false) {
        fclose($sock);

        return true;
    }

    return false;
}

describe('RedisBroker', function (): void {
    beforeEach(function (): void {
        if (!redisAvailable()) {
            $this->markTestSkipped('Redis not available on 127.0.0.1:6379');
        }

        // Enable coroutine TCP hook so ext-redis socket I/O yields coroutines
        // instead of blocking the process, as Via::start() does with its default hook_flags.
        Runtime::enableCoroutine(true, Via::defaultHookFlags());
    });

    test('foreign message reaches subscriber on other broker instance', function (): void {
        $received = null;

        Coroutine::run(function () use (&$received): void {
            $brokerA = new RedisBroker('127.0.0.1', 6379);
            $brokerB = new RedisBroker('127.0.0.1', 6379);

            $brokerB->subscribe(function (string $scope) use (&$received): void {
                $received = $scope;
            });

            $brokerA->connect();
            $brokerB->connect();

            // Allow the subscribe coroutine to register with Redis before publishing.
            usleep(50000);

            $brokerA->publish('route:/test');

            // Allow the read-loop coroutine to process the message.
            usleep(100000);

            $brokerA->disconnect();
            $brokerB->disconnect();
        });

        expect($received)->toBe('route:/test');
    });

    test('own messages are filtered (nodeId check)', function (): void {
        $selfReceived = false;

        Coroutine::run(function () use (&$selfReceived): void {
            $broker = new RedisBroker('127.0.0.1', 6379);

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
