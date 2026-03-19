<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class GreeterExample {
    public const string SLUG = 'greeter';

    public static function register(Via $app): void {
        $app->page('/examples/greeter', function (Context $c): void {
            $greeting = $c->signal('Hello...', 'greeting');

            $greetBob = $c->action(function () use ($greeting, $c): void {
                $greeting->setValue('Hello Bob!');
                $c->syncSignals();
            }, 'greetBob');

            $greetAlice = $c->action(function () use ($greeting, $c): void {
                $greeting->setValue('Hello Alice!');
                $c->syncSignals();
            }, 'greetAlice');

            $c->view('examples/greeter.html.twig', [
                'title' => '👋 Greeter',
                'description' => 'Form inputs, multiple actions, and signal updates with Twig templates.',
                'summary' => [
                    '<strong>Multiple actions</strong> can share the same signal. Both "Greet Bob" and "Greet Alice" update the same greeting signal with different values.',
                    '<strong>Twig templates</strong> render the UI server-side. Signal values are interpolated into the template and pushed to the browser via SSE patches.',
                    '<strong>Zero JavaScript</strong> — every button click fires a server round-trip via Datastar. The response patches only the changed DOM fragment, not the whole page.',
                ],
                'sourceFile' => 'greeter.php',
                'templateFiles' => ['greeter.html.twig'],
                'greeting' => $greeting,
                'greet_bob' => $greetBob,
                'greet_alice' => $greetAlice,
            ]);
        });
    }
}
