<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\Application;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\Via;

/*
 * Context::tabState() and setTabState(): server-side values of one tab that a revival brings back.
 * One worker keeps them in memory and in the revival record; several share them through the
 * context directory row, so every worker's copy of the context reads the same values.
 */

/**
 * A /report page whose handler records what tabState('result') held on each run.
 *
 * @param list<mixed> $seen
 */
function tabStateApp(array &$seen, ?Config $config = null): Via {
    $via = $config === null ? createVia() : new Via($config);
    $via->page('/report', function (Context $c) use (&$seen): void {
        $seen[] = $c->tabState('result');
        $c->action(function (Context $c): void {
            $c->setTabState('result', ['rows' => 3, 'html' => '<table></table>']);
        }, 'run');
        $c->view(fn (): string => '<div id="r">report</div>');
    });

    return $via;
}

/** Load /report like RequestHandler does, with the session 'sess'. */
function tabStateLoad(Via $via, string $contextId = '/report_/t1'): Context {
    $ctx = new Context($contextId, '/report', $via, null, 'sess');
    $via->contexts[$contextId] = $ctx;
    $via->invokeHandlerWithParams($via->getRouter()->getRoutes()['/report'], $ctx, []);
    $via->getApp()->registerContext($ctx);
    $via->getApp()->setContextSession($contextId, 'sess');
    $via->registerContextInScope($ctx, Scope::TAB);

    return $ctx;
}

function tabStateDestroy(Via $via, string $contextId): void {
    $via->getApp()->destroyContext($contextId);
    unset($via->contexts[$contextId]);
}

describe('tabState() on one worker', function (): void {
    test('returns the default until a value is set, and null removes the key', function (): void {
        $ctx = new Context('/p_/a', '/p', createVia());

        expect($ctx->tabState('cursor', 'none'))->toBe('none');
        $ctx->setTabState('cursor', 42);
        expect($ctx->tabState('cursor', 'none'))->toBe(42);
        $ctx->setTabState('cursor', null);
        expect($ctx->tabState('cursor', 'none'))->toBe('none')
            ->and($ctx->localTabState())->toBe([])
        ;
    });

    test('values are copies', function (): void {
        $ctx = new Context('/p_/a', '/p', createVia());
        $value = new ArrayObject(['a' => 1]);

        $ctx->setTabState('obj', $value);
        $value['a'] = 2;
        $read = $ctx->tabState('obj');
        $read['a'] = 3;

        expect($ctx->tabState('obj')['a'])->toBe(1);
    });

    test('a value that cannot be serialized throws', function (): void {
        $ctx = new Context('/p_/a', '/p', createVia());

        expect(fn () => $ctx->setTabState('fn', fn (): int => 1))
            ->toThrow(InvalidArgumentException::class, 'Tab state "fn" cannot be serialized')
        ;
    });

    test('a component has keys of its own, apart from its page and its siblings', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $components = [];
        foreach (['left', 'right'] as $name) {
            $page->component(function (Context $c) use (&$components, $name): void {
                $components[$name] = $c;
                $c->view(fn (): string => '<p></p>');
            }, $name);
        }

        $page->setTabState('open', 'page');
        $components['left']->setTabState('open', 'left');

        expect($page->tabState('open'))->toBe('page')
            ->and($components['left']->tabState('open'))->toBe('left')
            ->and($components['right']->tabState('open'))->toBeNull()
            ->and(array_keys($page->localTabState()))->toBe(['', 'component:left'])
        ;
    });

    test('a revived context reads the values its tab set before it was destroyed', function (): void {
        $seen = [];
        $via = tabStateApp($seen);
        $ctx = tabStateLoad($via);
        $ctx->executeAction('run');

        tabStateDestroy($via, '/report_/t1');
        $revived = $via->reviveContextFromClient('/report_/t1', 'sess', []);

        expect($seen)->toBe([null, ['rows' => 3, 'html' => '<table></table>']])
            ->and($revived?->tabState('result'))->toBe(['rows' => 3, 'html' => '<table></table>'])
        ;
    });

    test('a page load starts without values, and so does a context whose record is gone', function (): void {
        $seen = [];
        $via = tabStateApp($seen);
        tabStateLoad($via)->executeAction('run');
        tabStateDestroy($via, '/report_/t1');
        $via->getApp()->forgetRevivable('/report_/t1');

        tabStateLoad($via, '/report_/t2');

        expect($via->reviveContextFromClient('/report_/t1', 'sess', []))->toBeNull()
            ->and($seen)->toBe([null, null])
        ;
    });

    test('revival records past the tab state budget are evicted, oldest first', function (): void {
        $seen = [];
        $via = tabStateApp($seen);
        (new ReflectionProperty(Application::class, 'revivableStateBudget'))->setValue($via->getApp(), 300);

        foreach (['/report_/a', '/report_/b', '/report_/c'] as $id) {
            $ctx = tabStateLoad($via, $id);
            $ctx->setTabState('blob', str_repeat('x', 120));
            ob_start();
            tabStateDestroy($via, $id);
            ob_end_clean();
        }

        expect($via->getApp()->getRevivable('/report_/a'))->toBeNull()
            ->and($via->getApp()->getRevivable('/report_/b'))->not->toBeNull()
            ->and($via->getApp()->getRevivable('/report_/c')['tabState'] ?? null)->not->toBeNull()
        ;
    });

    test('a value of any size is kept, since only the revival records have a budget', function (): void {
        $ctx = new Context('/p_/a', '/p', createVia());

        $ctx->setTabState('big', str_repeat('y', 100_000));

        expect(strlen($ctx->tabState('big')))->toBe(100_000);
    });
});

