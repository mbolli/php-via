<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Broadcast;
use Mbolli\PhpVia\Attributes\OnCleanup;
use Mbolli\PhpVia\Attributes\OnDisconnect;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Composition\ClassMetadata;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * ClassMetadata reflection tests.
 *
 * Pure reflection — no OpenSwoole server needed. Locks in the attribute parsing
 * and the scope-independence invariant (#[Signal] stays TAB even under #[Broadcast]).
 */

#[Broadcast(Scope::ROUTE)]
final class CompositionMetaFixture {
    #[Signal]
    public int $count = 0;

    #[Signal(Scope::SESSION)]
    public string $name = 'Anon';

    #[Signal(Scope::GLOBAL)]
    public int $clicks = 0;

    #[Persist]
    public int $multiplier = 1;

    /** @var array<int, string> Plain static — must be ignored by the analyzer. */
    public static array $items = [];

    public function view(Context $ctx, string $slug): void {}

    #[Action]
    public function increment(Context $ctx): void {}

    #[Action(name: 'reset-it', scope: Scope::SESSION)]
    public function reset(Context $ctx): void {}

    #[OnDisconnect]
    public function leave(Context $ctx): void {}

    #[OnCleanup]
    public function dispose(Context $ctx): void {}

    public function helper(): string {
        return 'not an action';
    }
}

final class CompositionNoViewFixture {
    #[Signal]
    public int $count = 0;
}

final class CompositionDoubleDisconnectFixture {
    public function view(Context $ctx): void {}

    #[OnDisconnect]
    public function a(Context $ctx): void {}

    #[OnDisconnect]
    public function b(Context $ctx): void {}
}

describe('ClassMetadata signal scopes', function (): void {
    $meta = ClassMetadata::analyze(CompositionMetaFixture::class);

    test('TAB #[Signal] goes into signals', function () use ($meta): void {
        expect($meta->signals)->toBe(['count']);
    });

    test('scoped #[Signal(...)] goes into scopedSignals with raw scope', function () use ($meta): void {
        expect($meta->scopedSignals)->toContain(['prop' => 'name', 'scope' => Scope::SESSION])
            ->and($meta->scopedSignals)->toContain(['prop' => 'clicks', 'scope' => Scope::GLOBAL])
        ;
    });

    test('#[Broadcast] does not move #[Signal] out of TAB', function () use ($meta): void {
        // scope-independence invariant: count stays TAB even though the class is #[Broadcast(ROUTE)]
        $scopedProps = array_column($meta->scopedSignals, 'prop');
        expect($scopedProps)->not->toContain('count');
    });

    test('#[Persist] goes into persists, not signals', function () use ($meta): void {
        expect($meta->persists)->toBe(['multiplier'])
            ->and($meta->signals)->not->toContain('multiplier')
        ;
    });

    test('static property is ignored', function () use ($meta): void {
        $scopedProps = array_column($meta->scopedSignals, 'prop');
        expect($meta->signals)->not->toContain('items')
            ->and($meta->persists)->not->toContain('items')
            ->and($scopedProps)->not->toContain('items')
        ;
    });

    test('defaults are captured per property', function () use ($meta): void {
        expect($meta->defaults['count'])->toBe(0)
            ->and($meta->defaults['name'])->toBe('Anon')
            ->and($meta->defaults['multiplier'])->toBe(1)
        ;
    });
});

describe('ClassMetadata actions and lifecycle', function (): void {
    $meta = ClassMetadata::analyze(CompositionMetaFixture::class);

    test('only #[Action] methods become actions', function () use ($meta): void {
        $names = array_column($meta->actions, 'name');
        expect($names)->toContain('increment')
            ->and($names)->toContain('reset-it')
            ->and($names)->not->toContain('helper')
            ->and($names)->not->toContain('view')
        ;
    });

    test('action name and scope overrides are read', function () use ($meta): void {
        $byMethod = array_column($meta->actions, null, 'method');
        expect($byMethod['reset']['name'])->toBe('reset-it')
            ->and($byMethod['reset']['scope'])->toBe(Scope::SESSION)
        ;
    });

    test('plain #[Action] defaults name to method and scope to null', function () use ($meta): void {
        $byMethod = array_column($meta->actions, null, 'method');
        expect($byMethod['increment']['name'])->toBe('increment')
            ->and($byMethod['increment']['scope'])->toBeNull()
        ;
    });

    test('#[Broadcast] scope is captured', function () use ($meta): void {
        expect($meta->broadcastScope)->toBe(Scope::ROUTE);
    });

    test('lifecycle hooks are captured', function () use ($meta): void {
        expect($meta->onDisconnect)->toBe('leave')
            ->and($meta->onCleanup)->toBe('dispose')
        ;
    });

    test('view route params beyond Context are captured', function () use ($meta): void {
        expect($meta->viewRouteParams)->toBe([['name' => 'slug', 'type' => 'string']]);
    });
});

describe('ClassMetadata validation', function (): void {
    test('class without view() throws', function (): void {
        expect(fn () => ClassMetadata::analyze(CompositionNoViewFixture::class))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    test('duplicate lifecycle hook throws', function (): void {
        expect(fn () => ClassMetadata::analyze(CompositionDoubleDisconnectFixture::class))
            ->toThrow(InvalidArgumentException::class);
    });
});
