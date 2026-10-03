<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;

/*
 * Testing\TestApp on the pages apps write: a counter, a todo list with keyed rows, a SESSION page in
 * two tabs, a component and a revival.
 */

function scenarioApp(callable $routes, ?Config $config = null): TestApp {
    return new TestApp(($config ?? new Config())->withLogLevel('error'), $routes);
}

function counterPage(Via $via): void {
    $via->page('/counter', function (Context $c): void {
        $count = $c->signal(0, 'count');
        $c->action(function () use ($c, $count): void {
            $count->setValue($count->int() + 1);
            $c->sync();
        }, 'increment');
        $c->view(static fn (): string => '<div id="counter">Count: ' . $count->int() . '</div>');
    });
}

describe('a counter', function (): void {
    test('the connect sends the view and the signals, and each increment the new view and value', function (): void {
        $app = scenarioApp(counterPage(...));
        $tab = $app->open('/counter');

        expect($tab->patches())->toBe([
            ['type' => 'elements', 'html' => '<div id="counter">Count: 0</div>', 'selector' => null, 'mode' => PatchMode::Outer],
            ['type' => 'signals', 'signals' => ['count' => 0]],
            ['type' => 'signals', 'signals' => ['_disconnected' => false]],
        ]);

        $tab->action('increment')->action('increment');

        expect($tab->patches())->toBe([
            ['type' => 'elements', 'html' => '<div id="counter">Count: 1</div>', 'selector' => null, 'mode' => PatchMode::Outer],
            ['type' => 'signals', 'signals' => ['count' => 1]],
            ['type' => 'elements', 'html' => '<div id="counter">Count: 2</div>', 'selector' => null, 'mode' => PatchMode::Outer],
            ['type' => 'signals', 'signals' => ['count' => 2]],
        ])
            ->and($tab->signal('count'))->toBe(2)
            ->and($tab->html())->toContain('<div id="counter">Count: 2</div>')
            ->and($tab->patches())->toBe([])
        ;
    });

    test('an action takes the value the browser sends, as a bound input would', function (): void {
        $app = scenarioApp(counterPage(...));
        $tab = $app->open('/counter');

        $tab->action('increment', signals: ['count' => 41]);

        expect($tab->signal('count'))->toBe(42)
            ->and($tab->context()->getSignal('count')?->int())->toBe(42)
        ;
    });

    test('a value the server never sent stays out of the browser', function (): void {
        $app = scenarioApp(function (Via $via): void {
            $via->page('/quiet', function (Context $c): void {
                $hidden = $c->signal(0, 'hidden', clientWritable: false);
                $c->action(static function () use ($hidden): void {
                    $hidden->setValue(5);
                    $hidden->markSynced();
                }, 'write');
                $c->view(static fn (): string => '<p id="q">' . $hidden->int() . '</p>');
            });
        });
        $tab = $app->open('/quiet');

        $tab->action('write');

        expect($tab->signal('hidden'))->toBe(0, 'markSynced() kept the write from the browser')
            ->and($tab->html())->toContain('<p id="q">5</p>')
        ;
    });
});

function todoRow(int $id, string $title): string {
    return '<li id="todo-' . $id . '">' . htmlspecialchars($title) . '</li>';
}

describe('a todo list with keyed rows', function (): void {
    beforeEach(function (): void {
        $this->app = scenarioApp(function (Via $via): void {
            $via->page('/todos', function (Context $c): void {
                $todos = [1 => 'Buy milk'];
                $nextId = 2;
                $title = $c->signal('', 'title');
                $c->action(function () use ($c, $title, &$todos, &$nextId): void {
                    $id = $nextId++;
                    $todos[$id] = $title->string();
                    $c->patchElements(todoRow($id, $todos[$id]), '#todos', PatchMode::Append);
                    $title->setValue('');
                }, 'add');
                $c->action(function () use ($c, &$todos): void {
                    $id = (int) $c->input('id');
                    $todos[$id] = mb_strtoupper($todos[$id]);
                    $c->patchElements(todoRow($id, $todos[$id]));
                }, 'shout');
                $c->action(function () use ($c, &$todos): void {
                    $id = (int) $c->input('id');
                    unset($todos[$id]);
                    $c->patchElements(selector: '#todo-' . $id, mode: PatchMode::Remove);
                }, 'remove');
                $c->view(function () use (&$todos): string {
                    $rows = '';
                    foreach ($todos as $id => $todo) {
                        $rows .= todoRow($id, $todo);
                    }

                    return '<ul id="todos">' . $rows . '</ul>';
                });
            });
        });
        $this->tab = $this->app->open('/todos');
        $this->tab->patches();
    });

    test('adding appends a row and clears the input', function (): void {
        $this->tab->action('add', signals: ['title' => 'Walk the dog']);

        expect($this->tab->patches())->toBe([
            ['type' => 'elements', 'html' => '<li id="todo-2">Walk the dog</li>', 'selector' => '#todos', 'mode' => PatchMode::Append],
            ['type' => 'signals', 'signals' => ['title' => '']],
        ])
            ->and($this->tab->signal('title'))->toBe('')
            ->and($this->tab->html())->toContain('<ul id="todos"><li id="todo-1">Buy milk</li><li id="todo-2">Walk the dog</li></ul>')
        ;
    });

    test('a row morphs by its id, and removing one sends a remove for its selector', function (): void {
        $this->tab->action('add', signals: ['title' => 'Walk the dog']);
        $this->tab->patches();

        $this->tab->action('shout', ['id' => 2])->action('remove', ['id' => 1]);

        expect($this->tab->patches())->toBe([
            ['type' => 'elements', 'html' => '<li id="todo-2">WALK THE DOG</li>', 'selector' => null, 'mode' => PatchMode::Outer],
            ['type' => 'elements', 'html' => '', 'selector' => '#todo-1', 'mode' => PatchMode::Remove],
        ])
            ->and($this->tab->html())->toContain('<ul id="todos"><li id="todo-2">WALK THE DOG</li></ul>')
        ;
    });
});

