<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Composition API version of the multi-step form.
 *
 * Two kinds of state, mapped to two attributes:
 *  - Client-bound inputs (name, role, years, editor, stack) are #[Signal] — two-way
 *    bound via data-bind, hydrated onto the instance before each action.
 *  - Server-controlled render state (step, error) is #[Persist] — never sent to the
 *    client, never hydrated, so it survives untouched between action calls.
 *
 * Because step/error are #[Persist] (not signals) and the view is a callable that
 * reads them live, an action can mutate $this->step and call $ctx->sync() to re-render
 * the current step immediately — no stale-signal ordering issues.
 */
final class WizardExample {
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

    /** Current step (1–3). Server-controlled — #[Persist], not a client signal. */
    #[Persist]
    public int $step = 1;

    /** Validation message set by next() on step-1 failure. Server-controlled. */
    #[Persist]
    public string $error = '';

    // ── Step 1: client-bound inputs ──────────────────────────────────────────
    #[Signal]
    public string $name = '';
    #[Signal]
    public string $role = 'Backend Dev';
    #[Signal]
    public int $years = 3;

    // ── Step 2: stack checkboxes + editor ────────────────────────────────────
    #[Signal]
    public bool $sphp = false;
    #[Signal]
    public bool $sts = false;
    #[Signal]
    public bool $spython = false;
    #[Signal]
    public bool $sgo = false;
    #[Signal]
    public bool $srust = false;
    #[Signal]
    public bool $sjava = false;
    #[Signal]
    public bool $scs = false;
    #[Signal]
    public bool $sother = false;
    #[Signal]
    public string $editor = 'VS Code';

