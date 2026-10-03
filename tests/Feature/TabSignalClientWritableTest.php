<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Composition\ClassMetadata;
use Mbolli\PhpVia\Composition\PageMount;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * clientWritable for TAB signals: null keeps the old default (writable), false and true are
 * honoured, and Config::withStrictTabSignals() turns the default into server-owned.
 */

final class TabClientWritableFixture {
    #[Signal(clientWritable: false)]
    public string $owned = 'server';

    #[Signal]
    public string $free = 'server';

    public function view(Context $ctx): void {}
}

final class ScopedClientWritableFixture {
    #[Signal(Scope::GLOBAL, clientWritable: true)]
    public string $shared = 'server';

    public function view(Context $ctx): void {}
}

describe('TAB signal clientWritable', function (): void {
    test('a TAB signal declared clientWritable: false ignores client values', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $owned = $ctx->signal('server', 'owned', clientWritable: false);

        $ctx->injectSignals([$owned->id() => 'client']);

        expect($owned->isClientWritable())->toBeFalse()
            ->and($owned->getValue())->toBe('server')
        ;
    });

    test('a TAB signal without the argument stays client-writable (unchanged default)', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $free = $ctx->signal('server', 'free');

        $ctx->injectSignals([$free->id() => 'client']);

        expect($free->isClientWritable())->toBeTrue()
            ->and($free->getValue())->toBe('client')
        ;
    });

    test('a server-owned TAB signal is still sent to the client', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $owned = $ctx->signal(7, 'owned', clientWritable: false);
        $ctx->view(fn () => '<div id="x"></div>');

        $ctx->syncSignals();
        $patch = $ctx->getPatch();

        expect($patch)->not->toBeNull()
            ->and($patch['type'])->toBe('signals')
            ->and($patch['content'])->toHaveKey($owned->id())
        ;
    });

    test('a rejected client value that differs marks the signal for re-sync', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $owned = $ctx->signal('server', 'owned', clientWritable: false);
        $owned->markSynced();

        $ctx->injectSignals([$owned->id() => 'server']);
        expect($owned->hasChanged())->toBeFalse();

        $ctx->injectSignals([$owned->id() => 'client']);
        expect($owned->hasChanged())->toBeTrue()
            ->and($owned->getValue())->toBe('server')
        ;
    });

    test('an integral float that the browser posts back as an int is not re-sent', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $owned = $ctx->signal([1.0, 0.5], 'owned', clientWritable: false);
        $owned->markSynced();

        // JSON.stringify(1.0) is "1", so PHP decodes the browser's copy as int.
        $ctx->injectSignals([$owned->id() => [1, 0.5]]);

        expect($owned->hasChanged())->toBeFalse();
    });

    test('a signal holding an object takes the posted object, or is re-sent when server-owned', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $free = $ctx->signal(['a' => 1], 'free');
        $owned = $ctx->signal(['a' => 1], 'owned', clientWritable: false);
        $owned->markSynced();

        $ctx->injectSignals([$free->id() => ['a' => 2], $owned->id() => ['a' => 2]]);

        expect($free->getValue())->toBe(['a' => 2])
            ->and($owned->getValue())->toBe(['a' => 1])
            ->and($owned->hasChanged())->toBeTrue()
        ;
    });

    test('a component signal holding an object takes the posted object', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $ctx->component(function (Context $c): void {
            $c->signal(['a' => 1], 'filters');
            $c->view(fn () => '');
        }, 'cmp');
        $filters = array_values($ctx->getComponentManager()->getComponents())[0]->getSignal('filters');

        $ctx->injectSignals([$filters->id() => ['a' => 2]]);

        expect($filters->getValue())->toBe(['a' => 2]);
    });

    test('a rejected scoped value is overwritten by the next sync', function (): void {
        $ctx = new Context('ctx1', '/test', createVia());
        $ctx->scope('room:owned');
        $owned = $ctx->signal('server', 'n', 'room:owned', clientWritable: false);

        $ctx->injectSignals([$owned->id() => 'client']);
        $ctx->syncSignals();
        $patch = $ctx->getPatch();

        expect($owned->getValue())->toBe('server')
            ->and($patch['content'][$owned->id()] ?? null)->toBe('server')
        ;
    });
});

describe('strict TAB signals', function (): void {
    test('strict mode makes an undeclared TAB signal server-owned', function (): void {
        $ctx = new Context('ctx1', '/test', createVia((new Config())->withStrictTabSignals()));
        $sig = $ctx->signal('server', 'sig');

        $ctx->injectSignals([$sig->id() => 'client']);

        expect($sig->isClientWritable())->toBeFalse()
            ->and($sig->getValue())->toBe('server')
        ;
    });

    test('strict mode still accepts a TAB signal declared clientWritable: true', function (): void {
        $ctx = new Context('ctx1', '/test', createVia((new Config())->withStrictTabSignals()));
        $sig = $ctx->signal('server', 'sig', clientWritable: true);

        $ctx->injectSignals([$sig->id() => 'client']);

        expect($sig->getValue())->toBe('client');
    });

    test('strict mode leaves scoped signals as they were', function (): void {
        $ctx = new Context('ctx1', '/test', createVia((new Config())->withStrictTabSignals()));
        $ctx->scope('room:strict');
        $shared = $ctx->signal('', 'note', 'room:strict', clientWritable: true);

        $ctx->injectSignals([$shared->id() => 'client']);

        expect($shared->getValue())->toBe('client');
    });
});

describe('#[Signal(clientWritable: ...)]', function (): void {
    test('the attribute reaches the signal', function (): void {
        $via = createVia();
        $ctx = new Context('ctx1', '/test', $via);
        PageMount::buildClosure(ClassMetadata::analyze(TabClientWritableFixture::class), $via)($ctx);

        $owned = $ctx->getSignal('owned');
        $free = $ctx->getSignal('free');
        $ctx->injectSignals([$owned->id() => 'client', $free->id() => 'client']);

        expect($owned->isClientWritable())->toBeFalse()
            ->and($owned->getValue())->toBe('server')
            ->and($free->getValue())->toBe('client')
        ;
    });

    test('the attribute reaches a scoped signal', function (): void {
        $via = createVia();
        $ctx = new Context('ctx1', '/test', $via);
        PageMount::buildClosure(ClassMetadata::analyze(ScopedClientWritableFixture::class), $via)($ctx);

        $shared = $ctx->getSignal('shared');
        $ctx->injectSignals([$shared->id() => 'client']);

        expect($shared->isClientWritable())->toBeTrue()
            ->and($shared->getValue())->toBe('client')
        ;
    });
});
