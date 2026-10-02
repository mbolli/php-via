<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;
use PhpVia\Website\StaticPage;
use PhpVia\Website\SyntaxHighlightExtension;
use PhpVia\Website\Twig\CodeRuntime;
use Psr\Log\AbstractLogger;
use Tuupola\Middleware\CorsMiddleware;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

// ─── Configuration ──────────────────────────────────────────────────────────

$isDev = getenv('APP_ENV') === 'dev';
$corsOrigin = getenv('CORS_ORIGIN') ?: '*';

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
    ->withTracing(true)

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
;

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
    $config->withTracingWrites(true);

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

$corsLogger = $config->getDevMode()
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
    'credentials' => true,
    'cache' => 3600,
    'origin.server' => !str_contains($corsOrigin, '*') ? $corsOrigin : null,
    'logger' => $corsLogger,
    'error' => function ($request, $response, $arguments) {
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
$twig->addGlobal('siteUrl', 'https://via.zweiundeins.gmbh/');

// ─── 404 handler ─────────────────────────────────────────────────────────────

$app->notFound(function ($request, $response) use ($twig, $cssPath): void {
    $assetVersion = (string) (file_exists($cssPath) ? filemtime($cssPath) : time());
    $requestedPath = $request->server['request_uri'] ?? '/';
    $html = $twig->render('pages/404.html.twig', [
        'basePath' => '/',
        'assetVersion' => $assetVersion,
        'requestedPath' => htmlspecialchars($requestedPath, ENT_QUOTES, 'UTF-8'),
    ]);
    $response->status(404);
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end($html);
});

// ─── Shared state ────────────────────────────────────────────────────────────

// (Scoped signals handle shared counter state, no globalState needed)

// ─── Presence: broadcast globally on connect/disconnect ──────────────────────
//
// Debounced: rapid connect/disconnect bursts (e.g. load tests) collapse into a
// single broadcast. Without this, N connections joining simultaneously triggers
// N broadcasts × N contexts = O(N²) renders that saturate the server.
// Timer fires 200ms after the last event.
/** @var null|int $presenceTimer */
$presenceTimer = null;

$broadcastPresence = function () use ($app, &$presenceTimer): void {
    if ($presenceTimer !== null) {
        Timer::clear($presenceTimer);
    }
    $presenceTimer = Timer::after(200, function () use ($app, &$presenceTimer): void {
        $presenceTimer = null;
        $app->broadcast(PRESENCE_SCOPE);
    });
};

$app->onClientConnect(function (Context $c) use ($broadcastPresence): void {
    $broadcastPresence();
});

$app->onClientDisconnect(function (Context $c) use ($broadcastPresence): void {
    $broadcastPresence();
});

// ─── Demo components ─────────────────────────────────────────────────────────

// Custom scopes for the busiest widgets. A broadcast to the page's own scope re-renders every
// component on the page, so a click on one of these reaches only that widget.
const PRESENCE_SCOPE = 'site:presence';
const COUNTER_SCOPE = 'home:counter';

/**
 * Presence indicator: "N people on this website right now". Its own scope, so a visitor
 * arriving or leaving re-renders the indicators and not every open page.
 */
$presenceDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(PRESENCE_SCOPE);
    $c->view(function () use ($app, $twig): string {
        $count = count($app->getClients());

        return $twig->render('components/presence.html.twig', [
            'count' => $count,
            'person' => $count === 1 ? 'person' : 'people',
        ]);
    });
};

/**
 * Shared multiplayer counter: all visitors share one counter.
 * The "aha" moment: click and everyone sees it.
 */
$sharedCounterDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(COUNTER_SCOPE);

    $counter = $c->signal(0, 'counter');
    $lastClick = $c->signal('', 'lastClick');
    $lastClickHue = $c->signal(0, 'lastClickHue');

    // A TAB action on the component: the page looks actions up, and it would not find one
    // registered in COUNTER_SCOPE.
    $increment = $c->action(function (Context $c) use ($app, $counter, $lastClick, $lastClickHue): void {
        // Atomic: $counter inherits the shared COUNTER_SCOPE, so with more than one worker
        // setValue($counter->int() + 1) would let two workers read the same value and each
        // write back the same result, dropping a click.
        $counter->increment(broadcast: false);

        // $c is this component; the visitor is the page it sits on.
        $page = $c->getComponentManager()->getParentPageContext() ?? $c;
        $visitorNum = substr($page->getId(), -4);
        $lastClick->setValue('Visitor #' . strtoupper($visitorNum), broadcast: false);
        $lastClickHue->setValue(hexdec($visitorNum) % 360, broadcast: false);
        $app->broadcast(COUNTER_SCOPE);
    }, 'increment', Scope::TAB);

    $c->view(fn () => $twig->render('components/shared-counter.html.twig', [
        'counter_id' => $counter->id(),
        'counter_val' => $counter->int(),
        'last_click_id' => $lastClick->id(),
        'last_click_val' => $lastClick->string(),
        'last_click_hue_id' => $lastClickHue->id(),
        'last_click_hue_val' => $lastClickHue->int(),
        'increment_url' => $increment->url(),
    ]));
};

/**
 * Code + live result demo: syntax-highlighted PHP on the left, working counter on the right.
 */
$codeResultDemo = function (Context $c) use ($twig): void {
    $count = $c->signal(0, 'count');
    $increment = $c->action(function (Context $c) use ($count): void {
        $count->setValue($count->int() + 1);
        $c->sync();
    }, 'increment');

    $c->view(fn () => $twig->render('components/code-result.html.twig', [
        'count_id' => $count->id(),
        'count_val' => $count->int(),
        'increment_url' => $increment->url(),
    ]));
};

/**
 * Session counter for the home page demo section (demo box only, no code panel).
 * TAB-scoped so each visitor sees their own private counter.
 */
$homeSessionDemo = function (Context $c) use ($twig): void {
    $count = $c->signal(0, 'count');
    $increment = $c->action(function (Context $c) use ($count): void {
        $count->setValue($count->int() + 1);
        $c->sync();
    }, 'increment');

    $c->view(fn () => $twig->render('components/session-counter-demo.html.twig', [
        'count_id' => $count->id(),
        'count_val' => $count->int(),
        'increment_url' => $increment->url(),
    ]));
};

/**
 * Scope comparison: TAB-scoped vs ROUTE-scoped side by side.
 * The TAB counter is independent per visitor; the ROUTE counter is shared.
 * These are TWO separate components so a TAB sync never triggers a route broadcast.
 */
$tabScopeDemo = function (Context $c): void {
    // TAB-scoped (default): each visitor has their own isolated counter
    $tabCount = $c->signal(0, 'tabCount');
    $incTab = $c->action(function (Context $c) use ($tabCount): void {
        $tabCount->setValue($tabCount->int() + 1);
        $c->sync(); // Push updated signal to SSE stream
    }, 'incTab');

    $c->view(function () use ($tabCount, $incTab): string {
        $id = $tabCount->id();
        $val = $tabCount->int();
        $url = $incTab->url();

        return <<<HTML
        <div class="scope-card tab-scoped">
            <div class="scope-label">TAB scope</div>
            <div class="scope-count" data-text="\${$id}">{$val}</div>
            <div class="scope-description">Only you see this counter.</div>
            <button class="btn btn-secondary" style="width: 100%"
                    data-on:click="@post('{$url}')">+1 (just me)</button>
        </div>
        HTML;
    });
};

$routeScopeDemo = function (Context $c) use ($app): void {
    // ROUTE-scoped: shared counter for all visitors on the same route
    $c->scope(Scope::routeScope($c->getRoute()));
    $routeCount = $c->signal($app->globalState('scope_demo_count') ?? 0, 'routeCount');
    $incRoute = $c->action(function (Context $c) use ($app, $routeCount): void {
        // GlobalState is the counter of record: it is what survives a restart, and it
        // reseeds the signal above on first mount. Both stores are advanced atomically, so
        // neither drops a click when two workers handle one at the same moment.
        $routeCount->setValue($app->incrementGlobalState('scope_demo_count'));
    }, 'incRoute');

    $c->view(function () use ($routeCount, $incRoute): string {
        $id = $routeCount->id();
        $val = $routeCount->int();
        $url = $incRoute->url();

        return <<<HTML
        <div class="scope-card route-scoped">
            <div class="scope-label">ROUTE scope</div>
            <div class="scope-count" data-text="\${$id}">{$val}</div>
            <div class="scope-description">Everyone on this page shares this.</div>
            <button class="btn btn-secondary" style="width: 100%; border-color: var(--violet-6);"
                    data-on:click="@post('{$url}')">+1 (everyone)</button>
        </div>
        HTML;
    });
};

