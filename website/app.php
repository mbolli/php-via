<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use PhpVia\Website\Pairing\PairingDemo;
use PhpVia\Website\Pairing\PairingStore;
use PhpVia\Website\Pairing\RequestOrigin;
use PhpVia\Website\PresenceDemo;
use PhpVia\Website\StarbaseComponents;
use PhpVia\Website\StaticPage;
use PhpVia\Website\SyntaxHighlightExtension;
use PhpVia\Website\Twig\CodeRuntime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Tuupola\Middleware\CorsMiddleware;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

// ─── Configuration ──────────────────────────────────────────────────────────

$isDev = getenv('APP_ENV') === 'dev';
$corsOrigin = getenv('CORS_ORIGIN') ?: '*';

// One worker, the default: several examples and the homepage's PairingStore keep their state per process.
$config = (new Config())
    ->withHost('0.0.0.0')
    ->withPort((int) (getenv('VIA_PORT') ?: 3000))
    ->withDevMode($isDev)
    ->withTemplateDir(__DIR__ . '/templates')
    ->withTwigCacheDir(sys_get_temp_dir() . '/php-via-twig-cache')
    ->withStaticDir(__DIR__ . '/public')
    ->withLogLevel($isDev ? 'debug' : 'info')
    // Force the Via Dev Bar on even in production so the live site demos it.
    // Signal editing stays hard-disabled here (devMode is off in prod); it is
    // re-enabled below for local dev only.
    ->withDevBar(true)
    // The site has no CPU-bound loops that make cycles, so it takes the growth-based collector.
    ->withGcIntervalMs(30_000, onGrowth: true)

    // GlobalState lives in shared memory, which dies with the process. The poll tallies
    // and the ROUTE-scope demo counter are visitor-contributed, so without this every
    // deploy silently resets them to zero. Reads stay in memory; a leader-worker timer
    // batches the dirty keys into SQLite once a second.
    // Sibling of chat.db/spreadsheet.db, and covered by the same website/*.db gitignore.
    ->withPersistentGlobalState(__DIR__ . '/state.db')

    // Every demo action here is an unauthenticated POST. Many re-render every viewer of their
    // page (the poll, the shared counter, Game of Life), and All Scopes' status button and the
    // Composition demo's GLOBAL signals reach every connected client, so one IP can amplify.
    // The ceiling has to clear the per-keystroke examples though: Type Race and the
    // spreadsheet fire an action per key, so a fast typist alone sustains ~5/s and several
    // people behind one NAT multiply that. 1200/60s = 20/s per IP leaves them untouched while
    // still capping a flood.
    ->withActionRateLimit((int) (getenv('VIA_ACTION_RATE_LIMIT') ?: 1200), 60)

    // The leaderboard animates each update in a view transition of about 400 ms, and a new one
    // skips the one still running. One render per 400 ms lets each transition finish.
    ->withBroadcastThrottle(Scope::routeScope('/examples/leaderboard'), 400)
;

// The Datastar + Rocket build, and the Starbase components the site copies into public/vendor/starbase
StarbaseComponents::register($config->withDatastarRocket());
$config->withStaticCacheControl(StarbaseComponents::cacheControl(...));

if (!$isDev) {
    // Production hardening: Secure cookie flag (HTTPS) + explicit trusted origins.
    // CORS_ORIGIN is set by the deployment env (e.g. "https://via.zweiundeins.gmbh").
    // When it is a concrete origin (not the wildcard default) use it as the action
    // origin allowlist so cross-site action requests are blocked at the framework level.
    $config->withSecureCookie(true);
    if ($corsOrigin !== '*') {
        $config->withTrustedOrigins([$corsOrigin]);
    }
}

