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
    '<title>Path Parameters Demo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            padding: 2rem;
            max-width: 900px;
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
        .badge {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.85rem;
            margin-left: 0.5rem;
        }
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
        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }
        .old-way, .new-way {
            padding: 1rem;
            border-radius: 8px;
        }
        .old-way {
            background: #ffe0e0;
            border: 2px solid #ff9999;
        }
        .new-way {
            background: #e0ffe0;
            border: 2px solid #99ff99;
        }
        .section {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f9f9f9;
            border-radius: 8px;
            border-left: 4px solid #667eea;
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
                You can access them either manually or with automatic injection!
            </p>

            <div class="section">
                <h2>✨ Two Ways to Use Path Parameters:</h2>
                <div class="comparison">
                    <div class="old-way">
                        <h3>Manual Access</h3>
                        <div class="code-example">
                            <code>
$via->page(\'/users/{username}\',<br>
&nbsp;&nbsp;function (Context $c) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;$name = $c->getPathParam(\'username\');<br>
&nbsp;&nbsp;}<br>
);
                            </code>
                        </div>
                    </div>
                    <div class="new-way">
                        <h3>✅ Auto-Injection</h3>
                        <div class="code-example">
                            <code>
$via->page(\'/users/{username}\',<br>
&nbsp;&nbsp;function (Context $c, string $username) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;// $username auto-injected!<br>
&nbsp;&nbsp;}<br>
);
                            </code>
                        </div>
                    </div>
                </div>
            </div>

            <h2>Manual Access Examples:</h2>
            <div class="nav-links">
                <a href="/users/alice">User: alice</a>
                <a href="/posts/123">Post: 123</a>
                <a href="/blog/2024/12/hello-world">Blog: 2024/12/hello-world</a>
                <a href="/products/laptop/reviews">Product Reviews: laptop</a>
            </div>

            <h2>Auto-Injection Examples:</h2>
            <div class="nav-links">
                <a href="/profile/bob">Profile: bob (injected)</a>
                <a href="/articles/2025/01/testing">Article (3 params injected)</a>
                <a href="/items/smartphone/specs">Item Specs (injected)</a>
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

// ============================================================================
// AUTO-INJECTION EXAMPLES (Parameters injected directly into function signature)
// ============================================================================

// Example 1: Single parameter auto-injection
$via->page('/profile/{username}', function (Context $c, string $username): void {
    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>👤 User Profile <span class=\"badge\">Auto-injected</span></h1>

            <div class=\"param-display\">
                <span class=\"param-name\">username:</span>
                <span class=\"param-value\">{$username}</span>
            </div>

            <h2>Code for this route:</h2>
            <div class=\"code-example\">
                <code>
\$via->page('/profile/{username}', <br>
&nbsp;&nbsp;function (Context \$c, string \$username) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;// \$username is automatically injected!<br>
&nbsp;&nbsp;&nbsp;&nbsp;\$c->view(fn() => \"Hello {\$username}!\");<br>
&nbsp;&nbsp;}<br>
);
                </code>
            </div>

            <p class=\"description\">
                Notice how <code>\$username</code> is automatically populated from the URL
                without calling <code>\$c->getPathParam()</code>! Just add it to the function
                signature and it's automatically injected by name.
            </p>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/profile/charlie\">Try another profile</a>
                <a href=\"/users/alice\">Compare with manual method</a>
            </div>
        </div>
        ");
});

// Example 2: Multiple parameters auto-injection
$via->page('/articles/{year}/{month}/{slug}', function (Context $c, string $year, string $month, string $slug): void {
    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>📰 Article <span class=\"badge\">3 params auto-injected</span></h1>

            <div class=\"param-display\">
                <span class=\"param-name\">year:</span> <span class=\"param-value\">{$year}</span><br>
                <span class=\"param-name\">month:</span> <span class=\"param-value\">{$month}</span><br>
                <span class=\"param-name\">slug:</span> <span class=\"param-value\">{$slug}</span>
            </div>

            <h2>Code for this route:</h2>
            <div class=\"code-example\">
                <code>
\$via->page('/articles/{year}/{month}/{slug}',<br>
&nbsp;&nbsp;function (Context \$c, string \$year, string \$month, string \$slug) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;// All three parameters are auto-injected!<br>
&nbsp;&nbsp;&nbsp;&nbsp;// No need for multiple \$c->getPathParam() calls<br>
&nbsp;&nbsp;}<br>
);
                </code>
            </div>

            <h2>Comparison with manual method:</h2>
            <div class=\"comparison\">
                <div class=\"old-way\">
                    <h3>Manual (verbose)</h3>
                    <div class=\"code-example\">
                        <code>
\$year = \$c->getPathParam('year');<br>
\$month = \$c->getPathParam('month');<br>
\$slug = \$c->getPathParam('slug');
                        </code>
                    </div>
                </div>
                <div class=\"new-way\">
                    <h3>Auto-injected (clean)</h3>
                    <div class=\"code-example\">
                        <code>
function(Context \$c,<br>
&nbsp;&nbsp;string \$year,<br>
&nbsp;&nbsp;string \$month,<br>
&nbsp;&nbsp;string \$slug)
                        </code>
                    </div>
                </div>
            </div>

            <p class=\"description\">
                All three parameters are automatically injected based on their names.
                Much cleaner and more readable than calling getPathParam() multiple times!
            </p>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/articles/2025/12/new-features\">Try another article</a>
                <a href=\"/blog/2024/12/hello-world\">Compare with manual method</a>
            </div>
        </div>
        ");
});

// Example 3: Parameters matched by name (order doesn't matter)
$via->page('/items/{item_id}/specs', function (Context $c, string $item_id): void {
    $c->view(fn (): string => "
        <div class=\"container\">
            <h1>📋 Item Specifications <span class=\"badge\">Auto-injected</span></h1>

            <div class=\"param-display\">
                <span class=\"param-name\">item_id:</span>
                <span class=\"param-value\">{$item_id}</span>
            </div>

            <h2>Code for this route:</h2>
            <div class=\"code-example\">
                <code>
\$via->page('/items/{item_id}/specs',<br>
&nbsp;&nbsp;function (Context \$c, string \$item_id) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;// \$item_id auto-injected by name!<br>
&nbsp;&nbsp;}<br>
);
                </code>
            </div>

            <p class=\"description\">
                Parameters are matched by <strong>name</strong>, not position. This means
                you can list them in any order in your function signature, and they'll be
                correctly matched to the route parameters. The Context parameter can be
                anywhere in the signature too!
            </p>

            <div class=\"nav-links\">
                <a href=\"/\">← Back to Home</a>
                <a href=\"/items/laptop/specs\">Laptop Specs</a>
                <a href=\"/products/smartphone/reviews\">Compare with manual method</a>
            </div>
        </div>
        ");
});

echo "🚀 Path Parameters demo running on http://127.0.0.1:8080\n";
echo "   Manual access: http://127.0.0.1:8080/users/alice\n";
echo "   Auto-injection: http://127.0.0.1:8080/profile/bob\n";
echo "\n";
echo "💡 This demo shows BOTH ways to access path parameters:\n";
echo "   1. Manual: \$c->getPathParam('name')\n";
echo "   2. Auto-injection: function(Context \$c, string \$name)\n";

$via->start();