/**
 * Live poll: vote on "Favorite scope?" (bars shift in real-time for everyone).
 */
$livePollDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(Scope::routeScope('/'));

    // Initialize vote counts in global state
    if ($app->globalState('poll_initialized') === null) {
        $app->setGlobalState('poll_tab', 0);
        $app->setGlobalState('poll_route', 0);
        $app->setGlobalState('poll_session', 0);
        $app->setGlobalState('poll_global', 0);
        $app->setGlobalState('poll_initialized', true);
    }

    $vote = $c->action(function (Context $c) use ($app): void {
        $raw = $c->input('option');
        if (!in_array($raw, ['tab', 'route', 'session', 'global'], true)) {
            return;
        }
        // Atomic. These tallies are pure GlobalState with no signal in front of them, so
        // before incrementGlobalState() existed this had to be a read-modify-write and two
        // workers voting at once would have counted one vote.
        $app->incrementGlobalState('poll_' . $raw);
        $app->broadcast(Scope::routeScope('/'));
    }, 'vote');

    $c->view(function () use ($app, $vote, $twig) {
        $counts = [
            'tab' => (int) ($app->globalState('poll_tab') ?? 0),
            'route' => (int) ($app->globalState('poll_route') ?? 0),
            'session' => (int) ($app->globalState('poll_session') ?? 0),
            'global' => (int) ($app->globalState('poll_global') ?? 0),
        ];
        $total = max(1, array_sum($counts));
        $defs = [
            'tab' => ['TAB', 'var(--blue-6)'],
            'route' => ['ROUTE', 'var(--violet-6)'],
            'session' => ['SESSION', 'var(--green-6)'],
            'global' => ['GLOBAL', 'var(--orange-5)'],
        ];
        $options = [];
        foreach ($defs as $key => [$label, $color]) {
            $options[] = [
                'key' => $key,
                'label' => $label,
                'color' => $color,
                'count' => (int) ($counts[$key] ?? 0),
                'pct' => round(((int) ($counts[$key] ?? 0) / $total) * 100),
                'url' => $vote->url() . '?option=' . $key,
            ];
        }

        return $twig->render('components/live-poll.html.twig', [
            'options' => $options,
        ]);
    });
};

// ─── Routes ───────────────────────────────────────────────────────────────────

// Home page
$app->page('/', function (Context $c) use ($presenceDemo, $sharedCounterDemo, $homeSessionDemo, $livePollDemo): void {
    $c->scope(Scope::routeScope('/'));

    $presence = $c->component($presenceDemo, 'presence');
    $sharedCounter = $c->component($sharedCounterDemo, 'shared-counter');
    $sessionCounter = $c->component($homeSessionDemo, 'session-counter');
    $poll = $c->component($livePollDemo, 'poll');

    // Components patch their own target divs, so updates leave the page itself alone.
    StaticPage::view($c, 'pages/home.html.twig', fn (): array => [
        'presence' => $presence(),
        'sharedCounter' => $sharedCounter(),
        'sessionCounter' => $sessionCounter(),
        'poll' => $poll(),
    ]);
});

// ─── Docs routes ─────────────────────────────────────────────────────────────

