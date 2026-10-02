<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\ServerRequest;
use PhpVia\Website\Pairing\PairingDemo;
use PhpVia\Website\Pairing\PairingStore;
use PhpVia\Website\Pairing\RequestOrigin;

/*
 * The phone-pairing demo of the website's homepage: a component gives each tab a code, the page at
 * /pair/{code} joins the same "pair:<code>" scope, and a swatch picked on either side reaches the
 * other through a broadcast.
 */

$pairingAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$pairingReady = false;

if (is_file($pairingAutoload)) {
    require_once $pairingAutoload;
    $pairingReady = class_exists(PairingDemo::class);
}

if (!$pairingReady) {
    test('website pairing demo (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

/**
 * A Via with the website templates, the pairing routes and a stand-in homepage that mounts the
 * component the way app.php does.
 *
 * @return array{Via, PairingDemo}
 */
function pairingApp(PairingStore $store): array {
    $app = createVia((new Config())->withTemplateDir(dirname(__DIR__, 2) . '/website/templates'));
    foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
        $app->getTwig()->addGlobal($name, $value);
    }

    $demo = new PairingDemo($app, $store, 'https://example.test');
    $demo->register();
    $app->page('/', function (Context $c) use ($demo): void {
        $c->scope(Scope::routeScope('/'));
        $pairing = $c->component($demo->component(...), 'pairing');
        // Like StaticPage::view(): updates render nothing, and the component patches itself.
        $c->view(fn (bool $isUpdate): string => $isUpdate ? '' : '<main id="home">' . $pairing() . '</main>', cacheUpdates: false);
    });

    return [$app, $demo];
}

/**
 * Mint a page the way RequestHandler does and return it with its first HTML.
 *
 * @param array<string, string> $params
 * @param array<string, mixed>  $attributes
 *
 * @return array{Context, string}
 */
function pairingOpen(Via $app, string $route, string $id, array $params = [], array $attributes = []): array {
    $session = 'sess_' . md5($id);
    $ctx = new Context($id, $route, $app, null, $session);
    $ctx->injectRouteParams($params);
    if ($attributes !== []) {
        $ctx->setRequestAttributes($attributes);
    }
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()[$route], $ctx, $params);
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($id, $session);
    $app->registerContextInScope($ctx, Scope::TAB);

    return [$ctx, $app->buildHtmlDocument($ctx)];
}

function pairingComponent(Context $page): Context {
    return array_values($page->getComponentRegistry())[0];
}

function pairingCode(Context $page): string {
    return substr(pairingComponent($page)->getPrimaryScope(), strlen('pair:'));
}

/** Click a swatch: what ActionHandler does for POST /_action/<id>?colour=<colour> */
function pairingPick(Context $page, string $actionId, string $colour): void {
    $page->setRequestInput(['colour' => $colour], []);
    $page->executeAction($actionId);
}

/**
 * Take every queued patch and return the HTML of the element patches.
 *
 * @return list<string>
 */
function pairingFrames(Context $page): array {
    $frames = [];
    while (($patch = $page->getPatch()) !== null) {
        if ($patch['type'] === 'elements') {
            $frames[] = (string) $patch['content'];
        }
    }

    return $frames;
}

function pairingShownColour(string $html): ?string {
    return preg_match('/px-pairw-screen px-colour--([a-z]+)/', $html, $m) === 1 ? $m[1] : null;
}

