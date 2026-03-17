<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3001)
        ->withDevMode(true)
);

$app->page('/', function (Context $c): void {
    $count = $c->signal(0, 'count');
    $step = $c->signal(1, 'step');

    $increment = $c->action(function () use ($count, $step, $c): void {
        $count->setValue($count->int() + $step->int());
        $c->syncSignals();
    }, 'increment');

    $decrement = $c->action(function () use ($count, $step, $c): void {
        $count->setValue($count->int() - $step->int());
        $c->syncSignals();
    }, 'decrement');

    $reset = $c->action(function () use ($count, $c): void {
        $count->setValue(0);
        $c->syncSignals();
    }, 'reset');

    $c->html(<<<HTML
        <div class="text-center">
            <h1>⚡ Via Counter</h1>
            <p class="counter-display">{$count->int()}</p>
            <label>Step: <input type="number" {$step->bind()}></label>
            <div class="flex gap-1 justify-center mt-2">
                <button data-on:click="@post('{$increment->url()}')">+ Increment</button>
                <button data-on:click="@post('{$decrement->url()}')">− Decrement</button>
                <button data-on:click="@post('{$reset->url()}')">Reset</button>
            </div>
        </div>
    HTML);
});

$app->start();
