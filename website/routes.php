<?php

declare(strict_types=1);

/**
 * Route registration — loaded inside each worker via onStart() so that USR1 hot reload
 * picks up fresh class definitions from disk. Master process never includes this file
 * directly, which is the key condition for hot reload to work.
 *
 * $app is available from the calling closure's scope (require inside a closure shares
 * that closure's local scope in PHP).
 */

use PhpVia\Website\Examples\AllScopesExample;
use PhpVia\Website\Examples\ChatRoomExample;
use PhpVia\Website\Examples\ClientMonitorExample;
use PhpVia\Website\Examples\ComponentsExample;
use PhpVia\Website\Examples\ContactFormExample;
use PhpVia\Website\Examples\CounterExample;
use PhpVia\Website\Examples\FileUploadExample;
use PhpVia\Website\Examples\GameOfLifeExample;
use PhpVia\Website\Examples\GreeterExample;
use PhpVia\Website\Examples\LiveAuctionExample;
use PhpVia\Website\Examples\LiveSearchExample;
use PhpVia\Website\Examples\LoginExample;
use PhpVia\Website\Examples\MissionControlExample;
use PhpVia\Website\Examples\PathParamsExample;
use PhpVia\Website\Examples\ShoppingCartExample;
use PhpVia\Website\Examples\SpreadsheetExample;
use PhpVia\Website\Examples\StockTickerExample;
use PhpVia\Website\Examples\ThemeBuilderExample;
use PhpVia\Website\Examples\TodoExample;
use PhpVia\Website\Examples\TypeRaceExample;
use PhpVia\Website\Examples\WizardExample;

// ─── Examples: register routes ───────────────────────────────────────────────

CounterExample::register($app);
GreeterExample::register($app);
TodoExample::register($app);
ComponentsExample::register($app);
PathParamsExample::register($app);
StockTickerExample::register($app);
ChatRoomExample::register($app);
ClientMonitorExample::register($app);
ClientMonitorExample::registerHooks($app);
AllScopesExample::register($app);
GameOfLifeExample::register($app);
SpreadsheetExample::register($app);
LiveSearchExample::register($app);
ShoppingCartExample::register($app);
ThemeBuilderExample::register($app);
WizardExample::register($app);
LoginExample::register($app);
ContactFormExample::register($app);
FileUploadExample::register($app);
LiveAuctionExample::register($app);
TypeRaceExample::register($app);
MissionControlExample::register($app);

// ─── Sitemap ─────────────────────────────────────────────────────────────────
//
// Generated here (inside worker startup) so it includes all routes — both those
// registered in app.php (master) and the example routes registered just above.

(static function () use ($app): void {
    $siteUrl = 'https://via.zweiundeins.gmbh';
    $templateDir = __DIR__ . '/templates';

    // Map a route to its Twig template path so we can use filemtime() for lastmod.
    // Falls back to the routes.php mtime itself if no template is found.
    $routeTemplatePath = static function (string $route) use ($templateDir): string {
        $explicit = [
            '/' => $templateDir . '/pages/home.html.twig',
            '/docs' => $templateDir . '/docs/index.html.twig',
            '/examples' => $templateDir . '/pages/examples-intro.html.twig',
            '/support' => $templateDir . '/pages/support.html.twig',
            // Route slug differs from template name
            '/examples/stock-ticker' => $templateDir . '/examples/stock_dashboard.html.twig',
        ];

        if (isset($explicit[$route])) {
            return $explicit[$route];
        }

        // /docs/getting-started → templates/docs/getting-started.html.twig
        if (preg_match('#^/docs/(.+)$#', $route, $m)) {
            return $templateDir . '/docs/' . $m[1] . '.html.twig';
        }

        // /examples/counter → templates/examples/counter.html.twig
        // Template filenames use underscores; routes use hyphens and / as separators.
        // Try several candidate transformations in order of specificity, pick first that exists.
        if (preg_match('#^/examples/(.+)$#', $route, $m)) {
            $slug = $m[1]; // e.g. "chat-room", "file-upload/browse", "all-scopes/page-a"
            $parts = explode('/', $slug);
            $candidates = [
                $slug,                                               // as-is: "contact-form", "file-upload"
                str_replace(['-', '/'], '_', $slug),                 // underscores: "chat_room", "login_dashboard"
                str_replace('/', '-', $slug),                        // slashes→hyphens: "file-upload-browse"
                str_replace('-', '_', str_replace('/', '-', $slug)), // mixed: "file_upload_browse"
            ];
            if (count($parts) > 1) {
                // For sub-routes with no dedicated template, fall back to parent's template
                $candidates[] = str_replace('-', '_', $parts[0]);   // parent underscored: "all_scopes"
                $candidates[] = $parts[0];                           // parent as-is: "login"
            }
            foreach (array_unique($candidates) as $candidate) {
                $path = $templateDir . '/examples/' . $candidate . '.html.twig';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return $templateDir . '/pages/' . ltrim($route, '/') . '.html.twig';
    };

    $routes = array_keys($app->getRouter()->getRoutes());

    // Filter: skip internal routes (/_*), dynamic segments (:param), and
    // individual example sub-pages (interactive, not indexable as static content).
    $routes = array_filter($routes, static function (string $route): bool {
        if (str_starts_with($route, '/_')) {
            return false;
        }
        if (str_contains($route, ':') || str_contains($route, '{')) {
            return false;
        }

        return true;
    });

    // Assign priority and changefreq per route
    $entries = [];
    foreach ($routes as $route) {
        if ($route === '/') {
            $priority = '1.0';
            $changefreq = 'weekly';
        } elseif (str_starts_with($route, '/docs')) {
            $priority = '0.8';
            $changefreq = 'monthly';
        } else {
            $priority = '0.6';
            $changefreq = 'monthly';
        }

        $templatePath = $routeTemplatePath($route);
        $mtime = file_exists($templatePath) ? filemtime($templatePath) : filemtime(__FILE__);
        $lastmod = date('Y-m-d', (int) $mtime);

        $entries[] = sprintf(
            "    <url>\n        <loc>%s%s</loc>\n        <lastmod>%s</lastmod>\n        <changefreq>%s</changefreq>\n        <priority>%s</priority>\n    </url>",
            $siteUrl,
            $route,
            $lastmod,
            $changefreq,
            $priority,
        );
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . implode("\n", $entries) . "\n"
        . '</urlset>' . "\n";

    file_put_contents(__DIR__ . '/public/sitemap.xml', $xml);
})();
