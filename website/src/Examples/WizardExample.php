<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class WizardExample {
    public const string SLUG = 'wizard';

    /** @var list<string> */
    private const array ROLES = ['Backend Dev', 'Frontend Dev', 'Full Stack Dev', 'DevOps Engineer', 'Data Engineer', 'Security Engineer', 'Engineering Manager'];

    /** @var list<string> */
    private const array EDITORS = ['VS Code', 'Neovim', 'PhpStorm', 'Emacs', 'Sublime Text', 'Zed', 'Other'];

    /** @var list<array{key: string, label: string}> */
    private const array STACK_OPTIONS = [
        ['key' => 'php', 'label' => 'PHP'],
        ['key' => 'ts', 'label' => 'TypeScript'],
        ['key' => 'python', 'label' => 'Python'],
        ['key' => 'go', 'label' => 'Go'],
        ['key' => 'rust', 'label' => 'Rust'],
        ['key' => 'java', 'label' => 'Java'],
        ['key' => 'cs', 'label' => 'C#'],
        ['key' => 'other', 'label' => 'Other'],
    ];

    public static function register(Via $app): void {
        $app->page('/examples/wizard', function (Context $c): void {
            // Resume from session if the user navigated away and returned
            /** @var array<string, mixed> $saved */
            $saved = $c->sessionData('wizard', []);

            // Step state
            $step = $c->signal((int) ($saved['step'] ?? 1), 'step');
            $error = $c->signal('', 'error');

            // Step 1: Basics
            $name = $c->signal((string) ($saved['name'] ?? ''), 'name');
            $role = $c->signal((string) ($saved['role'] ?? 'Backend Dev'), 'role');
            $years = $c->signal((int) ($saved['years'] ?? 3), 'years');

            // Step 2: Stack & editor
            /** @var array<string, bool> $savedStack */
            $savedStack = $saved['stack'] ?? [];
            $stack = [
                'php' => $c->signal($savedStack['php'] ?? false, 'sphp'),
                'ts' => $c->signal($savedStack['ts'] ?? false, 'sts'),
                'python' => $c->signal($savedStack['python'] ?? false, 'spython'),
                'go' => $c->signal($savedStack['go'] ?? false, 'sgo'),
                'rust' => $c->signal($savedStack['rust'] ?? false, 'srust'),
                'java' => $c->signal($savedStack['java'] ?? false, 'sjava'),
                'cs' => $c->signal($savedStack['cs'] ?? false, 'scs'),
                'other' => $c->signal($savedStack['other'] ?? false, 'sother'),
            ];
            $editor = $c->signal((string) ($saved['editor'] ?? 'VS Code'), 'editor');

            // Persist current form state to session so it survives page navigations
            $saveState = function () use ($c, $stack): void {
                $c->setSessionData('wizard', [
                    'step' => $c->getSignal('step')->int(),
                    'name' => $c->getSignal('name')->string(),
                    'role' => $c->getSignal('role')->string(),
                    'years' => $c->getSignal('years')->int(),
                    'editor' => $c->getSignal('editor')->string(),
                    'stack' => array_map(static fn ($s) => $s->bool(), $stack),
                ]);
            };

            $c->action(function (Context $ctx) use ($saveState): void {
                $step = $ctx->getSignal('step');
                $name = $ctx->getSignal('name');
                $error = $ctx->getSignal('error');
                $s = $step->int();

                if ($s === 1) {
                    if (mb_trim($name->string()) === '') {
                        $error->setValue('Please enter your name.');
                        $ctx->sync();

                        return;
                    }
                }

                $error->setValue('');

                if ($s < 3) {
                    $step->setValue($s + 1);
                }

                $saveState();
                $ctx->sync();
            }, 'next');

            $c->action(function (Context $ctx) use ($saveState): void {
                $step = $ctx->getSignal('step');
                $error = $ctx->getSignal('error');
                $error->setValue('');

                if ($step->int() > 1) {
                    $step->setValue($step->int() - 1);
                }

                $saveState();
                $ctx->sync();
            }, 'back');

            $c->action(function (Context $ctx) use ($stack): void {
                $ctx->getSignal('step')->setValue(1);
                $ctx->getSignal('error')->setValue('');
                $ctx->getSignal('name')->setValue('');
                $ctx->getSignal('role')->setValue('Backend Dev');
                $ctx->getSignal('years')->setValue(3);
                $ctx->getSignal('editor')->setValue('VS Code');

                foreach ($stack as $sig) {
                    $sig->setValue(false);
                }

                $ctx->clearSessionData('wizard');
                $ctx->sync();
            }, 'restart');

            $c->view(fn (): string => $c->render('examples/wizard.html.twig', [
                'title' => '🪄 Multi-step Form',
                'description' => 'A 3-step dev identity card wizard. All form state lives on the server and persists across page refreshes — no client serialization, no session cookies, no localStorage.',
                'summary' => [
                    '<strong>Session persistence</strong> — wizard state is saved to <code>$c->setSessionData(\'wizard\', [...])</code> on every Next/Back action. Refreshing the page resumes exactly where you left off.',
                    '<strong>Server-owned form state</strong> — each step\'s inputs are signals held on the server. Going back returns the same values you entered. No client serialization needed.',
                    '<strong>data-bind</strong> creates two-way bindings between inputs and signals. When the user types, the signal updates client-side. When "Next" fires, the server reads the current signal values.',
                    '<strong>Step validation</strong> happens server-side in the next action. If validation fails, the error signal is set and $c->sync() re-renders the current step with the error message shown.',
                    '<strong>block: \'demo\'</strong> — only the wizard block is re-rendered on each step change. The page header and anatomy panel stay static in the DOM.',
                    '<strong>The generated card</strong> in step 3 is fully server-rendered from signal values. No client-side template, no JSON fetch — the complete card HTML is streamed via SSE.',
                ],
                'anatomy' => [
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
                'stack' => $stack,
                'stackOptions' => self::STACK_OPTIONS,
                'roles' => self::ROLES,
                'editors' => self::EDITORS,
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