if ($isDev) {
    // Local dev only: let the Dev Bar's Signals panel write values back.
    $config->withDevBarOptions(writes: true);

    // Dev: self-signed cert for direct HTTPS/HTTP2 (no Caddy needed).
    // Skip SSL when VIA_DISABLE_HTTPS is set so the benchmark hammer can connect via plain HTTP.
    if (!getenv('VIA_DISABLE_HTTPS')) {
        $certFile = __DIR__ . '/../certs/dev.crt';
        $keyFile = __DIR__ . '/../certs/dev.key';
        if (file_exists($certFile) && file_exists($keyFile)) {
            $config->withCertificate($certFile, $keyFile)->withBrotli();
        }
    }
} else {
    // Prod: Caddy terminates TLS, php-via speaks h2c and handles Brotli
    $config->withH2c()->withBrotli();
}

$app = new Via($config);

// ─── Middleware ──────────────────────────────────────────────────────────────

$corsLogger = $config->isDevMode()
    ? new class($app) extends AbstractLogger {
        public function __construct(private Via $app) {}

        public function log($level, string|Stringable $message, array $context = []): void {
            // Route through the framework logger so it also lands in the Dev Bar Logs panel.
            $this->app->log('debug', '[CORS] ' . $message);
        }
    }
: null;

