<?php

declare(strict_types=1);

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Actions Tests
 *
 * Tests action creation and behavior in different scopes.
 */

describe('Action Creation', function (): void {
    test('can create a TAB-scoped action', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $called = false;
        $action = $context->action(function (Context $ctx) use (&$called): void {
            $called = true;
        }, 'testAction');

        expect($action)->toBeInstanceOf(Action::class);
    });

    test('action has an ID', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action = $context->action(function (): void {}, 'myAction');

        expect($action->id())->not->toBeEmpty();
    });

    test('action has a URL', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action = $context->action(function (): void {}, 'myAction');

        expect($action->url())->toBeString();
        expect($action->url())->toContain('/_action/');
    });
});

describe('Actions after scope()', function (): void {
    test('an action declared after scope() is a TAB action, and needs no name', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/game', $app);
        $context->scope(Scope::ROUTE);

        $anonymous = $context->action(function (): void {});
        $named = $context->action(function (): void {}, 'toggle');

        expect($anonymous->id())->toBe('action0')
            ->and($named->id())->toBe('toggle')
            ->and($app->getScopedActions(Scope::routeScope('/game')))->toBe([])
        ;
    });

    test('each tab of a shared scope runs its own callback', function (string $scope): void {
        $app = createVia();
        $ran = [];

        $tabs = [];
        foreach (['ctx1', 'ctx2'] as $id) {
            $tab = new Context($id, '/game', $app);
            $tab->scope($scope);
            $tab->action(function (Context $c) use ($id, &$ran): void {
                $ran[] = $id . '@' . $c->getId();
            }, 'reset');
            $tabs[] = $tab;
        }

        $tabs[1]->executeAction('reset');
        $tabs[0]->executeAction('reset');

        expect($ran)->toBe(['ctx2@ctx2', 'ctx1@ctx1']);
    })->with([Scope::ROUTE, Scope::GLOBAL, 'room:lobby']);
});

describe('The removed $scope argument', function (): void {
    test('a third argument throws and names the fix', function (): void {
        $context = new Context(testContextId(), '/test', createVia());

        expect(fn () => $context->action(function (): void {}, 'globalAction', Scope::GLOBAL))
            ->toThrow(ArgumentCountError::class, 'The $scope argument of Context::action() was removed in php-via 0.14. An action runs for the tab that posts it: drop the third argument')
        ;
    });

    test('a named scope argument throws too', function (): void {
        $context = new Context(testContextId(), '/test', createVia());

        expect(fn () => $context->action(function (): void {}, 'tabAction', scope: Scope::TAB))
            ->toThrow(ArgumentCountError::class, 'drop the third argument')
        ;
    });
});

describe('Action URLs', function (): void {
    test('action URL contains action ID', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action = $context->action(function (): void {}, 'myAction');

        expect($action->url())->toContain($action->id());
    });
});

describe('Action URL Format', function (): void {
    test('action URL follows standard format', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action = $context->action(function (): void {}, 'myAction');

        // TAB-scoped named actions use a deterministic ID (the name) so a revived context
        // regenerates byte-identical URLs and the already-loaded DOM keeps working.
        expect($action->url())->toBe('/_action/myAction');
    });

    test('named TAB action IDs are deterministic across contexts (revival-stable)', function (): void {
        $app = createVia();

        $ctxA = new Context('ctxA', '/test', $app);
        $idA = $ctxA->action(function (): void {}, 'increment')->id();

        // A revived context re-runs the same handler; the action ID must match byte-for-byte.
        $ctxB = new Context('ctxA', '/test', $app);
        $idB = $ctxB->action(function (): void {}, 'increment')->id();

        expect($idA)->toBe('increment');
        expect($idB)->toBe('increment');
    });

    test('different actions have different URLs', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action1 = $context->action(function (): void {}, 'action1');
        $action2 = $context->action(function (): void {}, 'action2');

        expect($action1->url())->not->toBe($action2->url());
    });
});

describe('Component actions and the request', function (): void {
    test('a component action reads the request input and sets cookies on the page', function (): void {
        $app = createVia();
        $page = new Context(testContextId(), '/test', $app);
        $seen = null;
        $actionId = null;
        $page->component(function (Context $w) use (&$seen, &$actionId): void {
            $w->scope('widgets');
            $actionId = $w->action(function (Context $c) use (&$seen): void {
                $seen = $c->input('q');
                $c->setCookie('picked', 'yes');
            }, 'pick')->id();
            $w->view(fn (): string => 'widget');
        }, 'w');
        $page->setRequestInput(['q' => 'needle'], []);

        $page->executeAction((string) $actionId);

        expect($seen)->toBe('needle')
            ->and(array_column($page->flushPendingCookies(), 'value', 'name'))->toBe(['picked' => 'yes'])
        ;
    });
});

describe('Actions of components', function (): void {
    test('an action of a component in a custom scope runs, with the component', function (): void {
        $app = createVia();
        $page = new Context(testContextId(), '/test', $app);
        $ran = null;
        $component = null;
        $actionId = null;
        $page->component(function (Context $w) use (&$ran, &$component, &$actionId): void {
            $component = $w;
            $w->scope('widgets');
            $actionId = $w->action(function (Context $c) use (&$ran): void {
                $ran = $c;
            }, 'hit')->id();
            $w->view(fn (): string => 'widget');
        }, 'w');

        $page->executeAction((string) $actionId);

        expect($ran)->toBe($component);
    });

    test('an action of a component inside a component runs, with the inner component', function (): void {
        $app = createVia();
        $page = new Context(testContextId(), '/test', $app);
        $ran = null;
        $inner = null;
        $actionId = null;
        $page->component(function (Context $outer) use (&$ran, &$inner, &$actionId): void {
            $outer->component(function (Context $c) use (&$ran, &$inner, &$actionId): void {
                $inner = $c;
                $actionId = $c->action(function (Context $c) use (&$ran): void {
                    $ran = $c;
                }, 'bumpInner')->id();
                $c->view(fn (): string => 'inner');
            }, 'inner');
            $outer->view(fn (): string => 'outer');
        }, 'outer');

        $page->executeAction((string) $actionId);

        expect($ran)->toBe($inner);
    });

    test('a page without a component in that scope does not run its actions', function (): void {
        $app = createVia();
        $withWidget = new Context(testContextId(), '/test', $app);
        $withWidget->component(function (Context $w): void {
            $w->scope('widgets');
            $w->action(function (): void {}, 'hit');
            $w->view(fn (): string => 'widget');
        }, 'w');
        $other = new Context(testContextId(), '/test', $app);

        expect(fn () => $other->executeAction('w-hit'))->toThrow(RuntimeException::class, 'Action not found: w-hit');
    });
});
