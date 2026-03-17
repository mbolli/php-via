<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3005)
        ->withDevMode(true)
        ->withTemplateDir(__DIR__ . '/templates')
);

// Define a reusable counter component
$counterComponent = function (Context $c): void {
    $count = $c->signal(0, 'count');

    $increment = $c->action(function () use ($count, $c): void {
        $count->setValue($count->int() + 1);
        $c->sync();
    }, 'increment');

    $decrement = $c->action(function () use ($count, $c): void {
        $count->setValue($count->int() - 1);
        $c->sync();
    }, 'decrement');

    $c->view('component_counter.html.twig', [
        'namespace' => $c->getNamespace(),
        'count' => $count,
        'increment' => $increment,
        'decrement' => $decrement,
    ]);
};

$app->page('/', function (Context $c) use ($counterComponent): void {
    // Each component gets its own isolated namespace
    $counter1 = $c->component($counterComponent, 'counter1');
    $counter2 = $c->component($counterComponent, 'counter2');
    $counter3 = $c->component($counterComponent, 'counter3');

    $c->view('components.html.twig', [
        'counter1' => $counter1(),
        'counter2' => $counter2(),
        'counter3' => $counter3(),
    ]);
});

$app->start();