describe('PairingStore', function (): void {
    test('codes are unique, URL-safe and carry at least 40 bits', function (): void {
        $store = new PairingStore();
        $codes = [];
        for ($i = 0; $i < 3000; ++$i) {
            $codes[] = $store->create('https://example.test');
        }

        expect(array_unique($codes))->toHaveCount(3000)
            ->and(PairingStore::CODE_LENGTH)->toBeGreaterThanOrEqual(8)
            // 32 symbols, 5 bits each
            ->and(PairingStore::CODE_LENGTH * 5)->toBeGreaterThanOrEqual(40)
            ->and(count(array_unique(str_split(implode('', $codes)))))->toBe(32)
        ;
        foreach ($codes as $code) {
            expect(strlen($code))->toBe(PairingStore::CODE_LENGTH)
                ->and(rawurlencode($code))->toBe($code)
                ->and(PairingStore::isWellFormed($code))->toBeTrue()
            ;
        }
    });

    test('a pair idle for 30 minutes is pruned unless a tab still shows it', function (): void {
        $now = 1_000_000;
        $store = new PairingStore(function () use (&$now): int {
            return $now;
        });
        $idle = $store->create('https://example.test');
        $busy = $store->create('https://example.test');
        $shown = $store->codeForTab('tab-1', 'https://example.test');

        $now += 1000;
        $store->setColour($busy, 'green');
        $now += PairingStore::IDLE_SECONDS - 999;

        // $idle and $shown are 1801 s old, $busy 801 s; only $shown is still on a screen.
        expect($store->prune(fn (string $code): bool => $code === $shown))->toBe(1)
            ->and($store->has($idle))->toBeFalse()
            ->and($store->has($busy))->toBeTrue()
            ->and($store->has($shown))->toBeTrue()
            ->and($store->codeForTab('tab-1', 'https://example.test'))->toBe($shown)
        ;

        $now += PairingStore::IDLE_SECONDS + 1;
        expect($store->prune())->toBe(2)
            ->and($store->count())->toBe(0)
            ->and($store->colour($busy))->toBeNull()
            ->and($store->setColour($busy, 'orange'))->toBeFalse()
            // The tab's pair is gone, so the tab gets a new code.
            ->and($store->codeForTab('tab-1', 'https://example.test'))->not->toBe($shown)
        ;
    });

    test('the store drops the oldest pairs past its cap', function (): void {
        $store = new PairingStore();
        $first = $store->create('https://example.test');
        for ($i = 0; $i < PairingStore::MAX_PAIRS; ++$i) {
            $store->create('https://example.test');
        }

        expect($store->count())->toBe(PairingStore::MAX_PAIRS)
            ->and($store->has($first))->toBeFalse()
        ;
    });

    test('only the five swatches are accepted as a colour', function (): void {
        $store = new PairingStore();
        $code = $store->create('https://example.test');

        expect($store->setColour($code, 'red'))->toBeFalse()
            ->and($store->setColour($code, 'violet'))->toBeTrue()
            ->and($store->colour($code))->toBe('violet')
        ;
    });
});

