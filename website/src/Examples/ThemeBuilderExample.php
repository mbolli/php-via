<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Composition API version of the theme builder.
 *
 * The undo/redo history is per-connection state, which maps directly onto #[Persist]:
 * each connection gets its own instance, so $history and $historyIdx live for exactly
 * as long as the tab is open and are freed automatically when it closes. That removes
 * the closure version's static maps keyed by context id — and its onDisconnect cleanup.
 *
 * There are no signals: the visual state is server-authoritative. Each action mutates
 * the history array and calls $ctx->sync() to re-render the (callable) demo view.
 */
final class ThemeBuilderExample {
    /** @var array<string, string> slot => default hex color */
    private const array DEFAULT_THEME = [
        'bg' => '#ffffff',
        'primary' => '#6366f1',
        'text' => '#1e293b',
        'border' => '#e2e8f0',
        'accent' => '#f59e0b',
    ];

    /** @var array<string, string> slot => human label */
    private const array SLOT_LABELS = [
        'bg' => 'Background',
        'primary' => 'Primary',
        'text' => 'Text',
        'border' => 'Border',
        'accent' => 'Accent',
    ];

    /** @var array<string, list<string>> slot => allowed hex colors (without #) */
    private const array SWATCHES = [
        'bg' => ['ffffff', 'f8fafc', 'f1f5f9', 'fef3c7', 'ecfdf5', '1e293b', '0f172a', '18181b'],
        'primary' => ['6366f1', '8b5cf6', 'ec4899', 'ef4444', 'f97316', 'eab308', '22c55e', '06b6d4', '3b82f6', '1d4ed8'],
        'text' => ['1e293b', '374151', '4b5563', '6b7280', '9ca3af', 'd1d5db', 'f9fafb', 'ffffff'],
        'border' => ['f1f5f9', 'e2e8f0', 'cbd5e1', '94a3b8', '64748b', '334155', '1e293b', '000000'],
        'accent' => ['f59e0b', 'f97316', 'ef4444', 'ec4899', 'a855f7', '8b5cf6', '22c55e', '14b8a6'],
    ];

    /**
     * Undo/redo stack for this connection. #[Persist] — plain instance state, never a
     * signal, freed when the connection closes.
     *
     * @var list<array<string, string>>
     */
    #[Persist]
    public array $history = [self::DEFAULT_THEME];

    /** Index of the active entry in $history. */
    #[Persist]
    public int $historyIdx = 0;

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => $ctx->render('examples/theme_builder.html.twig', [
            'title' => '🎨 Theme Builder',
            'description' => 'Composition API: undo/redo history lives in a <code>#[Persist]</code> array — per-connection server state, no signals. Click swatches to repaint the preview card server-side.',
            'summary' => [
                '<strong>#[Persist] history</strong> — the undo/redo stack is a plain instance array. Each connection gets its own instance, so no static maps keyed by context id are needed; the state is freed automatically when the tab closes.',
                '<strong>No signals, no onDisconnect</strong> — the visual state is fully server-authoritative and bound to the instance lifetime. The closure version\'s manual onDisconnect cleanup disappears entirely.',
                '<strong>Undo/redo without JavaScript</strong> — setColor pushes a new entry; undo decrements <code>$this->historyIdx</code>, redo increments it. History truncation on branch is a single array_slice.',
                '<strong>Callable view + $ctx->sync()</strong> — each #[Action] mutates the history, then calls sync() to re-render. The view closure re-reads the live <code>$this->history</code> entry every render.',
                '<strong>Input validation</strong> — only pre-approved swatch hex values are accepted. The server ignores any color not in its whitelist, making the action safe from injected values.',
                '<strong>block: \'demo\'</strong> — on every undo/redo/setColor, only the demo block is re-rendered and morphed. The page header and anatomy panel stay static.',
            ],
            'anatomy' => [
                'signals' => [
                    ['name' => 'history', 'type' => 'array', 'scope' => 'Persist', 'default' => '[default theme]', 'desc' => '#[Persist] undo/redo stack. Per-connection instance state — not a signal, never sent to the client.'],
                    ['name' => 'historyIdx', 'type' => 'int', 'scope' => 'Persist', 'default' => '0', 'desc' => '#[Persist] index of the active history entry. Undo/redo move this pointer.'],
                ],
                'actions' => [
                    ['name' => 'setColor', 'desc' => 'Reads ?slot= and ?color= params, validates against the swatch whitelist, pushes to history, calls $ctx->sync().'],
                    ['name' => 'undo', 'desc' => 'Decrements $this->historyIdx and re-renders via $ctx->sync().'],
                    ['name' => 'redo', 'desc' => 'Increments $this->historyIdx and re-renders via $ctx->sync().'],
                    ['name' => 'reset', 'desc' => 'Resets history to the single default theme entry.'],
                ],
                'views' => [
                    ['name' => 'theme_builder.html.twig', 'desc' => 'Unchanged from the closure version. Preview uses inline CSS from the current history entry. Full demo block re-renders on each action.'],
                ],
            ],
            'githubLinks' => [
                ['label' => 'View page class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ThemeBuilderExample.php'],
                ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/theme_builder.html.twig'],
            ],
            'theme' => $this->history[$this->historyIdx],
            'canUndo' => $this->historyIdx > 0,
            'canRedo' => $this->historyIdx < \count($this->history) - 1,
            'historyCount' => \count($this->history),
            'historyIdx' => $this->historyIdx,
            'swatches' => self::SWATCHES,
            'slotLabels' => self::SLOT_LABELS,
        ]), block: 'demo', cacheUpdates: false);
    }

    #[Action]
    public function setColor(Context $ctx): void {
        $slot = $ctx->input('slot', '');
        $color = ltrim($ctx->input('color', ''), '#');

        // Only accept known slots and pre-approved swatch colors.
        $allowedColors = self::SWATCHES[$slot] ?? [];
        if ($allowedColors === [] || !\in_array($color, $allowedColors, strict: true)) {
            return;
        }

        $fullColor = '#' . $color;
        $currentTheme = $this->history[$this->historyIdx];
        if ($currentTheme[$slot] === $fullColor) {
            return; // No change
        }

        // Truncate redo-forward history, then push the new theme.
        $this->history = \array_slice($this->history, 0, $this->historyIdx + 1);
        $newTheme = $currentTheme;
        $newTheme[$slot] = $fullColor;
        $this->history[] = $newTheme;
        $this->historyIdx = \count($this->history) - 1;

        $ctx->sync();
    }

    #[Action]
    public function undo(Context $ctx): void {
        if ($this->historyIdx > 0) {
            --$this->historyIdx;
            $ctx->sync();
        }
    }

    #[Action]
    public function redo(Context $ctx): void {
        if ($this->historyIdx < \count($this->history) - 1) {
            ++$this->historyIdx;
            $ctx->sync();
        }
    }

    #[Action]
    public function reset(Context $ctx): void {
        $this->history = [self::DEFAULT_THEME];
        $this->historyIdx = 0;
        $ctx->sync();
    }

    public static function register(Via $app): void {
        $app->mount(self::class, '/examples/theme-builder');
    }
}
