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