describe('Pairing a homepage tab with the phone page', function (): void {
    test('the homepage shows a link and a QR code element for the URL of its own code', function (): void {
        [$app] = pairingApp(new PairingStore());
        [$desk, $html] = pairingOpen($app, '/', '/_/desk1', attributes: [RequestOrigin::ATTRIBUTE => 'http://192.168.1.20:3000']);
        $code = pairingCode($desk);
        $url = 'http://192.168.1.20:3000/pair/' . $code;

        expect(PairingStore::isWellFormed($code))->toBeTrue()
            ->and($html)->toContain('<code id="px-pair-url">' . $url . '</code>')
            ->and($html)->toContain('href="' . $url . '"')
            // The browser draws the code (public/js/px-qr.js); the server sends only the URL.
            ->and($html)->toContain('<div class="px-qr" role="img" aria-label="QR code for ' . $url . '"><px-qr value="' . $url . '"></px-qr></div>')
            ->and($html)->not->toContain('<svg')
            ->and(pairingShownColour($html))->toBe(PairingStore::DEFAULT_COLOUR)
        ;
    });

    test('a colour picked on the phone is what the desktop tab renders after the broadcast', function (): void {
        [$app] = pairingApp(new PairingStore());
        [$desk] = pairingOpen($app, '/', '/_/desk1');
        [$other] = pairingOpen($app, '/', '/_/desk2');
        $code = pairingCode($desk);
        [$phone, $phoneHtml] = pairingOpen($app, PairingDemo::ROUTE, '/pair/{code}_/phone1', ['code' => $code]);
        pairingFrames($desk);
        pairingFrames($other);

        expect($phone->getPrimaryScope())->toBe('pair:' . $code)
            ->and(pairingShownColour($phoneHtml))->toBe('blue')
        ;

        pairingPick($phone, $phone->getAction('pick')->id(), 'orange');

        $deskFrames = pairingFrames($desk);
        $phoneFrames = pairingFrames($phone);
        expect($deskFrames)->toHaveCount(1)
            ->and(pairingShownColour($deskFrames[0]))->toBe('orange')
            // The same value, so the morph leaves the drawn code alone
            ->and($deskFrames[0])->toContain('<px-qr value="https://example.test/pair/' . $code . '"></px-qr>')
            ->and($deskFrames[0])->toMatch('/aria-pressed="true"\s+data-on:click="@post\(\'\/_action\/pairing-pick\?colour=orange\'\)"/')
            ->and($phoneFrames)->toHaveCount(1)
            ->and($phoneFrames[0])->toStartWith('<div id="pair-widget"')
            ->and(pairingShownColour($phoneFrames[0]))->toBe('orange')
            // A tab with another code hears nothing.
            ->and(pairingFrames($other))->toBe([])
        ;
    });

    test('a colour picked on the desktop tab reaches the phone', function (): void {
        [$app] = pairingApp(new PairingStore());
        [$desk] = pairingOpen($app, '/', '/_/desk1');
        $code = pairingCode($desk);
        [$phone] = pairingOpen($app, PairingDemo::ROUTE, '/pair/{code}_/phone1', ['code' => $code]);
        pairingFrames($phone);

        // The page finds the component's TAB action, as it does for POST /_action/pairing-pick.
        pairingPick($desk, 'pairing-pick', 'green');

        $phoneFrames = pairingFrames($phone);
        expect($phoneFrames)->toHaveCount(1)
            ->and(pairingShownColour($phoneFrames[0]))->toBe('green')
        ;
    });

    test('the panel is sent again only when its colour changed', function (): void {
        [$app] = pairingApp(new PairingStore());
        [$desk] = pairingOpen($app, '/', '/_/desk1');
        pairingFrames($desk);

        // The SSE connect and a broadcast to the homepage route, such as a poll vote
        $desk->sync();
        $app->broadcast(Scope::routeScope('/'));
        expect(pairingFrames($desk))->toBe([]);

        pairingPick($desk, 'pairing-pick', 'violet');
        $frames = pairingFrames($desk);
        expect($frames)->toHaveCount(1)
            ->and(pairingShownColour($frames[0]))->toBe('violet')
        ;

        // Picking the colour the pair already has broadcasts nothing.
        pairingPick($desk, 'pairing-pick', 'violet');
        $app->broadcast(Scope::routeScope('/'));
        expect(pairingFrames($desk))->toBe([]);
    });

    test('a colour that is not a swatch changes nothing and broadcasts nothing', function (): void {
        [$app] = pairingApp(new PairingStore());
        [$desk] = pairingOpen($app, '/', '/_/desk1');
        [$phone] = pairingOpen($app, PairingDemo::ROUTE, '/pair/{code}_/phone1', ['code' => pairingCode($desk)]);
        pairingFrames($desk);

        pairingPick($phone, 'pick', 'red');

        expect(pairingFrames($desk))->toBe([]);
    });

    test('an unknown or pruned code shows a message and joins no pair', function (): void {
        $now = 1_000_000;
        $store = new PairingStore(function () use (&$now): int {
            return $now;
        });
        [$app] = pairingApp($store);
        $code = $store->create('https://example.test');
        $now += PairingStore::IDLE_SECONDS + 1;
        $store->prune();

        foreach ([$code, 'zzzzzzzzzz', 'not-a-code'] as $i => $param) {
            [$phone, $html] = pairingOpen($app, PairingDemo::ROUTE, '/pair/{code}_/gone' . $i, ['code' => $param]);

            expect($html)->toContain('This pairing code is not active')
                ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">')
                ->and($phone->getScopes())->toBe([Scope::TAB])
            ;
        }
    });

    test('an open phone page whose pair was dropped says so on its next render', function (): void {
        $now = 1_000_000;
        $store = new PairingStore(function () use (&$now): int {
            return $now;
        });
        [$app] = pairingApp($store);
        $code = $store->create('https://example.test');
        [$phone] = pairingOpen($app, PairingDemo::ROUTE, '/pair/{code}_/phone1', ['code' => $code]);
        pairingFrames($phone);

        $now += PairingStore::IDLE_SECONDS + 1;
        $store->prune();
        pairingPick($phone, 'pick', 'green');
        expect(pairingFrames($phone))->toBe([]);

        $phone->sync();
        expect(implode('', pairingFrames($phone)))->toContain('This pairing has ended.');
    });

    test('a revived homepage tab keeps its code, its link and a working swatch', function (): void {
        [$app] = pairingApp(new PairingStore());
        $id = '/_/desk1';
        [$desk] = pairingOpen($app, '/', $id, attributes: [RequestOrigin::ATTRIBUTE => 'http://192.168.1.20:3000']);
        $code = pairingCode($desk);
        [$phone] = pairingOpen($app, PairingDemo::ROUTE, '/pair/{code}_/phone1', ['code' => $code]);

        $app->getApp()->destroyContext($id);
        unset($app->contexts[$id]);
        $revived = $app->reviveContextFromClient($id, 'sess_' . md5($id), ['via_ctx' => $id], byConnect: true);

        expect($revived)->not->toBeNull()
            ->and(pairingCode($revived))->toBe($code)
            ->and($app->buildHtmlDocument($revived))->toContain('<px-qr value="http://192.168.1.20:3000/pair/' . $code . '"></px-qr>')
        ;

        pairingFrames($revived);
        pairingPick($phone, 'pick', 'yellow');
        $frames = pairingFrames($revived);

        expect($frames)->toHaveCount(1)
            ->and(pairingShownColour($frames[0]))->toBe('yellow')
        ;
    });
    test('a revived tab whose pair is gone gets a new code, and the connect sends its URL to the QR element', function (): void {
        $now = 1_000_000;
        $store = new PairingStore(function () use (&$now): int {
            return $now;
        });
        [$app] = pairingApp($store);
        $id = '/_/desk1';
        [$desk] = pairingOpen($app, '/', $id);
        $code = pairingCode($desk);

        $app->getApp()->destroyContext($id);
        unset($app->contexts[$id]);
        $now += PairingStore::IDLE_SECONDS + 1;
        $store->prune();
        $revived = $app->reviveContextFromClient($id, 'sess_' . md5($id), ['via_ctx' => $id], byConnect: true);
        $newCode = pairingCode($revived);

        // What the SSE connect does: the panel is new to this component, so it is sent with the new URL.
        pairingFrames($revived);
        $revived->sync();

        expect($newCode)->not->toBe($code)
            ->and(implode('', pairingFrames($revived)))->toContain('<px-qr value="https://example.test/pair/' . $newCode . '"></px-qr>')
        ;
    });
});

describe('RequestOrigin', function (): void {
    test('it prefers the forwarded scheme and host, and falls back for a host it cannot use', function (): void {
        $origin = new RequestOrigin('https://via.zweiundeins.gmbh', false);
        $request = static fn (array $headers): ServerRequest => new ServerRequest('GET', '/', $headers);

        expect($origin->origin($request(['Host' => '127.0.0.1:3000'])))->toBe('http://127.0.0.1:3000')
            ->and($origin->origin($request(['Host' => 'localhost', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'via.zweiundeins.gmbh, proxy.internal'])))->toBe('https://via.zweiundeins.gmbh')
            ->and($origin->origin($request(['Host' => '[::1]:3000'])))->toBe('http://[::1]:3000')
            ->and($origin->origin($request(['Host' => 'evil.test"><script>'])))->toBe('https://via.zweiundeins.gmbh')
            ->and($origin->origin($request(['Host' => 'via.test', 'X-Forwarded-Proto' => 'javascript'])))->toBe('http://via.test')
            ->and((new RequestOrigin('https://x.test', true))->origin($request(['Host' => 'dev.test'])))->toBe('https://dev.test')
        ;
    });
});
