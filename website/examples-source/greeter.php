<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3003)
        ->withDevMode(true)
        ->withTemplateDir(__DIR__ . '/templates')
);

$app->page('/', function (Context $c): void {
    $greeting = $c->signal('Hello...', 'greeting');

    $greetBob = $c->action(function () use ($greeting, $c): void {
        $greeting->setValue('Hello Bob!');
        $c->syncSignals();
    }, 'greetBob');

    $greetAlice = $c->action(function () use ($greeting, $c): void {
        $greeting->setValue('Hello Alice!');
        $c->syncSignals();
    }, 'greetAlice');

    // Uses Twig template: greeter.html.twig
    $c->view('greeter.html.twig', [
        'greeting' => $greeting,
        'greet_bob' => $greetBob,
        'greet_alice' => $greetAlice,
    ]);
});

$app->start();
