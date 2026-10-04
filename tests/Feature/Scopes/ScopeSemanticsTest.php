<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Broadcast;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Attributes\Signal as SignalAttr;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * scope() sets the primary scope, which is no default for later declarations: actions are per tab,
 * a signal is shared only in the scope it names, and naming one joins the context to it.
 */

const SEMANTICS_SID = 'd00d0000d00d0000d00d0000d00d0000';

#[Broadcast(Scope::ROUTE)]
final class SemanticsBroadcastPage {
    #[SignalAttr]
    public string $draft = '';

    #[Persist]
    public int $clicks = 0;

    #[Persist]
    public string $owner = '';

    /** @var list<string> */
    public static array $ran = [];

    #[Action]
    public function add(Context $c): void {
        ++$this->clicks;
        self::$ran[] = "{$this->owner}:{$this->clicks}@{$c->getId()}";
    }

    public function view(Context $c): void {
        $this->owner = $c->getId();
        $c->view(fn () => 'x');
    }
}

final class SemanticsScopedActionPage {
    #[Persist]
    public string $owner = '';

    /** @var list<string> */
    public static array $ran = [];

    #[Action(scope: Scope::ROUTE)]
    public function bumpRoute(Context $c): void {
        self::$ran[] = "{$this->owner}@{$c->getId()}";
    }

    #[Action(scope: Scope::SESSION)]
    public function bumpSession(Context $c): void {
        self::$ran[] = "{$this->owner}@{$c->getId()}";
    }

    public function view(Context $c): void {
        $this->owner = $c->getId();
        $c->view(fn () => 'x');
    }
}

/**
 * A context registered the way RequestHandler registers one, with its initial patches drained.
 */
function semanticsTab(Via $app, string $id, string $route = '/p', ?string $session = SEMANTICS_SID): Context {
    $ctx = new Context($id, $route, $app, null, $session);
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);

    return $ctx;
}

function semanticsDrain(Context $ctx): int {
    $n = 0;
    while ($ctx->getPatch() !== null) {
        ++$n;
    }

    return $n;
}

describe('signal() after scope()', function (): void {
    test('without a scope it throws and says how to keep the old meaning', function (string $scope): void {
        $ctx = new Context('A', '/p', createVia(), null, SEMANTICS_SID);
        $ctx->scope($scope);

        expect(fn () => $ctx->signal(0, 'count'))
            ->toThrow(LogicException::class, "Context::signal() for 'count' has no scope, and this context's primary scope is")
        ;
    })->with([Scope::ROUTE, Scope::SESSION, Scope::GLOBAL, 'room:lobby']);

    test('with Scope::TAB it is private to the tab', function (): void {
        $app = createVia();
        $a = new Context('A', '/p', $app);
        $b = new Context('B', '/p', $app);
        $a->scope('room:lobby');
        $b->scope('room:lobby');

        $draftA = $a->signal('', 'draft', Scope::TAB);
        $draftB = $b->signal('', 'draft', Scope::TAB);
        $draftA->setValue('typed');

        expect($draftA->isScoped())->toBeFalse()
            ->and($draftB->getValue())->toBe('')
        ;
    });

    test('with the primary scope it is shared', function (): void {
        $app = createVia();
        $a = new Context('A', '/p', $app);
        $b = new Context('B', '/p', $app);
        $a->scope('room:lobby');
        $b->scope('room:lobby');

        expect($a->signal(0, 'count', 'room:lobby'))->toBe($b->signal(5, 'count', 'room:lobby'));
    });
});

