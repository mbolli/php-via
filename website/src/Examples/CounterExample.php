<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class CounterExample {
    public const string SLUG = 'counter';

    public static function register(Via $app): void {
        $app->page('/examples/counter', function (Context $c): void {
            $c->signal(0, 'count');
            $c->signal(1, 'step');

            $c->action(function (Context $ctx): void {
                $count = $ctx->getSignal('count');
                $count->setValue($count->int() + $ctx->getSignal('step')->int());
            }, 'increment');

            $c->action(function (Context $ctx): void {
                $count = $ctx->getSignal('count');
                $count->setValue($count->int() - $ctx->getSignal('step')->int());
            }, 'decrement');

            $c->action(function (Context $ctx): void {
                $ctx->getSignal('count')->setValue(0);
            }, 'reset');

            $c->view('examples/counter.html.twig', [
                'title' => 'Counter',
                'description' => 'Count up or down, and type a step size to change how far each click goes. The count and the step are TAB signals, and <code>data-bind</code> keeps the step input and its signal in sync.',
                'summary' => [
                    '<strong>Signals</strong> hold reactive state. The count and the step are signals, and their new values reach the page over the SSE stream.',
                    '<strong>data-bind</strong> creates two-way binding between an input and a signal. Type a new step value and it syncs to the server automatically.',
                    '<strong>Actions</strong> are server-side functions triggered by button clicks. Each action modifies the signal and pushes the new value to the browser.',
                    '<strong>No JavaScript authored</strong>: every interaction is a server round-trip. Datastar handles the SSE connection, the DOM patches and the signal store.',
                    '<strong>TAB scope</strong> (the default) means each browser tab has its own independent counter. Open two tabs: clicking in one will not affect the other.',
                    '<strong>No sync call</strong>: after an action, php-via sends the signals it changed down the SSE stream without rendering the view again.',
                ],
                'anatomy' => [
                    'signals' => [
                        ['name' => 'count', 'type' => 'int', 'scope' => 'TAB', 'default' => '0', 'desc' => 'Current counter value. Updated by increment, decrement, and reset actions.'],
                        ['name' => 'step', 'type' => 'int', 'scope' => 'TAB', 'default' => '1', 'desc' => 'Increment/decrement step size. Two-way bound to the input via data-bind.'],
                    ],
                    'actions' => [
                        ['name' => 'increment', 'desc' => 'Adds step to count; the new value goes to the browser after the action.'],
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
            ]);
        });
    }
}
