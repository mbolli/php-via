<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class GreeterExample {
    public const string SLUG = 'greeter';

    public static function register(Via $app): void {
        $app->page('/examples/greeter', function (Context $c): void {
            $c->signal('Hello...', 'greeting');

            $c->action(function (Context $ctx): void {
                $ctx->getSignal('greeting')->setValue('Hello Bob!');
                $ctx->syncSignals();
            }, 'greetBob');

            $c->action(function (Context $ctx): void {
                $ctx->getSignal('greeting')->setValue('Hello Alice!');
                $ctx->syncSignals();
            }, 'greetAlice');

            $c->view('examples/greeter.html.twig', [
                'title' => '👋 Greeter',
                'description' => 'Form inputs, multiple actions, and signal updates with Twig templates.',
                'summary' => [
                    '<strong>Multiple actions</strong> can share the same signal. Both "Greet Bob" and "Greet Alice" update the same greeting signal with different values.',
                    '<strong>Twig templates</strong> render the UI server-side. Signal values are interpolated into the template and pushed to the browser via SSE patches.',
                    '<strong>Zero JavaScript</strong> — every button click fires a server round-trip via Datastar. The response patches only the changed DOM fragment, not the whole page.',
                ],
                'anatomy' => [
                    'signals' => [
                        ['name' => 'greeting', 'type' => 'string', 'scope' => 'TAB', 'default' => 'Hello...', 'desc' => 'The displayed greeting message. Both actions write to this same signal.'],
                    ],
                    'actions' => [
                        ['name' => 'greetBob', 'desc' => 'Sets the greeting signal to "Hello Bob!".'],
                        ['name' => 'greetAlice', 'desc' => 'Sets the greeting signal to "Hello Alice!".'],
                    ],
                    'views' => [
                        ['name' => 'greeter.html.twig', 'desc' => 'Buttons trigger server-side actions; the greeting text updates reactively via SSE patch.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/GreeterExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/greeter.html.twig'],
                ],
            ]);
        });
    }
}
