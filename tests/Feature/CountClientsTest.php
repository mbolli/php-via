<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine\Http\Client;

// Via::countClients(): the connected tabs a broadcast of a scope reaches, on every worker.

/** A page context on $route registered with $via as a page load registers it. */
function countClientsPage(Via $via, string $route = '/room', ?callable $setup = null): Context {
    $context = new Context($route . '_/' . bin2hex(random_bytes(6)), $route, $via, null, str_repeat('a', 32));
    if ($setup !== null) {
        $setup($context);
    }
    $via->contexts[$context->getId()] = $context;
    $via->getApp()->registerContext($context);
    $via->registerContextInScope($context, Scope::TAB);

    return $context;
}

/** The client registration an SSE connect makes. */
function countClientsConnect(Via $via, Context $context): void {
    $via->getApp()->registerClient($context->getId(), ['id' => 'client-' . $context->getId(), 'identicon' => '', 'connected_at' => time(), 'ip' => '127.0.0.1']);
}

describe('Via::countClients()', function (): void {
    test('counts the connected tabs in a scope that the page or a component joined, on a route, or anywhere', function (): void {
        $via = createVia();
        $lobby = countClientsPage($via, setup: fn (Context $c) => $c->addScope('room:lobby'));
        $games = countClientsPage($via, setup: fn (Context $c) => $c->scope('room:games'));
        $widget = countClientsPage($via, '/dash', function (Context $c): void {
            $c->component(static function (Context $w): void {
                $w->signal(0, 'unread', 'room:lobby');
                $w->view(static fn (): string => '<p>w</p>');
            }, 'widget');
        });
        countClientsPage($via, setup: fn (Context $c) => $c->addScope('room:lobby'));
        foreach ([$lobby, $games, $widget] as $context) {
            countClientsConnect($via, $context);
        }

        expect($via->countClients('room:lobby'))->toBe(2, 'the tab that has not connected is left out')
            ->and($via->countClients('room:games'))->toBe(1)
            ->and($via->countClients('room:*'))->toBe(3)
            ->and($via->countClients(Scope::routeScope('/room')))->toBe(2)
            ->and($via->countClients(Scope::routeScope('/dash')))->toBe(1)
            ->and($via->countClients(Scope::GLOBAL))->toBe(3)
            ->and($via->countClients('room:empty'))->toBe(0)
        ;
    });

    test('follows the scopes a connected tab joins and leaves, also through a component added later', function (): void {
        $via = createVia();
        $page = countClientsPage($via);
        countClientsConnect($via, $page);

        $page->addScope('room:a');
        expect($via->countClients('room:a'))->toBe(1);

        $page->scope('room:b');
        $page->removeScope('room:a');
        expect($via->countClients('room:a'))->toBe(0)->and($via->countClients('room:b'))->toBe(1);

        $page->component(static function (Context $c): void {
            $c->addScope('room:c');
            $c->view(static fn (): string => '<p>c</p>');
        }, 'late');
        expect($via->countClients('room:c'))->toBe(1);
    });

    test('leaves a tab out once its stream closes', function (): void {
        $via = createVia();
        $page = countClientsPage($via, setup: fn (Context $c) => $c->addScope('room:a'));
        countClientsConnect($via, $page);

        $via->getApp()->unregisterClient($page->getId());

        expect($via->countClients('room:a'))->toBe(0)->and($via->countClients(Scope::GLOBAL))->toBe(0);
    });

    test('counts the tabs of every worker through the shared client registry', function (): void {
        $registry = new SharedClientRegistry(64);
        $first = createVia();
        $first->getApp()->setClientRegistry($registry);
        $first->getApp()->claimWorker(0);
        $second = createVia();
        $second->getApp()->setClientRegistry($other = clone $registry);
        $second->getApp()->claimWorker(1);

        $here = countClientsPage($first, setup: fn (Context $c) => $c->addScope('room:a'));
        $there = countClientsPage($second, setup: fn (Context $c) => $c->addScope('room:a'));
        countClientsConnect($first, $here);
        countClientsConnect($second, $there);

        expect($first->countClients('room:a'))->toBe(2)
            ->and($second->countClients('room:*'))->toBe(2)
            ->and($second->countClients(Scope::GLOBAL))->toBe(2)
        ;

        $there->addScope('room:b');

        expect($first->countClients('room:b'))->toBe(1, 'a scope joined on one worker is seen on the other');
    });

    test('throws for a scope that needs a context to resolve', function (string $scope): void {
        createVia()->countClients($scope);
    })->throws(InvalidArgumentException::class)->with([Scope::TAB, Scope::ROUTE, Scope::SESSION]);
});

describe('Via::countClients() on a real server', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('every worker counts the tabs of every worker', function (): void {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/count_clients_workers.php') . ' 2>&1'
        );
        preg_match('/^connected=(\d+)$/m', $out, $connected);
        preg_match('/^pids=(\d+)$/m', $out, $pids);
        preg_match_all('/^count=(.*)$/m', $out, $counts);

        expect($connected[1] ?? null)->toBe('5', $out)
            ->and((int) ($pids[1] ?? 0))->toBeGreaterThan(1, 'the probes must reach more than one worker')
            ->and(array_unique($counts[1]))->toBe(['a=3 b=2 any=5 route=5 global=5'], $out)
        ;
    });
});
