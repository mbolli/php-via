<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

$app = new Via(
    (new Config())
        ->withPort(3011)
        ->withDevMode(true)
);

$boardSize = 50;
$board = array_fill(0, $boardSize * $boardSize, 'dead');
$running = true;
$generation = 0;
$sessionCounter = 0;
$sessionIds = []; // contextId => color index
$colors = ['red', 'blue', 'green', 'orange', 'fuchsia', 'purple'];
$neighbors = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];

function nextGeneration(array &$board, int $size, array $neighbors): void {
    $total = $size * $size;
    $next = [];

    for ($idx = 0; $idx < $total; ++$idx) {
        $row = intdiv($idx, $size);
        $col = $idx % $size;
        $cell = $board[$idx];

        $living = [];
        foreach ($neighbors as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;
            if ($r >= 0 && $c >= 0 && $r < $size && $c < $size) {
                $n = $board[$c + $r * $size];
                if ($n !== 'dead') {
                    $living[] = $n;
                }
            }
        }

        $count = count($living);
        $alive = $cell !== 'dead';

        if (($alive && ($count === 2 || $count === 3)) || (!$alive && $count === 3)) {
            $next[$idx] = $living[array_rand($living)];
        } else {
            $next[$idx] = 'dead';
        }
    }

    $board = $next;
}

function isAllDead(array $board): bool {
    foreach ($board as $cell) {
        if ($cell !== 'dead') return false;
    }
    return true;
}

function fillCross(array &$board, int $id, int $sessionId, array $colors, int $total): void {
    $size = (int) sqrt($total);
    $color = $colors[$sessionId % count($colors)];
    foreach ([$id - $size, $id - 1, $id, $id + 1, $id + $size] as $pos) {
        if ($pos >= 0 && $pos < $total) {
            $board[$pos] = $color;
        }
    }
}

$timerId = null;

$app->page('/', function (Context $c) use ($app, &$board, &$running, &$generation, &$sessionIds, &$sessionCounter, $boardSize, $colors): void {
    $contextId = $c->getId();
    if (!isset($sessionIds[$contextId])) {
        $sessionIds[$contextId] = $sessionCounter++;
    }

    $c->onCleanup(function () use ($contextId, &$sessionIds): void {
        unset($sessionIds[$contextId]);
    });

    $c->scope(Scope::ROUTE);

    $toggleRunning = $c->action(function () use ($app, &$running): void {
        $running = !$running;
        $app->broadcast(Scope::ROUTE);
    }, 'toggleRunning');

    $reset = $c->action(function () use ($app, &$board, &$generation, $boardSize): void {
        $board = array_fill(0, $boardSize * $boardSize, 'dead');
        $generation = 0;
        $app->broadcast(Scope::ROUTE);
    }, 'reset');

    $tapCell = $c->action(function (Context $ctx) use ($app, &$board, &$running, &$sessionIds, $boardSize, $colors): void {
        $id = $_GET['id'] ?? null;
        $sessionId = $sessionIds[$ctx->getId()] ?? 0;
        if ($id !== null) {
            fillCross($board, (int) $id, $sessionId, $colors, $boardSize * $boardSize);
            if (!$running) {
                $running = true;
            }
            $app->broadcast(Scope::ROUTE);
        }
    }, 'tapCell');

    $c->view(function () use (&$board, &$running, &$generation, $boardSize, $toggleRunning, $reset, $tapCell, $app): string {
        $tiles = '';
        foreach ($board as $id => $colorClass) {
            $tiles .= "<div class=\"gol-tile gol-{$colorClass}\" data-id=\"{$id}\"></div>";
        }

        $clientCount = count($app->getClients());
        $runningText = $running ? 'Pause' : 'Resume';

        return <<<HTML
        <div id="content">
            <h1>Game of Life</h1>
            <p>Generation: {$generation} &middot; Players: {$clientCount}</p>
            <button data-on:click="@get('{$toggleRunning->url()}')">{$runningText}</button>
            <button data-on:click="@get('{$reset->url()}')">Reset</button>
            <div class="gol-board">
                {$tiles}
            </div>
        </div>
        HTML;
    });
});

$app->onStart(function () use ($app, &$board, &$running, &$generation, $boardSize, $neighbors, &$timerId): void {
    $timerId = Timer::tick(200, function () use ($app, &$board, &$running, &$generation, $boardSize, $neighbors): void {
        if (!$running || $app->getContextsByScope(Scope::routeScope('/')) === []) {
            return;
        }

        nextGeneration($board, $boardSize, $neighbors);
        ++$generation;

        if (isAllDead($board)) {
            $running = false;
        }

        $app->broadcast(Scope::ROUTE);
    });
});

$app->onShutdown(function () use (&$timerId): void {
    if ($timerId !== null) Timer::clear($timerId);
});

$app->start();
