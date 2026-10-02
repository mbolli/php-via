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

    test('a single unregister keeps the context in its other scopes for teardown', function (): void {
        $app = createVia();
        $ctx = new Context('/x_/1', '/x', $app);
        $registry = new ScopeRegistry();
        $registry->registerContext($ctx, 'room:1');
        $registry->registerContext($ctx, 'room:2');

        $registry->unregisterContext($ctx, 'room:1');

        expect($registry->unregisterContextFromAllScopes($ctx))->toBe(['room:2']);
    });
});
