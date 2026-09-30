<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * Signal ids that differ only by punctuation, or by "_" versus punctuation, used to sanitise to
 * one id: one signal on the server, one key in the browser.
 */

/** A page with a punctuated TAB pair, a scope pair and a namespaced component, run on its own Via. */
function signalIdPage(Via $app, string $contextId): Context {
    $ctx = new Context($contextId, '/f', $app, null, 'sess');
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, 'sess');
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams(signalIdHandler(), $ctx, []);

    return $ctx;
}

function signalIdHandler(): Closure {
    return function (Context $c): void {
        $c->addScope('room:a-b');
        $c->addScope('room:a.b');
        $c->signal('', 'user-name');
        $c->signal('', 'user_name');
        $c->signal('', 'topic', 'room:a-b');
        $c->signal('', 'topic', 'room:a.b');
        $c->component(function (Context $k): void {
            $k->signal('', 'q');
            $k->signal(0, 'votes', Scope::GLOBAL);
            $k->view(fn (): string => '');
        }, 'search-1');
        $c->view(fn (): string => '');
    };
}

/** @return array<string, string> signal id by a label for each declaration of signalIdHandler() */
function signalIdsOf(Via $app, Context $page): array {
    $component = array_values($page->getComponentManager()->getComponents())[0];
    $scoped = [];
    foreach (['room:a-b', 'room:a.b'] as $scope) {
        foreach ($app->getScopedSignals($scope) as $signal) {
            $scoped[$scope] = $signal->id();
        }
    }

    return [
        'user-name' => $page->getSignal('user-name')?->id() ?? '',
        'user_name' => $page->getSignal('user_name')?->id() ?? '',
        'room:a-b topic' => $scoped['room:a-b'] ?? '',
        'room:a.b topic' => $scoped['room:a.b'] ?? '',
        'search-1 q' => $component->getSignal('q')?->id() ?? '',
        'search-1 votes' => $component->getSignal('votes')?->id() ?? '',
    ];
}

describe('signal ids stay apart when names differ only by punctuation', function (): void {
    test('two TAB signals in one context stay independent', function (): void {
        $ctx = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());
        $dash = $ctx->signal('x', 'cats-1');
        $under = $ctx->signal('y', 'cats_1');

        expect($dash->id())->not->toBe($under->id())
            ->and($dash->string())->toBe('x')
            ->and($under->string())->toBe('y')
            ->and($ctx->getSignalFactory()->getTabSignals())->toHaveCount(2)
        ;
    });

    test('client values reach the right TAB signal', function (): void {
        $ctx = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());
        $dash = $ctx->signal('', 'user-name');
        $under = $ctx->signal('', 'user_name');

        $ctx->injectSignals([$dash->id() => 'dash', $under->id() => 'under']);

        expect($dash->string())->toBe('dash')->and($under->string())->toBe('under');
    });

    test('components whose namespaces differ only by punctuation seed separate browser signals', function (): void {
        $page = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());
        $counter = function (Context $k): void {
            $k->signal(0, 'count');
            $k->view(fn (): string => '');
        };
        $page->component($counter, 'counter-a');
        $page->component($counter, 'counter_a');
        [$a, $b] = array_values($page->getComponentManager()->getComponents());
        $a->getSignal('count')->setValue(1);
        $b->getSignal('count')->setValue(2);

        expect($page->getPatchManager()->initialSignalValues())
            ->toBe([$a->getSignal('count')->id() => 1, $b->getSignal('count')->id() => 2])
        ;
    });

    test('a namespaced scoped signal is not the page signal named after namespace and name', function (): void {
        $page = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());
        $plain = $page->signal(10, 'cats_votes', Scope::GLOBAL);
        $page->component(function (Context $k): void {
            $k->signal(0, 'votes', Scope::GLOBAL);
            $k->view(fn (): string => '');
        }, 'cats');
        $widget = array_values($page->getComponentManager()->getComponents())[0]->getSignal('votes');

        expect($widget)->not->toBe($plain)
            ->and($widget->int())->toBe(0)
            ->and($plain->int())->toBe(10)
        ;
    });

    test('a page in two scopes that differ only by punctuation seeds both values', function (): void {
        $ctx = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());
        $ctx->scope('room:a-b');
        $ctx->addScope('room:a.b');
        $dash = $ctx->signal('dash', 'topic', 'room:a-b');
        $dot = $ctx->signal('dot', 'topic', 'room:a.b');

        expect($dash->id())->not->toBe($dot->id())
            ->and($ctx->getPatchManager()->initialSignalValues())->toBe([$dash->id() => 'dash', $dot->id() => 'dot'])
        ;
    });

    test('a component TAB signal does not share its id with a scoped signal', function (): void {
        $page = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());
        $page->scope('search');
        $scoped = $page->signal('shared', 'q');
        $page->component(function (Context $k): void {
            $k->signal('mine', 'q');
            $k->view(fn (): string => '');
        }, 'search');
        $tab = array_values($page->getComponentManager()->getComponents())[0]->getSignal('q');

        expect($tab->id())->not->toBe($scoped->id());
    });

    test('a signal cannot take the id of the via_ctx signal', function (): void {
        $ctx = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia());

        expect($ctx->signal(0, 'ctx', 'via')->id())->not->toBe('via_ctx');
    });
});

