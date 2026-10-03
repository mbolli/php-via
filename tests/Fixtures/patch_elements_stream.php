<?php

declare(strict_types=1);

/*
 * Fixture for PatchElementsStreamTest: Context::patchElements() through a real SSE loop, with a fake
 * response and no running server.
 *
 * argv[1] picks the case:
 *   wire     the SSE frames patchElements() produces, and a datastar-php mode queued by hand
 *   backlog  a client whose send queue is over the threshold: which element patches the loop drops
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE');
unset($_ENV['VIA_TEST_MODE'], $_SERVER['VIA_TEST_MODE']);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Timer;
use starfederation\datastar\enums\ElementPatchMode;
use Tests\Support\FakeActionRequest;
use Tests\Support\FixturePort;

final class CollectingResponse extends Response {
    public string $body = '';

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        return true;
    }

    public function write(string $data): bool {
        $this->body .= $data;

        return true;
    }

    public function isWritable(): bool {
        return true;
    }

    public function end(mixed $data = null): bool {
        return true;
    }
}

/** Never started: it only reports every connection as alive and far behind on reading. */
final class BackloggedServer extends Server {
    public function exists(int $fd): bool {
        return true;
    }

    public function getClientInfo(int $fd, int $reactorId = -1, bool $noCheckConnection = false): array|false {
        return ['send_queued_bytes' => PHP_INT_MAX];
    }
}

final class BackloggedVia extends Via {
    public function __construct(Config $config, private Server $backlogged) {
        parent::__construct($config);
    }

    public function getServer(): Server {
        return $this->backlogged;
    }
}

$case = (string) ($argv[1] ?? 'wire');
$config = (new Config())->withLogLevel('error')->withSseMaxQueuedBytes(1024);
$app = $case === 'backlog'
    ? new BackloggedVia($config, new BackloggedServer('127.0.0.1', FixturePort::pick(4600, 150)))
    : new Via($config);

Coroutine::run(static function () use ($app, $case): void {
    $context = new Context('tab', '/p', $app);
    $context->view(static fn (): string => '<main id="page">page</main>');
    $app->contexts['tab'] = $context;
    $app->getApp()->registerContext($context);

    $request = new FakeActionRequest('unused', ['via_ctx' => 'tab']);
    $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET', 'remote_addr' => '127.0.0.1'];
    $response = new CollectingResponse();
    $response->fd = 7;

    try {
        Coroutine::create(static fn () => (new SseHandler($app))->handleSSE($request, $response));
        Coroutine::usleep(30_000);

        $context->patchElements('<li>chunk 1</li>', '#log', PatchMode::Append);
        $context->patchElements('<p>modal</p>', '#modal', PatchMode::Inner);
        $context->patchElements(selector: '#toast', mode: PatchMode::Remove);
        $context->patchElements('<li>chunk 2</li>', '#log', PatchMode::Prepend);
        $context->getPatchManager()->queuePatch(['type' => 'elements', 'content' => '<li>chunk 3</li>', 'selector' => '#log', 'mode' => ElementPatchMode::After]);
        Coroutine::usleep(50_000);

        $frames = array_values(array_filter(
            explode("\n\n", $response->body),
            static fn (string $frame): bool => str_contains($frame, 'datastar-patch-elements') && !str_contains($frame, 'page</main>'),
        ));
        echo 'frames=', count($frames), "\n";
        foreach ($frames as $i => $frame) {
            echo "frame{$i}=", str_replace("\n", '|', preg_replace('/^(event|id|retry): .*\n/m', '', $frame)), "\n";
        }
        echo 'page_sent=', (int) str_contains($response->body, 'page</main>'), "\n";
    } catch (Throwable $e) {
        echo 'fixture_error=', $e->getMessage(), "\n";
    } finally {
        $context->getPatchManager()->closePatchChannel();
        Timer::clearAll();
    }
});
