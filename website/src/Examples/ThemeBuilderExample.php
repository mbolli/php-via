<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class ThemeBuilderExample
{
    public const string SLUG = 'theme-builder';

    /** @var array<string, string> slot => default hex color */
    private const array DEFAULT_THEME = [
        'bg'      => '#ffffff',
        'primary' => '#6366f1',
        'text'    => '#1e293b',
        'border'  => '#e2e8f0',
        'accent'  => '#f59e0b',
    ];

    /** @var array<string, string> slot => human label */
    private const array SLOT_LABELS = [
        'bg'      => 'Background',
        'primary' => 'Primary',
        'text'    => 'Text',
        'border'  => 'Border',
        'accent'  => 'Accent',
    ];

    /** @var array<string, list<string>> slot => allowed hex colors (without #) */
    private const array SWATCHES = [
        'bg'      => ['ffffff', 'f8fafc', 'f1f5f9', 'fef3c7', 'ecfdf5', '1e293b', '0f172a', '18181b'],
        'primary' => ['6366f1', '8b5cf6', 'ec4899', 'ef4444', 'f97316', 'eab308', '22c55e', '06b6d4', '3b82f6', '1d4ed8'],
        'text'    => ['1e293b', '374151', '4b5563', '6b7280', '9ca3af', 'd1d5db', 'f9fafb', 'ffffff'],
        'border'  => ['f1f5f9', 'e2e8f0', 'cbd5e1', '94a3b8', '64748b', '334155', '1e293b', '000000'],
        'accent'  => ['f59e0b', 'f97316', 'ef4444', 'ec4899', 'a855f7', '8b5cf6', '22c55e', '14b8a6'],
    ];

    /** @var array<string, list<array<string, string>>> contextId => history stack */
    private static array $history = [];

    /** @var array<string, int> contextId => current history index */
    private static array $historyIdx = [];

    public static function register(Via $app): void
    {
        $app->page('/examples/theme-builder', function (Context $c): void {
            $contextId = $c->getId();

            if (!isset(self::$history[$contextId])) {
                self::$history[$contextId]   = [self::DEFAULT_THEME];
                self::$historyIdx[$contextId] = 0;
            }

            $c->onDisconnect(function () use ($contextId): void {
                unset(self::$history[$contextId], self::$historyIdx[$contextId]);
            });

            $setColor = $c->action(function () use ($contextId, $c): void {
                $slot  = $_GET['slot'] ?? '';
                $color = ltrim($_GET['color'] ?? '', '#');

                // Only accept known slots and pre-approved swatch colors
                $allowedColors = self::SWATCHES[$slot] ?? [];

                if ($allowedColors === [] || !\in_array($color, $allowedColors, strict: true)) {
                    return;
                }

                $fullColor   = '#' . $color;
                $currentIdx  = self::$historyIdx[$contextId];
                $currentTheme = self::$history[$contextId][$currentIdx];

                if ($currentTheme[$slot] === $fullColor) {
                    return; // No change
                }

                // Truncate redo forward history
                self::$history[$contextId] = \array_slice(self::$history[$contextId], 0, $currentIdx + 1);

                $newTheme        = $currentTheme;
                $newTheme[$slot] = $fullColor;

                self::$history[$contextId][]  = $newTheme;
                self::$historyIdx[$contextId] = \count(self::$history[$contextId]) - 1;

                $c->sync();
            }, 'setColor');

            $undo = $c->action(function () use ($contextId, $c): void {
                if (self::$historyIdx[$contextId] > 0) {
                    self::$historyIdx[$contextId]--;
                    $c->sync();
                }
            }, 'undo');

            $redo = $c->action(function () use ($contextId, $c): void {
                if (self::$historyIdx[$contextId] < \count(self::$history[$contextId]) - 1) {
                    self::$historyIdx[$contextId]++;
                    $c->sync();
                }
            }, 'redo');

            $reset = $c->action(function () use ($contextId, $c): void {
                self::$history[$contextId]   = [self::DEFAULT_THEME];
                self::$historyIdx[$contextId] = 0;
                $c->sync();
            }, 'reset');

            $c->view(fn (): string => $c->render('examples/theme_builder.html.twig', [
                'title'        => '🎨 Theme Builder',
                'description'  => 'Click swatches to repaint the preview card server-side. Every color is a server round-trip — and undo/redo is just a PHP array index.',
                'summary'      => [
                    '<strong>Undo/redo without JavaScript</strong> — every setColor action pushes to a PHP array on the server. Undo decrements the index; redo increments it. No client history stack, no immutable state library.',
                    '<strong>Server-authoritative visual state</strong> — the preview card\'s colors are CSS inline styles rendered by Twig from the current history entry. The client is a pure render surface.',
                    '<strong>History truncation on branch</strong> — if you undo two steps and pick a new color, future redo states are discarded. Standard undo behavior, four lines of PHP.',
                    '<strong>Disconnect cleanup</strong> — onDisconnect removes the history array for this context, so long-lived sessions don\'t leak memory.',
                    '<strong>Input validation</strong> — only pre-approved swatch hex values are accepted. The server ignores any color not in its whitelist, making the action safe from injected values.',
                    '<strong>block: \'demo\'</strong> — on every undo/redo/setColor, only the demo block is re-rendered and morphed. The page header and anatomy panel stay static.',
                ],
                'anatomy'      => [
                    'signals' => [],
                    'actions' => [
                        ['name' => 'setColor', 'desc' => 'Reads ?slot= and ?color= params, validates against swatch whitelist, pushes to history, calls $c->sync().'],
                        ['name' => 'undo', 'desc' => 'Decrements the history index and re-renders via $c->sync().'],
                        ['name' => 'redo', 'desc' => 'Increments the history index and re-renders via $c->sync().'],
                        ['name' => 'reset', 'desc' => 'Resets history to the single default theme entry.'],
                    ],
                    'views' => [
                        ['name' => 'theme_builder.html.twig', 'desc' => 'Swatch grid + preview card. Preview uses inline CSS from the current history entry. Full demo block re-renders on each action.'],
                    ],
                ],
                'githubLinks'  => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ThemeBuilderExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/theme_builder.html.twig'],
                ],
                'theme'        => self::$history[$contextId][self::$historyIdx[$contextId]],
                'canUndo'      => self::$historyIdx[$contextId] > 0,
                'canRedo'      => self::$historyIdx[$contextId] < \count(self::$history[$contextId]) - 1,
                'historyCount' => \count(self::$history[$contextId]),
                'historyIdx'   => self::$historyIdx[$contextId],
                'swatches'     => self::SWATCHES,
                'slotLabels'   => self::SLOT_LABELS,
                'setColor'     => $setColor,
                'undo'         => $undo,
                'redo'         => $redo,
                'reset'        => $reset,
            ]), block: 'demo', cacheUpdates: false);
        });
    }
}
