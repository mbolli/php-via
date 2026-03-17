<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Swoole\Timer;

$app = new Via(
    (new Config())
        ->withPort(3009)
        ->withDevMode(true)
        ->withTemplateDir(__DIR__ . '/templates')
);

// In-memory stock data
$stocks = [
    'AAPL'  => ['name' => 'Apple Inc.',          'price' => 185.92, 'history' => [], 'color' => '#555555'],
    'GOOGL' => ['name' => 'Alphabet Inc.',       'price' => 142.58, 'history' => [], 'color' => '#4285F4'],
    'MSFT'  => ['name' => 'Microsoft Corp.',     'price' => 378.91, 'history' => [], 'color' => '#00A4EF'],
    'AMZN'  => ['name' => 'Amazon.com Inc.',     'price' => 178.25, 'history' => [], 'color' => '#FF9900'],
    'TSLA'  => ['name' => 'Tesla Inc.',          'price' => 243.84, 'history' => [], 'color' => '#E82127'],
    'NVDA'  => ['name' => 'NVIDIA Corp.',        'price' => 495.22, 'history' => [], 'color' => '#76B900'],
    'META'  => ['name' => 'Meta Platforms Inc.', 'price' => 474.99, 'history' => [], 'color' => '#0668E1'],
    'NFLX'  => ['name' => 'Netflix Inc.',        'price' => 672.85, 'history' => [], 'color' => '#E50914'],
];

// Seed 60 history points per stock
$t = time();
foreach ($stocks as &$s) {
    for ($i = 59; $i >= 0; --$i) {
        $s['history'][] = ['time' => $t - $i * 2, 'price' => $s['price']];
    }
}
unset($s);

// Dashboard — all stocks with live prices
$app->page('/', function (Context $c) use (&$stocks): void {
    $c->scope(Scope::ROUTE);
    $c->view(fn () => $c->render('stock_dashboard.html.twig', [
        'stocks' => $stocks,
    ]));
});

// Individual stock with chart (Scope::build creates topic-scoped groups)
$app->page('/stock/{symbol}', function (Context $c, string $symbol) use (&$stocks): void {
    $stock = $stocks[$symbol] ?? null;
    $c->scope(Scope::build('stock', $symbol));

    if (!$stock) {
        $c->view(fn () => '<p>Stock not found: ' . htmlspecialchars($symbol) . '</p>');
        return;
    }

    $history = $stock['history'];
    $times = array_map(fn (array $h) => date('H:i:s', $h['time']), $history);
    $prices = array_map(fn (array $h) => $h['price'], $history);

    $priceSignal  = $c->signal(number_format($stock['price'], 2), 'price');
    $timesSignal  = $c->signal($times, 'times');
    $pricesSignal = $c->signal($prices, 'prices');

    $c->view(function (bool $isUpdate, string $basePath) use ($c, $symbol, $stock, $stocks, $priceSignal, $timesSignal, $pricesSignal): string {
        if ($isUpdate) {
            return ''; // signals already updated by timer
        }

        // Build navigation links to other stocks
        $otherStocks = '';
        foreach ($stocks as $sym => $st) {
            if ($sym === $symbol) {
                continue;
            }
            $otherStocks .= "<a href='{$basePath}stock/{$sym}' class='stock-cta'>{$sym}</a> ";
        }

        return $c->render('stock_detail.html.twig', [
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

// Background timer — updates prices every 2 s, broadcasts to watchers
$timerId = null;
$app->onStart(function () use ($app, &$stocks, &$timerId): void {
    $timerId = Timer::tick(2000, function () use ($app, &$stocks): void {
        // Lazy: skip when nobody is watching
        if ($app->getContextsByScope(Scope::routeScope('/')) === []) {
            $hasDetailViewers = false;
            foreach (array_keys($stocks) as $sym) {
                if ($app->getContextsByScope(Scope::build('stock', $sym)) !== []) {
                    $hasDetailViewers = true;
                    break;
                }
            }
            if (!$hasDetailViewers) return;
        }

        foreach ($stocks as $sym => &$s) {
            $volatility = match ($sym) {
                'TSLA', 'NFLX' => 0.015,
                'NVDA', 'AMZN' => 0.012,
                'AAPL', 'MSFT', 'GOOGL' => 0.008,
                default => 0.01,
            };
            $change = (random_int(-1000, 1000) / 1000) * $volatility;
            $newPrice = $s['price'] * (1 + $change);
            $newPrice = max($newPrice, $s['price'] * 0.5);
            $newPrice = min($newPrice, $s['price'] * 2);
            $s['price'] = $newPrice;
            $s['history'][] = ['time' => time(), 'price' => $newPrice];
            if (count($s['history']) > 60) array_shift($s['history']);

            $app->broadcast(Scope::build('stock', $sym));
        }
        unset($s);

        $app->broadcast(Scope::routeScope('/'));
    });
});
$app->onShutdown(function () use (&$timerId): void {
    if ($timerId) Timer::clear($timerId);
});

$app->start();
