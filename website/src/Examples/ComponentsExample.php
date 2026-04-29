<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class ComponentsExample {
    public const string SLUG = 'components';

    public static function register(Via $app): void {
        $counterComponent = function (Context $c): void {
            $c->signal(0, 'count');

            $c->action(function (Context $ctx): void {
                $count = $ctx->getSignal('count');
                $count->setValue($count->int() + 1);
                $ctx->sync();
            }, 'increment');

            $c->action(function (Context $ctx): void {
                $count = $ctx->getSignal('count');
                $count->setValue($count->int() - 1);
                $ctx->sync();
            }, 'decrement');

            $c->view('examples/component_counter.html.twig', [
                'namespace' => $c->getNamespace(),
            ]);
        };

        $app->page('/examples/components', function (Context $c) use ($counterComponent): void {
            $counter1 = $c->component($counterComponent, 'counter1');
            $counter2 = $c->component($counterComponent, 'counter2');
            $counter3 = $c->component($counterComponent, 'counter3');

            $c->view('examples/components.html.twig', [
                'title' => '🧩 Components',
                'description' => 'Three independent counters on one page. Each is an isolated component with its own signals.',
                'summary' => [
                    '<strong>Components</strong> are sub-contexts with isolated state. Define a component once and instantiate it multiple times — each gets its own signals and actions.',
                    '<strong>Namespacing</strong> is automatic. Each component\'s signals and actions are prefixed with its name, preventing collisions even when the same component appears three times.',
                    '<strong>Independent updates</strong> — clicking a button in one component only re-renders that component\'s DOM target, not the entire page.',
                    '<strong>Zero boilerplate</strong> — a component is just a closure that receives a Context. Call <code>$c-&gt;component($fn, \'name\')</code> and it returns a render callable.',
                    '<strong>Shared logic, separate state</strong>. All three counters use the exact same closure. The only difference is the component name passed at mount time.',
                    '<strong>Composable</strong> — components can nest other components. Build complex UIs from small, testable pieces without a custom component class hierarchy.',
                ],
                'anatomy' => [
                    'signals' => [
                        ['name' => 'count', 'type' => 'int', 'scope' => 'TAB', 'default' => '0', 'desc' => 'Each component instance has its own count signal, auto-namespaced to avoid collisions.'],
                    ],
                    'actions' => [
                        ['name' => 'increment', 'desc' => 'Adds 1 to that component\'s count. Only re-renders the owning component.'],
                        ['name' => 'decrement', 'desc' => 'Subtracts 1 from that component\'s count.'],
                    ],
                    'views' => [
                        ['name' => 'components.html.twig', 'desc' => 'Page shell that mounts three independent counter components.'],
                        ['name' => 'component_counter.html.twig', 'desc' => 'Reusable counter UI rendered per component instance.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ComponentsExample.php'],
                    ['label' => 'View page template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/components.html.twig'],
                    ['label' => 'View component template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/component_counter.html.twig'],
                ],
                'counter1' => $counter1(),
                'counter2' => $counter2(),
                'counter3' => $counter3(),
            ]);
        });
    }
}
