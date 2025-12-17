<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Swoole\Timer;

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3007)
    ->withDevMode(true)
    ->withLogLevel('debug')
    ->withShellTemplate(dirname(__DIR__) . '/templates/gameoflife.html')
    // Note: View caching is automatic! Route scope (only route actions, no signals)
    // means the framework will cache the view automatically
;

// Create the application
$app = new Via($config);

// Global shared state (shared across all clients in this worker)
class GameState {
    /** @var array<int, string> */
    public static array $board;
    public static bool $running = true;
    public static int $generation = 0;
    public static int $sessionCounter = 0;

    /** @var array<string, int> */
    public static array $sessionIds = [];
    public static bool $initialized = false;
    public static ?int $timerId = null;

    /** @var array<string, Context> */
    public static array $contexts = [];

    public static ?Via $app = null;
    public static float $startTime;

    public static function init(): void {
        if (!self::$initialized) {
            self::$board = GameOfLife::emptyBoard();
            self::$startTime = microtime(true);
            self::$initialized = true;
        }
    }

    /**
     * Render board HTML tiles.
     */
    public static function renderBoard(): string {
        $tiles = '';
        foreach (self::$board as $id => $colorClass) {
            $tiles .= "<div class=\"tile {$colorClass}\" data-id=\"{$id}\"></div>";
        }

        return $tiles;
    }

    public static function startTimer(): void {
        if (self::$timerId === null) {
            $tickCount = 0;
            self::$timerId = Timer::tick(200, function () use (&$tickCount): void {
                if (self::$running && count(self::$contexts) > 0) {
                    self::$board = GameOfLife::nextGeneration(self::$board);

                    // Auto-pause if all cells are dead
                    if (GameOfLife::isAllDead(self::$board)) {
                        self::$running = false;
                    }

                    // Update all connected clients
                    self::$app->broadcast(Scope::ROUTE);

                    // Only increment after successful broadcast
                    ++self::$generation;

                    // Log memory every 50 ticks (~10 seconds)
                    ++$tickCount;
                    if ($tickCount % 50 === 0) {
                        $memMB = round(memory_get_usage(true) / 1024 / 1024, 2);
                        $memActual = round(memory_get_usage(false) / 1024 / 1024, 2);
                        $peakMB = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

                        // Count objects/arrays in memory
                        $stats = [
                            'gen' => self::$generation,
                            'clients' => count(self::$contexts),
                            'sessions' => count(self::$sessionIds),
                            'mem_alloc' => $memMB,
                            'mem_used' => $memActual,
                            'mem_peak' => $peakMB,
                        ];
                        error_log(json_encode($stats));
                        gc_collect_cycles(); // Force garbage collection
                    }

                    // Aggressive GC every 500 generations (~100 seconds)
                    if (self::$generation % 500 === 0) {
                        gc_mem_caches();

                        // Debug snapshot
                        $snapshot = [
                            'gen' => self::$generation,
                            'contexts_count' => count(self::$contexts),
                            'sessions_count' => count(self::$sessionIds),
                            'board_size' => strlen(serialize(self::$board)),
                            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        ];
                        error_log('SNAPSHOT: ' . json_encode($snapshot));
                    }
                }
            });
        }
    }
}

// Initialize shared state
GameState::init();
GameState::$app = $app;

/**
 * Conway's Game of Life Implementation.
 *
 * This class implements the classic cellular automaton where cells evolve
 * based on their neighbors following these rules:
 * 1. Any live cell with 2-3 neighbors survives
 * 2. Any dead cell with exactly 3 neighbors becomes alive
 * 3. All other cells die or stay dead
 *
 * This multiplayer version allows multiple users to draw patterns,
 * with each user's cells displayed in a unique color.
 */
class GameOfLife {
    /** @var array<string, string> Mapping of color names to hex values */
    public const array COLOR_HEX = [
        'red' => '#ef4444',
        'blue' => '#3b82f6',
        'green' => '#22c55e',
        'orange' => '#f97316',
        'fuchsia' => '#d946ef',
        'purple' => '#a855f7',
    ];

    /** @var int Size of the square game board (50x50 = 2500 cells) */
    private const int BOARD_SIZE = 50;

    /** @var array<int, array{int, int}> Relative positions of the 8 neighboring cells */
    private const array NEIGHBORS = [
        [-1, -1], [-1, 0], [-1, 1],  // Top row
        [0, -1], /* cell */ [0, 1],   // Middle row (excluding center cell)
        [1, -1], [1, 0], [1, 1],    // Bottom row
    ];

