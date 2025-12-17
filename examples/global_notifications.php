<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * Global Notifications Example.
 *
 * Demonstrates GLOBAL scope - a notification system that appears on ALL pages.
 * This shows how global state and global actions work across different routes.
 *
 * Features:
 * - Global notification count visible on every page
 * - Clicking "Add Notification" on ANY page updates ALL pages
 * - Single render cached globally (extreme performance)
 * - Uses globalAction() and globalState()
 */

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3008)
    ->withLogLevel('debug')
;

// Create the application
$app = new Via($config);

// Shared notification component (uses GLOBAL scope)
$notificationBanner = function (Context $c) use ($app): void {
    // Set GLOBAL scope - shared across ALL routes and users
    $c->scope(Scope::GLOBAL);

    // This action is GLOBAL - callable from any route, affects all routes
    $addNotification = $c->action(function (Context $ctx) use ($app): void {
        $count = $app->globalState('notificationCount', 0);
        $app->setGlobalState('notificationCount', $count + 1);
        $app->log('info', 'Notification added! Total: ' . ($count + 1));

        // Broadcast to ALL routes
        $app->broadcast(Scope::GLOBAL);
    }, 'addNotification');

    $clearNotifications = $c->action(function (Context $ctx) use ($app): void {
        $app->setGlobalState('notificationCount', 0);
        $app->log('info', 'Notifications cleared');
        $app->broadcast(Scope::GLOBAL);
    }, 'clearNotifications');

    // Note: No signals, ONLY global actions = GLOBAL scope
    // This view will be cached globally and shared across ALL routes!
    $c->view(function () use ($app, $addNotification, $clearNotifications): string {
        $count = $app->globalState('notificationCount', 0);

        return <<<HTML
        <div style="background: #f0f0f0; padding: 1rem; margin-bottom: 1rem; border-radius: 0.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>🔔 Notifications:</strong>
                    <span style="background: #ff6b6b; color: white; padding: 0.25rem 0.5rem; border-radius: 1rem; font-size: 0.9rem;">
                        {$count}
                    </span>
                </div>
                <div>
                    <button
                        data-on:click="@get('{$addNotification->url()}')"
                        style="padding: 0.5rem 1rem; margin-right: 0.5rem; cursor: pointer;">
                        ➕ Add Notification
                    </button>
                    <button
                        data-on:click="@get('{$clearNotifications->url()}')"
                        style="padding: 0.5rem 1rem; cursor: pointer;">
                        🗑️ Clear
                    </button>
                </div>
            </div>
            <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem; color: #666;">
                <em>This banner uses GLOBAL scope - it's rendered once and shared across ALL pages!
                Click "Add Notification" on any page to update everywhere.</em>
            </p>
        </div>
        HTML;
    });
};

// Page 1 - Home
$app->page('/', function (Context $c) use ($app, $notificationBanner): void {
    $banner = $c->component($notificationBanner, 'notifications');

    $app->appendToHead(<<<'HTML'
        <title>🌍 Global Scope Demo - Home</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
            nav { margin-bottom: 2rem; }
            nav a { margin-right: 1rem; padding: 0.5rem 1rem; background: #4CAF50; color: white; text-decoration: none; border-radius: 0.25rem; }
            nav a:hover { background: #45a049; }
        </style>
    HTML);

    $c->view(fn (): string => <<<HTML
        <nav>
            <a href="/">🏠 Home</a>
            <a href="/dashboard">📊 Dashboard</a>
            <a href="/settings">⚙️ Settings</a>
        </nav>

        {$banner()}

        <h1>🏠 Home Page</h1>
        <p>This is the home page. Notice how the notification banner appears on every page.</p>
        <p>Try this:</p>
        <ol>
            <li>Open this page in multiple tabs</li>
            <li>Navigate to different pages (Dashboard, Settings)</li>
            <li>Click "Add Notification" on any tab/page</li>
            <li>Watch ALL tabs on ALL pages update instantly!</li>
        </ol>
        <p><strong>How it works:</strong></p>
        <ul>
            <li>The notification banner uses <code>globalAction()</code> (not routeAction)</li>
            <li>Global state stored via <code>setGlobalState()</code></li>
            <li>Updates broadcast via <code>broadcastGlobal()</code></li>
            <li>The view is rendered ONCE and cached globally</li>
            <li>All routes share the same cached HTML (max performance!)</li>
        </ul>
    HTML);
});

// Page 2 - Dashboard
$app->page('/dashboard', function (Context $c) use ($app, $notificationBanner): void {
    $banner = $c->component($notificationBanner, 'notifications');

    $app->appendToHead(<<<'HTML'
        <title>🌍 Global Scope Demo - Dashboard</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
            nav { margin-bottom: 2rem; }
            nav a { margin-right: 1rem; padding: 0.5rem 1rem; background: #4CAF50; color: white; text-decoration: none; border-radius: 0.25rem; }
            nav a:hover { background: #45a049; }
        </style>
    HTML);

    $c->view(fn (): string => <<<HTML
        <nav>
            <a href="/">🏠 Home</a>
            <a href="/dashboard">📊 Dashboard</a>
            <a href="/settings">⚙️ Settings</a>
        </nav>

        {$banner()}

        <h1>📊 Dashboard Page</h1>
        <p>This is the dashboard. The same global notification banner is shared here!</p>
        <p><strong>Key point:</strong> When you click "Add Notification" here, it updates the banner on:</p>
        <ul>
            <li>✅ This dashboard page</li>
            <li>✅ The home page</li>
            <li>✅ The settings page</li>
            <li>✅ ALL open tabs, regardless of which page they're on</li>
        </ul>
    HTML);
});

// Page 3 - Settings
$app->page('/settings', function (Context $c) use ($app, $notificationBanner): void {
    $banner = $c->component($notificationBanner, 'notifications');

    $app->appendToHead(<<<'HTML'
        <title>🌍 Global Scope Demo - Settings</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
            nav { margin-bottom: 2rem; }
            nav a { margin-right: 1rem; padding: 0.5rem 1rem; background: #4CAF50; color: white; text-decoration: none; border-radius: 0.25rem; }
            nav a:hover { background: #45a049; }
        </style>
    HTML);

    $c->view(fn (): string => <<<HTML
        <nav>
            <a href="/">🏠 Home</a>
            <a href="/dashboard">📊 Dashboard</a>
            <a href="/settings">⚙️ Settings</a>
        </nav>

        {$banner()}

        <h1>⚙️ Settings Page</h1>
        <p>This is the settings page. The global notification system works here too!</p>
        <p><strong>Scope comparison:</strong></p>
        <table border="1" cellpadding="10" style="border-collapse: collapse;">
            <tr>
                <th>Scope</th>
                <th>Cache Key</th>
                <th>Shared Across</th>
                <th>Example</th>
            </tr>
            <tr>
                <td>GLOBAL</td>
                <td>App-wide (one cache for entire app)</td>
                <td>ALL routes, ALL users</td>
                <td>This notification banner</td>
            </tr>
            <tr>
                <td>ROUTE</td>
                <td>Per route (e.g., <code>/game</code>)</td>
                <td>Same route, ALL users</td>
                <td>Game of Life board</td>
            </tr>
            <tr>
                <td>TAB</td>
                <td>No cache</td>
                <td>Single user/tab only</td>
                <td>Personal profile page</td>
            </tr>
        </table>
    HTML);
});

echo "Starting Global Scope Demo on http://0.0.0.0:3008\n";
echo "Try opening multiple pages in different tabs and clicking 'Add Notification'!\n";
$app->start();
