<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$config = new Config();
$config = $config->withHost('0.0.0.0');
$config = $config->withPort(3011);
$config = $config->withLogLevel('info');

$via = new Via($config);

// Add simple styling
$via->appendToHead(
    '<title>Path Parameters Demo</title>
    <link rel="stylesheet" href="/_via.css">
    <style>
        body {
            padding: 2rem;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 { margin-bottom: 1.5rem; }
        h2 { margin-top: 2rem; margin-bottom: 1rem; font-size: 1.3rem; }
        h3 { font-size: 1.1rem; margin-bottom: 0.75rem; }
        .param-display {
            background: var(--color-light);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1rem 0;
            font-family: monospace;
            border: var(--border-width) solid var(--color-primary);
        }
        .param-name { color: var(--color-primary); font-weight: bold; }
        .param-value { color: var(--color-secondary); font-weight: bold; }
        .nav-links {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        .description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 1rem;
        }
        .code-example {
            background: var(--color-dark);
            color: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            overflow-x: auto;
            margin: 1rem 0;
            border: var(--border-width) solid var(--color-dark-subtle);
        }
        .code-example code {
            font-family: "Courier New", monospace;
            font-size: 0.9rem;
            color: var(--color-light);
        }
        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }
        .old-way, .new-way {
            padding: 1rem;
            border-radius: var(--border-radius);
        }
        .old-way {
            background: #ffe0e0;
            border: var(--border-width) solid var(--color-danger);
        }
        .new-way {
            background: #e0ffe0;
            border: var(--border-width) solid var(--color-success);
        }
        .section {
            margin: 2rem 0;
        }
        @media (max-width: 768px) {
            .comparison {
                grid-template-columns: 1fr;
            }
        }
    </style>'
);

// Home page
$via->page('/', function (Context $c): void {
    $c->view(fn (): string => '
        <div class="container">
            <h1>🔗 Path Parameters Demo</h1>

            <div class="card">
                <p class="description">
                    This demo shows how to use dynamic path parameters in your routes.
                    You can access them either manually or with automatic injection!
                </p>
            </div>

            <div class="card section">
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

            <div class="card">
                <h2>📋 Try It Out:</h2>
                <div class="nav-links">
                    <a href="/blog/2024/12/hello-world">Manual Access Example →</a>
                    <a href="/articles/2025/01/testing">Auto-Injection Example →</a>
                </div>
            </div>
        </div>
        ');
});

// Blog post route with multiple parameters - MANUAL ACCESS
$via->page('/blog/{year}/{month}/{slug}', function (Context $c): void {
    $year = $c->getPathParam('year');
    $month = $c->getPathParam('month');
    $slug = $c->getPathParam('slug');

    $c->view(fn (): string => "
        <div class=\"container\">
            <div class=\"card\">
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
                <a href=\"/articles/2025/01/testing\">Compare with auto-injection →</a>
            </div>
            </div>
        </div>
        ");
});

// ============================================================================
// AUTO-INJECTION EXAMPLE (Parameters injected directly into function signature)
// ============================================================================

// Multiple parameters auto-injection
$via->page('/articles/{year}/{month}/{slug}', function (Context $c, string $year, string $month, string $slug): void {
    $c->view(fn (): string => "
        <div class=\"container\">
            <div class=\"card\">
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
                <a href=\"/blog/2024/12/hello-world\">← Compare with manual method</a>
            </div>
            </div>
        </div>
        ");
});

echo "🚀 Path Parameters demo running on http://127.0.0.1:3011\n";
echo "   Manual access: http://127.0.0.1:3011/blog/2024/12/hello-world\n";
echo "   Auto-injection: http://127.0.0.1:3011/articles/2025/01/testing\n";
echo "\n";
echo "💡 This demo shows BOTH ways to access path parameters:\n";
echo "   1. Manual: \$c->getPathParam('name')\n";
echo "   2. Auto-injection: function(Context \$c, string \$name)\n";

$via->start();
