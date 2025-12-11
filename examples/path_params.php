<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$config = new Config();
$config = $config->withHost('127.0.0.1');
$config = $config->withPort(8080);
$config = $config->withLogLevel('info');

$via = new Via($config);

// Add simple styling
$via->appendToHead(
    '<style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            padding: 2rem;
            max-width: 800px;
            margin: 0 auto;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { color: #333; margin-bottom: 1.5rem; }
        h2 { color: #555; margin-top: 2rem; margin-bottom: 1rem; font-size: 1.3rem; }
        .param-display {
            background: #f0f0f0;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-family: monospace;
        }
        .param-name { color: #667eea; font-weight: bold; }
        .param-value { color: #764ba2; font-weight: bold; }
        .nav-links {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        .nav-links a {
            padding: 0.5rem 1rem;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .nav-links a:hover {
            background: #764ba2;
        }
        .description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 1rem;
        }
        .code-example {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1rem 0;
        }
        .code-example code {
            font-family: "Courier New", monospace;
            font-size: 0.9rem;
        }
    </style>'
);

// Home page
$via->page('/', function (Context $c): void {
    $c->view(fn (): string => '
        <div class="container">
            <h1>🔗 Path Parameters Demo</h1>
            <p class="description">
                This demo shows how to use dynamic path parameters in your routes.
                Click the links below to see different examples.
            </p>

            <h2>Available Routes:</h2>
            <div class="nav-links">
                <a href="/users/alice">User: alice</a>
                <a href="/users/bob">User: bob</a>
                <a href="/posts/123">Post: 123</a>
                <a href="/posts/456">Post: 456</a>
                <a href="/blog/2024/12/hello-world">Blog: 2024/12/hello-world</a>
                <a href="/products/laptop/reviews">Product Reviews: laptop</a>
            </div>

            <h2>Route Definitions:</h2>
            <div class="code-example">
                <code>
$via->page(\'/users/{username}\', ...)<br>
$via->page(\'/posts/{post_id}\', ...)<br>
$via->page(\'/blog/{year}/{month}/{slug}\', ...)<br>
$via->page(\'/products/{product_id}/reviews\', ...)
                </code>
            </div>
        </div>
        ');
});

// User profile route with single parameter
$via->page('/users/{username}', function (Context $c): void {
    $username = $c->getPathParam('username');

    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>👤 User Profile</h1>
            <div class=\"param-display\">
                <span class=\"param-name\">username:</span>
                <span class=\"param-value\">{$username}</span>
            </div>

            <p class=\"description\">
                This route demonstrates a single path parameter. The route pattern
                is <code>/users/{username}</code> and the parameter is extracted
                using <code>\$c->getPathParam('username')</code>.
            </p>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/users/charlie\">Try another user</a>
            </div>
        </div>
        ");
});

// Post detail route with single parameter
$via->page('/posts/{post_id}', function (Context $c): void {
    $postId = $c->getPathParam('post_id');

    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>📝 Post Details</h1>
            <div class=\"param-display\">
                <span class=\"param-name\">post_id:</span>
                <span class=\"param-value\">{$postId}</span>
            </div>

            <p class=\"description\">
                This is a simple route with one parameter that could represent a post ID.
                You could use this to load post data from a database.
            </p>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/posts/789\">Try another post</a>
            </div>
        </div>
        ");
});

// Blog post route with multiple parameters
$via->page('/blog/{year}/{month}/{slug}', function (Context $c): void {
    $year = $c->getPathParam('year');
    $month = $c->getPathParam('month');
    $slug = $c->getPathParam('slug');

    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>📰 Blog Post</h1>
            <div class=\"param-display\">
                <span class=\"param-name\">year:</span> <span class=\"param-value\">{$year}</span><br>
                <span class=\"param-name\">month:</span> <span class=\"param-value\">{$month}</span><br>
                <span class=\"param-name\">slug:</span> <span class=\"param-value\">{$slug}</span>
            </div>

            <p class=\"description\">
                This route demonstrates multiple path parameters working together.
                Perfect for creating SEO-friendly blog URLs with date-based structure.
            </p>

            <div class=\"code-example\">
                <code>
// Extract multiple parameters:<br>
\$year = \$c->getPathParam('year');<br>
\$month = \$c->getPathParam('month');<br>
\$slug = \$c->getPathParam('slug');
                </code>
            </div>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/blog/2025/01/new-year-post\">Try another blog post</a>
            </div>
        </div>
        ");
});

// Product reviews route (parameter + static segment)
$via->page('/products/{product_id}/reviews', function (Context $c): void {
    $productId = $c->getPathParam('product_id');

    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>⭐ Product Reviews</h1>
            <div class=\"param-display\">
                <span class=\"param-name\">product_id:</span>
                <span class=\"param-value\">{$productId}</span>
            </div>

            <p class=\"description\">
                This route combines a path parameter with a static segment (/reviews).
                The pattern <code>/products/{product_id}/reviews</code> only matches
                URLs that end with /reviews.
            </p>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/products/smartphone/reviews\">Smartphone Reviews</a>
                <a href=\"/products/headphones/reviews\">Headphones Reviews</a>
            </div>
        </div>
        ");
});

echo "🚀 Path Parameters demo running on http://127.0.0.1:8080\n";
echo "   Try: http://127.0.0.1:8080/users/alice\n";
echo "   Try: http://127.0.0.1:8080/blog/2024/12/hello-world\n";

$via->start();
