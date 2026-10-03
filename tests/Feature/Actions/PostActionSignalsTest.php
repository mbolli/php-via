<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal as SignalAttr;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Via;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * After every action the framework sends the TAB signals it changed, in both programming models
 * and also when the action throws. A forgotten syncSignals() used to leave the page stale.
 */

/**
 * @param array<string, mixed> $signals extra client values posted with via_ctx
 *
 * @return list<array<string, mixed>> the content of each signals patch queued for the page
 */
function postActionSignalFrames(Via $via, Context $page, string $actionId, array $signals = []): array {
    $via->contexts[$page->getId()] = $page;
    $response = new FakeStaticResponse();
    ob_start();

    try {
        (new ActionHandler($via))->handleAction(new FakeActionRequest($actionId, ['via_ctx' => $page->getId()] + $signals), $response, $actionId);
    } finally {
        ob_end_clean();
    }

    $frames = [];
    while (($patch = $page->getPatch()) !== null) {
        if ($patch['type'] === 'signals') {
            $frames[] = $patch['content'];
            ($patch['confirm'] ?? static fn () => null)();
        }
    }

    return $frames;
}

final class PostActionCounter {
    #[SignalAttr]
    public int $count = 0;

    #[Action]
    public function bump(Context $ctx): void {
        ++$this->count;
    }

    public function view(Context $ctx): void {
        $ctx->view(fn (): string => '<p id="n">' . $this->count . '</p>');
    }
}

describe('signals after an action', function (): void {
    test('a closure action that writes a TAB signal reaches the tab without syncSignals()', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $count = $page->signal(0, 'count');
        $count->markSynced();
        $action = $page->action(fn () => $count->setValue(5), 'set');

        expect(postActionSignalFrames($via, $page, $action->id()))->toBe([[$count->id() => 5]]);
    });

    test('the signals go out when the action throws after writing them', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $count = $page->signal(0, 'count');
        $count->markSynced();
        $action = $page->action(function () use ($count): void {
            $count->setValue(7);

            throw new RuntimeException('after the write');
        }, 'fail');

        expect(postActionSignalFrames($via, $page, $action->id()))->toBe([[$count->id() => 7]]);
    });

    test('a signal the action synced itself is not sent twice', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $count = $page->signal(0, 'count');
        $count->markSynced();
        $action = $page->action(function (Context $c) use ($count): void {
            $count->setValue(1);
            $c->syncSignals();
        }, 'set');

        expect(postActionSignalFrames($via, $page, $action->id()))->toBe([[$count->id() => 1]]);
    });

    test('a signal written again after a sync in the action goes out with its last value', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $count = $page->signal(0, 'count');
        $count->markSynced();
        $action = $page->action(function (Context $c) use ($count): void {
            $count->setValue(1);
            $c->syncSignals();
            $count->setValue(2);
        }, 'set');

        expect(postActionSignalFrames($via, $page, $action->id()))->toBe([[$count->id() => 1], [$count->id() => 2]]);
    });

    test('a value the client posted is not echoed back', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $name = $page->signal('', 'name');
        $name->markSynced();
        $action = $page->action(fn () => null, 'noop');

        expect(postActionSignalFrames($via, $page, $action->id(), [$name->id() => 'typed']))->toBe([])
            ->and($name->string())->toBe('typed')
        ;
    });

    test('a component signal an action of the component writes reaches the tab', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $ids = [];
        $page->component(function (Context $c) use (&$ids): void {
            $n = $c->signal(0, 'n');
            $n->markSynced();
            $ids = ['signal' => $n->id(), 'action' => $c->action(fn () => $n->setValue(3), 'bump')->id()];
            $c->view(fn (): string => '<p>k</p>');
        }, 'widget');

        expect(postActionSignalFrames($via, $page, $ids['action']))->toBe([[$ids['signal'] => 3]]);
    });

    test('a composition action sends its signals once', function (): void {
        $via = createVia();
        $page = new Context('/p_/a', '/p', $via);
        $page->component(PostActionCounter::class, 'counter');
        $component = array_values($page->getComponentRegistry())[0];
        foreach ($component->getSignals() as $signal) {
            $signal->markSynced();
        }
        $action = $component->getAction('bump');
        expect($action)->not->toBeNull();

        $frames = postActionSignalFrames($via, $page, $action->id());

        expect($frames)->toHaveCount(1)
            ->and(json_encode($frames[0]))->toContain('1')
        ;
    });
});
