<?php

declare(strict_types=1);

/*
 * Datastar 1.0 reads an event listener only as data-on:<event>. The dash form names a plugin, and
 * the only dash-form plugins are on-interval, on-intersect and on-signal-patch; any other
 * data-on-<x> attribute is silently ignored.
 */

describe('default shell template', function (): void {
    test('every data-on- attribute names a Datastar plugin', function (): void {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Rendering/shell.html');
        preg_match_all('/\sdata-on-([a-z-]+)/', $shell, $m);

        foreach ($m[1] as $name) {
            expect($name)->toMatch('/^(interval|intersect|signal-patch)(-|$)/');
        }
    });

    test('the connection warning listens to datastar-fetch events', function (): void {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Rendering/shell.html');

        expect($shell)->toContain('data-on:datastar-fetch="')
            ->and($shell)->toContain('data-show="$_disconnected"')
        ;
    });
});
