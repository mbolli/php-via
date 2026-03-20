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
                'anatomy' => [
                    'signals' => [
                        ['name' => 'count', 'type' => 'int', 'scope' => 'TAB', 'default' => '0', 'desc' => 'Current counter value. Updated by increment, decrement, and reset actions.'],
                        ['name' => 'step', 'type' => 'int', 'scope' => 'TAB', 'default' => '1', 'desc' => 'Increment/decrement step size. Two-way bound to the input via data-bind.'],
                    ],
                    'actions' => [
                        ['name' => 'increment', 'desc' => 'Adds step to count, then syncs the new value to the browser.'],
                        ['name' => 'decrement', 'desc' => 'Subtracts step from count.'],
                        ['name' => 'reset', 'desc' => 'Resets count back to 0, ignoring the current step value.'],
                    ],
                    'views' => [
                        ['name' => 'counter.html.twig', 'desc' => 'Uses data-text for reactive count display and data-bind for two-way step input.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/CounterExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/counter.html.twig'],
                ],
                'count' => $count,
                'step' => $step,
                'increment' => $increment,
                'decrement' => $decrement,
                'reset' => $reset,
            ]);
        });
    }
}
