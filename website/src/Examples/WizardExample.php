<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class WizardExample
{
    public const string SLUG = 'wizard';

    /** @var list<string> */
    private const array ROLES = ['Backend Dev', 'Frontend Dev', 'Full Stack Dev', 'DevOps Engineer', 'Data Engineer', 'Security Engineer', 'Engineering Manager'];

    /** @var list<string> */
    private const array EDITORS = ['VS Code', 'Neovim', 'PhpStorm', 'Emacs', 'Sublime Text', 'Zed', 'Other'];

    /** @var list<array{key: string, label: string}> */
    private const array STACK_OPTIONS = [
        ['key' => 'php',    'label' => 'PHP'],
        ['key' => 'ts',     'label' => 'TypeScript'],
        ['key' => 'python', 'label' => 'Python'],
        ['key' => 'go',     'label' => 'Go'],
        ['key' => 'rust',   'label' => 'Rust'],
        ['key' => 'java',   'label' => 'Java'],
        ['key' => 'cs',     'label' => 'C#'],
        ['key' => 'other',  'label' => 'Other'],
    ];

    public static function register(Via $app): void
    {
        $app->page('/examples/wizard', function (Context $c): void {
            // Step state
            $step  = $c->signal(1, 'step');
            $error = $c->signal('', 'error');

            // Step 1: Basics
            $name  = $c->signal('', 'name');
            $role  = $c->signal('Backend Dev', 'role');
            $years = $c->signal(3, 'years');

            // Step 2: Stack & editor
            $stack = [
                'php'    => $c->signal(false, 'sphp'),
                'ts'     => $c->signal(false, 'sts'),
                'python' => $c->signal(false, 'spython'),
                'go'     => $c->signal(false, 'sgo'),
                'rust'   => $c->signal(false, 'srust'),
                'java'   => $c->signal(false, 'sjava'),
                'cs'     => $c->signal(false, 'scs'),
                'other'  => $c->signal(false, 'sother'),
            ];
            $editor = $c->signal('VS Code', 'editor');

            $next = $c->action(function () use ($step, $name, $error, $c): void {
                $s = $step->int();

                if ($s === 1) {
                    if (mb_trim($name->string()) === '') {
                        $error->setValue('Please enter your name.');
                        $c->sync();

                        return;
                    }
                }

                $error->setValue('');

                if ($s < 3) {
                    $step->setValue($s + 1);
                }

                $c->sync();
            }, 'next');

            $back = $c->action(function () use ($step, $error, $c): void {
                $error->setValue('');

                if ($step->int() > 1) {
                    $step->setValue($step->int() - 1);
                }

                $c->sync();
            }, 'back');

            $restart = $c->action(function () use ($step, $error, $name, $role, $years, $editor, $stack, $c): void {
                $step->setValue(1);
                $error->setValue('');
                $name->setValue('');
                $role->setValue('Backend Dev');
                $years->setValue(3);
                $editor->setValue('VS Code');

                foreach ($stack as $sig) {
                    $sig->setValue(false);
                }

                $c->sync();
            }, 'restart');

            $c->view(fn (): string => $c->render('examples/wizard.html.twig', [
                'title'       => '🪄 Multi-step Form',
                'description' => 'A 3-step dev identity card wizard. All form state lives on the server — no session cookies, no localStorage, no hydration.',
                'summary'     => [
                    '<strong>Server-owned form state</strong> — each step\'s inputs are signals held on the server for this tab. Going back returns the same values you entered. No client serialization needed.',
                    '<strong>data-bind</strong> creates two-way bindings between inputs and signals. When the user types, the signal updates client-side. When "Next" fires, the server reads the current signal values.',
                    '<strong>Step validation</strong> happens server-side in the next action. If validation fails, the error signal is set and $c->sync() re-renders the current step with the error message shown.',
                    '<strong>block: \'demo\'</strong> — only the wizard block is re-rendered on each step change. The page header and anatomy panel stay static in the DOM.',
                    '<strong>The generated card</strong> in step 3 is fully server-rendered from signal values. No client-side template, no JSON fetch — the complete card HTML is streamed via SSE.',
                ],
                'anatomy'     => [
                    'signals' => [
                        ['name' => 'step', 'type' => 'int', 'scope' => 'TAB', 'default' => '1', 'desc' => 'Current wizard step (1–3). Controls which step UI is rendered.'],
                        ['name' => 'name', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Developer name, collected in step 1.'],
                        ['name' => 'role', 'type' => 'string', 'scope' => 'TAB', 'default' => '"Backend Dev"', 'desc' => 'Selected role from dropdown in step 1.'],
                        ['name' => 'years', 'type' => 'int', 'scope' => 'TAB', 'default' => '3', 'desc' => 'Years of experience, entered in step 1.'],
                        ['name' => 'sphp … sother', 'type' => 'bool', 'scope' => 'TAB', 'default' => 'false', 'desc' => 'One boolean signal per stack technology, bound to checkboxes in step 2.'],
                        ['name' => 'editor', 'type' => 'string', 'scope' => 'TAB', 'default' => '"VS Code"', 'desc' => 'Favourite editor, selected in step 2.'],
                        ['name' => 'error', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Validation message set by the next action on step 1 failure.'],
                    ],
                    'actions' => [
                        ['name' => 'next', 'desc' => 'Validates step 1, increments step, and calls $c->sync() to render the next step.'],
                        ['name' => 'back', 'desc' => 'Decrements step and re-renders. Signal values from the previous step are preserved.'],
                        ['name' => 'restart', 'desc' => 'Resets all signals to defaults and returns to step 1.'],
                    ],
                    'views' => [
                        ['name' => 'wizard.html.twig', 'desc' => 'Single template with step-conditional blocks. Only the demo block re-renders on each step transition.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/WizardExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/wizard.html.twig'],
                ],
                'step'         => $step,
                'error'        => $error,
                'name'         => $name,
                'role'         => $role,
                'years'        => $years,
                'stack'        => $stack,
                'stackOptions' => self::STACK_OPTIONS,
                'editor'       => $editor,
                'roles'        => self::ROLES,
                'editors'      => self::EDITORS,
                'next'         => $next,
                'back'         => $back,
                'restart'      => $restart,
                'selectedStack' => array_values(array_filter(
                    array_map(
                        static fn (array $opt) => $stack[$opt['key']]->bool() ? $opt['label'] : null,
                        self::STACK_OPTIONS
                    )
                )),
            ]), block: 'demo', cacheUpdates: false);
        });
    }
}
