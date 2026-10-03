<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\DevBar\DevBarController;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\OriginPolicy;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

// An explicit allowlist must not be laxer than none for a request without Origin, and the Dev Bar
// must apply the same rule as actions.

/**
 * @return array{0: FakeStaticResponse, 1: bool, 2: string} the response, whether the action ran, the log output
 */
function postAction(Config $config, ?string $origin, int $times = 1): array {
    $via = new Via($config->withLogLevel('warn'));
    $ctx = new Context('ctx-origin', '/origin', $via);
    $via->contexts['ctx-origin'] = $ctx;
    $ran = false;
    $action = $ctx->action(function () use (&$ran): void {
        $ran = true;
    }, 'save');

    $handler = new ActionHandler($via);
    ob_start();

    try {
        for ($i = 0; $i < $times; ++$i) {
            $request = new FakeActionRequest($action->id(), ['via_ctx' => 'ctx-origin']);
            if ($origin === null) {
                unset($request->header['origin']);
            } else {
                $request->header['origin'] = $origin;
            }
            $response = new FakeStaticResponse();
            $handler->handleAction($request, $response, $action->id());
        }
    } finally {
        $out = (string) ob_get_clean();
    }

    return [$response, $ran, $out];
}

/**
 * @return array{0: FakeStaticResponse, 1: bool} the response and whether the action ran
 */
function postActionWithoutOrigin(Config $config): array {
    [$response, $ran] = postAction($config, null);

    return [$response, $ran];
}

function devBarWithoutOrigin(Config $config, string $path): FakeStaticResponse {
    $request = new Request();
    $request->server = ['request_uri' => $path, 'request_method' => 'POST'];
    $request->header = ['host' => 'example.com'];
    $response = new FakeStaticResponse();

    (new DevBarController(createVia($config)))->handle($path, $request, $response);

    return $response;
}

describe('OriginPolicy: absent Origin', function (): void {
    test('is denied in production even with an allowlist', function (): void {
        $config = (new Config())->withTrustedOrigins(['https://example.com']);

        expect(OriginPolicy::allows($config->freeze(), null, 'example.com'))->toBeFalse();
    });

    test('is denied in production without an allowlist', function (): void {
        expect(OriginPolicy::allows((new Config())->freeze(), null, 'example.com'))->toBeFalse();
    });

    test('is allowed in production only after an explicit opt-in', function (): void {
        $config = (new Config())
            ->withTrustedOrigins(['https://example.com'])
            ->withAllowMissingOrigin()
        ;

        expect(OriginPolicy::allows($config->freeze(), null, 'example.com'))->toBeTrue()
            ->and(OriginPolicy::allows($config->freeze(), 'https://evil.example', 'example.com'))->toBeFalse()
            ->and(OriginPolicy::allows((new Config())->withAllowMissingOrigin()->freeze(), null, 'example.com'))->toBeTrue()
        ;
    });

    test('is allowed in dev mode with or without an allowlist', function (): void {
        $dev = (new Config())->withDevMode(true);
        $devWithAllowlist = (new Config())->withDevMode(true)->withTrustedOrigins(['https://example.com']);

        expect(OriginPolicy::allows($dev->freeze(), null, 'localhost:3000'))->toBeTrue()
            ->and(OriginPolicy::allows($devWithAllowlist->freeze(), null, 'localhost:3000'))->toBeTrue()
        ;
    });

    test('the opt-in defaults to off and can be turned off again', function (): void {
        expect((new Config())->freeze()->allowMissingOrigin)->toBeFalse()
            ->and((new Config())->withAllowMissingOrigin()->withAllowMissingOrigin(false)->freeze()->allowMissingOrigin)->toBeFalse()
        ;
    });
});

describe('ActionHandler: absent Origin', function (): void {
    test('an allowlist in production answers 403 and does not run the action', function (): void {
        [$response, $ran] = postActionWithoutOrigin((new Config())->withTrustedOrigins(['https://example.com']));

        expect($response->statusCode)->toBe(403)
            ->and($ran)->toBeFalse()
        ;
    });

    test('withAllowMissingOrigin() lets the action run', function (): void {
        [$response, $ran] = postActionWithoutOrigin(
            (new Config())->withTrustedOrigins(['https://example.com'])->withAllowMissingOrigin()
        );

        expect($response->statusCode)->not->toBe(403)
            ->and($ran)->toBeTrue()
        ;
    });
});

