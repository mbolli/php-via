<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal as SignalAttr;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\DevBar\DevBarController;
use Mbolli\PhpVia\Scope;

/*
 * One resolver turns Scope::ROUTE and Scope::SESSION into the scopes contexts register under,
 * wherever a scope is passed, and session scopes never carry the session id (the HttpOnly cookie).
 */

const RESOLVER_SID_A = 'c0ffee00c0ffee00c0ffee00c0ffee00';
const RESOLVER_SID_B = 'facade00facade00facade00facade00';

final class ResolverSessionActionPage {
    /** @var list<string> */
    public static array $ran = [];

    public string $owner = '';

    #[Action(scope: Scope::SESSION)]
    public function bump(Context $c): void {
        self::$ran[] = $this->owner . '@' . $c->getId();
    }

    public function view(Context $c): void {
        $this->owner = $c->getId();
        $c->view(fn () => '<p>x</p>');
    }
}

final class ResolverSessionSignalPage {
    #[SignalAttr(Scope::SESSION)]
    public string $name = '';

    public function view(Context $ctx): void {
        $ctx->view(fn () => '<p>hi</p>');
    }
}

describe('Scope::sessionScope()', function (): void {
    test('derives an opaque scope from the session id without containing it', function (): void {
        $scope = Scope::sessionScope(RESOLVER_SID_A);

        expect($scope)->toMatch('/^session:[0-9a-f]{32}$/')
            ->and($scope)->toBe('session:' . substr(hash('sha256', 'via.session-scope|' . RESOLVER_SID_A), 0, 32))
            ->and($scope)->not->toContain(RESOLVER_SID_A)
            ->and(Scope::sessionScope(RESOLVER_SID_B))->not->toBe($scope)
            ->and(Scope::isValidWireScope($scope))->toBeTrue()
        ;
    });
});

describe('Context::scope()', function (): void {
    test('Scope::SESSION puts each session in its own scope', function (): void {
        $app = createVia();
        $a = new Context('A', '/p', $app, null, RESOLVER_SID_A);
        $b = new Context('B', '/q', $app, null, RESOLVER_SID_B);
        $a2 = new Context('A2', '/q', $app, null, RESOLVER_SID_A);

        $a->scope(Scope::SESSION);
        $b->scope(Scope::SESSION);
        $a2->scope(Scope::SESSION);

        expect($a->getPrimaryScope())->toBe(Scope::sessionScope(RESOLVER_SID_A))
            ->and($b->getPrimaryScope())->toBe(Scope::sessionScope(RESOLVER_SID_B))
            ->and($a2->getPrimaryScope())->toBe($a->getPrimaryScope())
            ->and($app->getLocalContexts(Scope::SESSION))->toBe([])
            ->and(array_map(static fn (Context $c): string => $c->getId(), $app->getLocalContexts(Scope::sessionScope(RESOLVER_SID_A))))->toBe(['A', 'A2'])
        ;
    });

    test('Scope::SESSION on a context without a session throws', function (): void {
        $app = createVia();
        $ctx = new Context('N', '/p', $app);

        expect(fn () => $ctx->scope(Scope::SESSION))->toThrow(LogicException::class, 'has no session');
    });
});

describe('Context::addScope() and removeScope()', function (): void {
    test('resolve Scope::ROUTE and Scope::SESSION like scope() does', function (): void {
        $app = createVia();
        $ctx = new Context('C', '/p', $app, null, RESOLVER_SID_A);

        $ctx->addScope(Scope::ROUTE);
        $ctx->addScope(Scope::SESSION);

        expect($ctx->getScopes())->toBe([Scope::TAB, Scope::routeScope('/p'), Scope::sessionScope(RESOLVER_SID_A)])
            ->and($app->getLocalContexts(Scope::routeScope('/p')))->toBe([$ctx])
            ->and($app->getLocalContexts(Scope::ROUTE))->toBe([])
            ->and($app->getLocalContexts(Scope::SESSION))->toBe([])
        ;

        $ctx->removeScope(Scope::ROUTE);
        $ctx->removeScope(Scope::SESSION);

        expect($ctx->getScopes())->toBe([Scope::TAB])
            ->and($app->getLocalContexts(Scope::routeScope('/p')))->toBe([])
            ->and($app->getLocalContexts(Scope::sessionScope(RESOLVER_SID_A)))->toBe([])
        ;
    });
});