    public function view(Context $ctx): void {
        // Resume from session if the user navigated away and returned.
        /** @var array<string, mixed> $saved */
        $saved = $ctx->sessionData('wizard', []);
        if ($saved !== []) {
            $this->step = (int) ($saved['step'] ?? 1);
            $ctx->getSignal('name')?->setValue((string) ($saved['name'] ?? ''));
            $ctx->getSignal('role')?->setValue((string) ($saved['role'] ?? 'Backend Dev'));
            $ctx->getSignal('years')?->setValue((int) ($saved['years'] ?? 3));
            $ctx->getSignal('editor')?->setValue((string) ($saved['editor'] ?? 'VS Code'));

            /** @var array<string, bool> $savedStack */
            $savedStack = \is_array($saved['stack'] ?? null) ? $saved['stack'] : [];
            foreach (self::STACK_OPTIONS as $opt) {
                $ctx->getSignal('s' . $opt['key'])?->setValue((bool) ($savedStack[$opt['key']] ?? false));
            }
        }

        $ctx->view(function () use ($ctx): string {
            // Build the stack signal map + the selected-label list from live signal values.
            $stack = [];
            $selectedStack = [];
            foreach (self::STACK_OPTIONS as $opt) {
                $sig = $ctx->getSignal('s' . $opt['key']);
                $stack[$opt['key']] = $sig;
                if ($sig !== null && $sig->bool()) {
                    $selectedStack[] = $opt['label'];
                }
            }

            return $ctx->render('examples/wizard.html.twig', [
                'title' => '🪄 Multi-step Form',
                'description' => 'Composition API: client inputs are <code>#[Signal]</code>, while the step and validation error are <code>#[Persist]</code> — server-only state that survives between actions and drives the server-side re-render.',
                'summary' => [
                    '<strong>#[Persist] step &amp; error</strong> — server-controlled state that is never sent to the client and never hydrated from it. Because the class instance lives for the whole connection, the step survives across Next/Back actions.',
                    '<strong>#[Signal] inputs</strong> — name, role, years, editor and the eight stack booleans are two-way bound via <code>data-bind</code>. They are hydrated onto the instance before each action runs.',
                    '<strong>$ctx->sync()</strong> — each action mutates <code>$this->step</code> (a #[Persist] prop the callable view reads live), then calls sync() to re-render the current step. No stale-signal timing issues.',
                    '<strong>Server-side validation</strong> — next() checks <code>$this->name</code>; on failure it sets <code>$this->error</code> and re-renders the same step with the message shown.',
                    '<strong>Session persistence</strong> — saveState() writes the current values to <code>$ctx->setSessionData(\'wizard\', …)</code> on every step. view() resumes from the session on reconnect.',
                    '<strong>block: \'demo\'</strong> — only the wizard block re-renders on each step change; the header and anatomy panel stay static in the DOM.',
                ],
                'anatomy' => [
                    'signals' => [
                        ['name' => 'step', 'type' => 'int', 'scope' => 'Persist', 'default' => '1', 'desc' => '#[Persist] — server-only current step (1–3). Drives which step UI is rendered.'],
                        ['name' => 'error', 'type' => 'string', 'scope' => 'Persist', 'default' => '""', 'desc' => '#[Persist] — server-only validation message set by next() on step-1 failure.'],
                        ['name' => 'name', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => '#[Signal] — developer name, collected in step 1.'],
                        ['name' => 'role', 'type' => 'string', 'scope' => 'TAB', 'default' => '"Backend Dev"', 'desc' => '#[Signal] — selected role from the step-1 dropdown.'],
                        ['name' => 'years', 'type' => 'int', 'scope' => 'TAB', 'default' => '3', 'desc' => '#[Signal] — years of experience, from the step-1 range slider.'],
                        ['name' => 'sphp … sother', 'type' => 'bool', 'scope' => 'TAB', 'default' => 'false', 'desc' => 'Eight #[Signal] booleans, one per stack technology, bound to step-2 checkboxes.'],
                        ['name' => 'editor', 'type' => 'string', 'scope' => 'TAB', 'default' => '"VS Code"', 'desc' => '#[Signal] — favourite editor, selected in step 2.'],
                    ],
                    'actions' => [
                        ['name' => 'next', 'desc' => 'Validates step 1, increments $this->step, saves to session, then $ctx->sync() renders the next step.'],
                        ['name' => 'back', 'desc' => 'Decrements $this->step and re-renders. Signal values from the previous step are preserved.'],
                        ['name' => 'restart', 'desc' => 'Resets every signal + #[Persist] prop to defaults, clears the session, and returns to step 1.'],
                    ],
                    'views' => [
                        ['name' => 'wizard.html.twig', 'desc' => 'Single template with step-conditional blocks. step/error are passed as plain view data; inputs use auto-injected signals.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View page class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/WizardExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/wizard.html.twig'],
                ],
                'step' => $this->step,
                'error' => $this->error,
                'stack' => $stack,
                'stackOptions' => self::STACK_OPTIONS,
                'roles' => self::ROLES,
                'editors' => self::EDITORS,
                'selectedStack' => $selectedStack,
            ]);
        }, block: 'demo', cacheUpdates: false);
    }

    #[Action]
    public function next(Context $ctx): void {
        if ($this->step === 1 && mb_trim($this->name) === '') {
            $this->error = 'Please enter your name.';
            $ctx->sync();

            return;
        }

        $this->error = '';
        if ($this->step < 3) {
            ++$this->step;
        }

        $this->saveState($ctx);
        $ctx->sync();
    }

    #[Action]
    public function back(Context $ctx): void {
        $this->error = '';
        if ($this->step > 1) {
            --$this->step;
        }

        $this->saveState($ctx);
        $ctx->sync();
    }

    #[Action]
    public function restart(Context $ctx): void {
        $this->step = 1;
        $this->error = '';
        $this->name = '';
        $this->role = 'Backend Dev';
        $this->years = 3;
        $this->editor = 'VS Code';
        $this->sphp = $this->sts = $this->spython = $this->sgo = false;
        $this->srust = $this->sjava = $this->scs = $this->sother = false;

        $ctx->clearSessionData('wizard');
        $ctx->sync();
    }

    public static function register(Via $app): void {
        $app->mount(self::class, '/examples/wizard');
    }

    /**
     * Persist the current form state to the session so it survives page navigations.
     * A plain private helper — not an #[Action], so it is never exposed as a route.
     */
    private function saveState(Context $ctx): void {
        $stack = [];
        foreach (self::STACK_OPTIONS as $opt) {
            $stack[$opt['key']] = (bool) $this->{'s' . $opt['key']};
        }

        $ctx->setSessionData('wizard', [
            'step' => $this->step,
            'name' => $this->name,
            'role' => $this->role,
            'years' => $this->years,
            'editor' => $this->editor,
            'stack' => $stack,
        ]);
    }
}