describe('Scoped signals join their scope', function (): void {
    test('a write reaches every tab that declared the signal, without addScope()', function (string $scope): void {
        $app = createVia((new Config())->withBroadcastCoalescing(false));
        $tabs = [];
        foreach (['A', 'B'] as $id) {
            $ctx = semanticsTab($app, $id);
            $signal = $ctx->signal(0, 'cart', $scope);
            $ctx->view(fn () => "<div id='v'>{$signal->int()}</div>");
            semanticsDrain($ctx);
            $tabs[$id] = [$ctx, $signal];
        }

        $tabs['A'][1]->setValue(5);

        expect($tabs['A'][0]->getScopes())->toContain($tabs['A'][1]->getScope())
            ->and(semanticsDrain($tabs['A'][0]))->toBeGreaterThan(0)
            ->and(semanticsDrain($tabs['B'][0]))->toBeGreaterThan(0)
        ;
    })->with(['session' => Scope::SESSION, 'custom' => 'room:x', 'global' => Scope::GLOBAL]);

    test('scope() called after the signal keeps the scope it joined', function (): void {
        $app = createVia((new Config())->withBroadcastCoalescing(false));
        $tabs = [];
        foreach (['A', 'B'] as $id) {
            $ctx = semanticsTab($app, $id);
            $signal = $ctx->signal('', 'name', Scope::SESSION);
            $ctx->addScope('room:x');
            $ctx->scope(Scope::ROUTE);
            $ctx->view(fn () => "<div id='v'>{$signal->string()}</div>");
            semanticsDrain($ctx);
            $tabs[$id] = [$ctx, $signal];
        }
        $signalSent = static function (Context $ctx, string $signalId): bool {
            $sent = false;
            while (($patch = $ctx->getPatch()) !== null) {
                $sent = $sent || ($patch['type'] === 'signals' && is_array($patch['content']) && array_key_exists($signalId, $patch['content']));
            }

            return $sent;
        };

        $tabs['A'][1]->setValue('bob');

        expect($tabs['A'][0]->getScopes())->toBe([Scope::routeScope('/p'), Scope::sessionScope(SEMANTICS_SID), 'room:x'])
            ->and($signalSent($tabs['A'][0], $tabs['A'][1]->id()))->toBeTrue()
            ->and($signalSent($tabs['B'][0], $tabs['A'][1]->id()))->toBeTrue()
        ;
    });

    test('scope() still replaces the primary scope it set before', function (): void {
        $ctx = new Context('A', '/p', createVia(), null, SEMANTICS_SID);
        $ctx->scope('room:a');
        $ctx->scope(Scope::ROUTE);
        $ctx->addScope('room:b');
        $ctx->scope('room:b');

        expect($ctx->getScopes())->toBe(['room:b']);
    });
});

describe('Composition actions', function (): void {
    test('a #[Broadcast] page runs each tab\'s actions on that tab\'s instance', function (): void {
        SemanticsBroadcastPage::$ran = [];
        $app = createVia();
        $app->mount(SemanticsBroadcastPage::class, '/b');
        $handler = $app->getRouter()->getRoutes()['/b'];
        $a = semanticsTab($app, 'A', '/b');
        $app->invokeHandlerWithParams($handler, $a, []);
        $b = semanticsTab($app, 'B', '/b');
        $app->invokeHandlerWithParams($handler, $b, []);

        $a->executeAction('add');
        $b->executeAction('add');
        $b->executeAction('add');

        expect(SemanticsBroadcastPage::$ran)->toBe(['A:1@A', 'B:1@B', 'B:2@B'])
            ->and($a->getPrimaryScope())->toBe(Scope::routeScope('/b'))
        ;
    });

    test('a scoped #[Action] runs on the instance of the tab that posts it', function (string $action): void {
        SemanticsScopedActionPage::$ran = [];
        $app = createVia();
        $app->mount(SemanticsScopedActionPage::class, '/s');
        $handler = $app->getRouter()->getRoutes()['/s'];
        $a = semanticsTab($app, 'A', '/s');
        $app->invokeHandlerWithParams($handler, $a, []);
        $b = semanticsTab($app, 'B', '/s');
        $app->invokeHandlerWithParams($handler, $b, []);

        $b->executeAction($action);
        $a->executeAction($action);

        expect(SemanticsScopedActionPage::$ran)->toBe(['B@B', 'A@A']);
    })->with(['bumpRoute', 'bumpSession']);

    test('a scoped #[Action] of a component runs on that component\'s instance', function (): void {
        SemanticsScopedActionPage::$ran = [];
        $app = createVia();
        $pages = [];
        foreach (['A', 'B'] as $id) {
            $page = semanticsTab($app, $id, '/c');
            $page->component(SemanticsScopedActionPage::class, 'card');
            $page->view(fn () => 'page');
            $pages[$id] = $page;
        }
        $componentId = static fn (Context $page): string => array_values($page->getComponentRegistry())[0]->getId();

        $pages['B']->executeAction('card-bumpRoute');

        expect(SemanticsScopedActionPage::$ran)->toBe([$componentId($pages['B']) . '@' . $componentId($pages['B'])]);
    });
});
