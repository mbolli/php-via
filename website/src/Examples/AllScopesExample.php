<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class AllScopesExample
{
    public const string SLUG = 'all-scopes';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Three scopes on one page</strong> — GLOBAL (status banner), ROUTE (shared page counter), and TAB (personal message). Each component lives in a different scope to show the contrast.',
        '<strong>Navigate between sub-pages</strong> to see the difference: the GLOBAL banner stays identical everywhere, the ROUTE counter resets per page, and the TAB message is unique per browser tab.',
        '<strong>Components</strong> encapsulate each scope layer. The same page factory mounts all three components, so adding a new sub-page is a single function call.',
        '<strong>Scope hierarchy</strong> visualised: GLOBAL lives for the entire server lifetime, ROUTE resets when you change URL, and TAB is born and dies with each browser tab.',
        '<strong>Try it</strong>: open two tabs on the same sub-page and click the ROUTE counter. Both tabs update. Now open a tab on a different sub-page — its counter is independent.',
    ];

    /** @var array<string, int> */
    private static array $counters = [
        '/examples/all-scopes' => 0,
        '/examples/all-scopes/page-a' => 0,
        '/examples/all-scopes/page-b' => 0,
    ];

    public static function register(Via $app): void
    {
        $app->setGlobalState('example:allscopes:status', 'All systems operational');
        $app->setGlobalState('example:allscopes:visitors', 0);

        // GLOBAL scope component
        $globalBanner = function (Context $c) use ($app): void {
            $c->scope(Scope::GLOBAL);

            $updateStatus = $c->action(function () use ($app): void {
                $statuses = ['All systems operational', 'Maintenance mode', 'High load detected', 'Everything is awesome!'];
                $app->setGlobalState('example:allscopes:status', $statuses[array_rand($statuses)]);
                $visitors = $app->globalState('example:allscopes:visitors', 0);
                $app->setGlobalState('example:allscopes:visitors', $visitors + 1);
                $app->broadcast(Scope::GLOBAL);
            }, 'updateStatus');

            $c->view(function () use ($app, $updateStatus): string {
                $status = $app->globalState('example:allscopes:status', 'Unknown');
                $visitors = $app->globalState('example:allscopes:visitors', 0);

                return <<<HTML
                <div class="card scope-card scope-card--global">
                    <div class="scope-card-header">
                        <div>
                            <div class="scope-card-label">GLOBAL &mdash; System Status</div>
                            <div>Status: <strong>{$status}</strong> · Visitors: <strong>{$visitors}</strong></div>
                        </div>
                        <button data-on:click="@get('{$updateStatus->url()}')">Update Status</button>
                    </div>
                    <p class="scope-card-hint">Shared across ALL pages and users. Changes propagate everywhere.</p>
                </div>
                HTML;
            });
        };

        // ROUTE scope component
        $routeCounter = function (Context $c) use ($app): void {
            $route = $c->getRoute();
            $c->scope(Scope::ROUTE);

            $increment = $c->action(function () use ($app, $route): void {
                ++self::$counters[$route];
                $app->broadcast(Scope::ROUTE);
            }, 'increment_' . str_replace('/', '_', $route));

            $reset = $c->action(function () use ($app, $route): void {
                self::$counters[$route] = 0;
                $app->broadcast(Scope::ROUTE);
            }, 'reset_' . str_replace('/', '_', $route));

            $c->view(function () use ($route, $increment, $reset): string {
                $count = self::$counters[$route] ?? 0;

                return <<<HTML
                <div class="card scope-card scope-card--route">
                    <div class="scope-card-header">
                        <div>
                            <div class="scope-card-label">ROUTE &mdash; Shared Page Counter</div>
                            <div style="font-size: var(--font-size-5); font-weight: var(--font-weight-9);">{$count}</div>
                        </div>
                        <div style="display: flex; gap: var(--size-2);">
                            <button data-on:click="@get('{$increment->url()}')">+ Increment</button>
                            <button class="danger" data-on:click="@get('{$reset->url()}')">Reset</button>
                        </div>
                    </div>
                    <p class="scope-card-hint">Shared by all users on THIS page only. Different pages have different counters.</p>
                </div>
                HTML;
            });
        };

        // TAB scope component
        $tabMessage = function (Context $c): void {
            $message = $c->signal('Hello from your personal tab!', 'personalMessage');

            $updateMessage = $c->action(function () use ($message, $c): void {
                $messages = ['You are awesome!', 'Having a great day?', 'Keep coding!', 'This is YOUR personal message!', 'Tab scope is cool!'];
                $message->setValue($messages[array_rand($messages)]);
                $c->syncSignals();
            }, 'updateMessage');

            $c->view(fn (): string => <<<HTML
                <div class="card scope-card scope-card--tab">
                    <div class="scope-card-header">
                        <div style="flex: 1;">
                            <div class="scope-card-label">TAB &mdash; Your Personal Message</div>
                            <input type="text" {$message->bind()} style="width: 100%; margin-block: var(--size-1);">
                            <div>Your message: <span data-text="\${$message->id()}"></span></div>
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

                $c->view(function (bool $isUpdate, string $basePath) use ($pageTitle, $content, $global, $counter, $tab, $c): string {
                    if ($isUpdate) {
                        return "{$global()}{$counter()}{$tab()}";
                    }

                    return $c->render('examples/all_scopes.html.twig', [
                        'title' => '📊 All Scopes',
                        'description' => 'Demonstrates GLOBAL, ROUTE, and TAB scopes side by side.',
                        'summary' => self::SUMMARY,
                        'sourceFile' => 'all_scopes.php',
                        'templateFiles' => ['all_scopes.html.twig'],
                        'pageTitle' => $pageTitle,
                        'content' => $content,
                        'globalBanner' => $global(),
                        'routeCounter' => $counter(),
                        'tabMessage' => $tab(),
                        'basePath' => $basePath,
                    ]);
                });
            });
        };

        $createPage('Home', '/examples/all-scopes', '<p>This app demonstrates all three scopes. Navigate between pages to see how each scope behaves differently.</p>');
        $createPage('Page A', '/examples/all-scopes/page-a', '<p>Notice: Global status is the SAME as Home, but the Route counter is DIFFERENT (this is Page A\'s counter).</p>');
        $createPage('Page B', '/examples/all-scopes/page-b', '<p>Notice: Global status is STILL the same, but Route counter is DIFFERENT from both Home and Page A.</p>');
    }
}
