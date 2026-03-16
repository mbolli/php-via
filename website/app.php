<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use PhpVia\Website\SyntaxHighlightExtension;

// ─── Configuration ──────────────────────────────────────────────────────────

$config = (new Config())
    ->withHost('0.0.0.0')
    ->withPort(3100)
    ->withDevMode(true)
    ->withTemplateDir(__DIR__ . '/templates')
    ->withShellTemplate(__DIR__ . '/templates/shells/marketing.html')
    ->withStaticDir(__DIR__ . '/public')
    ->withLogLevel(getenv('APP_ENV') === 'production' ? 'info' : 'debug')
;

$app = new Via($config);
$app->getTwig()->addExtension(new SyntaxHighlightExtension());
$twig = $app->getTwig();

// ─── Shell helpers ───────────────────────────────────────────────────────────

$marketingShell = __DIR__ . '/templates/shells/marketing.html';
$docsShell = __DIR__ . '/templates/shells/docs.html';

// ─── Shared state ────────────────────────────────────────────────────────────

// Shared global counter (persists across connections in memory)
$app->setGlobalState('shared_counter', 0);
$app->setGlobalState('last_click_visitor', '');

// ─── Presence: broadcast to route on connect/disconnect ─────────────────────

$app->onClientConnect(function (Context $c) use ($app): void {
    $app->broadcast(Scope::routeScope($c->getRoute()));
});

$app->onClientDisconnect(function (Context $c) use ($app): void {
    $app->broadcast(Scope::routeScope($c->getRoute()));
});

// ─── Demo components ─────────────────────────────────────────────────────────

/**
 * Presence indicator: "🟢 N people on this page right now"
 * Route-scoped so it shows the client count for the current page.
 */
$presenceDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(Scope::ROUTE);
    $c->view(function () use ($app, $twig) {
        $count = count($app->getClients());

        return $twig->render('components/presence.html.twig', [
            'count' => $count,
            'person' => $count === 1 ? 'person' : 'people',
        ]);
    }, cacheUpdates: false);
};

/**
 * Shared multiplayer counter. ROUTE-scoped: all visitors share one counter.
 * The "aha" moment — click and everyone sees it.
 */
$sharedCounterDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(Scope::ROUTE);

    $counter = $c->signal($app->globalState('shared_counter'), 'counter');
    $lastClick = $c->signal($app->globalState('last_click_visitor'), 'lastClick');

    $increment = $c->action(function (Context $c) use ($app, $counter, $lastClick): void {
        $newVal = $app->globalState('shared_counter') + 1;
        $app->setGlobalState('shared_counter', $newVal);

        $visitorNum = substr($c->getId(), -4);
        $app->setGlobalState('last_click_visitor', 'Visitor #' . strtoupper($visitorNum));

        $counter->setValue($newVal);
        $lastClick->setValue($app->globalState('last_click_visitor'));
        $app->broadcast(Scope::routeScope('/'));
    }, 'increment');

    $c->view(fn () => $twig->render('components/shared-counter.html.twig', [
        'counter_id' => $counter->id(),
        'counter_val' => $counter->int(),
        'last_click_id' => $lastClick->id(),
        'last_click_val' => $lastClick->string(),
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
 * Scope comparison: TAB-scoped vs ROUTE-scoped side by side.
 * The TAB counter is independent per visitor; the ROUTE counter is shared.
 */
$scopeComparisonDemo = function (Context $c) use ($app, $twig): void {
    // TAB-scoped: default, each visitor has their own
    $tabCount = $c->signal(0, 'tabCount');
    $incTab = $c->action(function (Context $c) use ($tabCount): void {
        $tabCount->setValue($tabCount->int() + 1);
        $c->sync();
    }, 'incTab');

    // ROUTE-scoped: shared across all visitors on this route
    $c->addScope(Scope::routeScope($c->getRoute()));
    $routeCount = $c->signal($app->globalState('scope_demo_count') ?? 0, 'routeCount', Scope::routeScope($c->getRoute()));
    $incRoute = $c->action(function (Context $c) use ($app, $routeCount): void {
        $newVal = ($app->globalState('scope_demo_count') ?? 0) + 1;
        $app->setGlobalState('scope_demo_count', $newVal);
        $routeCount->setValue($newVal);
        $app->broadcast(Scope::routeScope($c->getRoute()));
    }, 'incRoute');

    $c->view(fn () => $twig->render('components/scope-comparison.html.twig', [
        'tab_count_id' => $tabCount->id(),
        'tab_count_val' => $tabCount->int(),
        'route_count_id' => $routeCount->id(),
        'route_count_val' => $routeCount->int(),
        'inc_tab_url' => $incTab->url(),
        'inc_route_url' => $incRoute->url(),
    ]));
};

/**
 * Live poll: vote on "Favorite scope?" — bars shift in real-time for everyone.
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

    $votes = $c->signal([
        'tab' => $app->globalState('poll_tab'),
        'route' => $app->globalState('poll_route'),
        'session' => $app->globalState('poll_session'),
        'global' => $app->globalState('poll_global'),
    ], 'votes');

    $vote = $c->action(function (Context $c) use ($app, $votes): void {
        $raw = $_POST['option'] ?? ($_GET['option'] ?? null);
        if (!in_array($raw, ['tab', 'route', 'session', 'global'], true)) {
            return;
        }
        $key = 'poll_' . $raw;
        $app->setGlobalState($key, ($app->globalState($key) ?? 0) + 1);
        $votes->setValue([
            'tab' => $app->globalState('poll_tab'),
            'route' => $app->globalState('poll_route'),
            'session' => $app->globalState('poll_session'),
            'global' => $app->globalState('poll_global'),
        ]);
        $app->broadcast(Scope::routeScope('/'));
    }, 'vote');

    $c->view(function () use ($app, $votes, $vote, $twig) {
        $counts = [
            'tab'     => (int) ($app->globalState('poll_tab') ?? 0),
            'route'   => (int) ($app->globalState('poll_route') ?? 0),
            'session' => (int) ($app->globalState('poll_session') ?? 0),
            'global'  => (int) ($app->globalState('poll_global') ?? 0),
        ];
        // Keep the signal in sync for all clients receiving this broadcast update
        // so data-text="$poll_votes.*" reads fresh values.
        $votes->setValue($counts);
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
                'key'   => $key,
                'label' => $label,
                'color' => $color,
                'count' => (int) ($counts[$key] ?? 0),
                'pct'   => round(((int) ($counts[$key] ?? 0) / $total) * 100),
                'url'   => $vote->url() . '?option=' . $key,
            ];
        }

        return $twig->render('components/live-poll.html.twig', [
            'options' => $options,
            'votes_id' => $votes->id(),
        ]);
    }, cacheUpdates: false);
};

// ─── Routes ───────────────────────────────────────────────────────────────────

// Home page
$app->page('/', function (Context $c) use ($marketingShell, $presenceDemo, $sharedCounterDemo, $codeResultDemo, $livePollDemo, $twig): void {
    $c->setShellTemplate($marketingShell);
    $c->appendToHead('<title>php-via — Real-time PHP. No JavaScript. No build step.</title>');
    $c->appendToHead('<meta name="description" content="php-via is a real-time engine for building reactive PHP web applications with Swoole and Datastar. No JavaScript, no build step, multiplayer by default.">');

    $c->scope(Scope::routeScope('/'));

    $presence = $c->component($presenceDemo, 'presence');
    $sharedCounter = $c->component($sharedCounterDemo, 'shared-counter');
    $codeResult = $c->component($codeResultDemo, 'code-result');
    $poll = $c->component($livePollDemo, 'poll');

    // On broadcast updates, skip re-rendering the full page — component sub-contexts
    // handle their own patches via their own target divs. Re-rendering here with stale
    // embedded component HTML would overwrite those fresh patches.
    $c->view(function (bool $isUpdate) use ($twig, $presence, $sharedCounter, $codeResult, $poll): string {
        if ($isUpdate) {
            return '';
        }

        return $twig->render('pages/home.html.twig', [
            'presence'      => $presence(),
            'sharedCounter' => $sharedCounter(),
            'codeResult'    => $codeResult(),
            'poll'          => $poll(),
        ]);
    });
});

// Docs landing
$app->page('/docs', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Documentation — php-via</title>');
    $c->scope(Scope::routeScope('/docs'));
    $c->view('docs/index.html.twig');
});

// Getting started (tutorial with embedded demo)
$app->page('/docs/getting-started', function (Context $c) use ($docsShell, $codeResultDemo): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Getting Started — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/getting-started'));

    $demo = $c->component($codeResultDemo, 'gs-demo');

    $c->view('docs/getting-started.html.twig', [
        'demo' => $demo(),
    ]);
});

// Signals concept page
$app->page('/docs/signals', function (Context $c) use ($docsShell, $scopeComparisonDemo): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Signals — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/signals'));

    $demo = $c->component($scopeComparisonDemo, 'scope-demo');

    $c->view('docs/signals.html.twig', [
        'demo' => $demo(),
    ]);
});

// Scopes concept page
$app->page('/docs/scopes', function (Context $c) use ($docsShell, $scopeComparisonDemo): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Scopes — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/scopes'));

    $demo = $c->component($scopeComparisonDemo, 'scope-demo');

    $c->view('docs/scopes.html.twig', [
        'demo' => $demo(),
    ]);
});

// Actions
$app->page('/docs/actions', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Actions — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/actions'));
    $c->view('docs/actions.html.twig');
});

// Views
$app->page('/docs/views', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Views — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/views'));
    $c->view('docs/views.html.twig');
});

// Components
$app->page('/docs/components', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Components — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/components'));
    $c->view('docs/components.html.twig');
});

// Broadcasting
$app->page('/docs/broadcasting', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Broadcasting — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/broadcasting'));
    $c->view('docs/broadcasting.html.twig');
});

// Lifecycle
$app->page('/docs/lifecycle', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Lifecycle — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/lifecycle'));
    $c->view('docs/lifecycle.html.twig');
});

// Twig templates
$app->page('/docs/twig', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Twig Templates — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/twig'));
    $c->view('docs/twig.html.twig');
});

// Deployment
$app->page('/docs/deployment', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Deployment — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/deployment'));
    $c->view('docs/deployment.html.twig');
});

// API reference
$app->page('/docs/api', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>API Reference — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/api'));
    $c->view('docs/api.html.twig');
});

// Comparisons
$app->page('/docs/comparisons', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Comparisons — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/comparisons'));
    $c->view('docs/comparisons.html.twig');
});

// FAQ / common pitfalls
$app->page('/docs/faq', function (Context $c) use ($docsShell): void {
    $c->setShellTemplate($docsShell);
    $c->appendToHead('<title>Common Pitfalls — php-via Docs</title>');
    $c->scope(Scope::routeScope('/docs/faq'));
    $c->view('docs/faq.html.twig');
});

// Examples gallery
$app->page('/examples', function (Context $c) use ($marketingShell): void {
    $c->setShellTemplate($marketingShell);
    $c->appendToHead('<title>Examples — php-via</title>');
    $c->scope(Scope::routeScope('/examples'));
    $c->view('pages/examples.html.twig');
});

// ─── Start ───────────────────────────────────────────────────────────────────

echo "⚡ php-via website running on http://0.0.0.0:3100\n";
$app->start();