describe('tabState() with more than one worker', function (): void {
    /**
     * Two Via instances on one directory stand in for two workers; $seenB collects what B's handler read.
     *
     * @param list<mixed> $seenB
     *
     * @return array{0: Via, 1: Via, 2: SharedContextDirectory}
     */
    function tabStateWorkers(int $maxStateBytes = 1024, array &$seenB = []): array {
        $directory = new SharedContextDirectory(maxRows: 64, maxStateBytes: $maxStateBytes);
        $seenA = [];
        $a = tabStateApp($seenA);
        $b = tabStateApp($seenB);
        $a->getApp()->setContextDirectory($directory);
        $b->getApp()->setContextDirectory($directory);

        return [$a, $b, $directory];
    }

    test('a copy rebuilt on another worker reads and writes the values the first one sees', function (): void {
        [$a, $b, $directory] = tabStateWorkers();
        $ctx = tabStateLoad($a);
        $ctx->setTabState('cursor', 10);

        $copy = $b->reviveContextFromClient('/report_/t1', 'sess', []);
        $copy?->executeAction('run');
        $copy?->setTabState('cursor', 11);

        expect($copy?->tabState('cursor'))->toBe(11)
            ->and($ctx->tabState('cursor'))->toBe(11)
            ->and($ctx->tabState('result'))->toBe(['rows' => 3, 'html' => '<table></table>'])
            ->and($ctx->localTabState())->toBe([])
            ->and($directory->getState('/report_/t1'))->toHaveKey('')
        ;
    });

    test('values the page handler set before the record existed move into the directory row', function (): void {
        [$a, $b, $directory] = tabStateWorkers();
        $a->page('/seeded', function (Context $c): void {
            $c->setTabState('from', 'handler');
            $c->view(fn (): string => '<p></p>');
        });
        $ctx = new Context('/seeded_/s1', '/seeded', $a, null, 'sess');
        $a->contexts['/seeded_/s1'] = $ctx;
        $a->invokeHandlerWithParams($a->getRouter()->getRoutes()['/seeded'], $ctx, []);

        expect($ctx->localTabState())->not->toBe([]);
        $a->getApp()->registerContext($ctx);

        expect($ctx->localTabState())->toBe([])
            ->and($ctx->tabState('from'))->toBe('handler')
            ->and($directory->getState('/seeded_/s1')['']['from'] ?? null)->toBe(serialize('handler'))
        ;
    });

    test('a revival after the first worker destroyed the context reads the values from the row', function (): void {
        $seenB = [];
        [$a, $b] = tabStateWorkers(seenB: $seenB);
        tabStateLoad($a)->executeAction('run');
        tabStateDestroy($a, '/report_/t1');

        $b->reviveContextFromClient('/report_/t1', 'sess', []);

        expect($seenB)->toBe([['rows' => 3, 'html' => '<table></table>']]);
    });

    test('a write past maxTabStateBytes throws OverflowException and keeps the earlier values', function (): void {
        [$a] = tabStateWorkers(maxStateBytes: 128);
        $ctx = tabStateLoad($a);
        $ctx->setTabState('small', 'ok');

        expect(fn () => $ctx->setTabState('large', str_repeat('z', 200)))
            ->toThrow(OverflowException::class, 'maxTabStateBytes')
            ->and($ctx->tabState('small'))->toBe('ok')
            ->and($ctx->tabState('large'))->toBeNull()
        ;
    });

    test('a page handler write past maxTabStateBytes throws before the record exists', function (): void {
        [$a] = tabStateWorkers(maxStateBytes: 128);
        $ctx = new Context('/report_/early', '/report', $a, null, 'sess');

        expect(fn () => $ctx->setTabState('large', str_repeat('z', 200)))->toThrow(OverflowException::class, 'maxTabStateBytes');
    });

    test('forgetting the record drops the values with it', function (): void {
        [$a, , $directory] = tabStateWorkers();
        tabStateLoad($a)->executeAction('run');

        $a->getApp()->forgetRevivable('/report_/t1');

        expect($directory->getState('/report_/t1'))->toBeNull();
    });

    test('a heartbeat that rewrites the record keeps the values', function (): void {
        [$a, , $directory] = tabStateWorkers();
        $ctx = tabStateLoad($a);
        $ctx->executeAction('run');

        $a->getApp()->refreshContextRecord($ctx);

        expect($directory->getState('/report_/t1')[''] ?? [])->toHaveKey('result');
    });

    test('with revival off, the values stay on the worker', function (): void {
        $directory = new SharedContextDirectory(maxRows: 64, maxStateBytes: 128);
        $seen = [];
        $via = tabStateApp($seen, (new Config())->withLogLevel('error')->withContextTimeouts(revivalWindowMs: 0));
        $via->getApp()->setContextDirectory($directory);
        $ctx = tabStateLoad($via);

        $ctx->setTabState('large', str_repeat('z', 200));

        expect($ctx->tabState('large'))->toBe(str_repeat('z', 200))
            ->and($directory->count())->toBe(0)
        ;
    });
});