describe('SESSION signals', function (): void {
    test('carry the hashed session scope in their scope and browser id, never the session id', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/demo', $app, null, RESOLVER_SID_A);

        $signal = $ctx->signal('x', 'theme', Scope::SESSION);

        expect($signal->getScope())->toBe(Scope::sessionScope(RESOLVER_SID_A))
            ->and($signal->id())->not->toContain(RESOLVER_SID_A)
            ->and($signal->bind())->not->toContain(RESOLVER_SID_A)
        ;
    });

    test('keep the session id out of the Dev Bar scope list and its traces', function (): void {
        $app = createVia((new Config())->withDevMode(false)->withDevBar(true)->withBroadcastCoalescing(false));
        $app->mount(ResolverSessionSignalPage::class, '/demo');
        $ctx = new Context('ctx1', '/demo', $app, null, RESOLVER_SID_A);
        $app->contexts['ctx1'] = $ctx;
        $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/demo'], $ctx, []);

        $ctx->getSignal('name')?->setValue('bob');

        $traces = json_encode($app->getTraceStore()?->recent(50) ?? []);
        expect(json_encode((new DevBarController($app))->buildScopesSnapshot()))->not->toContain(RESOLVER_SID_A)
            ->and($traces)->toContain(Scope::sessionScope(RESOLVER_SID_A))
            ->and($traces)->not->toContain(RESOLVER_SID_A)
        ;
    });
});

describe('SESSION actions', function (): void {
    test('#[Action(scope: Scope::SESSION)] is found from every tab of the session', function (): void {
        ResolverSessionActionPage::$ran = [];
        $app = createVia();
        $app->mount(ResolverSessionActionPage::class, '/s');
        $handler = $app->getRouter()->getRoutes()['/s'];

        $a = new Context('A', '/s', $app, null, RESOLVER_SID_A);
        $app->invokeHandlerWithParams($handler, $a, []);
        $b = new Context('B', '/s', $app, null, RESOLVER_SID_A);
        $app->invokeHandlerWithParams($handler, $b, []);

        $a->executeAction('bump');
        $b->executeAction('bump');

        expect(ResolverSessionActionPage::$ran)->toBe(['A@A', 'B@B'])
            ->and($app->getScopedActions(Scope::SESSION))->toBe([])
            ->and($app->getScopedActions(Scope::sessionScope(RESOLVER_SID_A)))->toHaveKey('bump')
        ;
    });

    test('a SESSION action is dropped with the last tab of its session', function (): void {
        $app = createVia();
        $app->mount(ResolverSessionActionPage::class, '/s');
        $handler = $app->getRouter()->getRoutes()['/s'];
        foreach (['A', 'B'] as $id) {
            $ctx = new Context($id, '/s', $app, null, RESOLVER_SID_A);
            $app->contexts[$id] = $ctx;
            $app->getApp()->registerContext($ctx);
            $app->registerContextInScope($ctx, Scope::TAB);
            $app->invokeHandlerWithParams($handler, $ctx, []);
        }

        $app->getApp()->destroyContext('A');
        $afterFirst = $app->getScopedActions(Scope::sessionScope(RESOLVER_SID_A));
        $app->getApp()->destroyContext('B');

        expect($afterFirst)->toHaveKey('bump')
            ->and($app->getScopedActions(Scope::sessionScope(RESOLVER_SID_A)))->toBe([])
        ;
    });
});
