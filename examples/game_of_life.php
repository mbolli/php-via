<?php

declare(strict_types=1);

// Disable Xdebug for long-running SSE connections
ini_set('xdebug.mode', 'off');

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3000)
    ->withDocumentTitle('🎮 Via Game of Life')
    ->withDevMode(true)
    ->withLogLevel('info')
    ->withTemplateDir(__DIR__ . '/../templates');

// Create the application
$app = new Via($config);

// Global shared state (shared across all clients in this worker)
class GameState
{
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
    private static ?string $cachedBoardHtml = null;

    public static function init(): void
    {
        if (!self::$initialized) {
            self::$board = GameOfLife::emptyBoard();
            self::$initialized = true;
        }
    }

    /**
     * Get cached board HTML or render it if needed
     */
    public static function getBoardHtml(): string
    {
        if (self::$cachedBoardHtml === null) {
            self::renderBoardHtml();
        }
        return self::$cachedBoardHtml;
    }

    /**
     * Render the board HTML and cache it
     */
    private static function renderBoardHtml(): void
    {
        $tiles = '';
        foreach (self::$board as $id => $colorClass) {
            $tiles .= "<div class=\"tile {$colorClass}\" data-id=\"{$id}\"></div>";
        }
        self::$cachedBoardHtml = $tiles;
    }

    /**
     * Invalidate the board HTML cache (call when board changes)
     */
    public static function invalidateBoardCache(): void
    {
        self::$cachedBoardHtml = null;
    }

    public static function startTimer(): void
    {
        if (self::$timerId === null) {
            self::$timerId = \Swoole\Timer::tick(200, function (): void {
                if (self::$running) {
                    self::$board = GameOfLife::nextGeneration(self::$board);
                    ++self::$generation;
                    self::invalidateBoardCache();
                    
                    // Update all connected clients
                    foreach (self::$contexts as $context) {
                        $context->sync();
                    }
                }
            });
        }
    }
}

// Initialize shared state
GameState::init();

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
$app->page('/', function (Context $c): void {
    // Register this context for global updates
    GameState::$contexts[$c->getId()] = $c;
    
    // Start the global timer (only runs once)
    GameState::startTimer();
    
    // Use global shared state instead of static variables
    $board = &GameState::$board;
    $running = &GameState::$running;
    $generation = &GameState::$generation;

    // Track unique users by context ID (each browser tab gets unique context)
    $contextId = $c->getId();
    if (!isset(GameState::$sessionIds[$contextId])) {
        GameState::$sessionIds[$contextId] = GameState::$sessionCounter++;
    }
    $sessionId = GameState::$sessionIds[$contextId];

    // Create reactive signals for state synchronization with browser
    // Note: We don't create a signal for the board itself (too large)
    // Instead we re-render the view when board changes
    $runningSignal = $c->signal($running, 'running');  // Whether simulation is running

    // Action: Toggle the running/paused state
    $toggleRunning = $c->action(function () use ($runningSignal, $c): void {
        GameState::$running = !GameState::$running;
        $runningSignal->setValue(GameState::$running);
        
        // Update all clients
        foreach (GameState::$contexts as $context) {
            $context->sync();
        }
    }, 'toggleRunning');

    // Action: Reset the board to empty state
    $reset = $c->action(function (): void {
        GameState::$board = GameOfLife::emptyBoard();
        GameState::$generation = 0;
        GameState::invalidateBoardCache();
        
        // Update all clients
        foreach (GameState::$contexts as $context) {
            $context->sync();
        }
    }, 'reset');

    // Action: Handle cell clicks to draw patterns
    $tapCell = $c->action(function () use ($sessionId): void {
        $id = $_GET['id'] ?? null;
        error_log("Cell tapped: " . var_export($id, true));
        if ($id !== null) {
            // Draw a cross pattern at clicked position
            GameState::$board = GameOfLife::fillCross(GameState::$board, (int) $id, $sessionId);
            GameState::invalidateBoardCache();
            
            // Update all clients
            foreach (GameState::$contexts as $context) {
                $context->sync();
            }
        }
    }, 'tapCell');

    // Render the HTML view
    $c->view(function () use ($toggleRunning, $reset, $tapCell) {
        // Read current state directly from GameState (not from local references)
        $tiles = GameState::getBoardHtml();
        $generation = GameState::$generation;
        $running = GameState::$running;

        // Dynamic button text based on state
        $runningText = $running ? 'Pause' : 'Resume';
        $runningEmoji = $running ? '⏸️' : '▶️';

        return <<<HTML
        <div class="page-layout" id="gameoflife">
            <div class="container">
                <h1>🎮 Game of Life</h1>
                <p class="subtitle">Click cells to draw patterns. Multiple users can draw simultaneously!</p>
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

        <style>
            .page-layout {
                max-width: 900px;
                margin: 50px auto;
                padding: 20px;
                font-family: system-ui, -apple-system, sans-serif;
            }

            .container {
                width: 100%;
            }

            h1 {
                color: #333;
                margin-bottom: 10px;
            }

            p.subtitle {
                color: #666;
                font-style: italic;
                margin-bottom: 15px;
            }

            p.generation {
                font-weight: bold;
                color: #0066cc;
                font-size: 1.1em;
                margin-bottom: 15px;
            }

            .controls {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
            }

            button {
                padding: 10px 20px;
                background: #0066cc;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                font-weight: 500;
                transition: background 0.2s;
            }

            button:hover {
                background: #0052a3;
            }

            button:active {
                transform: scale(0.98);
            }

            .board {
                background: white;
                width: 100%;
                max-width: 700px;
                display: grid;
                aspect-ratio: 1/1;
                grid-template-rows: repeat(50, 1fr);
                grid-template-columns: repeat(50, 1fr);
                border: 1px solid #ddd;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .tile {
                border-bottom: 1px solid #f0f0f0;
                border-right: 1px solid #f0f0f0;
                cursor: crosshair;
                transition: background 0.3s ease;
            }

            .dead {
                background: white;
            }

            .red {
                background: #ef4444;
            }

            .blue {
                background: #3b82f6;
            }

            .green {
                background: #22c55e;
            }

            .orange {
                background: #f97316;
            }

            .fuchsia {
                background: #d946ef;
            }

            .purple {
                background: #a855f7;
            }
        </style>
        HTML;
    });
});

// Start the server
echo "Starting Via server on http://0.0.0.0:3000\n";
echo "Press Ctrl+C to stop\n";
$app->start();