$app->middleware(new CorsMiddleware([
    'origin' => [$corsOrigin],
    'methods' => ['GET', 'POST'],
    'headers.allow' => ['Content-Type', 'Authorization'],
    // Credentials only for a concrete origin: with '*' Tuupola reflects any Origin.
    'credentials' => $corsOrigin !== '*',
    'cache' => 3600,
    'origin.server' => !str_contains($corsOrigin, '*') ? $corsOrigin : null,
    'logger' => $corsLogger,
    'error' => function (ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface {
        $body = json_encode([
            'error' => 'CORS',
            'message' => $arguments['message'],
        ], JSON_UNESCAPED_SLASHES);

        $response = $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json')
        ;

        $response->getBody()->write($body);

        return $response;
    },
]));
$app->getTwig()->addExtension(new SyntaxHighlightExtension());
$app->getTwig()->addRuntimeLoader(new FactoryRuntimeLoader([
    CodeRuntime::class => fn () => new CodeRuntime(),
]));
$twig = $app->getTwig();

// Asset cache-busting version (based on CSS file mtime)
$cssPath = __DIR__ . '/public/css/site.css';
$twig->addGlobal('assetVersion', (string) (file_exists($cssPath) ? filemtime($cssPath) : time()));
$workerPath = __DIR__ . '/public/upload-worker.js';
$twig->addGlobal('workerVersion', (string) (file_exists($workerPath) ? filemtime($workerPath) : time()));
$siteOrigin = 'https://via.zweiundeins.gmbh';
$twig->addGlobal('siteUrl', $siteOrigin . '/');

// ─── 404 handler ─────────────────────────────────────────────────────────────

// No context renders this page, so it goes through Twig directly.
$app->notFound(function ($request, $response) use ($twig): void {
    $html = $twig->render('pages/404.html.twig', [
        'basePath' => '/',
        'requestedPath' => $request->server['request_uri'] ?? '/',
    ]);
    $response->status(404);
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end($html);
});

// ─── Presence ────────────────────────────────────────────────────────────────

$presenceDemo = new PresenceDemo($app);
$presenceDemo->register();

// ─── Demo components ─────────────────────────────────────────────────────────

// A custom scope for the busiest widget, like PresenceDemo::SCOPE. A broadcast to the page's own
// scope re-renders every component on the page, so a click here reaches only this widget.
const COUNTER_SCOPE = 'home:counter';

/**
 * Shared multiplayer counter: all visitors share one counter.
 * The "aha" moment: click and everyone sees it.
 */
$sharedCounterDemo = function (Context $c) use ($app): void {
    $c->scope(COUNTER_SCOPE);

    // GlobalState keeps the count once the homepage is empty, when the scope's signals go.
    $counter = $c->signal($app->globalState('home_counter') ?? 0, 'counter', COUNTER_SCOPE);
    $lastClick = $c->signal('', 'lastClick', COUNTER_SCOPE);
    $lastClickHue = $c->signal(0, 'lastClickHue', COUNTER_SCOPE);

    // Each write broadcasts COUNTER_SCOPE, and the three land in one render.
    $c->action(function (Context $c) use ($app, $counter, $lastClick, $lastClickHue): void {
        // Atomic, so two workers that take a click at the same moment do not drop one.
        $counter->setValue($app->incrementGlobalState('home_counter'));

        // $c is this component; the visitor is the page it sits on.
        $visitorNum = substr($c->getPageContext()->getId(), -4);
        $lastClick->setValue('Visitor #' . strtoupper($visitorNum));
        $lastClickHue->setValue(hexdec($visitorNum) % 360);
    }, 'increment');

    // The template gets the signals and the action by name.
    $c->view('components/shared-counter.html.twig', shareRender: true);
};

/**
 * Code + live result demo: syntax-highlighted PHP on the left, working counter on the right.
 */
$codeResultDemo = function (Context $c): void {
    $count = $c->signal(0, 'count');
    $c->action(function () use ($count): void {
        $count->setValue($count->int() + 1);
    }, 'increment');

    $c->view('components/code-result.html.twig');
};

/**
 * Session counter for the home page demo section (demo box only, no code panel).
 * TAB-scoped so each visitor sees their own private counter.
 */
$homeSessionDemo = function (Context $c): void {
    $count = $c->signal(0, 'count');
    $c->action(function () use ($count): void {
        $count->setValue($count->int() + 1);
    }, 'increment');

    $c->view('components/session-counter-demo.html.twig');
};

/**
 * Scope comparison: TAB-scoped vs ROUTE-scoped side by side.
 * The TAB counter is independent per visitor; the ROUTE counter is shared.
 * These are TWO separate components so a TAB sync never triggers a route broadcast.
 */
$tabScopeDemo = function (Context $c): void {
    // TAB-scoped (default): each visitor has their own isolated counter
    $tabCount = $c->signal(0, 'tabCount');
    $incTab = $c->action(function () use ($tabCount): void {
        $tabCount->setValue($tabCount->int() + 1);
    }, 'incTab');

    $c->view(fn (): string => <<<HTML
        <div class="scope-card tab-scoped">
            <div class="scope-label">TAB scope</div>
            <div class="scope-count" data-text="{$tabCount->ref()}">{$tabCount->int()}</div>
            <div class="scope-description">Only you see this counter.</div>
            <button class="btn btn-secondary" style="width: 100%"
                    data-on:click="@post('{$incTab->url()}')">+1 (just me)</button>
        </div>
        HTML);
};

$routeScopeDemo = function (Context $c) use ($app): void {
    // ROUTE-scoped: shared counter for all visitors on the same route
    $c->scope(Scope::ROUTE);
    // One counter of record per page, as the ROUTE signal is one per page.
    $key = 'scope_demo_count:' . $c->getPageContext()->getRoute();
    $routeCount = $c->signal($app->globalState($key) ?? 0, 'routeCount', Scope::ROUTE);
    $incRoute = $c->action(function () use ($app, $routeCount, $key): void {
        // GlobalState is the counter of record: it is what survives a restart, and it
        // reseeds the signal above on first mount. Both stores are advanced atomically, so
        // neither drops a click when two workers handle one at the same moment.
        $routeCount->setValue($app->incrementGlobalState($key));
    }, 'incRoute');

    $c->view(fn (): string => <<<HTML
        <div class="scope-card route-scoped">
            <div class="scope-label">ROUTE scope</div>
            <div class="scope-count" data-text="{$routeCount->ref()}">{$routeCount->int()}</div>
            <div class="scope-description">Everyone on this page shares this.</div>
            <button class="btn btn-secondary" style="width: 100%; border-color: var(--violet-6);"
                    data-on:click="@post('{$incRoute->url()}')">+1 (everyone)</button>
        </div>
        HTML, shareRender: true);
};

/**
 * Live poll: vote on "Favorite scope?" (bars shift in real-time for everyone).
 */
$livePollDemo = function (Context $c) use ($app): void {
    $c->scope(Scope::ROUTE);

    $vote = $c->action(function (Context $c) use ($app): void {
        $raw = $c->input('option');
        if (!in_array($raw, ['tab', 'route', 'session', 'global'], true)) {
            return;
        }
        // Atomic, so two workers voting at once count two votes. The tallies are GlobalState,
        // not signals, so the vote broadcasts the poll's scope itself.
        $app->incrementGlobalState('poll_' . $raw);
        $c->broadcast();
    }, 'vote');

    $c->view('components/live-poll.html.twig', function () use ($app, $vote): array {
        $defs = [
            'tab' => ['TAB', 'var(--blue-6)'],
            'route' => ['ROUTE', 'var(--violet-6)'],
            'session' => ['SESSION', 'var(--green-6)'],
            'global' => ['GLOBAL', 'var(--orange-5)'],
        ];
        $counts = [];
        foreach (array_keys($defs) as $key) {
            $counts[$key] = (int) ($app->globalState('poll_' . $key) ?? 0);
        }
        $total = max(1, array_sum($counts));

        $options = [];
        foreach ($defs as $key => [$label, $color]) {
            $options[] = [
                'key' => $key,
                'label' => $label,
                'color' => $color,
                'count' => $counts[$key],
                'pct' => round($counts[$key] / $total * 100),
                'url' => $vote->url() . '?option=' . $key,
            ];
        }

        return ['options' => $options];
    }, shareRender: true);
};

// ─── Routes ───────────────────────────────────────────────────────────────────

// Phone pairing in the hero: a code per homepage tab, and the phone page at /pair/{code}
$pairingDemo = new PairingDemo($app, new PairingStore(), $siteOrigin);

// Home page
$app->page('/', function (Context $c) use ($presenceDemo, $sharedCounterDemo, $homeSessionDemo, $livePollDemo, $pairingDemo): void {
    $presence = $c->component($presenceDemo->component(...), 'presence');
    $sharedCounter = $c->component($sharedCounterDemo, 'shared-counter');
    $sessionCounter = $c->component($homeSessionDemo, 'session-counter');
    $poll = $c->component($livePollDemo, 'poll');
    $pairing = $c->component($pairingDemo->component(...), 'pairing');

    // Components patch their own target divs, so updates leave the page itself alone.
    StaticPage::view($c, 'pages/home.html.twig', fn (): array => [
        'presence' => $presence(),
        'sharedCounter' => $sharedCounter(),
        'sessionCounter' => $sessionCounter(),
        'poll' => $poll(),
        'pairing' => $pairing(),
    ]);
})->middleware(new RequestOrigin($siteOrigin, $config->isHttps()));

// ─── Docs routes ─────────────────────────────────────────────────────────────

$app->group('/docs', function (Via $app) use ($codeResultDemo, $tabScopeDemo, $routeScopeDemo): void {
    // Landing
    $app->page('/', function (Context $c): void {
        StaticPage::view($c, 'docs/index.html.twig');
    });

    // Getting started (tutorial with embedded demo)
    $app->page('/getting-started', function (Context $c) use ($codeResultDemo): void {
        $demo = $c->component($codeResultDemo, 'gs-demo');

        StaticPage::view($c, 'docs/getting-started.html.twig', fn (): array => ['demo' => $demo()]);
    });

    // Signals concept page
    $app->page('/signals', function (Context $c) use ($tabScopeDemo, $routeScopeDemo): void {
        $tabDemo = $c->component($tabScopeDemo, 'scope-tab');
        $routeDemo = $c->component($routeScopeDemo, 'scope-route');

        StaticPage::view($c, 'docs/signals.html.twig', fn (): array => [
            'tabDemo' => $tabDemo(),
            'routeDemo' => $routeDemo(),
        ]);
    });

    // Scopes concept page
    $app->page('/scopes', function (Context $c) use ($tabScopeDemo, $routeScopeDemo): void {
        $tabDemo = $c->component($tabScopeDemo, 'scope-tab');
        $routeDemo = $c->component($routeScopeDemo, 'scope-route');

        StaticPage::view($c, 'docs/scopes.html.twig', fn (): array => [
            'tabDemo' => $tabDemo(),
            'routeDemo' => $routeDemo(),
        ]);
    });

    $app->page('/upgrading', function (Context $c): void {
        StaticPage::view($c, 'docs/upgrading.html.twig');
    });

    $app->page('/actions', function (Context $c): void {
        StaticPage::view($c, 'docs/actions.html.twig');
    });

    $app->page('/views', function (Context $c): void {
        StaticPage::view($c, 'docs/views.html.twig');
    });

    $app->page('/components', function (Context $c): void {
        StaticPage::view($c, 'docs/components.html.twig');
    });

    $app->page('/dev-bar', function (Context $c): void {
        StaticPage::view($c, 'docs/dev-bar.html.twig');
    });

    $app->page('/composition', function (Context $c): void {
        StaticPage::view($c, 'docs/composition.html.twig');
    });

    $app->page('/broadcasting', function (Context $c): void {
        StaticPage::view($c, 'docs/broadcasting.html.twig');
    });

    $app->page('/broker', function (Context $c): void {
        StaticPage::view($c, 'docs/broker.html.twig');
    });

    $app->page('/lifecycle', function (Context $c): void {
        StaticPage::view($c, 'docs/lifecycle.html.twig');
    });

    $app->page('/middleware', function (Context $c): void {
        StaticPage::view($c, 'docs/middleware.html.twig');
    });

    $app->page('/twig', function (Context $c): void {
        StaticPage::view($c, 'docs/twig.html.twig');
    });

    $app->page('/web-components', function (Context $c): void {
        StaticPage::view($c, 'docs/web-components.html.twig');
    });

    $app->page('/development', function (Context $c): void {
        StaticPage::view($c, 'docs/development.html.twig');
    });

    $app->page('/deployment', function (Context $c): void {
        StaticPage::view($c, 'docs/deployment.html.twig');
    });

    $app->page('/performance', function (Context $c): void {
        StaticPage::view($c, 'docs/performance.html.twig');
    });

    $app->page('/api', function (Context $c): void {
        StaticPage::view($c, 'docs/api.html.twig');
    });

    $app->page('/design', function (Context $c): void {
        StaticPage::view($c, 'docs/design.html.twig');
    });

    $app->page('/comparisons', function (Context $c): void {
        StaticPage::view($c, 'docs/comparisons.html.twig');
    });

    $app->page('/faq', function (Context $c): void {
        StaticPage::view($c, 'docs/faq.html.twig');
    });
});

// Examples intro
$app->page('/examples', function (Context $c): void {
    StaticPage::view($c, 'pages/examples-intro.html.twig');
});

// Phone side of the homepage pairing demo
$pairingDemo->register();

// Professional support / body-leasing page
$app->page('/support', function (Context $c): void {
    StaticPage::view($c, 'pages/support.html.twig');
});

// ─── Hot reload: load routes inside each worker ──────────────────────────────
//
// Including routes.php via onWorkerStart() means it runs AFTER the worker is
// forked from master. Master never loads Example classes directly, so each
// fresh worker autoloads them from disk, enabling USR1 hot reload. The sitemap
// is also regenerated there with the full route set. Routes registered in an
// onWorkerStart() callback reach the request handler.

$app->onWorkerStart(function () use ($app): void {
    require __DIR__ . '/routes.php';
});

// ─── Start ───────────────────────────────────────────────────────────────────

$startScheme = $config->isHttps() ? 'https' : 'http';
echo "⚡ php-via website running on {$startScheme}://0.0.0.0:" . (getenv('VIA_PORT') ?: 3000) . "\n";
$app->start();
