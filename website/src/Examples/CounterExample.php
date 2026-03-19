<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class CounterExample {
    public const string SLUG = 'counter';

    public static function register(Via $app): void {
        $app->page('/examples/counter', function (Context $c): void {
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

            $c->view('examples/counter.html.twig', [
                'title' => '⚡ Counter',
                'description' => 'Counter with configurable step. Uses data-bind for two-way input binding.',
                'summary' => [
                    '<strong>Signals</strong> hold reactive state. The count and step values are signals — when they change on the server, the UI updates instantly via SSE.',
                    '<strong>data-bind</strong> creates two-way binding between an input and a signal. Type a new step value and it syncs to the server automatically.',
                    '<strong>Actions</strong> are server-side functions triggered by button clicks. Each action modifies the signal and pushes the new value to the browser.',
                    '<strong>No JavaScript authored</strong> — every interaction is a server round-trip. Datastar handles the SSE connection, DOM patching, and signal store transparently.',
                    '<strong>TAB scope</strong> (the default) means each browser tab has its own independent counter. Open two tabs — clicking in one will not affect the other.',
                    '<strong>syncSignals()</strong> flushes changed signal values down the SSE channel. It is called automatically after an action, so the UI always reflects the latest state.',
                ],
                'sourceFile' => 'counter.php',
                'templateFiles' => ['counter.html.twig'],
                'count' => $count,
                'step' => $step,
                'increment' => $increment,
                'decrement' => $decrement,
                'reset' => $reset,
            ]);
        });
    }
}
