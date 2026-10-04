<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;

// Datastar patches a page view's top-level elements by id: dev mode warns once per route about an update it cannot patch.

/**
 * The warnings about root ids while tabs open each route of $views, a view's HTML by route, twice.
 *
 * @param array<string, callable(Context): void|string> $views route => the HTML its view renders, or its handler
 *
 * @return list<string>
 */
function rootIdWarnings(array $views, bool $devMode = true, ?Config $config = null): array {
    $app = new TestApp(($config ?? new Config())->withDevMode($devMode)->withLogLevel('warn'), static function (Via $via) use ($views): void {
        foreach ($views as $route => $view) {
            $via->page($route, is_string($view) ? static fn (Context $c) => $c->view(static fn (): string => $view) : $view);
        }
    });
    $app->logs();
    foreach (array_keys($views) as $route) {
        $app->open($route);
        $app->open($route);
    }
    $warnings = array_values(array_filter($app->logs(), static fn (string $line): bool => str_contains($line, 'Datastar drops')));
    $app->shutdown();

    return $warnings;
}

describe('a page view without a root element id', function (): void {
    test('is warned about once per route in dev mode, with the fix', function (): void {
        $warnings = rootIdWarnings(['/counter' => '<p>Count: 0</p><button>+</button>']);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toEndWith('The view of /counter renders a top-level <p> without an id, so Datastar drops its updates (PatchElementsNoTargetsFound in the browser console): give the view one root element with an id, such as <div id="counter">...</div>.')
        ;
    });

    test('is warned about for text outside any element, a second root without an id, and a block without one', function (): void {
        $twig = (new Config())->withTemplateEngine(arrayTwig([
            'page.html.twig' => '<main id="page">{% block count %}<span>{{ n }}</span>{% endblock %}</main>',
        ]));

        expect(rootIdWarnings(['/text' => 'Count: 0'])[0] ?? '')->toContain('renders text outside any element')
            ->and(rootIdWarnings(['/two' => '<main id="m">x</main><aside>y</aside>'])[0] ?? '')->toContain('a top-level <aside> without an id')
            ->and(rootIdWarnings(['/block' => static fn (Context $c) => $c->view('page.html.twig', ['n' => 1], block: 'count')], config: $twig)[0] ?? '')
            ->toContain('The view of /block renders a top-level <span> without an id')
        ;
    });

    test('is not warned about outside dev mode', function (): void {
        expect(rootIdWarnings(['/counter' => '<p>Count: 0</p>'], devMode: false))->toBe([]);
    });
});

describe('a page view php-via can patch', function (): void {
    test('is not warned about: roots with ids around a comment, a component inside, or a whole document', function (): void {
        expect(rootIdWarnings([
            '/counter' => "\n  <div id=\"counter\"><p>Count: 0</p><button>+</button></div>\n  <!-- note -->\n",
            '/two' => '<main id="m">x</main><dialog id="d"></dialog>',
            '/widget' => static function (Context $c): void {
                $widget = $c->component(static fn (Context $w) => $w->view(static fn (): string => '<p>widget</p>'), 'widget');
                $c->view(static fn (): string => '<main id="page">' . $widget() . '</main>');
            },
            '/doc' => static function (Context $c): void {
                $c->view(static fn (): string => '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $c->viaHead() . '</head><body><p>x</p>' . $c->viaFoot() . '</body></html>');
            },
        ]))->toBe([]);
    });
});
