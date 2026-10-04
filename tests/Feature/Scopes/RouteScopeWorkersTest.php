<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine\Http\Client;
use Tests\Support\TestBroker;

// The scopes a broadcast crosses to other workers and nodes with: route scopes of routes with parameters among them.

describe('Scope::isValidWireScope()', function (): void {
    test('takes the scope of a route with a parameter, and every character of a URL path', function (string $scope): void {
        expect(Scope::isValidWireScope($scope))->toBeTrue();
    })->with([
        Scope::routeScope('/blog/{slug}'),
        Scope::routeScope('/users/{id}/posts/{post_id}'),
        Scope::routeScope("/a~b/@me/caf%C3%A9/(x)+,;=!\$&'"),
        'room:*',
        Scope::GLOBAL,
        Scope::sessionScope(str_repeat('a', 32)),
    ]);

    test('refuses whitespace, control characters, quotes, backslashes, angle brackets and more than one wildcard', function (string $scope): void {
        expect(Scope::isValidWireScope($scope))->toBeFalse();
    })->with([
        'room: lobby',
        "room:\nlobby",
        "room:\0",
        'room:"x"',
        'room:a\\b',
        'room:<script>',
        'room:*:*',
        '*room',
        '',
        str_repeat('a', 257),
    ]);
});

describe('a broadcast of a scope other nodes refuse', function (): void {
    test('stays on its node, with a warning once per scope', function (): void {
        [$brokerA, $brokerB] = TestBroker::createLinked();
        $a = new Via((new Config())->withBroker($brokerA)->withLogLevel('warn'));
        $received = [];
        $brokerB->subscribe(static function (string $scope) use (&$received): void {
            $received[] = $scope;
        });

        ob_start();
        $a->broadcast('room:a b');
        $a->broadcast('room:a b');
        $a->broadcast(Scope::routeScope('/blog/{slug}'));
        $logs = (string) ob_get_clean();

        expect($received)->toBe([Scope::routeScope('/blog/{slug}')])
            ->and(substr_count($logs, 'Broadcasts of scope "room:a b" stay on this worker'))->toBe(1)
        ;
        TestBroker::reset();
    });
});

describe('a ROUTE signal on a route with a parameter, with two workers', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('a write on one worker reaches a tab whose stream is on the other', function (): void {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/route_scope_workers.php') . ' 2>&1'
        );
        preg_match('/^stream_pid=(\d+)$/m', $out, $stream);
        preg_match('/^writer_pid=(\d+)$/m', $out, $writer);

        expect((int) ($stream[1] ?? 0))->toBeGreaterThan(0, $out)
            ->and((int) ($writer[1] ?? 0))->toBeGreaterThan(0, $out)
            ->and($writer[1] ?? '')->not->toBe($stream[1] ?? '')
            ->and($out)->toMatch('/^hits=[1-9]\d*$/m')
            ->and($out)->toContain("seen=1\n")
        ;
    });
});
