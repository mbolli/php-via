<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Broadcast;
use Mbolli\PhpVia\Attributes\OnCleanup;
use Mbolli\PhpVia\Attributes\OnDisconnect;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Composition\ClassMetadata;
use Mbolli\PhpVia\Composition\PageMount;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * PageMount: the class model must behave like the closure model it is built on. Each #[Action]
 * runs on the calling tab's own instance, #[Broadcast] only sets the broadcast target, cleanup
 * hooks see current values, and class components share the page's session.
 */

final class PageMountLog {
    /** @var list<mixed> */
    public static array $calls = [];
}

#[Broadcast(Scope::ROUTE)]
final class PmBroadcastPage {
    #[Persist]
    public int $clicks = 0;

    #[Persist]
    public string $owner = '';

    public function view(Context $ctx): void {
        $this->owner = $ctx->getId();
        $ctx->view(fn (): string => '');
    }

    #[Action]
    public function add(Context $ctx): void {
        ++$this->clicks;
        PageMountLog::$calls[] = [$ctx->getId(), $this->owner, $this->clicks];
    }
}

#[Broadcast(Scope::ROUTE)]
final class PmBroadcastSignalsPage {
    #[Signal(Scope::GLOBAL)]
    public int $total = 0;

    #[Signal('room:pm')]
    public int $room = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => '');
    }
}

final class PmScopedActionPage {
    #[Persist]
    public string $owner = '';

    public function view(Context $ctx): void {
        $this->owner = $ctx->getId();
        $ctx->view(fn (): string => '');
    }

    #[Action(scope: Scope::ROUTE)]
    public function bump(Context $ctx): void {
        PageMountLog::$calls[] = ['bump', $ctx->getId(), $this->owner];
    }

    #[Action(scope: Scope::SESSION)]
    public function save(Context $ctx): void {
        PageMountLog::$calls[] = ['save', $ctx->getId(), $this->owner];
    }

    #[Action(scope: 'room:pm-actions')]
    public function shout(Context $ctx): void {
        PageMountLog::$calls[] = ['shout', $ctx->getId(), $this->owner];
    }
}

final class PmScopedWidget {
    #[Persist]
    public string $owner = '';

    public function view(Context $ctx): void {
        $this->owner = $ctx->getId();
        $ctx->view(fn (): string => '');
    }

    #[Action(scope: Scope::GLOBAL)]
    public function vote(Context $ctx): void {
        PageMountLog::$calls[] = [$ctx->getId(), $this->owner];
    }
}

final class PmTwoCleanups {
    #[Signal]
    public int $n = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => '');
    }

    #[OnCleanup]
    public function first(Context $ctx): void {
        PageMountLog::$calls[] = ['first', $this->n];
    }

    #[OnCleanup]
    public function second(Context $ctx): void {
        PageMountLog::$calls[] = ['second', $this->n];
    }
}

final class PmOnDisconnectPage {
    public function view(Context $ctx): void {}

    #[OnDisconnect]
    public function leave(Context $ctx): void {}
}

final class PmSessionWidget {
    #[Signal(Scope::SESSION)]
    public string $theme = 'light';

    public function view(Context $ctx): void {
        PageMountLog::$calls[] = $ctx->getSessionId();
        $ctx->view(fn (): string => $this->theme);
    }
}

/** @param class-string $class */
function pageMountHandler(Via $app, string $class, ?callable $factory = null): Closure {
    return PageMount::buildClosure(ClassMetadata::analyze($class), $app, $factory);
}

function pageMountApp(): Via {
    return new Via((new Config())->withLogLevel('error'));
}

beforeEach(function (): void {
    PageMountLog::$calls = [];
});