$app->group('/docs', function (Via $app) use ($codeResultDemo, $tabScopeDemo, $routeScopeDemo): void {
    // Landing
    $app->page('/', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs'));
        StaticPage::view($c, 'docs/index.html.twig');
    });

    // Getting started (tutorial with embedded demo)
    $app->page('/getting-started', function (Context $c) use ($codeResultDemo): void {
        $c->scope(Scope::routeScope('/docs/getting-started'));

        $demo = $c->component($codeResultDemo, 'gs-demo');

        StaticPage::view($c, 'docs/getting-started.html.twig', fn (): array => ['demo' => $demo()]);
    });

    // Signals concept page
    $app->page('/signals', function (Context $c) use ($tabScopeDemo, $routeScopeDemo): void {
        $c->scope(Scope::routeScope('/docs/signals'));

        $tabDemo = $c->component($tabScopeDemo, 'scope-tab');
        $routeDemo = $c->component($routeScopeDemo, 'scope-route');

        StaticPage::view($c, 'docs/signals.html.twig', fn (): array => [
            'tabDemo' => $tabDemo(),
            'routeDemo' => $routeDemo(),
        ]);
    });

    // Scopes concept page
    $app->page('/scopes', function (Context $c) use ($tabScopeDemo, $routeScopeDemo): void {
        $c->scope(Scope::routeScope('/docs/scopes'));

        $tabDemo = $c->component($tabScopeDemo, 'scope-tab');
        $routeDemo = $c->component($routeScopeDemo, 'scope-route');

        StaticPage::view($c, 'docs/scopes.html.twig', fn (): array => [
            'tabDemo' => $tabDemo(),
            'routeDemo' => $routeDemo(),
        ]);
    });

    $app->page('/actions', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/actions'));
        StaticPage::view($c, 'docs/actions.html.twig');
    });

    $app->page('/views', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/views'));
        StaticPage::view($c, 'docs/views.html.twig');
    });

    $app->page('/components', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/components'));
        StaticPage::view($c, 'docs/components.html.twig');
    });

    $app->page('/dev-bar', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/dev-bar'));
        StaticPage::view($c, 'docs/dev-bar.html.twig');
    });

    $app->page('/composition', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/composition'));
        StaticPage::view($c, 'docs/composition.html.twig');
    });

    $app->page('/broadcasting', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/broadcasting'));
        StaticPage::view($c, 'docs/broadcasting.html.twig');
    });

    $app->page('/broker', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/broker'));
        StaticPage::view($c, 'docs/broker.html.twig');
    });

    $app->page('/lifecycle', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/lifecycle'));
        StaticPage::view($c, 'docs/lifecycle.html.twig');
    });

    $app->page('/middleware', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/middleware'));
        StaticPage::view($c, 'docs/middleware.html.twig');
    });

    $app->page('/twig', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/twig'));
        StaticPage::view($c, 'docs/twig.html.twig');
    });

    $app->page('/development', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/development'));
        StaticPage::view($c, 'docs/development.html.twig');
    });

    $app->page('/deployment', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/deployment'));
        StaticPage::view($c, 'docs/deployment.html.twig');
    });

    $app->page('/performance', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/performance'));
        StaticPage::view($c, 'docs/performance.html.twig');
    });

    $app->page('/api', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/api'));
        StaticPage::view($c, 'docs/api.html.twig');
    });

    $app->page('/design', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/design'));
        StaticPage::view($c, 'docs/design.html.twig');
    });

    $app->page('/comparisons', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/comparisons'));
        StaticPage::view($c, 'docs/comparisons.html.twig');
    });

    $app->page('/faq', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/faq'));
        StaticPage::view($c, 'docs/faq.html.twig');
    });
});

// Examples intro
$app->page('/examples', function (Context $c): void {
    $c->scope(Scope::routeScope('/examples'));
    StaticPage::view($c, 'pages/examples-intro.html.twig');
});

// Professional support / body-leasing page
$app->page('/support', function (Context $c): void {
    $c->scope(Scope::routeScope('/support'));
    StaticPage::view($c, 'pages/support.html.twig');
});

// ─── Hot reload: load routes inside each worker ──────────────────────────────
//
// Including routes.php via onStart() means it runs inside onWorkerStart, AFTER
// the worker is forked from master. Master never loads Example classes directly,
// so each fresh worker autoloads them from disk, enabling USR1 hot reload.
// The sitemap is also regenerated there with the full route set.
// Via::onWorkerStart() calls setRoutes() after startCallbacks, so RequestHandler
// always sees the up-to-date route table.

$app->onStart(function () use ($app): void {
    require __DIR__ . '/routes.php';
});

// ─── Start ───────────────────────────────────────────────────────────────────

$startScheme = $config->isHttps() ? 'https' : 'http';
echo "⚡ php-via website running on {$startScheme}://0.0.0.0:3000\n";
$app->start();
