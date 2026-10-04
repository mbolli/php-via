<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class GameOfLifeExample {
    public const string SLUG = 'game-of-life';

    private const int BOARD_SIZE = 50;
    private const array NEIGHBORS = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
    private const array COLORS = ['red', 'blue', 'green', 'orange', 'fuchsia', 'purple'];

    /** @var array<int, string> */
    private static array $board = [];
    private static bool $running = true;
    private static int $generation = 0;
    private static int $sessionCounter = 0;

    /** @var array<string, int> */
    private static array $sessionIds = [];
    private static bool $initialized = false;

    public static function register(Via $app): void {
        self::init();

        $app->page('/examples/game-of-life', function (Context $c) use ($app): void {
            $contextId = $c->getId();
            if (!isset(self::$sessionIds[$contextId])) {
                self::$sessionIds[$contextId] = self::$sessionCounter++;
            }

            $c->onCleanup(function () use ($contextId): void {
                unset(self::$sessionIds[$contextId]);
            });

            $c->scope(Scope::ROUTE);

            $toggleRunning = $c->action(function (Context $ctx): void {
                self::$running = !self::$running;
                $ctx->broadcast();
            }, 'toggleRunning');

            $reset = $c->action(function (Context $ctx): void {
                self::$board = array_fill(0, self::BOARD_SIZE * self::BOARD_SIZE, 'dead');
                self::$generation = 0;
                $ctx->broadcast();
            }, 'reset');

            $tapCell = $c->action(function (Context $ctx): void {
                $id = $ctx->input('id');
                $sessionId = self::$sessionIds[$ctx->getId()] ?? 0;
                if ($id !== null) {
                    self::fillCross((int) $id, $sessionId);
                    if (!self::$running) {
                        self::$running = true;
                    }
                    $ctx->broadcast();
                }
            }, 'tapCell');

            $c->view('examples/game_of_life.html.twig', function () use ($toggleRunning, $reset, $tapCell, $app): array {
                $tiles = self::renderBoard();
                $generation = self::$generation;
                $running = self::$running;
                $clientCount = $app->countClients(Scope::routeScope('/examples/game-of-life'));
                $runningText = $running ? 'Pause' : 'Resume';

                return [
                    'title' => 'Game of Life',
                    'perWorker' => 'the board',
                    'description' => 'Click the board to draw crosses in your colour and watch Conway\'s Game of Life evolve them, together with everyone else on the page. A server timer advances the board every 200 ms and broadcasts the ROUTE scope, with one shared render per tick.',
                    'summary' => [
                        '<strong>Shared board</strong> via ROUTE scope: everyone on this page sees and edits the same 50×50 grid. Click to draw a cross pattern in your color.',
                        '<strong>200ms timer</strong>: a <code>$app->setInterval()</code> callback evolves the board every 200 milliseconds and broadcasts the route. With <code>shareRender: true</code> the board renders once per tick for all viewers.',
                        '<strong>2,500 divs, no problem</strong>: each tick sends all 2,500 cells as plain HTML. No diffing, no virtual DOM, no clever protocol. The "stupid" solution just works because Brotli compression over SSE is absurdly efficient: 18 seconds of continuous updates transferred only 58 KB over the wire from 15 MB of raw HTML, roughly 250× compression.',
                        '<strong>No canvas, no JS drawing</strong>: the grid is a flat CSS Grid of server-rendered <code>&lt;div&gt;</code> elements with color classes. Datastar morphs them in place. The entire rendering pipeline is PHP string concatenation.',
                        '<strong>Why this matters</strong>: frameworks that diff on the client or send fine-grained patches add complexity for marginal gains. SSE + Brotli makes the brute-force approach viable even at 5 fps with thousands of elements, which covers the vast majority of real-world UIs.',
                        '<strong>Color identity</strong>: each tab gets the next of six colors, so the crosses of different players usually look different.',
                        '<strong>Lazy timer</strong>: the timer skips its work while <code>countClients()</code> finds nobody on this page, on any worker. Re-open the page and the board resumes from where it left off.',
                        '<strong>Collaborative drawing</strong>: every click is an action that mutates the shared board, then broadcasts the result. Multiple users can sculpt patterns together in real time.',
                    ],
                    'anatomy' => [
                        'signals' => [],
                        'actions' => [
                            ['name' => 'toggleRunning', 'desc' => 'Pauses or resumes the simulation timer.'],
                            ['name' => 'reset', 'desc' => 'Clears the entire board and resets generation counter.'],
                            ['name' => 'tapCell', 'desc' => 'Draws a cross pattern at the tapped cell in the tab\'s color, and resumes a paused board.'],
                        ],
                        'views' => [
                            ['name' => 'game_of_life.html.twig', 'desc' => 'ROUTE-scoped. 2,500 divs in a CSS Grid, re-rendered every 200ms by server timer. No canvas, no JS drawing.'],
                        ],
                    ],
                    'githubLinks' => [
                        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/GameOfLifeExample.php'],
                        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/game_of_life.html.twig'],
                    ],
                    'tiles' => $tiles,
                    'generation' => $generation,
                    'clientCount' => $clientCount,
                    'runningText' => $runningText,
                    'toggleUrl' => $toggleRunning->url(),
                    'resetUrl' => $reset->url(),
                    'tapUrl' => $tapCell->url(),
                ];
            }, block: 'demo', shareRender: true);
        });

        $app->setInterval(fn () => self::tick($app), 200);
    }

    private static function tick(Via $app): void {
        if (!self::$running || $app->countClients(Scope::routeScope('/examples/game-of-life')) === 0) {
            return;
        }

        self::nextGeneration();

        if (self::isAllDead()) {
            self::$running = false;
        }

        $app->broadcast(Scope::routeScope('/examples/game-of-life'));
    }

    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::$board = array_fill(0, self::BOARD_SIZE * self::BOARD_SIZE, 'dead');
        self::$initialized = true;
    }

    private static function nextGeneration(): void {
        $size = self::BOARD_SIZE;
        $total = $size * $size;
        $next = [];

        for ($idx = 0; $idx < $total; ++$idx) {
            $row = intdiv($idx, $size);
            $col = $idx % $size;
            $cell = self::$board[$idx];

            $living = [];
            foreach (self::NEIGHBORS as [$dr, $dc]) {
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r >= 0 && $c >= 0 && $r < $size && $c < $size) {
                    $n = self::$board[$c + $r * $size];
                    if ($n !== 'dead') {
                        $living[] = $n;
                    }
                }
            }

            $count = \count($living);
            $alive = $cell !== 'dead';

            if (($alive && ($count === 2 || $count === 3)) || (!$alive && $count === 3)) {
                $next[$idx] = $living[array_rand($living)];
            } else {
                $next[$idx] = 'dead';
            }
        }

        self::$board = $next;
        ++self::$generation;
    }

    private static function isAllDead(): bool {
        foreach (self::$board as $cell) {
            if ($cell !== 'dead') {
                return false;
            }
        }

        return true;
    }

    private static function renderBoard(): string {
        $tiles = '';
        foreach (self::$board as $id => $colorClass) {
            $tiles .= "<div class=\"gol-tile gol-{$colorClass}\" data-id=\"{$id}\"></div>";
        }

        return $tiles;
    }

    private static function fillCross(int $id, int $sessionId): void {
        $size = self::BOARD_SIZE;
        $total = $size * $size;
        $color = self::COLORS[$sessionId % \count(self::COLORS)];

        foreach ([$id - $size, $id - 1, $id, $id + 1, $id + $size] as $pos) {
            if ($pos >= 0 && $pos < $total) {
                self::$board[$pos] = $color;
            }
        }
    }
}
