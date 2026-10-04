<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class AllScopesExample {
    public const string SLUG = 'all-scopes';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Three scopes on one page</strong>: GLOBAL (status banner), ROUTE (shared page counter), and TAB (personal message). Each component lives in a different scope to show the contrast.',
        '<strong>Navigate between sub-pages</strong> to see the difference: the GLOBAL banner stays identical everywhere, each page has its own ROUTE counter, and the TAB message is unique per browser tab.',
        '<strong>Components</strong> encapsulate each scope layer. The same page factory mounts all three components, so adding a new sub-page is a single function call.',
        '<strong>Scope hierarchy</strong> visualised: GLOBAL is one state for the whole server, ROUTE is one state per URL, and TAB is born and dies with each browser tab.',
        '<strong>Try it</strong>: open two tabs on the same sub-page and click the ROUTE counter. Both tabs update. Now open a tab on a different sub-page: its counter is independent.',
    ];

    /** @var array<string, int> */
    private static array $counters = [
        '/examples/all-scopes' => 0,
        '/examples/all-scopes/page-a' => 0,
        '/examples/all-scopes/page-b' => 0,
    ];

    public static function register(Via $app): void {
        // Seed once, not on every call. register() runs from routes.php, which app.php pulls in
        // via onWorkerStart(), so it re-runs on every worker start AND on every USR1 hot reload.
        // Unconditional writes here would wipe the persisted tally each time the server came up.
        if ($app->globalState('example:allscopes:status') === null) {
            $app->setGlobalState('example:allscopes:status', 'All systems operational');
            $app->setGlobalState('example:allscopes:visitors', 0);
        }

        // GLOBAL scope component
        $globalBanner = function (Context $c) use ($app): void {
            $c->scope(Scope::GLOBAL);

            $updateStatus = $c->action(function () use ($app): void {
                $statuses = ['All systems operational', 'Maintenance mode', 'High load detected', 'Everything is awesome!'];
                $app->setGlobalState('example:allscopes:status', $statuses[array_rand($statuses)]);
                $app->incrementGlobalState('example:allscopes:visitors');
                $app->broadcast(Scope::GLOBAL);
            }, 'updateStatus');

            $c->view(function () use ($app, $updateStatus): string {
                $status = $app->globalState('example:allscopes:status', 'Unknown');
                $visitors = $app->globalState('example:allscopes:visitors', 0);

                return <<<HTML
                <div class="card scope-card scope-card--global">
                    <div class="scope-card-header">
                        <div>
                            <div class="scope-card-label">GLOBAL: System Status</div>
                            <div>Status: <strong>{$status}</strong> · Visitors: <strong>{$visitors}</strong></div>
                        </div>
                        <button data-on:click="@post('{$updateStatus->url()}')">Update Status</button>
                    </div>
                    <p class="scope-card-hint">Shared across ALL pages and users. Changes propagate everywhere.</p>
                </div>
                HTML;
            }, shareRender: true);
        };

        // ROUTE scope component
        $routeCounter = function (Context $c) use ($app): void {
            $route = $c->getRoute();
            $c->scope(Scope::ROUTE);

            $increment = $c->action(function () use ($app, $route): void {
                ++self::$counters[$route];
                $app->broadcast(Scope::routeScope($route));
            }, 'increment');

            $reset = $c->action(function () use ($app, $route): void {
                self::$counters[$route] = 0;
                $app->broadcast(Scope::routeScope($route));
            }, 'reset');

            $c->view(function () use ($route, $increment, $reset): string {
                $count = self::$counters[$route] ?? 0;

                return <<<HTML
                <div class="card scope-card scope-card--route">
                    <div class="scope-card-header">
                        <div>
                            <div class="scope-card-label">ROUTE: Shared Page Counter</div>
                            <div style="font-size: var(--font-size-5); font-weight: var(--font-weight-9);">{$count}</div>
                        </div>
                        <div style="display: flex; gap: var(--size-2);">
                            <button data-on:click="@post('{$increment->url()}')">+ Increment</button>
                            <button class="danger" data-on:click="@post('{$reset->url()}')" aria-label="Reset this page's counter">Reset</button>
                        </div>
                    </div>
                    <p class="scope-card-hint">Shared by all users on THIS page only. Different pages have different counters.</p>
                </div>
                HTML;
            }, shareRender: true);
        };

        // TAB scope component
        $tabMessage = function (Context $c): void {
            $message = $c->signal('Hello from your personal tab!', 'personalMessage');
            $h = htmlspecialchars(...);

            $updateMessage = $c->action(function () use ($message): void {
                $messages = ['You are awesome!', 'Having a great day?', 'Keep coding!', 'This is YOUR personal message!', 'Tab scope is cool!'];
                $message->setValue($messages[array_rand($messages)]);
            }, 'updateMessage');

            $c->view(fn (): string => <<<HTML
                <div class="card scope-card scope-card--tab">
                    <div class="scope-card-header">
                        <div style="flex: 1;">
                            <div class="scope-card-label">TAB: Your Personal Message</div>
                            <input type="text" {$message->bind()} style="width: 100%; margin-block: var(--size-1);">
                            <div>Your message: <span data-text="{$message->ref()}">{$h($message->string())}</span></div>
                        </div>
                        <button data-on:click="@post('{$updateMessage->url()}')">Random</button>
                    </div>
                    <p class="scope-card-hint">Private to this browser tab. Other tabs have their own value.</p>
                </div>
                HTML);
        };

        // Page factory
        $createPage = function (string $pageTitle, string $route, string $content) use ($app, $globalBanner, $routeCounter, $tabMessage): void {
            $app->page($route, function (Context $c) use ($pageTitle, $content, $globalBanner, $routeCounter, $tabMessage): void {
                $global = $c->component($globalBanner, 'global');
                $counter = $c->component($routeCounter, 'route');
                $tab = $c->component($tabMessage, 'private');

                $c->view(function (bool $isUpdate) use ($pageTitle, $content, $global, $counter, $tab, $c): string {
                    if ($isUpdate) {
                        return "{$global()}{$counter()}{$tab()}";
                    }

                    return $c->render('examples/all_scopes.html.twig', [
                        'title' => 'All Scopes',
                        'perWorker' => 'the ROUTE counters',
                        'description' => 'Change a status that every visitor sees, a counter shared by everyone on this page, and a message only your tab sees. The three cards are components in GLOBAL, ROUTE and TAB scope; Page A and Page B each get their own ROUTE counter.',
                        'summary' => self::SUMMARY,
                        'anatomy' => [
                            'signals' => [
                                ['name' => 'personalMessage', 'type' => 'string', 'scope' => 'TAB', 'default' => '"Hello from your personal tab!"', 'desc' => 'Per-tab editable message. Private to each browser tab.'],
                            ],
                            'actions' => [
                                ['name' => 'updateStatus', 'desc' => 'Randomizes the system status, counts the visit in GlobalState and broadcasts Scope::GLOBAL.'],
                                ['name' => 'increment', 'desc' => 'Increments the page-specific shared counter and broadcasts the page\'s ROUTE scope.'],
                                ['name' => 'reset', 'desc' => 'Resets the page-specific counter to 0 and broadcasts the page\'s ROUTE scope.'],
                                ['name' => 'updateMessage', 'desc' => 'Randomizes the personal tab message. The new value goes to the tab after the action, with no broadcast.'],
                            ],
                            'views' => [
                                ['name' => 'all_scopes.html.twig', 'desc' => 'Page shell with navigation between sub-pages.'],
                                ['name' => 'Global banner', 'desc' => 'GLOBAL-scoped component showing system status shared across all pages and users.'],
                                ['name' => 'Route counter', 'desc' => 'ROUTE-scoped component with a counter shared between all tabs on the same page.'],
                                ['name' => 'Tab message', 'desc' => 'TAB-scoped component with a private editable message per browser tab.'],
                            ],
                        ],
                        'githubLinks' => [
                            ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/AllScopesExample.php'],
                            ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/all_scopes.html.twig'],
                        ],
                        'pageTitle' => $pageTitle,
                        'content' => $content,
                        'globalBanner' => $global(),
                        'routeCounter' => $counter(),
                        'tabMessage' => $tab(),
                    ]);
                });
            });
        };

        $createPage('Home', '/examples/all-scopes', '<p>This app demonstrates all three scopes. Navigate between pages to see how each scope behaves differently.</p>');
        $createPage('Page A', '/examples/all-scopes/page-a', '<p>Notice: Global status is the SAME as Home, but the Route counter is DIFFERENT (this is Page A\'s counter).</p>');
        $createPage('Page B', '/examples/all-scopes/page-b', '<p>Notice: Global status is STILL the same, but Route counter is DIFFERENT from both Home and Page A.</p>');
    }
}
