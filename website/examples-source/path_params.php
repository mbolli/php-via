<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3011)
        ->withDevMode(true)
);

// Home page — shows both approaches
$app->page('/', function (Context $c): void {
    $c->html('
        <div>
            <h1>🔗 Path Parameters Demo</h1>
            <p>Two ways to use path parameters: manual access or auto-injection.</p>
            <div class="flex gap-2 mt-2">
                <a href="blog/2024/12/hello-world">Manual Access →</a>
                <a href="articles/2025/01/testing">Auto-Injection →</a>
            </div>
        </div>
    ');
});

// Manual access: $c->getPathParam()
$app->page('/blog/{year}/{month}/{slug}', function (Context $c): void {
    $year = $c->getPathParam('year');
    $month = $c->getPathParam('month');
    $slug = $c->getPathParam('slug');

    $c->html("
        <div>
            <h1>📰 Blog Post (Manual)</h1>
            <pre>year: {$year}\nmonth: {$month}\nslug: {$slug}</pre>
            <a href='/'>← Back</a>
        </div>
    ");
});

// Auto-injection: parameters in function signature
$app->page(
    '/articles/{year}/{month}/{slug}',
    function (Context $c, string $year, string $month, string $slug): void {
        $c->html("
            <div>
                <h1>📰 Article (Auto-Injected)</h1>
                <pre>year: {$year}\nmonth: {$month}\nslug: {$slug}</pre>
                <a href='/'>← Back</a>
            </div>
        ");
    }
);

$app->start();
