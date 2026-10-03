<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * view(callable) or view('template', array|callable $data, ?string $block, bool $shareRender).
 * Shapes that used to do nothing quietly now throw, and a shared update render is opt-in.
 */

function viewShapeApp(?Config $config = null): Via {
    $engine = arrayTwig([
        'count.html.twig' => '<p id="n">{{ n }}</p>',
        'doc.html.twig' => '<!DOCTYPE html><html><head><meta data-signals=\'{"via_ctx":"{{ contextId }}"}\'></head><body>{% block main %}<main id="m">{{ n }}</main>{% endblock %}</body></html>',
    ]);

    return $config === null ? createVia((new Config())->withTemplateEngine($engine)) : new Via($config->withTemplateEngine($engine));
}

function viewShapeLog(callable $fn): string {
    ob_start();

    try {
        $fn();

        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

describe('view() shapes', function (): void {
    test('a string with markup throws, since a string is always a template name', function (): void {
        $c = new Context('ctx1', '/p', viewShapeApp());

        expect(fn () => $c->view('<p>Hello</p>'))
            ->toThrow(InvalidArgumentException::class, 'not markup')
        ;
    });

    test('data with a callable view throws', function (): void {
        $c = new Context('ctx1', '/p', viewShapeApp());

        expect(fn () => $c->view(fn (): string => '<p>x</p>', ['n' => 1]))
            ->toThrow(InvalidArgumentException::class, 'data applies only to a template view')
        ;
    });

    test('a data callable runs on every render, so later state reaches the page', function (): void {
        $c = new Context('ctx1', '/p', viewShapeApp());
        $n = 1;
        $c->view('count.html.twig', function () use (&$n): array {
            return ['n' => $n];
        });

        expect($c->renderView())->toBe('<p id="n">1</p>');
        $n = 2;
        expect($c->renderView(isUpdate: true))->toBe('<p id="n">2</p>');
    });

    test('a data array is fixed when the view is defined', function (): void {
        $c = new Context('ctx1', '/p', viewShapeApp());
        $n = 1;
        $c->view('count.html.twig', ['n' => $n]);
        $n = 2;

        expect($c->renderView(isUpdate: true))->toBe('<p id="n">1</p>');
    });

    test('component() needs a namespace', function (): void {
        $c = new Context('ctx1', '/p', viewShapeApp());

        // @phpstan-ignore arguments.count
        expect(fn () => $c->component(fn (Context $k) => $k->view(fn (): string => 'k')))
            ->toThrow(ArgumentCountError::class)
        ;
    });

    test('component() refuses a namespace another component on the page has', function (): void {
        $c = new Context('ctx1', '/p', viewShapeApp());
        $leaf = fn (Context $k) => $k->view(fn (): string => 'k');
        $outer = fn (string $inner) => function (Context $o) use ($leaf, $inner): void {
            $o->component($leaf, $inner);
            $o->view(fn (): string => 'o');
        };
        $c->component($leaf, 'item');
        $c->component($outer('row'), 'list-a');

        expect(fn () => $c->component($leaf, 'item'))->toThrow(InvalidArgumentException::class, "A component named 'item' is already on this page")
            ->and(fn () => $c->component($outer('item'), 'list-b'))->toThrow(InvalidArgumentException::class, "'item'")
            ->and(fn () => $c->component($outer('row'), 'list-c'))->toThrow(InvalidArgumentException::class, "'row'")
            ->and(fn () => $c->component($leaf, 'list-a'))->toThrow(InvalidArgumentException::class, "'list-a'")
            ->and(fn () => $c->component($outer('box'), 'box'))->toThrow(InvalidArgumentException::class, "'box'")
            ->and(fn () => $c->component(function (Context $o) use ($leaf): void {
                $o->component($leaf, 'cell');
                $o->component($leaf, 'cell');
            }, 'grid'))->toThrow(InvalidArgumentException::class, "'cell'")
            ->and($c->getComponentRegistry())->toHaveCount(2)
        ;
    });
});

describe('shareRender', function (): void {
    /** @return array{0: Context, 1: Context, 2: Closure(): int} two contexts of one view in 'room:1' and their render count */
    $pair = function (bool $share): array {
        $app = viewShapeApp();
        $renders = 0;
        $make = function (string $id) use ($app, $share, &$renders): Context {
            $c = new Context($id, '/room', $app);
            $c->scope('room:1');
            $c->view(function () use (&$renders): string {
                ++$renders;

                return '<p id="r">' . $renders . '</p>';
            }, shareRender: $share);

            return $c;
        };

        return [$make('/room_/a'), $make('/room_/b'), function () use (&$renders): int {
            return $renders;
        }];
    };

    test('a scoped view renders every update itself by default', function () use ($pair): void {
        [$a, $b, $renders] = $pair(false);
        $a->renderView(isUpdate: true);
        $b->renderView(isUpdate: true);

        expect($renders())->toBe(2);
    });

    test('shareRender: true renders an update once for every context of the view', function () use ($pair): void {
        [$a, $b, $renders] = $pair(true);
        $htmlA = $a->renderView(isUpdate: true);
        $htmlB = $b->renderView(isUpdate: true);

        expect($renders())->toBe(1)
            ->and($htmlB)->toBe($htmlA)
        ;
    });

    test('a positional fourth argument of true shares the render', function (): void {
        $app = viewShapeApp();
        $renders = 0;
        foreach (['/room_/a', '/room_/b'] as $id) {
            $c = new Context($id, '/room', $app);
            $c->scope('room:1');
            $c->view('count.html.twig', function () use (&$renders): array {
                return ['n' => ++$renders];
            }, null, true);
            $c->renderView(isUpdate: true);
        }

        expect($renders)->toBe(1);
    });

    test('shareRender: true on a TAB-primary context throws at render', function (): void {
        $c = new Context('ctx1', '/tab', viewShapeApp());
        $c->addScope('room:1');
        $c->view(fn (): string => '<p>x</p>', shareRender: true);

        expect(fn () => $c->renderView())
            ->toThrow(LogicException::class, 'primary scope is TAB')
        ;
    });

    test('scope() after view() is fine, since the check runs at render', function (): void {
        $c = new Context('ctx1', '/late', viewShapeApp());
        $c->view(fn (): string => '<p>x</p>', shareRender: true);
        $c->scope('room:1');

        expect($c->renderView(isUpdate: true))->toBe('<p>x</p>');
    });

    test('a full document never shares its update render, and dev mode says so once', function (): void {
        $app = viewShapeApp((new Config())->withDevMode(true)->withLogLevel('warn'));
        $make = function (string $id) use ($app): Context {
            $c = new Context($id, '/doc', $app);
            $c->scope('room:1');
            $c->view('doc.html.twig', ['n' => 1], shareRender: true);

            return $c;
        };
        $a = $make('/doc_/a');
        $b = $make('/doc_/b');

        $log = viewShapeLog(function () use ($a, $b, &$htmlA, &$htmlB): void {
            $htmlA = $a->renderView(isUpdate: true);
            $htmlB = $b->renderView(isUpdate: true);
        });

        expect($htmlA)->toContain('/doc_/a')
            ->and($htmlB)->toContain('/doc_/b')
            ->and(substr_count($log, 'full document'))->toBe(1)
        ;
    });

    test('a block update of a full-document template still shares', function (): void {
        $app = viewShapeApp();
        $renders = 0;
        foreach (['/doc_/a', '/doc_/b'] as $id) {
            $c = new Context($id, '/doc', $app);
            $c->scope('room:1');
            $c->view('doc.html.twig', function () use (&$renders): array {
                return ['n' => ++$renders];
            }, block: 'main', shareRender: true);
            expect($c->renderView(isUpdate: true))->toBe('<main id="m">1</main>');
        }

        expect($renders)->toBe(1);
    });

    test('a broadcast drops the shared render', function (): void {
        $app = viewShapeApp((new Config())->withLogLevel('error')->withBroadcastCoalescing(false));
        $v = 1;
        $c = new Context('/room_/a', '/room', $app);
        $c->scope('room:1');
        $c->view(function () use (&$v): string {
            return '<p id="v">' . $v . '</p>';
        }, shareRender: true);
        expect($c->renderView(isUpdate: true))->toBe('<p id="v">1</p>');

        $v = 2;
        expect($c->renderView(isUpdate: true))->toBe('<p id="v">1</p>');
        $app->broadcast('room:1');

        expect($c->renderView(isUpdate: true))->toBe('<p id="v">2</p>');
    });
});

test('Scope::TAB passed to scope() is TAB-primary for shareRender too', function (): void {
    $c = new Context('ctx1', '/tab', viewShapeApp());
    $c->scope(Scope::TAB);
    $c->view(fn (): string => '<p>x</p>', shareRender: true);

    expect(fn () => $c->renderView(isUpdate: true))->toThrow(LogicException::class);
});
