<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class StockTickerExample {
    public const string SLUG = 'stock-ticker';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Simulated market data</strong> updates every 2 seconds from a <code>$app->setInterval()</code> timer. Prices drift randomly and history is tracked for each symbol.',
        '<strong>ROUTE scope</strong> on the dashboard, with <code>shareRender: true</code>: one render per tick serves every viewer. Each detail page declares its signals in a custom scope per symbol, so each symbol updates independently.',
        '<strong>Idle when unwatched</strong>: the timer skips its work while <code>countClients()</code> finds no open dashboard or detail page on any worker.',
        '<strong>Deep linking</strong>: each stock has its own URL (<code>/stock/{symbol}</code>). Navigate directly to a ticker or click through from the dashboard.',
        '<strong>Signal-driven charts</strong> on detail pages. The timer writes the price history into the symbol\'s signals, and each write broadcasts to its viewers, so the chart updates without rendering the page again.',
        '<strong>Apache ECharts</strong> draws the chart on detail pages. Datastar writes the history signals into the chart element\'s attributes, and the element redraws when they change.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'price', 'type' => 'string', 'scope' => 'Custom', 'desc' => 'Current formatted price for a stock symbol. Custom scope per symbol.'],
            ['name' => 'times', 'type' => 'array', 'scope' => 'Custom', 'desc' => 'Timestamp array for the price history chart.'],
            ['name' => 'prices', 'type' => 'array', 'scope' => 'Custom', 'desc' => 'Price array for the chart. Updated every 2 seconds by the server timer.'],
        ],
        'actions' => [],
        'views' => [
            ['name' => 'stock_dashboard.html.twig', 'desc' => 'ROUTE-scoped dashboard with the price of all 8 stocks. One shared render per tick for all viewers.'],
            ['name' => 'stock_detail.html.twig', 'desc' => 'Per-symbol detail page with the ECharts chart. Rendered once; the signals carry every later price.'],
            ['name' => 'stock_not_found.html.twig', 'desc' => 'Fallback for unknown stock symbols.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/StockTickerExample.php'],
        ['label' => 'View dashboard template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/stock_dashboard.html.twig'],
        ['label' => 'View detail template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/stock_detail.html.twig'],
    ];

    /** @var array<string, array{name: string, price: float, history: array<array{time: int, price: float}>, color: string}> */
    private static array $stocks = [
        'AAPL' => ['name' => 'Apple Inc.', 'price' => 185.92, 'history' => [], 'color' => '#555555'],
        'GOOGL' => ['name' => 'Alphabet Inc.', 'price' => 142.58, 'history' => [], 'color' => '#4285F4'],
        'MSFT' => ['name' => 'Microsoft Corp.', 'price' => 378.91, 'history' => [], 'color' => '#00A4EF'],
        'AMZN' => ['name' => 'Amazon.com Inc.', 'price' => 178.25, 'history' => [], 'color' => '#FF9900'],
        'TSLA' => ['name' => 'Tesla Inc.', 'price' => 243.84, 'history' => [], 'color' => '#E82127'],
        'NVDA' => ['name' => 'NVIDIA Corp.', 'price' => 495.22, 'history' => [], 'color' => '#76B900'],
        'META' => ['name' => 'Meta Platforms Inc.', 'price' => 474.99, 'history' => [], 'color' => '#0668E1'],
        'NFLX' => ['name' => 'Netflix Inc.', 'price' => 672.85, 'history' => [], 'color' => '#E50914'],
    ];

    private static bool $initialized = false;

    public static function register(Via $app): void {
        self::init();

        // Dashboard
        $app->page('/examples/stock-ticker', function (Context $c): void {
            $c->scope(Scope::ROUTE);
            $c->view('examples/stock_dashboard.html.twig', fn (): array => [
                'title' => '📈 Stock Ticker',
                'description' => 'Real-time stock price simulation with live chart updates every 2 seconds.',
                'perWorker' => 'the prices',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'stocks' => self::$stocks,
            ], block: 'demo', shareRender: true);
        });

        // Individual stock page
        $app->page('/examples/stock-ticker/stock/{symbol}', function (Context $c, string $symbol): void {
            $stock = self::$stocks[$symbol] ?? null;
            if ($stock === null) {
                $c->view('examples/stock_not_found.html.twig', [
                    'title' => '📈 Stock Ticker',
                    'description' => 'Stock not found.',
                    'perWorker' => 'the prices',
                    'summary' => self::SUMMARY,
                    'anatomy' => self::ANATOMY,
                    'githubLinks' => self::GITHUB_LINKS,
                    'symbol' => $symbol,
                ]);

                return;
            }

            // The symbol's scope, so every viewer of this stock shares these signals and the timer's writes reach them.
            $stockScope = Scope::build('example:stock', $symbol);
            $c->signal(number_format($stock['price'], 2), 'price', $stockScope);
            $c->signal(self::times($stock['history']), 'times', $stockScope);
            $c->signal(self::prices($stock['history']), 'prices', $stockScope);

            // Rendered once: the signals carry every later change.
            $c->view(fn (bool $isUpdate): string => $isUpdate ? '' : $c->render('examples/stock_detail.html.twig', [
                'title' => '📈 Stock Ticker',
                'description' => $symbol . ' · ' . $stock['name'],
                'perWorker' => 'the prices',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'symbol' => $symbol,
                'name' => $stock['name'],
                'color' => $stock['color'],
                'otherSymbols' => array_values(array_diff(array_keys(self::$stocks), [$symbol])),
            ]));
        });

        $app->setInterval(fn () => self::tick($app), 2000);
    }

    private static function tick(Via $app): void {
        // Skip while nobody has the dashboard or a detail page open, on any worker.
        if ($app->countClients(Scope::routeScope('/examples/stock-ticker')) === 0 && $app->countClients('example:stock:*') === 0) {
            return;
        }

        foreach (self::$stocks as $symbol => &$stock) {
            $volatility = match ($symbol) {
                'TSLA', 'NFLX' => 0.015,
                'NVDA', 'AMZN' => 0.012,
                'AAPL', 'MSFT', 'GOOGL' => 0.008,
                default => 0.01,
            };

            $changePercent = (random_int(-1000, 1000) / 1000) * $volatility;
            $newPrice = $stock['price'] * (1 + $changePercent);
            $newPrice = max($newPrice, $stock['price'] * 0.5);
            $newPrice = min($newPrice, $stock['price'] * 2);
            $stock['price'] = $newPrice;
            $stock['history'][] = ['time' => time(), 'price' => $newPrice];

            if (\count($stock['history']) > 60) {
                array_shift($stock['history']);
            }

            // Each write broadcasts the stock's scope, which sends the new values to its detail pages.
            $scope = Scope::build('example:stock', $symbol);
            $app->getScopedSignalByName($scope, 'price')?->setValue(number_format($newPrice, 2));
            $app->getScopedSignalByName($scope, 'times')?->setValue(self::times($stock['history']));
            $app->getScopedSignalByName($scope, 'prices')?->setValue(self::prices($stock['history']));
        }
        unset($stock);

        // The dashboard reads the prices from the static array, not from signals.
        $app->broadcast(Scope::routeScope('/examples/stock-ticker'));
    }

    /**
     * @param array<array{time: int, price: float}> $history
     *
     * @return list<string>
     */
    private static function times(array $history): array {
        return array_map(static fn (array $h): string => date('H:i:s', $h['time']), $history);
    }

    /**
     * @param array<array{time: int, price: float}> $history
     *
     * @return list<float>
     */
    private static function prices(array $history): array {
        return array_map(static fn (array $h): float => $h['price'], $history);
    }

    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        foreach (self::$stocks as &$stock) {
            $time = time();
            for ($i = 59; $i >= 0; --$i) {
                $stock['history'][] = ['time' => $time - ($i * 2), 'price' => $stock['price']];
            }
        }
        self::$initialized = true;
    }
}