describe('a SESSION page in two tabs', function (): void {
    test('a write in one tab reaches the other tab of the session, and no tab of another session', function (): void {
        $app = scenarioApp(function (Via $via): void {
            $via->page('/cart', function (Context $c): void {
                $items = $c->signal(0, 'items', Scope::SESSION);
                $c->action(static fn () => $items->setValue($items->int() + 1), 'add');
                $c->view(static fn (): string => '<p id="cart">Items: ' . $items->int() . '</p>');
            });
        });
        $first = $app->open('/cart');
        $second = $first->open('/cart');
        $stranger = $app->open('/cart');
        foreach ([$first, $second, $stranger] as $tab) {
            $tab->patches();
        }

        $first->action('add');

        $session = $first->context()->getSessionId();
        expect($second->context()->getSessionId())->toBe($session)
            ->and($stranger->context()->getSessionId())->not->toBe($session)
            ->and($app->broadcasts())->toBe([Scope::sessionScope((string) $session)])
            ->and($second->patches())->toContain(['type' => 'signals', 'signals' => ['items' => 1]])
            ->and($second->signal('items'))->toBe(1)
            ->and($first->signal('items'))->toBe(1)
            ->and($stranger->patches())->toBe([])
            ->and($stranger->signal('items'))->toBe(0)
        ;
    });
});

describe('a component', function (): void {
    test('its actions and signals go by namespace.name, and its patches reach its page', function (): void {
        $app = scenarioApp(function (Via $via): void {
            $via->page('/shop', function (Context $c): void {
                $cart = $c->component(function (Context $cart): void {
                    $count = $cart->signal(0, 'count');
                    $cart->action(static fn () => $count->setValue($count->int() + 1), 'add');
                    $cart->action(static fn () => $cart->sync(), 'refresh');
                    $cart->view(static fn (): string => '<span>Cart: ' . $count->int() . '</span>');
                }, 'cart');
                $c->view(static fn (): string => '<main id="shop">' . $cart() . '</main>');
            });
        });
        $tab = $app->open('/shop');
        $tab->patches();

        $tab->action('cart.add');

        expect($tab->patches())->toBe([['type' => 'signals', 'signals' => ['cart.count' => 1]]])
            ->and($tab->signal('cart.count'))->toBe(1)
        ;

        $tab->action('cart.refresh');
        $patches = $tab->patches();

        expect($patches[0]['type'])->toBe('elements')
            ->and($patches[0]['html'] ?? '')->toContain('<span>Cart: 1</span>')
            ->and($patches[0]['selector'] ?? '')->toStartWith('#c-')
            ->and($tab->html())->toContain('<span>Cart: 1</span>')
        ;
    });
});

describe('a revival', function (): void {
    beforeEach(function (): void {
        $this->events = [];
        $events = &$this->events;
        $this->app = scenarioApp(function (Via $via) use (&$events): void {
            counterPage($via);
            $via->onClientConnect(static function (Context $c) use (&$events): void {
                $events[] = 'connect';
            });
            $via->onClientDisconnect(static function (Context $c) use (&$events): void {
                $events[] = 'disconnect';
            });
        });
    });

    test('a tab whose context expired gets it back on connect, with the same id and its values', function (): void {
        $tab = $this->app->open('/counter');
        $tab->action('increment')->action('increment');
        $contextId = $tab->context()->getId();
        $tab->patches();

        $tab->disconnect(expire: true);

        expect(fn () => $tab->context())->toThrow(LogicException::class, 'is destroyed');

        $tab->connect();

        expect($tab->context()->getId())->toBe($contextId)
            ->and($tab->context()->getSignal('count')?->int())->toBe(2)
            ->and($tab->context()->isConnected())->toBeTrue()
            ->and($tab->patches())->toBe([
                ['type' => 'elements', 'html' => '<div id="counter">Count: 2</div>', 'selector' => null, 'mode' => PatchMode::Outer],
                ['type' => 'signals', 'signals' => ['count' => 2]],
                ['type' => 'signals', 'signals' => ['_disconnected' => false]],
            ])
            ->and($this->events)->toBe(['connect', 'disconnect', 'connect'])
        ;
    });

    test('an action revives it too, and its patches wait for the connect', function (): void {
        $tab = $this->app->open('/counter');
        $tab->action('increment');
        $tab->disconnect(expire: true);
        $tab->patches();

        $tab->action('increment');

        expect($tab->context()->isConnected())->toBeFalse()
            ->and($tab->patches())->toBe([])
            ->and($tab->signal('count'))->toBe(1, 'the browser has not heard yet')
        ;

        $tab->connect();

        expect($tab->patches())->toContain(['type' => 'signals', 'signals' => ['count' => 2]])
            ->and($tab->signal('count'))->toBe(2)
        ;
    });

    test('without a revival window the connect sends a reload, and the tab stays disconnected', function (): void {
        $app = scenarioApp(counterPage(...), (new Config())->withContextTimeouts(revivalWindowMs: 0));
        $tab = $app->open('/counter');
        $tab->disconnect(expire: true);
        $tab->patches();

        $tab->connect();

        expect($tab->patches())->toBe([[
            'type' => 'elements',
            'html' => '<script data-effect="el.remove()">window.location.reload()</script>',
            'selector' => 'body',
            'mode' => PatchMode::Append,
        ]])
            ->and(fn () => $tab->action('increment'))->toThrow(RuntimeException::class, "Action 'increment' answered 400 Invalid context")
        ;
    });
});