    /** @var array<int, string> Colors assigned to different users */
    private const array COLORS = ['red', 'blue', 'green', 'orange', 'fuchsia', 'purple'];

    /**
     * Create an empty game board with all dead cells.
     *
     * @return array<int, string> Array of 2500 'dead' cells
     */
    public static function emptyBoard(): array {
        return array_fill(0, self::BOARD_SIZE * self::BOARD_SIZE, 'dead');
    }

    /**
     * Convert 2D grid coordinates to 1D array index.
     *
     * @param int $row Row position (0-49)
     * @param int $col Column position (0-49)
     *
     * @return int Array index (0-2499)
     */
    public static function coordinatesToIndex(int $row, int $col): int {
        return $col + ($row * self::BOARD_SIZE);
    }

    /**
     * Convert 1D array index to 2D grid coordinates.
     *
     * @param int $idx Array index (0-2499)
     *
     * @return array{int, int} [row, col] coordinates
     */
    public static function indexToCoordinates(int $idx): array {
        return [
            intdiv($idx, self::BOARD_SIZE),
            $idx % self::BOARD_SIZE,
        ];
    }

    /**
     * Get all valid neighbor indices for a cell at given coordinates.
     *
     * @param int $row Row position
     * @param int $col Column position
     *
     * @return array<int, int> Array of neighbor indices
     */
    public static function getNeighbors(int $row, int $col): array {
        $neighbors = [];
        foreach (self::NEIGHBORS as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;

            // Only include neighbors within board boundaries
            if ($r >= 0 && $c >= 0 && $r < self::BOARD_SIZE && $c < self::BOARD_SIZE) {
                $neighbors[] = self::coordinatesToIndex($r, $c);
            }
        }

        return $neighbors;
    }

    /**
     * Check if a cell is alive.
     *
     * @param string $cell Cell state (color name or 'dead')
     *
     * @return bool True if cell is alive
     */
    public static function isAlive(string $cell): bool {
        return $cell !== 'dead';
    }

    /**
     * Determine color for a newly born cell
     * Randomly selects from the colors of living neighbors.
     *
     * @param array<int, string> $livingNeighbors Array of neighbor colors
     *
     * @return string Color for the new cell
     */
    public static function aliveCell(array $livingNeighbors): string {
        return empty($livingNeighbors) ? 'dead' : $livingNeighbors[array_rand($livingNeighbors)];
    }

    /**
     * Apply Conway's Game of Life rules to determine next cell state.
     *
     * @param string             $cell            Current cell state
     * @param int                $neighborCount   Number of living neighbors
     * @param array<int, string> $livingNeighbors Colors of living neighbors
     *
     * @return string Next cell state
     */
    public static function cellTransition(string $cell, int $neighborCount, array $livingNeighbors): string {
        $alive = self::isAlive($cell);

        // Survival: 2-3 neighbors, or Birth: exactly 3 neighbors
        if (($alive && ($neighborCount === 2 || $neighborCount === 3))
            || (!$alive && $neighborCount === 3)) {
            return self::aliveCell($livingNeighbors);
        }

        return 'dead';
    }

    /**
     * Calculate the next generation of the board.
     *
     * @param array<int, string> $board Current board state
     *
     * @return array<int, string> Next board state
     */
    public static function nextGeneration(array $board): array {
        /** @var array<int, string> */
        $nextBoard = [];
        $size = self::BOARD_SIZE * self::BOARD_SIZE;

        // Process each cell
        for ($idx = 0; $idx < $size; ++$idx) {
            [$row, $col] = self::indexToCoordinates($idx);
            $cell = $board[$idx];

            // Get living neighbors
            $neighborIndices = self::getNeighbors($row, $col);
            $livingNeighbors = array_filter(
                array_map(fn (int $i) => $board[$i], $neighborIndices),
                fn (string $c) => self::isAlive($c)
            );

            // Apply transition rules
            $neighborCount = count($livingNeighbors);
            $nextBoard[$idx] = self::cellTransition($cell, $neighborCount, array_values($livingNeighbors));
        }

        return $nextBoard;
    }