describe('ActionHandler: present Origin', function (): void {
    test('a cross-origin POST answers 403 and does not run the action', function (): void {
        [$response, $ran] = postAction(new Config(), 'https://evil.example');

        expect($response->statusCode)->toBe(403)
            ->and($response->body)->toContain('untrusted origin')
            ->and($ran)->toBeFalse()
        ;
    });

    test('a same-host POST runs the action', function (): void {
        [$response, $ran] = postAction(new Config(), 'http://localhost:3000');

        expect($response->statusCode)->not->toBe(403)
            ->and($ran)->toBeTrue()
        ;
    });

    test('an allowlisted Origin runs the action', function (): void {
        [$response, $ran] = postAction((new Config())->withTrustedOrigins(['https://app.example']), 'https://app.example');

        expect($response->statusCode)->not->toBe(403)
            ->and($ran)->toBeTrue()
        ;
    });
});

describe('ActionHandler: missing Origin diagnostics', function (): void {
    test('the denial says missing Origin and logs the opt-in once per handler', function (): void {
        [$response, , $out] = postAction((new Config())->withTrustedOrigins(['https://example.com']), null, times: 3);

        expect($response->statusCode)->toBe(403)
            ->and($response->body)->toContain('missing Origin')
            ->and(substr_count($out, 'withAllowMissingOrigin()'))->toBe(1)
            ->and($out)->toContain('[WARN]')
        ;
    });

    test('a cross-origin denial does not log the opt-in', function (): void {
        [, , $out] = postAction(new Config(), 'https://evil.example');

        expect($out)->not->toContain('withAllowMissingOrigin');
    });
});

describe('RequestHandler: /_session/close', function (): void {
    /**
     * @param array<string, string> $headers
     */
    function postSessionClose(array $headers): FakeStaticResponse {
        $via = createVia();
        $request = new class extends Request {
            public function rawContent(): false|string {
                return 'ctx-unknown';
            }
        };
        $request->server = ['request_uri' => '/_session/close', 'request_method' => 'POST', 'remote_addr' => '127.0.0.1'];
        $request->header = $headers;
        $request->cookie = [];
        $response = new FakeStaticResponse();

        (new RequestHandler($via, new SseHandler($via), new ActionHandler($via)))->handleRequest($request, $response);

        return $response;
    }

    test('a cross-origin beacon is denied', function (): void {
        $response = postSessionClose(['host' => 'example.com', 'origin' => 'https://evil.example']);

        expect($response->statusCode)->toBe(403);
    });

    test('a beacon without Origin is denied in production', function (): void {
        expect(postSessionClose(['host' => 'example.com'])->statusCode)->toBe(403);
    });

    test('a same-host beacon is accepted', function (): void {
        $response = postSessionClose(['host' => 'example.com', 'origin' => 'https://example.com']);

        expect($response->statusCode)->toBe(200);
    });
});

describe('DevBarController: absent Origin', function (): void {
    test('/_via/reset is denied in production without an allowlist', function (): void {
        expect(devBarWithoutOrigin(new Config(), '/_via/reset')->statusCode)->toBe(403);
    });

    test('/_via/reset is denied in production with an allowlist', function (): void {
        $config = (new Config())->withTrustedOrigins(['https://example.com']);

        expect(devBarWithoutOrigin($config, '/_via/reset')->statusCode)->toBe(403);
    });

    test('/_via/signal is denied in production without an allowlist', function (): void {
        expect(devBarWithoutOrigin(new Config(), '/_via/signal')->statusCode)->toBe(403);
    });

    test('/_via/reset is allowed in dev mode', function (): void {
        expect(devBarWithoutOrigin((new Config())->withDevMode(true), '/_via/reset')->statusCode)->toBe(200);
    });

    test('a same-host Origin without a Host header is denied in production', function (): void {
        $request = new Request();
        $request->server = ['request_uri' => '/_via/reset', 'request_method' => 'POST'];
        $request->header = ['origin' => 'https://example.com'];
        $response = new FakeStaticResponse();

        (new DevBarController(createVia()))->handle('/_via/reset', $request, $response);

        expect($response->statusCode)->toBe(403);
    });
});
