<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Response;
use Tests\Support\FakeActionRequest;

/*
 * signal(..., clientSeeded: true): the browser holds the initial value. The page seed and the first
 * sync leave the signal out, a re-declaration keeps the live value, and an SSE connect gives it the
 * browser's value before the view renders, until the server writes it.
 */

const SEEDED_SESSION = 'a11ce000a11ce000a11ce000a11ce000';

/** A /nav page with a clientSeeded 'page' signal whose view prints it. */
function seededApp(?Config $config = null): Via {
    $via = $config === null ? createVia() : new Via($config);
    $via->page('/nav', function (Context $c): void {
        $page = $c->signal('overview', 'page', clientSeeded: true);
        $c->signal(0, 'count');
        $c->action(function () use ($page): void {
            $page->setValue('health');
        }, 'go-health');
        $c->view(fn (): string => '<div id="nav">page=' . $page->string() . '</div>');
    });

    return $via;
}

function seededLoad(Via $via, string $contextId = '/nav_/n1'): Context {
    $ctx = new Context($contextId, '/nav', $via, null, SEEDED_SESSION);
    $via->contexts[$contextId] = $ctx;
    $via->invokeHandlerWithParams($via->getRouter()->getRoutes()['/nav'], $ctx, []);
    $via->getApp()->registerContext($ctx);
    $via->getApp()->setContextSession($contextId, SEEDED_SESSION);
    $via->registerContextInScope($ctx, Scope::TAB);

    return $ctx;
}

/**
 * Run an SSE connect through SseHandler and return what reached the wire.
 *
 * @param array<string, mixed> $signals
 */
function seededConnect(Via $via, string $contextId, array $signals): string {
    $connect = new FakeActionRequest('unused', []);
    $connect->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
    $connect->get = ['datastar' => (string) json_encode(['via_ctx' => $contextId] + $signals)];
    $connect->cookie = ['via_session_id' => SEEDED_SESSION];
    $stream = new class extends Response {
        public string $written = '';
        private int $polls = 0;

        public function header(string $key, mixed $value, bool $ucwords = true): bool {
            return true;
        }

        public function status(int $statusCode, string $reason = ''): bool {
            return true;
        }

        public function write(string $data): bool {
            $this->written .= $data;

            return true;
        }

        public function isWritable(): bool {
            return ++$this->polls <= 5;
        }

        public function end(mixed $data = null): bool {
            return true;
        }
    };

    try {
        (new SseHandler($via))->handleSSE($connect, $stream);
    } finally {
        $via->getApp()->cancelContextCleanup($contextId);
    }

    return $stream->written;
}

describe('a clientSeeded signal', function (): void {
    test('is left out of the page seed', function (): void {
        $via = seededApp();
        $ctx = seededLoad($via);
        $page = $ctx->getSignal('page');

        $html = $via->buildHtmlDocument($ctx);

        expect($html)->toContain('data-signals__ifmissing')
            ->and($html)->toContain((string) $ctx->getSignal('count')?->id())
            ->and($html)->not->toContain((string) $page?->id())
        ;
    });

    test('takes the browser\'s value on the SSE connect, before the first sync renders the view', function (): void {
        $via = seededApp();
        $ctx = seededLoad($via);
        $id = (string) $ctx->getSignal('page')?->id();

        $written = seededConnect($via, '/nav_/n1', [$id => 'flows']);

        expect($ctx->getSignal('page')?->getValue())->toBe('flows')
            ->and($written)->toContain('page=flows')
            ->and($written)->not->toContain($id)
        ;
    });

    test('keeps a value the server wrote over the browser\'s on a later connect', function (): void {
        $via = seededApp();
        $ctx = seededLoad($via);
        $id = (string) $ctx->getSignal('page')?->id();
        $ctx->executeAction('go-health');

        $written = seededConnect($via, '/nav_/n1', [$id => 'flows']);

        expect($ctx->getSignal('page')?->getValue())->toBe('health')
            ->and($written)->toContain('page=health')
        ;
    });

    test('takes the browser\'s value from an action, as any client-writable signal', function (): void {
        $via = seededApp((new Config())->withLogLevel('error')->withStrictTabSignals());
        $ctx = seededLoad($via);
        $page = $ctx->getSignal('page');

        $ctx->injectSignals([(string) $page?->id() => 'talkers']);

        expect($page?->isClientWritable())->toBeTrue('clientSeeded wins over withStrictTabSignals()')
            ->and($page?->getValue())->toBe('talkers')
            ->and($page?->hasChanged())->toBeFalse()
        ;
    });

    test('keeps its live value when declared again', function (): void {
        $via = createVia();
        $ctx = new Context('/x_/a', '/x', $via);
        $first = $ctx->signal('overview', 'page', clientSeeded: true);
        $ctx->injectSignals([$first->id() => 'flows']);

        $again = $ctx->signal('overview', 'page', clientSeeded: true);

        expect($again)->toBe($first)
            ->and($again->getValue())->toBe('flows')
            ->and($again->hasChanged())->toBeFalse()
        ;
    });

    test('a revival seeds it from the browser like the other signals', function (): void {
        $via = seededApp();
        $ctx = seededLoad($via);
        $id = (string) $ctx->getSignal('page')?->id();
        $via->getApp()->destroyContext('/nav_/n1');
        unset($via->contexts['/nav_/n1']);

        $revived = $via->reviveContextFromClient('/nav_/n1', SEEDED_SESSION, [$id => 'alerts'], byConnect: true);

        expect($revived?->getSignal('page')?->getValue())->toBe('alerts');
    });

    test('a write of another type from the browser is refused', function (): void {
        $via = seededApp();
        $ctx = seededLoad($via);
        $id = (string) $ctx->getSignal('page')?->id();

        $via->seedFromConnect($ctx, [$id => ['not' => 'a string']]);

        expect($ctx->getSignal('page')?->getValue())->toBe('overview');
    });

    test('is a TAB signal only, and client-writable', function (): void {
        $ctx = new Context('/x_/a', '/x', createVia());

        expect(fn () => $ctx->signal('a', 'shared', Scope::GLOBAL, clientSeeded: true))
            ->toThrow(InvalidArgumentException::class, 'a shared signal has one value for every tab')
            ->and(fn () => $ctx->signal('a', 'owned', clientWritable: false, clientSeeded: true))
            ->toThrow(InvalidArgumentException::class, 'clientWritable: false refuses it')
        ;
    });

    test('markSynced() keeps working for the same purpose', function (): void {
        $via = createVia();
        $ctx = new Context('/x_/a', '/x', $via);
        $signal = $ctx->signal('overview', 'page');
        $signal->markSynced();

        expect($signal->hasChanged())->toBeFalse()
            ->and($signal->isClientSeeded())->toBeFalse()
        ;
    });
});
