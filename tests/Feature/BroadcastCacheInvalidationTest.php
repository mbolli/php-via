<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * The update cache is keyed by a context's primary scope. A broadcast that reaches contexts any
 * other way (a secondary scope, a wildcard, GLOBAL) must not serve them an update cached earlier.
 */

/** A page whose primary scope is 'room:main', also in 'alerts', rendering a global probe. */
function cacheProbeContext(Via $app, string $id): Context {
    $c = new Context($id, '/a', $app);
    $c->scope('room:main');
    $c->addScope('alerts');
    $c->view(fn (): string => '<p>v=' . $GLOBALS['cache_probe'] . '</p>');
    $app->contexts[$id] = $c;
    $app->getApp()->registerContext($c);

    return $c;
}

/** @return list<string> the element patches queued for $c, oldest first */
function cacheProbeElements(Context $c): array {
    $html = [];
    while (($patch = $c->getPatch()) !== null) {
        if ($patch['type'] === 'elements') {
            $html[] = (string) $patch['content'];
        }
    }

    return $html;
}

describe('Update cache across broadcast paths', function (): void {
    beforeEach(function (): void {
        $GLOBALS['cache_probe'] = 1;
    });

    test('a broadcast through a secondary scope renders the current state', function (): void {
        $app = createVia();
        $a = cacheProbeContext($app, '/a_/1');
        $b = cacheProbeContext($app, '/a_/2');
        $app->broadcast('room:main');
        cacheProbeElements($a);
        cacheProbeElements($b);

        $GLOBALS['cache_probe'] = 2;
        $app->broadcast('alerts');

        expect(implode('', cacheProbeElements($a)))->toContain('v=2')
            ->and(implode('', cacheProbeElements($b)))->toContain('v=2')
        ;
    });

    test('a wildcard broadcast renders the current state', function (): void {
        $app = createVia();
        $a = cacheProbeContext($app, '/a_/1');
        $app->broadcast('room:main');
        cacheProbeElements($a);

        $GLOBALS['cache_probe'] = 3;
        $app->broadcast('room:*');

        expect(implode('', cacheProbeElements($a)))->toContain('v=3');
    });

    test('a GLOBAL broadcast renders the current state', function (): void {
        $app = createVia();
        $a = cacheProbeContext($app, '/a_/1');
        $app->broadcast('room:main');
        cacheProbeElements($a);

        $GLOBALS['cache_probe'] = 4;
        $app->broadcast(Scope::GLOBAL);

        expect(implode('', cacheProbeElements($a)))->toContain('v=4');
    });
});

/** @return array<string, string> key => value from the fixture's key=value lines */
function viewCacheFixture(string $case): array {
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/view_cache_cases.php') . ' ' . escapeshellarg($case) . ' 2>&1');
    preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m, PREG_SET_ORDER);
    $values = ['out' => $out];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    return $values;
}

describe('Update cache in coalesced and concurrent broadcasts', function (): void {
    test('one flush of several scopes that reach one primary scope renders it once, with fresh state', function (): void {
        $r = viewCacheFixture('flush');

        expect($r['renders'] ?? null)->toBe('1', $r['out'])
            ->and($r['fresh'] ?? null)->toBe('4', $r['out'])
        ;
    });

    test('a render that started before a newer broadcast does not overwrite the newer cached update', function (): void {
        $r = viewCacheFixture('concurrent');

        expect($r['sync_fresh'] ?? null)->toBe('1', $r['out']);
    });

    test('two revivals of one context return the same context and leave no timers behind', function (): void {
        $r = viewCacheFixture('revival');

        expect($r['same_context'] ?? null)->toBe('1', $r['out'])
            ->and($r['ticks_after_destroy'] ?? null)->toBe('0', $r['out'])
        ;
    });

    test('a component re-rendered with its page renders the current state', function (): void {
        $GLOBALS['cache_probe'] = 1;
        $app = createVia();
        $page = new Context('/a_/1', '/a', $app);
        $widget = $page->component(static function (Context $w): void {
            $w->scope('widgets');
            $w->view(static fn (): string => '<span>w=' . $GLOBALS['cache_probe'] . '</span>');
        }, 'w');
        // The page's update frame leaves the component out, so the component syncs on its own.
        $page->view(static fn (bool $isUpdate): string => $isUpdate ? '<div id="page">header</div>' : '<div id="page">header' . $widget() . '</div>');
        $app->contexts['/a_/1'] = $page;
        $app->getApp()->registerContext($page);
        $app->broadcast('widgets');
        cacheProbeElements($page);

        $GLOBALS['cache_probe'] = 2;
        $app->broadcast(Scope::routeScope('/a'));

        expect(implode('', cacheProbeElements($page)))->toContain('w=2');
    });
});
