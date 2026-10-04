<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

test('a component on a route with parameters patches the id it was rendered with', function (): void {
    $app = createVia();
    $page = new Context('/blog/{slug}/_/a1b2c3', '/blog/{slug}', $app);

    $component = null;
    $render = $page->component(function (Context $c) use (&$component): void {
        $component = $c;
        $c->view(fn (): string => '<span>card</span>');
    }, 'card');
    $page->view(fn (): string => '<main>' . $render() . '</main>');

    preg_match('/<div id="([^"]+)">/', $render(), $match);
    $wrapperId = $match[1] ?? '';

    expect($wrapperId)->toMatch('/^c-[A-Za-z0-9-]+$/');

    $component->sync();
    $patch = $page->getPatch();

    expect($patch['type'] ?? null)->toBe('elements')
        ->and($patch['selector'] ?? null)->toBe('#' . $wrapperId)
    ;
});