describe('signal ids are deterministic', function (): void {
    test('two workers give every signal the same distinct id', function (): void {
        $workerA = createVia();
        $workerB = createVia();
        $onA = signalIdsOf($workerA, signalIdPage($workerA, '/f_/a1b2c3d4e5f6a7b8'));
        $onB = signalIdsOf($workerB, signalIdPage($workerB, '/f_/a1b2c3d4e5f6a7b8'));

        expect($onA)->toBe($onB)
            ->and(array_unique($onA))->toHaveCount(count($onA))
        ;
    });

    test('a revived context gets the same ids back and takes the client values', function (): void {
        $app = createVia();
        $app->page('/f', signalIdHandler());
        $contextId = '/f_/a1b2c3d4e5f6a7b8';
        $before = signalIdsOf($app, signalIdPage($app, $contextId));
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);

        $revived = $app->reviveContextFromClient($contextId, 'sess', [
            $before['user-name'] => 'dash',
            $before['user_name'] => 'under',
            $before['search-1 q'] => 'typed',
        ]);
        $component = array_values($revived->getComponentManager()->getComponents())[0];

        expect(signalIdsOf($app, $revived))->toBe($before)
            ->and(array_unique($before))->toHaveCount(count($before))
            ->and($revived->getSignal('user-name')->string())->toBe('dash')
            ->and($revived->getSignal('user_name')->string())->toBe('under')
            ->and($component->getSignal('q')->string())->toBe('typed')
        ;
    });
});

describe('signal ids that do not change', function (): void {
    test('plain custom and route scopes keep their ids', function (string $scope, string $name, string $expected): void {
        $ctx = new Context('/f_/a1b2c3d4e5f6a7b8', '/f', createVia(), null, 'sess');

        expect($ctx->signal(0, $name, $scope)->id())->toBe($expected);
    })->with([
        [Scope::GLOBAL, 'count', 'global_count'],
        ['room:lobby', 'messages', 'room_lobby_messages'],
        ['example:stock:AAPL', 'price', 'example_stock_AAPL_price'],
        [Scope::routeScope('/'), 'counter', 'route___counter'],
        [Scope::routeScope('/examples/counter'), 'count', 'route__examples_counter_count'],
    ]);
});

describe('finding a scoped signal from outside a context', function (): void {
    test('getScopedSignalByName() finds signals whose scope, name or namespace has punctuation', function (): void {
        $app = createVia();
        $stock = Scope::build('example:stock', 'BRK.B');
        $ctx = new Context('/f_/a1b2c3d4e5f6a7b8', '/examples/stock-ticker', $app);
        $price = $ctx->signal('1.00', 'price', $stock);
        $hits = $ctx->signal(0, 'hits', Scope::ROUTE);
        $page = signalIdPage($app, '/f_/b1b2c3d4e5f6a7b8');
        $votes = array_values($page->getComponentManager()->getComponents())[0]->getSignal('votes');

        $app->getScopedSignalByName($stock, 'price')?->setValue('2.00');

        expect($price->string())->toBe('2.00')
            ->and($app->getScopedSignalByName(Scope::routeScope('/examples/stock-ticker'), 'hits'))->toBe($hits)
            ->and($app->getScopedSignalByName(Scope::GLOBAL, 'votes', 'search-1'))->toBe($votes)
            ->and($app->getScopedSignalByName(Scope::GLOBAL, 'votes'))->toBeNull()
        ;
    });
});
