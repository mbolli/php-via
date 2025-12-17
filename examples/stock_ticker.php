<?php

/**
 * Real-time Stock Ticker Demo.
 *
 * Features:
 * - Live stock price updates with Apache ECharts
 * - Multiple stocks to choose from
 * - Real-time price history chart
 * - Automatic price simulation
 */

require 'vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Swoole\Timer;

$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3009)
    ->withLogLevel('debug')
    ->withTemplateDir(__DIR__ . '/../templates')
;

$app = new Via($config);

// Stock data management
class StockData {
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

    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        // Initialize with some history
        foreach (self::$stocks as $symbol => &$stock) {
            $time = time();
            for ($i = 59; $i >= 0; --$i) {
                $stock['history'][] = [
                    'time' => $time - ($i * 2),
                    'price' => $stock['price'],
                ];
            }
        }

        self::$initialized = true;
    }

    public static function getAll(): array {
        return self::$stocks;
    }

    public static function get(string $symbol): ?array {
        return self::$stocks[$symbol] ?? null;
    }

    public static function updatePrice(string $symbol, float $price): void {
        if (isset(self::$stocks[$symbol])) {
            self::$stocks[$symbol]['price'] = $price;
            self::$stocks[$symbol]['history'][] = [
                'time' => time(),
                'price' => $price,
            ];

            // Keep only last 60 data points (2 minutes of history)
            if (count(self::$stocks[$symbol]['history']) > 60) {
                array_shift(self::$stocks[$symbol]['history']);
            }
        }
    }

    public static function getPriceHistory(string $symbol): array {
        return self::$stocks[$symbol]['history'] ?? [];
    }
}

// Initialize stock data
StockData::init();

// =============================================================================
// Main Stock Dashboard - Shows all stocks
// =============================================================================

$app->page('/', function (Context $c): void {
    $c->view(function () use ($c): string {
        $stocks = StockData::getAll();

        $stockCards = '';
        foreach ($stocks as $symbol => $stock) {
            $stockCards .= <<<HTML
                <div class="stock-card">
                    <div class="stock-header">
                        <span class="stock-symbol">{$symbol}</span>
                        <span class="stock-name">{$stock['name']}</span>
                    </div>
                    <div class="stock-price">\${$stock['price']}</div>
                    <a href="/stock/{$symbol}" class="view-link">View Chart →</a>
                </div>
            HTML;
        }

        return $c->render('stock_dashboard.html.twig', [
            'stockCards' => $stockCards,
        ]);
    });
});

// =============================================================================
// Individual Stock Page with Real-time Chart
// =============================================================================

$app->page('/stock/{symbol}', function (Context $c, string $symbol) use ($app): void {
    // Topic-based scope - all watchers of this stock share updates
    $c->scope(Scope::build('stock', $symbol));

    // Start the background updater on first access
    static $updaterStarted = false;
    if (!$updaterStarted) {
        startStockUpdater($app);
        $updaterStarted = true;
    }

    $c->view(function (bool $isUpdate = false) use ($symbol, $c): string {
        $stock = StockData::get($symbol);

        if (!$stock) {
            return $c->render('stock_not_found.html.twig', ['symbol' => $symbol]);
        }

        $price = $stock['price'];
        $name = $stock['name'];
        $color = $stock['color'];

        // Prepare chart data
        $history = StockData::getPriceHistory($symbol);
        $times = array_map(fn ($h) => date('H:i:s', $h['time']), $history);
        $prices = array_map(fn ($h) => $h['price'], $history);

        $priceSignal = $c->signal(number_format($price, 2), 'price');
        $timesSignal = $c->signal($times, 'times');
        $pricesSignal = $c->signal($prices, 'prices');

        if ($isUpdate) {
            return '';
        }

        // Get other stocks for quick navigation
        $allStocks = StockData::getAll();
        $otherStocks = '';
        foreach ($allStocks as $sym => $st) {
            if ($sym === $symbol) {
                continue;
            }
            $otherStocks .= "<a href='/stock/{$sym}' class='stock-link'>{$sym}</a> ";
        }

        return $c->render('stock_detail.html.twig', [
            'symbol' => $symbol,
            'name' => $name,
            'color' => $color,
            'priceSignal' => $priceSignal,
            'timesSignal' => $timesSignal,
            'pricesSignal' => $pricesSignal,
            'otherStocks' => $otherStocks,
        ]);
    });
});

// =============================================================================
// Background Stock Price Updater
// =============================================================================

function startStockUpdater(Via $app): void {
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;

    echo "Starting stock price updater...\n";

    Timer::tick(2000, function () use ($app): void {
        // Update all stock prices
        $stocks = StockData::getAll();

        foreach ($stocks as $symbol => $stock) {
            $currentPrice = $stock['price'];

            // Simulate realistic price movements
            // More volatile for TSLA, NVDA; less for AAPL, MSFT
            $volatility = match ($symbol) {
                'TSLA', 'NFLX' => 0.015,  // 1.5% max change
                'NVDA', 'AMZN' => 0.012,  // 1.2% max change
                'AAPL', 'MSFT', 'GOOGL' => 0.008,  // 0.8% max change
                default => 0.01,  // 1% max change
            };

            $changePercent = (random_int(-1000, 1000) / 1000) * $volatility;
            $newPrice = $currentPrice * (1 + $changePercent);

            // Ensure price stays positive and reasonable
            $newPrice = max($newPrice, $currentPrice * 0.5);
            $newPrice = min($newPrice, $currentPrice * 2);

            StockData::updatePrice($symbol, $newPrice);

            // Broadcast to all contexts watching this stock
            $app->broadcast(Scope::build('stock', $symbol));
        }
    });
}

echo "Starting Stock Ticker Demo on http://0.0.0.0:3009\n";
echo "Dashboard: http://0.0.0.0:3009/\n";
echo "Stock prices update every 2 seconds\n";

$app->start();
