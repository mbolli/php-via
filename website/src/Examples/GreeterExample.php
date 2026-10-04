<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Composition API version of the greeter.
 *
 * A single #[Signal] property plus two #[Action] methods make up the page. The
 * Twig template gets the signal and the actions by name (`greeting`, `greetBob`,
 * `greetAlice`).
 */
final class GreeterExample {
    /** The displayed greeting. Both actions write to this same signal. */
    #[Signal]
    public string $greeting = 'Hello...';

    public function view(Context $ctx): void {
        $ctx->view('examples/greeter.html.twig', [
            'title' => 'Greeter',
            'description' => 'Composition API: one <code>#[Signal]</code> property and two <code>#[Action]</code> methods make up a small page class. The template gets them by name.',
            'summary' => [
                '<strong>#[Signal]</strong> turns the <code>$greeting</code> property into a reactive signal. After an action writes <code>$this->greeting</code>, php-via sends the new value to the browser over SSE.',
                '<strong>#[Action]</strong> marks <code>greetBob()</code> and <code>greetAlice()</code> as client-callable. Both write to the same <code>$greeting</code> property with different values.',
                '<strong>Auto-injection</strong>: the template reads <code>{{ greeting.ref }}</code> and <code>{{ greetBob.url }}</code> with no view data passed for them. Signals and actions are injected by name automatically.',
                '<strong>Zero JavaScript</strong>: every button click fires a server round-trip via Datastar. The response patches only the changed signal, not the whole page.',
            ],
            'anatomy' => [
                'signals' => [
                    ['name' => 'greeting', 'type' => 'string', 'scope' => 'TAB', 'default' => 'Hello...', 'desc' => '#[Signal] property. The displayed greeting message; both actions write to it.'],
                ],
                'actions' => [
                    ['name' => 'greetBob', 'desc' => '#[Action] method. Sets $this->greeting to "Hello Bob!".'],
                    ['name' => 'greetAlice', 'desc' => '#[Action] method. Sets $this->greeting to "Hello Alice!".'],
                ],
                'views' => [
                    ['name' => 'greeter.html.twig', 'desc' => 'Buttons trigger the actions; data-text shows the greeting as the signal patch arrives.'],
                ],
            ],
            'githubLinks' => [
                ['label' => 'View page class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/GreeterExample.php'],
                ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/greeter.html.twig'],
            ],
        ]);
    }

    #[Action]
    public function greetBob(Context $ctx): void {
        $this->greeting = 'Hello Bob!';
    }

    #[Action]
    public function greetAlice(Context $ctx): void {
        $this->greeting = 'Hello Alice!';
    }

    public static function register(Via $app): void {
        $app->mount(self::class, '/examples/greeter');
    }
}
