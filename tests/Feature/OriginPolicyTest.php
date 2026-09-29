<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\DevBar\DevBarController;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\OriginPolicy;
use OpenSwoole\Http\Request;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

// An explicit allowlist must not be laxer than none for a request without Origin, and the Dev Bar
// must apply the same rule as actions.

/**
 * @return array{0: FakeStaticResponse, 1: bool} the response and whether the action ran
 */
function postActionWithoutOrigin(Config $config): array {
    $via = createVia($config);
    $ctx = new Context('ctx-origin', '/origin', $via);
    $via->contexts['ctx-origin'] = $ctx;
    $ran = false;
    $action = $ctx->action(function () use (&$ran): void {
        $ran = true;
    }, 'save');

    $request = new FakeActionRequest($action->id(), ['via_ctx' => 'ctx-origin']);
    unset($request->header['origin']);
    $response = new FakeStaticResponse();

    ob_start();

    try {
        (new ActionHandler($via))->handleAction($request, $response, $action->id());
    } finally {
        ob_end_clean();
    }

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

        expect(OriginPolicy::allows($config, null, 'example.com'))->toBeFalse();
    });

    test('is denied in production without an allowlist', function (): void {
        expect(OriginPolicy::allows(new Config(), null, 'example.com'))->toBeFalse();
    });

    test('is allowed in production only after an explicit opt-in', function (): void {
        $config = (new Config())
            ->withTrustedOrigins(['https://example.com'])
            ->withAllowMissingOrigin()
        ;

        expect(OriginPolicy::allows($config, null, 'example.com'))->toBeTrue()
            ->and(OriginPolicy::allows($config, 'https://evil.example', 'example.com'))->toBeFalse()
            ->and(OriginPolicy::allows((new Config())->withAllowMissingOrigin(), null, 'example.com'))->toBeTrue()
        ;
    });

    test('is allowed in dev mode with or without an allowlist', function (): void {
        $dev = (new Config())->withDevMode(true);

        expect(OriginPolicy::allows($dev, null, 'localhost:3000'))->toBeTrue()
            ->and(OriginPolicy::allows($dev->withTrustedOrigins(['https://example.com']), null, 'localhost:3000'))->toBeTrue()
        ;
    });

    test('the opt-in defaults to off and can be turned off again', function (): void {
        expect((new Config())->getAllowMissingOrigin())->toBeFalse()
            ->and((new Config())->withAllowMissingOrigin()->withAllowMissingOrigin(false)->getAllowMissingOrigin())->toBeFalse()
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
