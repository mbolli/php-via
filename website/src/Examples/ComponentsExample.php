<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class ComponentsExample
{
    public const string SLUG = 'components';

    public static function register(Via $app): void
    {
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

            $c->view('examples/component_counter.html.twig', [
                'namespace' => $c->getNamespace(),
                'count' => $count,
                'increment' => $increment,
                'decrement' => $decrement,
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
                'sourceFile' => 'components.php',
                'templateFiles' => ['components.html.twig', 'component_counter.html.twig'],
                'counter1' => $counter1(),
                'counter2' => $counter2(),
                'counter3' => $counter3(),
            ]);
        });
    }
}
