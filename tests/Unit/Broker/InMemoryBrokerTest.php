<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\InMemoryBroker;

describe('InMemoryBroker', function (): void {
    test('publish is a no-op (no handler called)', function (): void {
        $broker = new InMemoryBroker();
        $called = false;

        $broker->subscribe(function (string $scope) use (&$called): void {
            $called = true;
        });

        $broker->connect();
        $broker->publish('global');

        expect($called)->toBeFalse('InMemoryBroker::publish() must not invoke its own handler');
    });

    test('connect and disconnect are no-ops (no exceptions)', function (): void {
        $broker = new InMemoryBroker();
        $broker->connect();
        $broker->disconnect();

        expect(true)->toBeTrue(); // reached without exception
    });

    test('getNodeId returns a non-empty string', function (): void {
        $broker = new InMemoryBroker();
        expect($broker->getNodeId())->toBeString()->not->toBeEmpty();
    });

    test('each instance has a unique nodeId', function (): void {
        $a = new InMemoryBroker();
        $b = new InMemoryBroker();
        expect($a->getNodeId())->not->toBe($b->getNodeId());
    });
});
