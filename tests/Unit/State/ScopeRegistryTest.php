<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\ScopeRegistry;

describe('ScopeRegistry', function (): void {
    test('unregistering from all scopes also removes scopes the context no longer lists', function (): void {
        $app = createVia();
        $ctx = new Context('/x_/1', '/x', $app);
        $registry = new ScopeRegistry();

        // The TAB entry and an earlier scope are registered, but scope() replaced the list.
        $registry->registerContext($ctx, Scope::TAB);
        $registry->registerContext($ctx, 'room:old');
        $registry->registerContext($ctx, 'room:new');

        $emptied = $registry->unregisterContextFromAllScopes($ctx);

        expect($emptied)->toEqualCanonicalizing([Scope::TAB, 'room:old', 'room:new'])
            ->and($registry->getAllScopes())->toBe([])
        ;
    });

    test('a scope another context still holds is not reported as emptied', function (): void {
        $app = createVia();
        $a = new Context('/x_/a', '/x', $app);
        $b = new Context('/x_/b', '/x', $app);
        $registry = new ScopeRegistry();
        $registry->registerContext($a, 'room:1');
        $registry->registerContext($b, 'room:1');

        expect($registry->unregisterContextFromAllScopes($a))->toBe([])
            ->and($registry->getContextsByScope('room:1'))->toBe([$b])
        ;
    });

    test('a single unregister stops the broadcasts of one scope and keeps the context a member of both', function (): void {
        $app = createVia();
        $ctx = new Context('/x_/1', '/x', $app);
        $registry = new ScopeRegistry();
        $registry->registerContext($ctx, 'room:1');
        $registry->registerContext($ctx, 'room:2');

        $registry->unregisterContext($ctx, 'room:1');

        expect($registry->getContextsByScope('room:1'))->toBe([])
            ->and($registry->getMemberCount('room:1'))->toBe(1)
            ->and($registry->unregisterContextFromAllScopes($ctx))->toEqualCanonicalizing(['room:1', 'room:2'])
        ;
    });

    test('a context whose stream ended keeps its scope from emptying when another member goes', function (): void {
        $app = createVia();
        $away = new Context('/x_/away', '/x', $app);
        $gone = new Context('/x_/gone', '/x', $app);
        $registry = new ScopeRegistry();
        $registry->registerContext($away, 'room:1');
        $registry->registerContext($gone, 'room:1');

        $registry->unregisterContext($away, 'room:1');

        expect($registry->unregisterContextFromAllScopes($gone))->toBe([])
            ->and($registry->getAllScopes())->toBe([])
            ->and($registry->unregisterContextFromAllScopes($away))->toBe(['room:1'])
        ;
    });

    test('retain() makes a member that broadcasts do not render, and registering it later counts it once', function (): void {
        $app = createVia();
        $ctx = new Context('/x_/1', '/x', $app);
        $registry = new ScopeRegistry();

        $registry->retain($ctx, Scope::GLOBAL);
        expect($registry->getContextsByScope(Scope::GLOBAL))->toBe([])
            ->and($registry->getMemberCount(Scope::GLOBAL))->toBe(1)
        ;

        $registry->registerContext($ctx, Scope::GLOBAL);
        $registry->retain($ctx, Scope::GLOBAL);
        expect($registry->getContextsByScope(Scope::GLOBAL))->toBe([$ctx])
            ->and($registry->getMemberCount(Scope::GLOBAL))->toBe(1)
            ->and($registry->unregisterContextFromAllScopes($ctx))->toBe([Scope::GLOBAL])
        ;
    });

    test('a revived context and the one torn down under the same id are two members', function (): void {
        $app = createVia();
        $old = new Context('/x_/1', '/x', $app);
        $new = new Context('/x_/1', '/x', $app);
        $registry = new ScopeRegistry();
        $registry->registerContext($old, 'room:1');
        $registry->registerContext($new, 'room:1');

        expect($registry->unregisterContextFromAllScopes($old))->toBe([])
            ->and($registry->getContextsByScope('room:1'))->toBe([$new])
            ->and($registry->unregisterContextFromAllScopes($new))->toBe(['room:1'])
        ;
    });
});