describe('#[Action] runs on the calling context\'s instance', function (): void {
    test('unscoped actions on a #[Broadcast] class register per tab', function (): void {
        $app = pageMountApp();
        $handler = pageMountHandler($app, PmBroadcastPage::class);
        $a = new Context('A', '/p', $app, null, 's1');
        $handler($a);
        $b = new Context('B', '/p', $app, null, 's2');
        $handler($b);

        $a->executeAction('add');
        $b->executeAction('add');
        $b->executeAction('add');

        expect(PageMountLog::$calls)->toBe([['A', 'A', 1], ['B', 'B', 1], ['B', 'B', 2]])
            ->and($app->getScopedActions(Scope::routeScope('/p')))->toBe([])
            ->and($a->getPrimaryScope())->toBe(Scope::routeScope('/p'))
        ;
    });

    test('a ROUTE-scoped action runs on the caller\'s instance, not the first tab\'s', function (): void {
        $app = pageMountApp();
        $handler = pageMountHandler($app, PmScopedActionPage::class);
        $a = new Context('A', '/p', $app, null, 's1');
        $handler($a);
        $b = new Context('B', '/p', $app, null, 's2');
        $handler($b);

        $b->executeAction('bump');
        $a->executeAction('bump');

        expect(PageMountLog::$calls)->toBe([['bump', 'B', 'B'], ['bump', 'A', 'A']])
            ->and($a->getAction('bump')?->id())->toBe('bump')
        ;
    });

    test('a SESSION-scoped action is found and runs on each caller\'s instance', function (): void {
        $app = pageMountApp();
        $handler = pageMountHandler($app, PmScopedActionPage::class);
        $a = new Context('A', '/p', $app, null, 'sess1');
        $handler($a);
        $b = new Context('B', '/p', $app, null, 'sess2');
        $handler($b);

        $a->executeAction('save');
        $b->executeAction('save');

        expect(PageMountLog::$calls)->toBe([['save', 'A', 'A'], ['save', 'B', 'B']]);
    });

    test('a custom-scoped action is found and runs on the caller\'s instance', function (): void {
        $app = pageMountApp();
        $handler = pageMountHandler($app, PmScopedActionPage::class);
        $a = new Context('A', '/p', $app, null, 's1');
        $handler($a);
        $b = new Context('B', '/p', $app, null, 's2');
        $handler($b);

        $b->executeAction('shout');

        expect(PageMountLog::$calls)->toBe([['shout', 'B', 'B']]);
    });

    test('a component\'s GLOBAL action reached through the page runs on that page\'s component', function (): void {
        $app = pageMountApp();
        $p1 = new Context('P1', '/p', $app, null, 's1');
        $p1->component(PmScopedWidget::class, 'w');
        $p2 = new Context('P2', '/p', $app, null, 's2');
        $p2->component(PmScopedWidget::class, 'w');
        $component = (string) array_key_first($p2->getComponentRegistry());

        $p2->executeAction('vote');

        expect(PageMountLog::$calls)->toBe([[$component, $component]]);
    });

    test('a scoped action keeps no instance alive once its context is gone', function (): void {
        $app = pageMountApp();
        $refs = [];
        $handler = pageMountHandler($app, PmScopedActionPage::class, function () use (&$refs): object {
            $instance = new PmScopedActionPage();
            $refs[] = WeakReference::create($instance);

            return $instance;
        });
        $a = new Context('A', '/p', $app, null, 's1');
        $handler($a);
        $b = new Context('B', '/p', $app, null, 's2');
        $handler($b);

        $a->cleanup();
        unset($a);
        gc_collect_cycles();

        expect($refs[0]->get())->toBeNull()
            ->and($refs[1]->get())->not->toBeNull()
        ;
        $b->executeAction('bump');
        expect(PageMountLog::$calls)->toBe([['bump', 'B', 'B']]);
    });
});

describe('#[Broadcast] only sets the broadcast target', function (): void {
    test('scoped #[Signal] properties keep their scopes on a #[Broadcast] class', function (): void {
        $app = pageMountApp();
        $ctx = new Context('A', '/p', $app, null, 's1');
        pageMountHandler($app, PmBroadcastSignalsPage::class)($ctx);

        $signalIds = array_keys($ctx->getSignals());

        expect($ctx->getPrimaryScope())->toBe(Scope::routeScope('/p'))
            ->and($ctx->getScopes())->toContain(Scope::GLOBAL)
            ->and($ctx->getScopes())->toContain('room:pm')
            ->and($signalIds)->toContain($ctx->getSignal('total')?->id())
            ->and($signalIds)->toContain($ctx->getSignal('room')?->id())
        ;
    });
});

describe('cleanup hooks', function (): void {
    test('several #[OnCleanup] methods run in declaration order on a hydrated instance', function (): void {
        $app = pageMountApp();
        $ctx = new Context('A', '/p', $app, null, 's1');
        pageMountHandler($app, PmTwoCleanups::class)($ctx);
        $ctx->getSignal('n')?->setValue(7);

        $ctx->cleanup();

        expect(PageMountLog::$calls)->toBe([['first', 7], ['second', 7]]);
    });

    test('#[OnDisconnect] fails at mount and names #[OnCleanup]', function (): void {
        $app = pageMountApp();

        expect(fn () => $app->mount(PmOnDisconnectPage::class, '/gone'))
            ->toThrow(LogicException::class, 'PmOnDisconnectPage::leave() uses #[OnDisconnect], which was removed in php-via 0.14. Use #[OnCleanup]')
        ;
    });
});

describe('components share the page\'s session', function (): void {
    test('a class component with #[Signal(Scope::SESSION)] mounts on the page\'s session', function (): void {
        $app = pageMountApp();
        $page = new Context('P', '/p', $app, null, 'sess-1');
        $other = new Context('Q', '/q', $app, null, 'sess-1');

        $page->component(PmSessionWidget::class, 'theme');
        $other->component(PmSessionWidget::class, 'theme');

        $widget = array_values($page->getComponentRegistry())[0];
        $otherWidget = array_values($other->getComponentRegistry())[0];

        expect(PageMountLog::$calls)->toBe(['sess-1', 'sess-1'])
            ->and($widget->getSignal('theme'))->toBe($otherWidget->getSignal('theme'))
        ;
    });

    test('closure and nested components read and write the page\'s session data', function (): void {
        $app = pageMountApp();
        $page = new Context('P', '/p', $app, null, 'sess-2');
        $seen = [];

        $page->component(function (Context $outer) use (&$seen): void {
            $outer->setSessionData('k', 'v');
            $outer->component(function (Context $inner) use (&$seen): void {
                $seen[] = $inner->getSessionId();
                $seen[] = $inner->sessionData('k');
                $inner->view(fn (): string => '');
            }, 'inner');
            $outer->view(fn (): string => '');
        }, 'outer');

        expect($seen)->toBe(['sess-2', 'v'])
            ->and($page->sessionData('k'))->toBe('v')
        ;
    });
});
