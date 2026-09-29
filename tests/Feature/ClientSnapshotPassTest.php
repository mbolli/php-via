<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\Via;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * getClients() during a broadcast. Outside a coroutine broadcast() fans out synchronously, which
 * holds a read epoch like a flush does; the flush itself runs in Fixtures/client_snapshot_cases.php.
 * A clone of the registry stands in for another worker.
 */

/** @param array{id?: string, ip?: string} $info */
function connectClient(Via $via, string $contextId, array $info = []): void {
    $id = $info['id'] ?? 'client-' . $contextId;
    $via->getApp()->registerClient($contextId, [
        'id' => $id,
        'identicon' => $via->generateIdenticon($id),
        'connected_at' => 1000,
        'ip' => $info['ip'] ?? '127.0.0.1',
    ]);
}

/**
 * Tabs in the GLOBAL fan-out whose view records the client count it rendered.
 *
 * @param null|Closure(int): void $onRender runs after each render's read, with its position in the pass
 *
 * @return ArrayObject<int, int> the counts, in render order
 */
function presenceTabs(Via $via, int $tabs, ?Closure $onRender = null): ArrayObject {
    /** @var ArrayObject<int, int> $counts */
    $counts = new ArrayObject();
    for ($i = 0; $i < $tabs; ++$i) {
        $ctx = new Context("tab-{$i}", '/presence', $via);
        $ctx->view(function () use ($via, $counts, $onRender): string {
            $n = count($via->getClients());
            $counts[] = $n;
            if ($onRender !== null) {
                $onRender(count($counts) - 1);
            }

            return "<div id=\"presence\">{$n}</div>";
        }, cacheUpdates: false);
        $via->contexts["tab-{$i}"] = $ctx;
    }

    return $counts;
}

/** @return array<string, mixed> what the scenario in Fixtures/client_snapshot_cases.php observed */
function clientSnapshotCase(string $case): array {
    $fixture = dirname(__DIR__) . '/Fixtures/client_snapshot_cases.php';
    $out = trim((string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($case) . ' 2>&1'
    ));
    $lines = explode("\n", $out);
    $data = json_decode((string) end($lines), true);

    expect($data)->toBeArray('fixture output: ' . var_export($out, true));
    expect($data)->not->toHaveKey('error', 'fixture output: ' . var_export($out, true));

    return $data;
}

describe('multi-worker', function (): void {
    beforeEach(function (): void {
        $this->via = createVia();
        $this->registry = new SharedClientRegistry(64);
        $this->via->getApp()->setClientRegistry($this->registry);
        $this->remote = clone $this->registry;
        $this->remote->claimWorker(1);

        connectClient($this->via, 'local-1');
        connectClient($this->via, 'local-2');
    });

    test('a client joining on another worker mid-broadcast shows up on the next broadcast, not halfway through', function (): void {
        $counts = presenceTabs($this->via, 5, function (int $render): void {
            if ($render === 0) {
                $this->remote->register('remote-1', 'client-remote-1', '10.0.0.1', 1000);
            }
        });

        $this->via->broadcast(Scope::GLOBAL);

        expect($counts->getArrayCopy())->toBe([2, 2, 2, 2, 2]);

        $counts->exchangeArray([]);
        $this->via->broadcast(Scope::GLOBAL);

        expect($counts->getArrayCopy())->toBe([3, 3, 3, 3, 3]);
    });

    test('a client leaving this worker mid-broadcast is gone from the renders after it', function (): void {
        $counts = presenceTabs($this->via, 4, function (int $render): void {
            if ($render === 1) {
                $this->via->getApp()->unregisterClient('local-1');
            }
        });

        $this->via->broadcast(Scope::GLOBAL);

        expect($counts->getArrayCopy())->toBe([2, 2, 1, 1]);
    });

    test('outside a broadcast a change on another worker is visible at once', function (): void {
        expect($this->via->getClients())->toHaveCount(2);

        $this->remote->register('remote-1', 'client-remote-1', '10.0.0.1', 1000);
        expect($this->via->getClients())->toHaveCount(3);

        $this->remote->unregister('remote-1');
        expect($this->via->getClients())->toHaveCount(2);
    });

    test('the connect callback sees its own client and the disconnect callback does not', function (): void {
        $ctx = new class('ctx-sse', '/sse', $this->via) extends Context {
            public function getPatch(): ?array {
                throw new LogicException('client went away');
            }
        };
        $this->via->contexts['ctx-sse'] = $ctx;
        $ctx->view(fn (): string => '<div id="ok">ok</div>');

        $seen = [];
        $this->via->onClientConnect(function () use (&$seen): void {
            $seen['connect'] = array_keys($this->via->getClients());
        });
        $this->via->onClientDisconnect(function () use (&$seen): void {
            $seen['disconnect'] = array_keys($this->via->getClients());
        });

        $request = new FakeActionRequest('unused', ['via_ctx' => 'ctx-sse']);
        $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
        ob_start();

        try {
            (new SseHandler($this->via))->handleSSE($request, new FakeStaticResponse());
        } catch (LogicException) {
            // RequestHandler answers this one.
        } finally {
            ob_end_clean();
            $this->via->getApp()->cancelContextCleanup('ctx-sse');
        }

        expect($seen['connect'])->toEqualCanonicalizing(['local-1', 'local-2', 'ctx-sse']);
        expect($seen['disconnect'])->toEqualCanonicalizing(['local-1', 'local-2']);
        expect($this->registry->count())->toBe(2);
    });
});

describe('single worker', function (): void {
    test('getClients() returns each client with its context ID, in the documented key order', function (): void {
        $via = createVia();
        connectClient($via, 'ctx-a', ['id' => 'client-a', 'ip' => '10.0.0.1']);

        expect($via->getClients())->toBe([
            'ctx-a' => [
                'id' => 'client-a',
                'identicon' => $via->generateIdenticon('client-a'),
                'connected_at' => 1000,
                'ip' => '10.0.0.1',
                'context_id' => 'ctx-a',
            ],
        ]);

        $via->getApp()->unregisterClient('ctx-a');

        expect($via->getClients())->toBe([]);
    });

    test('a broadcast sees a client that joins during it', function (): void {
        $via = createVia();
        connectClient($via, 'local-1');
        $counts = presenceTabs($via, 3, function (int $render) use ($via): void {
            if ($render === 0) {
                connectClient($via, 'local-2');
            }
        });

        $via->broadcast(Scope::GLOBAL);

        expect($counts->getArrayCopy())->toBe([1, 2, 2]);
    });
});

describe('broadcast flush', function (): void {
    test('a flush of several scopes reads the list once, and the next flush sees a client that joined meanwhile', function (): void {
        $r = clientSnapshotCase('flush-reads-once');

        expect($r['first'])->toBe(['a1=2', 'a2=2', 'b1=2', 'b2=2']);
        expect($r['second'])->toBe(['a1=3', 'a2=3', 'b1=3', 'b2=3']);
        expect($r['flushes'])->toBe(2);
    });

    test('a view waiting on I/O does not split the flush\'s list', function (): void {
        $r = clientSnapshotCase('waiting-view');

        expect($r['renders'])->toBe(['r1=2', 'r2=2', 'r3=2']);
        expect($r['after'])->toBe(3);
    });

    test('a coroutine outside the flush reads the current list while a view waits, and the flush reads again after it', function (): void {
        $r = clientSnapshotCase('interleaved-reader');

        expect($r['reader'])->toBe(3);
        expect($r['renders'])->toBe(['r1=2', 'r2=3', 'r3=3']);
    });
});
