<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

final class StockTickerExample {
    public const string SLUG = 'stock-ticker';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Simulated market data</strong> updates every 2 seconds via a server-side OpenSwoole timer. Prices drift randomly and history is tracked for each symbol.',
        '<strong>ROUTE scope</strong> on the dashboard means all viewers share the same rendered output. Per-stock detail pages use custom scopes so each symbol updates independently.',
        '<strong>Lazy timers</strong> — the price ticker only runs while at least one client is connected. Zero viewers means zero CPU cost.',
        '<strong>Deep linking</strong> — each stock has its own URL (<code>/stock/{symbol}</code>). Navigate directly to a ticker or click through from the dashboard.',
        '<strong>Signal-driven charts</strong> on detail pages. Price history is stored in signals so the chart data updates live without re-rendering the entire page.',
        '<strong>Apache ECharts</strong> renders sparklines on the dashboard and the full chart on detail pages. The chart library receives updated data via Datastar signals — no manual JS refresh needed.',
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
            ['name' => 'stock_dashboard.html.twig', 'desc' => 'ROUTE-scoped dashboard with sparklines for all 8 stocks. Shared by all viewers.'],
            ['name' => 'stock_detail.html.twig', 'desc' => 'Per-symbol detail page with full ECharts chart. Custom scope per stock symbol.'],
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
    private static ?int $timerId = null;

    public static function register(Via $app): void {
        self::init();

        // Dashboard
        $app->page('/examples/stock-ticker', function (Context $c): void {
            $c->scope(Scope::ROUTE);
            $c->view(fn (): string => $c->render('examples/stock_dashboard.html.twig', [
                'title' => '📈 Stock Ticker',
                'description' => 'Real-time stock price simulation with live chart updates every 2 seconds.',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'stocks' => self::$stocks,
            ]));
        });

        // Individual stock page
        $app->page('/examples/stock-ticker/stock/{symbol}', function (Context $c, string $symbol): void {
            $c->scope(Scope::build('example:stock', $symbol));

            $c->view(function (bool $isUpdate, string $basePath) use ($symbol, $c): string {
                $stock = self::$stocks[$symbol] ?? null;

                if (!$stock) {
                    return $c->render('examples/stock_not_found.html.twig', [
                        'title' => '📈 Stock Ticker',
                        'description' => 'Stock not found.',
                        'summary' => self::SUMMARY,
                        'anatomy' => self::ANATOMY,
                        'githubLinks' => self::GITHUB_LINKS,
                        'symbol' => $symbol,
                    ]);
                }

                $price = $stock['price'];
                $history = $stock['history'];
                $times = array_map(fn (array $h) => date('H:i:s', $h['time']), $history);
                $prices = array_map(fn (array $h) => $h['price'], $history);

                $priceSignal = $c->signal(number_format($price, 2), 'price');
                $timesSignal = $c->signal($times, 'times');
                $pricesSignal = $c->signal($prices, 'prices');

                if ($isUpdate) {
                    return '';
                }

                $allStocks = self::$stocks;
                $otherStocks = '';
                foreach ($allStocks as $sym => $st) {
                    if ($sym === $symbol) {
                        continue;
                    }
                    $otherStocks .= "<a href='{$basePath}examples/stock-ticker/stock/{$sym}' style='display:inline-block;padding:var(--size-1) var(--size-3);background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-md);margin:2px;font-weight:var(--font-weight-6);text-decoration:none;'>{$sym}</a> ";
                }

                return $c->render('examples/stock_detail.html.twig', [
                    'title' => '📈 Stock Ticker',
                    'description' => $symbol . ' — ' . $stock['name'],
                    'summary' => self::SUMMARY,
                    'anatomy' => self::ANATOMY,
                    'githubLinks' => self::GITHUB_LINKS,
                    'symbol' => $symbol,
                    'name' => $stock['name'],
                    'color' => $stock['color'],
                    'priceSignal' => $priceSignal,
                    'timesSignal' => $timesSignal,
                    'pricesSignal' => $pricesSignal,
                    'otherStocks' => $otherStocks,
                ]);
            }, cacheUpdates: false);
        });
    }

    public static function startTimer(Via $app): void {
        self::$timerId = Timer::tick(2000, function () use ($app): void {
            // Skip if nobody is watching any stock ticker page
            if ($app->getContextsByScope(Scope::routeScope('/examples/stock-ticker')) === []) {
                $hasDetailViewers = false;
                foreach (array_keys(self::$stocks) as $symbol) {
                    if ($app->getContextsByScope(Scope::build('example:stock', $symbol)) !== []) {
                        $hasDetailViewers = true;

                        break;
                    }
                }
                if (!$hasDetailViewers) {
                    return;
                }
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

                $scope = Scope::build('example:stock', $symbol);
                $history = $stock['history'];
                $times = array_map(fn (array $h) => date('H:i:s', $h['time']), $history);
                $prices = array_map(fn (array $h) => $h['price'], $history);

                $priceSignal = $app->getScopedSignal($scope, 'example_stock_' . $symbol . '_price');
                $priceSignal?->setValue(number_format($newPrice, 2), markChanged: true, broadcast: false);

                $timesSignal = $app->getScopedSignal($scope, 'example_stock_' . $symbol . '_times');
                $timesSignal?->setValue($times, markChanged: true, broadcast: false);

                $pricesSignal = $app->getScopedSignal($scope, 'example_stock_' . $symbol . '_prices');
                $pricesSignal?->setValue($prices, markChanged: true, broadcast: false);

                $app->broadcast(Scope::build('example:stock', $symbol));
            }
            unset($stock);

            $app->broadcast(Scope::routeScope('/examples/stock-ticker'));
        });
    }

    public static function stopTimer(): void {
        if (self::$timerId !== null) {
            Timer::clear(self::$timerId);
            self::$timerId = null;
        }
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
