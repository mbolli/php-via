<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\SwooleBroker;

/*
 * SwooleBroker Unit Tests
 *
 * OpenSwoole\Http\Server cannot be instantiated in test mode (no socket binding).
 * We use a simple anonymous class spy to verify sendMessage() calls.
 */

/**
 * Create a minimal Server spy that records sendMessage() calls.
 *
 * @return object{calls: list<array{string, int}>, sendMessage: callable, worker_num: int}
 */
function makeServerSpy(int $workerNum = 4): object {
    return new class($workerNum) {
        /** @var list<array{string, int}> */
        public array $calls = [];
        public int $worker_num;

        public function __construct(int $workerNum) {
            $this->worker_num = $workerNum;
        }

        public function sendMessage(string $data, int $workerId): void {
            $this->calls[] = [$data, $workerId];
        }
    };
}

describe('SwooleBroker', function (): void {
    test('connect() marks broker as connected', function (): void {
        $broker = new SwooleBroker();
        expect($broker->isConnected())->toBeFalse();
        $broker->connect();
        expect($broker->isConnected())->toBeTrue();
    });

    test('disconnect() marks broker as disconnected', function (): void {
        $broker = new SwooleBroker();
        $broker->connect();
        $broker->disconnect();
        expect($broker->isConnected())->toBeFalse();
    });

    test('getNodeId() returns a non-empty unique string', function (): void {
        $a = new SwooleBroker();
        $b = new SwooleBroker();
        expect($a->getNodeId())->toBeString()->not->toBeEmpty();
        expect($a->getNodeId())->not->toBe($b->getNodeId());
    });

    test('publish() sends to all sibling workers except self', function (): void {
        $broker = new SwooleBroker();
        $broker->connect();

        $spy = makeServerSpy(workerNum: 4);

        // Inject as worker 2 of 4 (IDs 0-3)
        /** @phpstan-ignore argument.type */
        $broker->setServer($spy, workerId: 2, workerNum: 4);

        $broker->publish('route:/test');

        // Should have sent to workers 0, 1, 3 (not 2)
        $targetIds = array_column($spy->calls, 1);
        sort($targetIds);
        expect($targetIds)->toBe([0, 1, 3]);
    });

    test('publish() sends to NO workers when worker_num is 1', function (): void {
        $broker = new SwooleBroker();
        $broker->connect();

        $spy = makeServerSpy(workerNum: 1);

        /** @phpstan-ignore argument.type */
        $broker->setServer($spy, workerId: 0, workerNum: 1);

        $broker->publish('global');

        expect($spy->calls)->toBeEmpty();
    });

    test('publish() message contains scope and nodeId', function (): void {
        $broker = new SwooleBroker();
        $broker->connect();

        $spy = makeServerSpy(workerNum: 2);

        /** @phpstan-ignore argument.type */
        $broker->setServer($spy, workerId: 0, workerNum: 2);

        $broker->publish('route:/home');

        expect($spy->calls)->toHaveCount(1);
        $payload = json_decode($spy->calls[0][0], true);
        expect($payload['scope'])->toBe('route:/home');
        expect($payload['nodeId'])->toBe($broker->getNodeId());
    });

    test('publish() is a no-op when server is not injected', function (): void {
        $broker = new SwooleBroker();
        $broker->connect();
        // No setServer() call — server is null
        // Should not throw
        $broker->publish('global');
        expect(true)->toBeTrue();
    });

    test('subscribe() stores the handler (does not call it on publish)', function (): void {
        $broker = new SwooleBroker();
        $called = false;
        $broker->subscribe(function (string $scope) use (&$called): void {
            $called = true;
        });
        $broker->connect();

        // publish() fans out to sibling workers — does NOT invoke own handler
        $spy = makeServerSpy(workerNum: 2);

        /** @phpstan-ignore argument.type */
        $broker->setServer($spy, workerId: 0, workerNum: 2);
        $broker->publish('global');

        expect($called)->toBeFalse('SwooleBroker must not invoke its own subscribe handler on publish');
    });
});
