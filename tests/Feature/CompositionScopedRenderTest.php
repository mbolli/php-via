<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal as SignalAttr;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;

// A composition view that reads a scoped #[Signal] property renders the value another tab wrote.

final class ScopedRenderTally {
    #[SignalAttr(Scope::ROUTE)]
    public int $total = 0;

    #[SignalAttr]
    public int $mine = 0;

    #[Action]
    public function add(Context $c): void {
        ++$this->total;
        ++$this->mine;
    }

    #[Action]
    public function addAndShow(Context $c): void {
        ++$this->total;
        // The tab's own render inside the action shows the change before it is written back.
        $c->sync();
    }

    public function view(Context $c): void {
        $c->view(fn (): string => '<p id="tally">total ' . $this->total . ', mine ' . $this->mine . '</p>');
    }
}

function scopedRenderApp(): TestApp {
    return new TestApp((new Config())->withLogLevel('error'), static function (Via $via): void {
        $via->mount(ScopedRenderTally::class, '/tally');
    });
}

/**
 * The HTML of the element patches in $patches.
 *
 * @param list<array<string, mixed>> $patches
 *
 * @return list<string>
 */
function scopedRenderFrames(array $patches): array {
    return array_values(array_map(
        static fn (array $p): string => (string) $p['html'],
        array_filter($patches, static fn (array $p): bool => $p['type'] === 'elements' && ($p['mode'] ?? null) === PatchMode::Outer),
    ));
}

describe('a scoped #[Signal] property in a composition view', function (): void {
    test('renders the value another tab wrote when the broadcast reaches this tab', function (): void {
        $app = scopedRenderApp();
        $a = $app->open('/tally');
        $b = $app->open('/tally');
        $b->patches();

        $a->action('add');

        expect(scopedRenderFrames($b->patches()))->toBe(['<p id="tally">total 1, mine 0</p>'])
            ->and($b->html())->toContain('<p id="tally">total 1, mine 0</p>')
        ;

        $b->action('add');
        expect(scopedRenderFrames($a->patches()))->toContain('<p id="tally">total 2, mine 1</p>');
        $app->shutdown();
    });

    test('keeps an action\'s own change for a render inside that action', function (): void {
        $app = scopedRenderApp();
        $a = $app->open('/tally');
        $b = $app->open('/tally');
        $a->patches();
        $b->patches();

        $a->action('addAndShow');

        expect(scopedRenderFrames($a->patches())[0] ?? null)->toBe('<p id="tally">total 1, mine 0</p>')
            ->and(scopedRenderFrames($b->patches()))->toBe(['<p id="tally">total 1, mine 0</p>'])
        ;
        $app->shutdown();
    });
});