    /**
     * Check if all cells on the board are dead.
     *
     * @param array<int, string> $board Current board state
     *
     * @return bool True if all cells are dead
     */
    public static function isAllDead(array $board): bool {
        foreach ($board as $cell) {
            if (self::isAlive($cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cell statistics by color.
     *
     * @param array<int, string> $board Current board state
     *
     * @return array<string, int> Array of color counts, sorted by count descending
     */
    public static function getCellStats(array $board): array {
        $stats = [];
        foreach ($board as $cell) {
            if (self::isAlive($cell)) {
                $stats[$cell] = ($stats[$cell] ?? 0) + 1;
            }
        }
        arsort($stats);

        return $stats;
    }

    /**
     * Fill a single cell with a color (with bounds checking).
     *
     * @param array<int, string> $board Current board state
     * @param string             $color Color to fill
     * @param int                $id    Cell index to fill
     *
     * @return array<int, string> Updated board
     */
    public static function fillCell(array $board, string $color, int $id): array {
        $size = self::BOARD_SIZE * self::BOARD_SIZE;
        if ($id >= 0 && $id < $size) {
            $board[$id] = $color;
        }

        return $board;
    }

    /**
     * Fill a cross pattern (5 cells) centered at the clicked cell
     * Pattern: top, left, center, right, bottom.
     *
     * @param array<int, string> $board     Current board state
     * @param int                $id        Center cell index
     * @param int                $sessionId User session ID (determines color)
     *
     * @return array<int, string> Updated board
     */
    public static function fillCross(array $board, int $id, int $sessionId): array {
        // Select color based on session ID
        $userColor = self::COLORS[$sessionId % count(self::COLORS)];

        // Fill cross pattern (with bounds checking in fillCell)
        $board = self::fillCell($board, $userColor, $id - self::BOARD_SIZE); // top
        $board = self::fillCell($board, $userColor, $id - 1);                // left
        $board = self::fillCell($board, $userColor, $id);                    // center
        $board = self::fillCell($board, $userColor, $id + 1);                // right

        return self::fillCell($board, $userColor, $id + self::BOARD_SIZE); // bottom
    }
}

// Register the game page
$app->page('/', function (Context $c) use ($app): void {
    // Check if we're in embed mode (must be done per-request for Swoole)
    // TAB-scoped signal (default) so each context gets its own embed state
    $embed = $c->signal(($_GET['embed'] ?? '') === '1', 'embed');

    // Register this context for global updates
    $contextId = $c->getId();
    GameState::$contexts[$contextId] = $c;

    // Clean up when connection closes
    $c->onCleanup(function () use ($contextId): void {
        unset(GameState::$contexts[$contextId], GameState::$sessionIds[$contextId]);

        $memMB = round(memory_get_usage(true) / 1024 / 1024, 2);
        error_log("CLIENT_DISCONNECT: id={$contextId}, remaining=" . count(GameState::$contexts) . ", mem={$memMB}MB");
    });

    // Start the global timer (only runs once)
    GameState::startTimer();

    // Track unique users by context ID (each browser tab gets unique context)
    $contextId = $c->getId();
    if (!isset(GameState::$sessionIds[$contextId])) {
        GameState::$sessionIds[$contextId] = GameState::$sessionCounter++;
    }

    // Set ROUTE scope - shared state across all users on this route
    $c->scope(Scope::ROUTE);

    // Note: No signals needed - we use global GameState and re-render on each broadcast
    // ROUTE scope means one cached view for all users

    // Action: Toggle the running/paused state
    $toggleRunning = $c->action(function (Context $ctx): void {
        GameState::$running = !GameState::$running;

        // Update all clients
        GameState::$app->broadcast(Scope::ROUTE);
    }, 'toggleRunning');

    // Action: Reset the board to empty state
    $reset = $c->action(function (Context $ctx): void {
        GameState::$board = GameOfLife::emptyBoard();
        GameState::$generation = 0;

        // Update all clients
        GameState::$app->broadcast(Scope::ROUTE);
    }, 'reset');

    // Action: Handle cell clicks to draw patterns
    $tapCell = $c->action(function (Context $ctx): void {
        $id = $_GET['id'] ?? null;
        // Get session ID from GameState using context ID (route actions are shared, so can't capture per-context data)
        $sessionId = GameState::$sessionIds[$ctx->getId()] ?? 0;
        error_log("Cell tapped: id={$id}, session={$sessionId}, contextId={$ctx->getId()}");
        if ($id !== null) {
            // Draw a cross pattern at clicked position
            GameState::$board = GameOfLife::fillCross(GameState::$board, (int) $id, $sessionId);

            // Resume game if it was paused
            if (!GameState::$running) {
                GameState::$running = true;
            }

            // Update all clients
            GameState::$app->broadcast(Scope::ROUTE);
        }
    }, 'tapCell');

    // Render the HTML view
    $c->view(function (bool $isUpdate) use ($toggleRunning, $reset, $tapCell, $app) {
        // Read current state directly from GameState (not from local references)
        $tiles = GameState::renderBoard();
        $generation = GameState::$generation;
        $running = GameState::$running;

        // Get profiling data
        $clients = $app->getClients();
        $stats = $app->getRenderStats();
        $clientCount = count($clients);

        // Build compact client icons
        $clientIcons = '';
        foreach (array_slice($clients, 0, 10) as $client) {
            $identicon = htmlspecialchars($client['identicon']);
            $id = htmlspecialchars($client['id']);
            $clientIcons .= "<img src=\"{$identicon}\" title=\"{$id}\" class=\"client-icon\" />";
        }
        if ($clientCount > 10) {
            $clientIcons .= '<span class="client-more">+' . ($clientCount - 10) . '</span>';
        }

        // Format stats
        $renderCount = number_format($stats['render_count']);
        $avgTime = number_format($stats['avg_time'] * 1000, 2);
        $minTime = number_format($stats['min_time'] * 1000, 2);
        $maxTime = number_format($stats['max_time'] * 1000, 2);
        $memoryMB = number_format(memory_get_usage(false) / 1024 / 1024, 2);

        // Calculate uptime
        $uptime = microtime(true) - GameState::$startTime;
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = floor($uptime % 60);
        $uptimeStr = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

        // Get cell statistics
        $cellStats = GameOfLife::getCellStats(GameState::$board);
        $totalLiveCells = array_sum($cellStats);
        $cellStatsHtml = '';
        if (!empty($cellStats)) {
            $maxCount = max($cellStats);
            $index = 0;
            foreach ($cellStats as $color => $count) {
                // Get color hex value from constant
                $bgColor = GameOfLife::COLOR_HEX[$color] ?? '#666';

                // Calculate opacity based on rank (first=1.0, gradually decreasing more aggressively)
                $opacity = 1.0 - ($index * 0.25);
                $opacity = max($opacity, 0.25); // minimum opacity

                if ($index > 0) {
                    $cellStatsHtml .= ' ';
                }
                $cellStatsHtml .= "<span class=\"cell-badge\" style=\"background:{$bgColor};opacity:{$opacity};\">{$count}</span>";
                ++$index;
            }
        } else {
            $cellStatsHtml = '<span class="no-cells">No live cells</span>';
        }

        // Dynamic button text based on state
        $runningText = $running ? 'Pause' : 'Resume';
        $runningEmoji = $running ? '⏸️' : '▶️';

        return <<<HTML
        <div class="page-layout" id="gameoflife">
            <div class="container">
                <h1>🎮 Game of Life</h1>
                <p class="subtitle">Click cells to draw patterns. Multiple users can draw simultaneously!</p>

                <div class="stats-grid">
                    <div class="stat-box clients">
                        <strong>👥 {$clientCount}</strong> {$clientIcons}
                    </div>
                    <div class="stat-box renders">
                        <strong>📊</strong> {$renderCount} renders • {$avgTime}ms avg<br>
                        <small>min: {$minTime}ms • max: {$maxTime}ms</small>
                    </div>
                    <div class="stat-box memory">
                        <strong>💾</strong> {$memoryMB} MB memory<br>
                        <small>⏱️ uptime: {$uptimeStr}</small>
                    </div>
                    <div class="stat-box cells">
                        <strong>🎨</strong> {$totalLiveCells} cells<br>
                        {$cellStatsHtml}
                    </div>
                </div>

                <p class="generation">Generation: {$generation}</p>

                <div class="controls">
                    <button data-on:click="@get('{$toggleRunning->url()}')">{$runningEmoji} {$runningText}</button>
                    <button data-on:click="@get('{$reset->url()}')">🔄 Reset</button>
                </div>
                <div class="board" data-on:pointerdown="@get('{$tapCell->url()}?id=' + event.target.dataset.id)">
                    {$tiles}
                </div>
            </div>
        </div>
        HTML;
    });
});

// Start the server
echo "Starting Via server on http://0.0.0.0:3007\n";
echo "Press Ctrl+C to stop\n";
$app->start();
