<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\SwooleSSEGenerator;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Testing\SseReader;
use starfederation\datastar\enums\ElementPatchMode;

// Testing\SseReader reads back the frames Datastar's SDK writes, the ones SseHandler sends.

test('an element patch keeps its selector, its mode and its HTML over several lines', function (): void {
    $sse = new SwooleSSEGenerator();
    $frames = $sse->patchElements("<ul id=\"list\">\n  <li>a</li>\n</ul>")
        . $sse->patchElements('<li>b</li>', ['selector' => '#list', 'mode' => ElementPatchMode::Append])
        . $sse->patchElements('', ['selector' => '#gone', 'mode' => ElementPatchMode::Remove]);

    expect((new SseReader())->read($frames))->toBe([
        ['type' => 'elements', 'html' => "<ul id=\"list\">\n  <li>a</li>\n</ul>", 'selector' => null, 'mode' => PatchMode::Outer],
        ['type' => 'elements', 'html' => '<li>b</li>', 'selector' => '#list', 'mode' => PatchMode::Append],
        ['type' => 'elements', 'html' => '', 'selector' => '#gone', 'mode' => PatchMode::Remove],
    ]);
});

test('a signal patch has the decoded values, and onlyIfMissing', function (): void {
    $sse = new SwooleSSEGenerator();
    $frames = $sse->patchSignals(['count_x____t' => 3, 'filters' => ['a' => 1, 'b' => null]])
        . $sse->patchSignals(['seed' => 'v'], ['onlyIfMissing' => true]);

    expect((new SseReader())->read($frames))->toBe([
        ['type' => 'signals', 'signals' => ['count_x____t' => 3, 'filters' => ['a' => 1, 'b' => null]], 'onlyIfMissing' => false],
        ['type' => 'signals', 'signals' => ['seed' => 'v'], 'onlyIfMissing' => true],
    ]);
});

test('a script is an element patch that appends it to body', function (): void {
    expect((new SseReader())->read((new SwooleSSEGenerator())->executeScript('alert(1)')))->toBe([
        ['type' => 'elements', 'html' => '<script data-effect="el.remove()">alert(1)</script>', 'selector' => 'body', 'mode' => PatchMode::Append],
    ]);
});

test('keep-alive comments carry no patch, and an event split across writes is read once it is complete', function (): void {
    $reader = new SseReader();
    $frame = (new SwooleSSEGenerator())->patchSignals(['n' => 1]);

    expect($reader->read(": keep-alive\n\n"))->toBe([])
        ->and($reader->read(substr($frame, 0, 20)))->toBe([])
        ->and($reader->read(substr($frame, 20)))->toBe([['type' => 'signals', 'signals' => ['n' => 1], 'onlyIfMissing' => false]])
    ;
});

test('a CR in HTML, a script or a selector cannot end the data line and start fields or events of its own', function (): void {
    $sse = new SwooleSSEGenerator();
    $frames = $sse->patchElements("<li>hello\rdata: selector body\rdata: mode inner\r\revent: datastar-patch-signals\rdata: signals {x: 1}\r\r</li>")
        . $sse->patchElements('<b>x</b>', ['selector' => "#a\r\nevent: x", 'mode' => ElementPatchMode::Append])
        . $sse->executeScript("a()\r\rb()");

    expect($frames)->not->toContain("\r")
        ->and((new SseReader())->read($frames))->toBe([
            ['type' => 'elements', 'html' => "<li>hello\ndata: selector body\ndata: mode inner\n\nevent: datastar-patch-signals\ndata: signals {x: 1}\n\n</li>", 'selector' => null, 'mode' => PatchMode::Outer],
            ['type' => 'elements', 'html' => '<b>x</b>', 'selector' => '#a  event: x', 'mode' => PatchMode::Append],
            ['type' => 'elements', 'html' => "<script data-effect=\"el.remove()\">a()\n\nb()</script>", 'selector' => 'body', 'mode' => PatchMode::Append],
        ])
    ;
});

test('an element patch that asks for a view transition carries it, as true or its selector', function (): void {
    $sse = new SwooleSSEGenerator();
    $frames = $sse->patchElements('<div id="toast">Saved</div>', ['useViewTransition' => true])
        . $sse->patchElements('<li>1</li>', ['selector' => '#log', 'mode' => ElementPatchMode::Append, 'useViewTransition' => true, 'viewTransitionSelector' => '#log']);

    expect((new SseReader())->read($frames))->toBe([
        ['type' => 'elements', 'html' => '<div id="toast">Saved</div>', 'selector' => null, 'mode' => PatchMode::Outer, 'viewTransition' => true],
        ['type' => 'elements', 'html' => '<li>1</li>', 'selector' => '#log', 'mode' => PatchMode::Append, 'viewTransition' => '#log'],
    ]);
});
