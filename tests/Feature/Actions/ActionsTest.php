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

describe('Route-Scoped Actions', function (): void {
    test('route-scoped actions require a name', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/game', $app);
        $context->scope(Scope::ROUTE);

        expect(function () use ($context): void {
            $context->action(function (): void {}); // No name provided
        })->toThrow(InvalidArgumentException::class);
    });

    test('can create named route-scoped action', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/game', $app);
        $context->scope(Scope::ROUTE);

        $action = $context->action(function (): void {}, 'toggle');

        expect($action->id())->toBe('toggle');
    });

    test('route-scoped actions are reused across contexts', function (): void {
        $app = createVia();

        $ctx1 = new Context('ctx1', '/game', $app);
        $ctx1->scope(Scope::ROUTE);
        $action1 = $ctx1->action(function (): void {}, 'reset');

        $ctx2 = new Context('ctx2', '/game', $app);
        $ctx2->scope(Scope::ROUTE);
        $action2 = $ctx2->action(function (): void {}, 'reset');

        // Same action ID means they're the same action
        expect($action1->id())->toBe($action2->id());
    });
});

describe('Global-Scoped Actions', function (): void {
    test('global-scoped actions require a name', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);
        $context->scope(Scope::GLOBAL);

        expect(function () use ($context): void {
            $context->action(function (): void {}); // No name provided
        })->toThrow(InvalidArgumentException::class);
    });

    test('can create named global-scoped action', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/notifications', $app);
        $context->scope(Scope::GLOBAL);

        $action = $context->action(function (): void {}, 'add');

        expect($action->id())->toBe('add');
    });

    test('global actions are shared across all routes', function (): void {
        $app = createVia();

        $ctx1 = new Context('ctx1', '/page1', $app);
        $ctx1->scope(Scope::GLOBAL);
        $action1 = $ctx1->action(function (): void {}, 'notify');

        $ctx2 = new Context('ctx2', '/page2', $app);
        $ctx2->scope(Scope::GLOBAL);
        $action2 = $ctx2->action(function (): void {}, 'notify');

        // Same action ID across different routes
        expect($action1->id())->toBe($action2->id());
    });
});

describe('Explicit Scope Override', function (): void {
    test('can explicitly set action scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);
        // Context is TAB scope by default

        // But we can create a GLOBAL scoped action
        $action = $context->action(
            function (): void {},
            'globalAction',
            Scope::GLOBAL
        );

        expect($action->id())->toBe('globalAction');
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

        // URL format is /_action/{actionId}
        expect($action->url())->toMatch('#^/_action/[a-f0-9]+$#');
    });

    test('different actions have different URLs', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action1 = $context->action(function (): void {}, 'action1');
        $action2 = $context->action(function (): void {}, 'action2');

        expect($action1->url())->not->toBe($action2->url());
    });
});
