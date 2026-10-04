<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;

// When a worker drops a scope's signals and actions: once no live context on it uses the scope.

final class LifetimeGlobalActionPage {
    public static int $bumps = 0;

    #[Action(scope: Scope::GLOBAL)]
    public function bump(Context $c): void {
        ++self::$bumps;
    }

    public function view(Context $c): void {
        $c->view(static fn (): string => '<p id="bump">bump</p>');
    }
}

function lifetimeApp(): TestApp {
    return new TestApp((new Config())->withLogLevel('error'), static function (Via $via): void {
        $via->page('/room/{id}', function (Context $c, string $id): void {
            $count = $c->signal(0, 'count', "room:{$id}");
            $c->action(static fn () => $count->increment(), 'increment');
            $c->view(static fn (): string => '<p id="count">' . $count->int() . '</p>');
        });
        $via->page('/widget/{id}', function (Context $c, string $id): void {
            $c->component(static function (Context $w) use ($id): void {
                $count = $w->signal(0, 'count', "room:{$id}");
                $w->action(static fn () => $count->increment(), 'increment');
                $w->view(static fn (): string => '<p>' . $count->int() . '</p>');
            }, 'widget');
            $c->view(static fn (): string => '<main id="widget">widget</main>');
        });
        $via->page('/global', function (Context $c): void {
            $hits = $c->signal(0, 'hits', Scope::GLOBAL);
            $c->view(static fn (): string => '<p id="hits">' . $hits->int() . '</p>');
        });
        $via->mount(LifetimeGlobalActionPage::class, '/bump');
    });
}

describe('a scope with no live context on the worker', function (): void {
    test('loses its signals after its last tab connected, wrote one and expired', function (): void {
        $app = lifetimeApp();
        $first = $app->open('/room/x');
        $first->action('increment')->action('increment');
        expect($first->signal('count'))->toBe(2);

        $first->disconnect(expire: true);

        expect($app->open('/room/x')->signal('count'))->toBe(0, 'the next tab starts from the initial value');
        $app->shutdown();
    });

    test('loses them when the last component in it goes with its page', function (): void {
        $app = lifetimeApp();
        $first = $app->open('/widget/y');
        $first->action('widget.increment');
        expect($first->signal('widget.count'))->toBe(1);

        $first->disconnect(expire: true);

        expect($app->open('/widget/y')->signal('widget.count'))->toBe(0);
        $app->shutdown();
    });

    test('drops its actions, and keeps them while a page that registered one without joining lives', function (): void {
        LifetimeGlobalActionPage::$bumps = 0;
        $app = lifetimeApp();
        $bumper = $app->open('/bump');
        $other = $app->open('/global');

        // The last tab that joined GLOBAL goes; the page with the GLOBAL action still uses the scope.
        $other->disconnect(expire: true);
        $bumper->action('bump');

        expect(LifetimeGlobalActionPage::$bumps)->toBe(1)
            ->and($app->via()->getScopedAction(Scope::GLOBAL, 'bump'))->not->toBeNull()
        ;

        $bumper->disconnect(expire: true);
        expect($app->via()->getScopedAction(Scope::GLOBAL, 'bump'))->toBeNull();
        $app->shutdown();
    });
});

describe('a scope a live context still uses', function (): void {
    test('keeps its signals while a tab in it has no stream and has not expired', function (): void {
        $app = lifetimeApp();
        $away = $app->open('/room/z');
        $away->action('increment')->action('increment');
        $away->disconnect();

        $passing = $app->open('/room/z');
        expect($passing->signal('count'))->toBe(2);
        $passing->disconnect(expire: true);

        $away->connect();
        expect($away->context()->getSignal('count')?->int())->toBe(2)
            ->and($app->open('/room/z')->signal('count'))->toBe(2)
        ;
        $app->shutdown();
    });

    test('keeps the value another worker holds: a worker drops only its own copy', function (): void {
        $store = new SharedSignalStore(maxRows: 16);
        $workers = [];
        foreach ([1, 2] as $n) {
            $via = createVia();
            $via->setSharedSignalStore($store);
            $workers[$n] = $via;
        }
        $open = static function (Via $via, string $id): Context {
            $context = new Context($id, '/room', $via);
            $via->contexts[$id] = $context;
            $via->getApp()->registerContext($context);
            $via->registerContextInScope($context, Scope::TAB);
            $context->signal(0, 'count', 'room:shared');

            return $context;
        };

        $one = $open($workers[1], '/room_/one');
        $one->getSignal('count')?->setValue(5);
        $two = $open($workers[2], '/room_/two');
        $workers[1]->getApp()->destroyContext($one->getId());

        expect($workers[1]->getScopedSignalByName('room:shared', 'count')?->int())->toBe(5, 'a detached handle on the shared value')
            ->and($two->getSignal('count')?->int())->toBe(5)
            ->and($open($workers[1], '/room_/three')->getSignal('count')?->int())->toBe(5, 'a new context adopts the shared value')
        ;
    });

    test('deletes the shared rows once no context on any worker uses the scope', function (): void {
        $store = new SharedSignalStore(maxRows: 64);
        $workers = [];
        foreach ([1, 2] as $n) {
            $via = createVia();
            $via->setSharedSignalStore($store);
            $workers[$n] = $via;
        }
        $open = static function (Via $via, string $id, string $room): Context {
            $context = new Context($id, '/room', $via);
            $via->contexts[$id] = $context;
            $via->getApp()->registerContext($context);
            $via->registerContextInScope($context, Scope::TAB);
            $context->signal(0, 'count', $room);
            $context->signal('', 'topic', $room);

            return $context;
        };

        $other = $open($workers[1], '/room_/other', 'room:other');
        for ($i = 0; $i < 20; ++$i) {
            $one = $open($workers[1], "/room_/a{$i}", "room:{$i}");
            $two = $open($workers[2], "/room_/b{$i}", "room:{$i}");
            $one->getSignal('count')?->setValue($i + 1);
            $workers[1]->getApp()->destroyContext($one->getId());
            expect($store->has("room:{$i}\0" . $two->getSignal('count')?->id()))->toBeTrue('worker 2 still uses the scope');
            $workers[2]->getApp()->destroyContext($two->getId());
        }

        expect($store->count())->toBe(2, 'only room:other is left')
            ->and($store->scopeCount())->toBe(1)
            ->and($open($workers[2], '/room_/again', 'room:3')->getSignal('count')?->int())->toBe(0, 'a scope declared again starts from its default')
            ->and($other->getSignal('count')?->int())->toBe(0)
        ;
    });
});
