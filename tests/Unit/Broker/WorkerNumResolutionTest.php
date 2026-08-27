<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Via;

/*
 * Regression: Via read $server->worker_num when wiring ServerAwareBroker.
 *
 * ext-openswoole 26 exposes the worker count as $server->setting['worker_num'];
 * there is no worker_num property. The read produced
 *   "PHP Warning: Undefined property: OpenSwoole\Http\Server::$worker_num"
 * and then a TypeError inside SwooleBroker::setServer() ("Argument #3 ($workerNum)
 * must be of type int, null given"), which the surrounding try/catch swallowed as
 * "Broker connect failed ... running without multi-node broadcast".
 *
 * Result: $this->server stayed null in the broker, publish() returned early forever,
 * and there were ZERO cross-worker broadcasts.
 *
 * The pre-existing SwooleBrokerTest spy declares a worker_num property, so the test
 * double modelled a server shape the extension does not have — which is why this was
 * never caught. These spies model the real one.
 */

/** ext-openswoole 26: worker count lives in the setting array. */
function openswoole26ServerSpy(int $workerNum = 4): object {
    return new class($workerNum) {
        /** @var array<string, mixed> */
        public array $setting;

        /** @var list<array{string, int}> */
        public array $calls = [];

        public function __construct(int $workerNum) {
            $this->setting = ['worker_num' => $workerNum, 'backlog' => 4096];
        }

        public function sendMessage(string $data, int $workerId): void {
            $this->calls[] = [$data, $workerId];
        }
    };
}

test('worker count is resolved from the setting array', function (): void {
    expect(Via::resolveWorkerNum(openswoole26ServerSpy(8)))->toBe(8);
});

test('worker count falls back to a legacy worker_num property', function (): void {
    $legacy = new class {
        public int $worker_num = 3;
    };

    expect(Via::resolveWorkerNum($legacy))->toBe(3);
});

test('worker count defaults to 1 when the server exposes neither', function (): void {
    expect(Via::resolveWorkerNum(new class {}))->toBe(1);
});

test('broker wired from an openswoole 26 server publishes to sibling workers', function (): void {
    $server = openswoole26ServerSpy(4);
    $broker = new SwooleBroker();
    $broker->connect();

    // Exactly what Via's workerStart does.
    $broker->setServer($server, 1, Via::resolveWorkerNum($server));
    $broker->publish('route:/test');

    // Should reach workers 0, 2, 3 — every sibling but itself.
    expect($server->calls)->toHaveCount(3);
    expect(array_column($server->calls, 1))->toBe([0, 2, 3]);
});
