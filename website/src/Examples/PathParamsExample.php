<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class PathParamsExample {
    public const string SLUG = 'path-params';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>{year}/{month}/{slug}</strong> placeholders in the route pattern are captured and passed to your handler automatically.',
        '<strong>Two access styles</strong> — call <code>$c-&gt;getPathParam()</code> manually, or let Via inject matching parameters directly into your callback\'s function signature.',
        '<strong>Reflection-based injection</strong> matches parameter names to route placeholders. Type-hint <code>int</code> and Via casts the value for you — no manual parsing required.',
    ];

    /** @var array<string, list<array{name: string, desc: string}>> */
    private const array ANATOMY = [
        'signals' => [],
        'actions' => [],
        'views' => [
            ['name' => 'path_params.html.twig', 'desc' => 'Landing page with links to both blog (manual) and article (auto-injection) routes.'],
            ['name' => 'path_params_detail.html.twig', 'desc' => 'Detail page showing extracted year, month, and slug from the URL.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/PathParamsExample.php'],
        ['label' => 'View landing template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/path_params.html.twig'],
        ['label' => 'View detail template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/path_params_detail.html.twig'],
    ];

    public static function register(Via $app): void {
        // Home page
        $app->page('/examples/path-params', function (Context $c): void {
            $c->view('examples/path_params.html.twig', [
                'title' => 'Path Parameters',
                'description' => 'Dynamic routing with automatic type-cast parameter injection.',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
            ]);
        });

        // Manual access route
        $app->page('/examples/path-params/blog/{year}/{month}/{slug}', function (Context $c): void {
            $year = $c->getPathParam('year');
            $month = $c->getPathParam('month');
            $slug = $c->getPathParam('slug');

            $c->view('examples/path_params_detail.html.twig', [
                'title' => 'Path Parameters',
                'description' => 'Dynamic routing with automatic type-cast parameter injection.',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'pageTitle' => 'Blog Post (Manual Access)',
                'method' => 'manual',
                'year' => $year,
                'month' => $month,
                'slug' => $slug,
            ]);
        });

        // Auto-injection route
        $app->page('/examples/path-params/articles/{year}/{month}/{slug}', function (Context $c, string $year, string $month, string $slug): void {
            $c->view('examples/path_params_detail.html.twig', [
                'title' => 'Path Parameters',
                'description' => 'Dynamic routing with automatic type-cast parameter injection.',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'pageTitle' => 'Article (Auto-Injection)',
                'method' => 'auto',
                'year' => $year,
                'month' => $month,
                'slug' => $slug,
            ]);
        });
    }
}
