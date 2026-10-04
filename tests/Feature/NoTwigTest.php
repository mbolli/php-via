<?php

declare(strict_types=1);

/*
 * twig/twig is only suggested: an app without it runs closure views and its own template engine,
 * and a Twig setup fails with the composer command (Fixtures/no_twig_app.php).
 */

test('the core runs closure views without Twig installed, and every Twig entry point names composer require twig/twig', function (): void {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/no_twig_app.php') . ' 2>&1'
    );
    preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);
    $r = [];
    foreach ($m as [, $key, $value]) {
        $r[$key] = $value;
    }

    expect($r['done'] ?? null)->toBe('1', $out)
        ->and($r['twig_loadable'])->toBe('0')
        ->and($r['page_has_via_head'])->toBe('1')
        ->and($r['page_has_via_foot'])->toBe('1')
        ->and($r['page_has_content'])->toBe('1')
        ->and($r['update'])->toBe('<p id="count">2</p>')
        ->and($r['template_view'])->toStartWith("LogicException: view('page.html.twig') renders a template, and this app has no template engine.")
        ->and($r['template_view'])->toContain('composer require twig/twig')
        ->and($r['template_view'])->toContain('->withTemplateEngine(new \\Mbolli\\PhpVia\\Twig\\TwigEngine(')
        ->and($r['get_twig'])->toContain('LogicException: getTwig() needs Twig templates')
        ->and($r['template_dir'])->toBe('LogicException: Twig templates need twig/twig, which is not installed: run composer require twig/twig')
        ->and($r['own_engine'])->toBe('own.tpl:7:head')
        ->and($r['twig_classes_loaded'])->toBe('0')
    ;
});
