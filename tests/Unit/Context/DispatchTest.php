<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Context::dispatch() fires a browser CustomEvent through a script whose name and detail are
 * JSON-encoded, so no value breaks out of the script element Datastar inserts.
 */

/** @return list<string> the scripts queued for $ctx, oldest first */
function dispatchedScripts(Context $ctx): array {
    $scripts = [];
    while (($patch = $ctx->getPatch()) !== null) {
        expect($patch['type'])->toBe('script');
        $scripts[] = (string) $patch['content'];
    }

    return $scripts;
}

/**
 * The event name and detail a dispatch script carries, decoded.
 *
 * @return array{0: mixed, 1: mixed}
 */
function dispatchedEvent(string $script): array {
    expect(preg_match('/^window\.dispatchEvent\(new CustomEvent\((".*?(?<!\\\)"), \{detail: (.*)\}\)\)$/s', $script, $m))->toBe(1, $script);

    return [json_decode($m[1], true, 512, JSON_THROW_ON_ERROR), json_decode($m[2], true, 512, JSON_THROW_ON_ERROR)];
}

describe('Context::dispatch()', function (): void {
    test('queues a script that fires the event on window with the detail', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        $ctx->dispatch('toast', ['level' => 'error', 'text' => 'Save failed']);
        $ctx->dispatch('refreshed');

        expect(dispatchedScripts($ctx))->toBe([
            'window.dispatchEvent(new CustomEvent("toast", {detail: {"level":"error","text":"Save failed"}}))',
            'window.dispatchEvent(new CustomEvent("refreshed", {detail: null}))',
        ]);
    });

    test('encodes a name and a detail that would break a hand-written script', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $detail = ['text' => "it's \"quoted\" \\ </script><script>alert(1)</script> <!-- \u{2028} & done", 'path' => '/a/b', 'n' => 1.5];

        $ctx->dispatch('my-"event"</script>', $detail);

        [$script] = dispatchedScripts($ctx);
        expect($script)->not->toContain('<')->not->toContain('>')->not->toContain("\u{2028}")
            ->and(dispatchedEvent($script))->toBe(['my-"event"</script>', $detail])
        ;
    });

    test('replaces invalid UTF-8 in the detail', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        $ctx->dispatch('log', "bytes \xB1\x31");

        expect(dispatchedEvent(dispatchedScripts($ctx)[0])[1])->toBe("bytes \u{FFFD}1");
    });

    test('sends a component\'s event to its page', function (): void {
        $page = new Context(testContextId(), '/test', createVia());
        $page->component(static fn (Context $c) => $c->view(static fn (): string => '<p>cart</p>'), 'cart');
        $components = $page->getComponentRegistry();
        $component = end($components) ?: throw new LogicException('no component');

        $component->dispatch('cart-changed', 3);

        expect(dispatchedEvent(dispatchedScripts($page)[0]))->toBe(['cart-changed', 3])
            ->and(dispatchedScripts($component))->toBe([])
        ;
    });

    test('throws for an empty name or a detail JSON cannot hold, and queues nothing', function (string $event, mixed $detail, string $message): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        expect(fn () => $ctx->dispatch($event, $detail))->toThrow(InvalidArgumentException::class, $message)
            ->and(dispatchedScripts($ctx))->toBe([])
        ;
    })->with([
        'an empty name' => ['', null, 'needs an event name'],
        'NAN' => ['tick', NAN, "dispatch('tick') takes a detail that json_encode() can encode"],
        'a resource' => ['file', STDIN, "dispatch('file') takes a detail"],
    ]);
});
