<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Composition\ClassMetadata;
use Mbolli\PhpVia\Composition\PageMount;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * A client write must have the signal's type: the type of its initial value, or of its #[Signal]
 * property. A lossless form ('5' for a number, 'false' for a bool) is stored as that type; any
 * other value is refused like a write to a signal that is not client-writable.
 */

final class TypedClientWriteFixture {
    #[Signal]
    public int $count = 0;

    #[Signal]
    public ?string $note = null;

    #[Signal]
    public mixed $free = 0;

    public int $seen = -1;

    #[Action]
    public function read(Context $ctx): void {
        $this->seen = $this->count;
    }

    public function view(Context $ctx): void {}
}

describe('typed client writes', function (): void {
    test('a write of another type is refused, and the server value goes back to the tab', function (): void {
        $ctx = new Context('/t_/a', '/t', createVia());
        $count = $ctx->signal(5, 'count');
        $count->markSynced();

        $ctx->injectSignals([$count->id() => 'abc']);

        expect($count->getValue())->toBe(5)
            ->and($count->hasChanged())->toBeTrue('the tab gets 5 back')
        ;
    });

    test('a lossless form is stored as the signal\'s type and not sent back', function (mixed $initial, mixed $sent, mixed $stored): void {
        $ctx = new Context('/t_/a', '/t', createVia());
        $signal = $ctx->signal($initial, 'value');
        $signal->markSynced();

        $ctx->injectSignals([$signal->id() => $sent]);

        expect($signal->getValue())->toBe($stored)
            ->and($signal->hasChanged())->toBeFalse()
        ;
    })->with([
        'a textarea on a number' => [0, '12', 12],
        'a decimal on an int' => [0, 2.5, 2.5],
        'a checkbox on a number' => [0, true, 1],
        'a radio group on a bool' => [false, 'true', true],
        'a number on a string' => ['', 7, '7'],
    ]);

    test('the typed getters read what they read before', function (): void {
        $ctx = new Context('/t_/a', '/t', createVia());
        $count = $ctx->signal(0, 'count');
        $on = $ctx->signal(false, 'on');
        $name = $ctx->signal('', 'name');

        $ctx->injectSignals([$count->id() => '42', $on->id() => 'yes', $name->id() => 3]);

        expect($count->int())->toBe(42)
            ->and($on->bool())->toBeTrue()
            ->and($name->string())->toBe('3')
        ;
    });

    test('a signal declared with null takes any type', function (): void {
        $ctx = new Context('/t_/a', '/t', createVia());
        $any = $ctx->signal(null, 'any');

        $ctx->injectSignals([$any->id() => ['a' => 1]]);
        expect($any->getValue())->toBe(['a' => 1]);
        $ctx->injectSignals([$any->id() => 'text']);
        expect($any->getValue())->toBe('text');
    });

    test('a client-writable scoped signal keeps its value on a write of another type', function (): void {
        $via = createVia();
        $ctx = new Context('/t_/a', '/t', $via);
        $shared = $ctx->signal(1, 'shared', Scope::GLOBAL, clientWritable: true);

        $ctx->injectSignals([$shared->id() => 'x']);
        expect($shared->getValue())->toBe(1);
        $ctx->injectSignals([$shared->id() => 2]);
        expect($shared->getValue())->toBe(2);
    });

    test('a #[Signal] property refuses what its declared type cannot hold, so hydrate() never throws', function (): void {
        $via = createVia();
        $ctx = new Context('/t_/a', '/t', $via);
        PageMount::buildClosure(ClassMetadata::analyze(TypedClientWriteFixture::class), $via)($ctx);
        $count = $ctx->getSignal('count');
        $note = $ctx->getSignal('note');
        $free = $ctx->getSignal('free');

        $ctx->injectSignals([$count?->id() => 1.5, $note?->id() => 'hi', $free?->id() => 'any']);
        $ctx->executeAction('read');

        expect($count?->getValue())->toBe(0, 'an int property takes no fraction')
            ->and($note?->getValue())->toBe('hi', 'a ?string declared with null still takes a string')
            ->and($free?->getValue())->toBe('any', 'a mixed property takes any type')
        ;

        $ctx->injectSignals([$count?->id() => '3']);
        $ctx->executeAction('read');
        expect($count?->getValue())->toBe(3);
    });

    test('dev mode warns once per signal, without the value', function (): void {
        $via = new Via((new Config())->withDevMode(true)->withLogLevel('warn'));
        $ctx = new Context('/t_/a', '/t', $via);
        $count = $ctx->signal(0, 'count');

        ob_start();
        $ctx->injectSignals([$count->id() => 'secret-text']);
        $ctx->injectSignals([$count->id() => 'more-text']);
        $log = (string) ob_get_clean();

        expect(substr_count($log, 'holds a value of type int, and the browser sent one of type string'))->toBe(1)
            ->and($log)->not->toContain('secret-text')
        ;
    });

    test('an action-revived context seeded from its SSE connect applies the same check', function (): void {
        $via = createVia();
        $via->page('/seed', function (Context $c): void {
            $c->signal(0, 'count');
            $c->view(fn (): string => '<p></p>');
        });
        $ctx = new Context('/seed_/s', '/seed', $via, null, 'sess');
        $via->contexts['/seed_/s'] = $ctx;
        $via->invokeHandlerWithParams($via->getRouter()->getRoutes()['/seed'], $ctx, []);
        $via->getApp()->registerContext($ctx);
        $id = (string) $ctx->getSignal('count')?->id();
        $via->getApp()->destroyContext('/seed_/s');
        unset($via->contexts['/seed_/s']);

        $revived = $via->reviveContextFromClient('/seed_/s', 'sess', ['via_ctx' => '/seed_/s']);
        $via->seedFromConnect($revived ?? $ctx, [$id => 'junk']);

        expect($revived?->getSignal('count')?->getValue())->toBe(0);
    });
});
