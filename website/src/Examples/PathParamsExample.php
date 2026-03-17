<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class PathParamsExample
{
    public const string SLUG = 'path-params';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>{year}/{month}/{slug}</strong> placeholders in the route pattern are captured and passed to your handler automatically.',
        '<strong>Two access styles</strong> — call <code>$c-&gt;getPathParam()</code> manually, or let Via inject matching parameters directly into your callback\'s function signature.',
        '<strong>Reflection-based injection</strong> matches parameter names to route placeholders. Type-hint <code>int</code> and Via casts the value for you — no manual parsing required.',
    ];

    public static function register(Via $app): void
    {
        // Home page
        $app->page('/examples/path-params', function (Context $c): void {
            $c->view('examples/path_params.html.twig', [
                'title' => 'Path Parameters',
                'description' => 'Dynamic routing with automatic type-cast parameter injection.',
                'summary' => self::SUMMARY,
                'sourceFile' => 'path_params.php',
                'templateFiles' => ['path_params.html.twig', 'path_params_detail.html.twig'],
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
                'sourceFile' => 'path_params.php',
                'templateFiles' => ['path_params.html.twig', 'path_params_detail.html.twig'],
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
                'sourceFile' => 'path_params.php',
                'templateFiles' => ['path_params.html.twig', 'path_params_detail.html.twig'],
                'pageTitle' => 'Article (Auto-Injection)',
                'method' => 'auto',
                'year' => $year,
                'month' => $month,
                'slug' => $slug,
            ]);
        });
    }
}
