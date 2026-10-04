<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

final class TypeRaceExample {
    public const string SLUG = 'type-race';

    /** @var string[] */
    private const array SNIPPETS = [
        '$app->page(\'/hello\', function (Context $c): void {
    $name = $c->signal(\'World\', \'name\');
    $c->view(fn () => "<h1>Hello, {$name->string()}!</h1>");
});',
        'function fibonacci(int $n): int {
    if ($n <= 1) return $n;
    return fibonacci($n - 1) + fibonacci($n - 2);
}',
        '$result = array_filter(
    array_map(fn ($x) => $x ** 2, range(1, 10)),
    fn ($x) => $x % 2 === 0
);',
        'class Signal {
    public function __construct(
        private mixed $value,
        private readonly string $id,
    ) {}
}',
        'match (true) {
    $score >= 90 => \'A\',
    $score >= 80 => \'B\',
    $score >= 70 => \'C\',
    default      => \'F\',
};',
    ];

    private const int COUNTDOWN_SECONDS = 3;
    private const int MAX_RACERS_PER_RACE = 4;

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Race state machine</strong>: each race moves through <code>waiting</code>, <code>countdown</code>, <code>racing</code> and <code>done</code>. All transitions happen server-side; the client just sends keystrokes.',
        '<strong>Custom scope per race</strong> isolates each race\'s broadcasts. Multiple races can run simultaneously. Joining players are routed to an open race automatically.',
        '<strong>Progress is server-computed</strong>: the client sends only the latest typed text; the server counts matching leading characters against the snippet. No snippet logic ships to the browser.',
        '<strong>Countdown timer</strong>: an OpenSwoole <code>Timer::tick()</code> per race counts 3…2…1 and broadcasts to all racers each tick. WPM comes from the time since the start.',
        '<strong>SESSION identity</strong> gives each racer a persistent name across tabs and refreshes. Joining the same race twice from two tabs counts as two racers.',
        '<strong>Anti-cheat by design</strong>: the server holds the snippet truth and computes every progress value. Sending the wrong text just gives zero progress.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'username', 'type' => 'string', 'scope' => 'SESSION', 'desc' => 'Racer handle, auto-assigned. Persists across tabs.'],
            ['name' => 'typedText', 'type' => 'string', 'scope' => 'TAB', 'desc' => 'Current textarea value. Sent to server on every input event; never broadcast. The server clears it when a race starts.'],
        ],
        'actions' => [
            ['name' => 'updateProgress', 'desc' => 'Called on every input event. Server counts correct leading chars, updates this racer\'s progress and WPM, broadcasts to race scope.'],
            ['name' => 'startRace', 'desc' => 'Starts the countdown of the race this tab waits in.'],
            ['name' => 'newRace', 'desc' => 'Resets a finished race with a new snippet and pulls in racers waiting alone in another lobby.'],
        ],
        'views' => [
            ['name' => 'type_race.html.twig', 'desc' => 'Lobby, countdown overlay, racing textarea + progress bars, and results podium. The race\'s status, kept on the server, picks the panel.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/TypeRaceExample.php'],
        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/type_race.html.twig'],
    ];

    // ── Race state ─────────────────────────────────────────────────────────────

    /**
     * @var array<string, array{
     *   status: string,
     *   snippet: string,
     *   racers: array<string, array{name: string, progress: int, wpm: float, finished: bool, finishRank: int}>,
     *   countdownValue: int,
     *   timerId: null|int,
     *   startTime: null|int,
     *   finishCount: int,
     * }>
     */
    private static array $races = [];

    /** @var array<string, string> contextId => raceId */
    private static array $contextRace = [];

    private static int $raceCounter = 0;

    // ── Registration ───────────────────────────────────────────────────────────

    public static function register(Via $app): void {
        $app->page('/examples/type-race', function (Context $c) use ($app): void {
            $contextId = $c->getId();

            // Session-scoped username
            $usernameSignal = $c->signal('', 'username', Scope::SESSION);
            if ($usernameSignal->getValue() === '') {
                $animals = ['Cheetah', 'Falcon', 'Mamba', 'Puma', 'Viper', 'Lynx', 'Jaguar', 'Raptor'];
                $usernameSignal->setValue($animals[array_rand($animals)] . random_int(10, 99));
            }
            $username = $usernameSignal->getValue();

            // Join an open race (or create one)
            $raceId = self::joinRace($contextId, $username);
            $race = &self::$races[$raceId];
            $scope = Scope::build('example:typerace', $raceId);
            $c->addScope($scope);

            // Notify existing players in the lobby that someone just joined
            if (\count($race['racers']) > 1) {
                $app->broadcast($scope);
            }
            unset($race);

            // TAB-only input
            $c->signal('', 'typedText');

            // ── Actions ───────────────────────────────────────────────────────

            $c->action(function (Context $ctx) use ($contextId, $app): void {
                $raceId = self::currentRace($contextId);
                if ($raceId === null) {
                    return;
                }
                $raceScope = Scope::build('example:typerace', $raceId);
                $race = &self::$races[$raceId];
                if ($race['status'] !== 'racing') {
                    return;
                }
                if (!isset($race['racers'][$contextId])) {
                    return;
                }

                $text = (string) $ctx->getSignal('typedText')->getValue();
                $snippet = $race['snippet'];
                $len = \strlen($snippet);

                // Count leading correct characters
                $correct = 0;
                for ($i = 0; $i < min(\strlen($text), $len); ++$i) {
                    if ($text[$i] === $snippet[$i]) {
                        ++$correct;
                    } else {
                        break;
                    }
                }

                $progress = (int) round(($correct / $len) * 100);

                // WPM: words = chars / 5, time in minutes
                $elapsed = time() - (int) $race['startTime'];
                $wpm = $elapsed > 0 ? round(($correct / 5) / ($elapsed / 60)) : 0;

                $racer = &$race['racers'][$contextId];
                $racer['progress'] = $progress;
                $racer['wpm'] = (float) $wpm;

                if ($progress >= 100 && !$racer['finished']) {
                    $racer['finished'] = true;
                    $racer['wpm'] = (float) $wpm;
                    ++$race['finishCount'];
                    $racer['finishRank'] = $race['finishCount'];
                    self::endRaceIfAllFinished($raceId);
                }

                $app->broadcast($raceScope);
                unset($racer, $race);
            }, 'updateProgress');

            $c->action(function () use ($contextId, $app): void {
                $raceId = self::currentRace($contextId);
                if ($raceId === null) {
                    return;
                }
                $race = &self::$races[$raceId];
                if ($race['status'] !== 'waiting' || \count($race['racers']) < 1) {
                    return;
                }
                $scope = Scope::build('example:typerace', $raceId);
                unset($race);
                self::beginCountdown($raceId, $app);
                $app->broadcast($scope);
            }, 'startRace');

            $c->action(function () use ($contextId, $app): void {
                $raceId = self::currentRace($contextId);
                if ($raceId === null) {
                    return;
                }
                $race = &self::$races[$raceId];
                // Stop any running timer
                if ($race['timerId'] !== null) {
                    Timer::clear($race['timerId']);
                    $race['timerId'] = null;
                }
                // Reset all racer progress
                foreach ($race['racers'] as $id => $_) {
                    $race['racers'][$id] = self::newRacer($race['racers'][$id]['name']);
                }
                // Reset race state with a new snippet
                $race['status'] = 'waiting';
                $race['snippet'] = self::SNIPPETS[array_rand(self::SNIPPETS)];
                $race['countdownValue'] = self::COUNTDOWN_SECONDS;
                $race['startTime'] = null;
                $race['finishCount'] = 0;
                unset($race);

                // Pull in racers waiting alone in another lobby, while this race has room.
                $newScope = Scope::build('example:typerace', $raceId);
                foreach (self::$races as $otherId => $otherRace) {
                    if (\count(self::$races[$raceId]['racers']) >= self::MAX_RACERS_PER_RACE) {
                        break;
                    }
                    if ($otherId === $raceId || $otherRace['status'] !== 'waiting' || \count($otherRace['racers']) !== 1) {
                        continue;
                    }
                    $oldScope = Scope::build('example:typerace', $otherId);
                    foreach ($app->getLocalContexts($oldScope) as $ctx) {
                        $pid = $ctx->getId();
                        // Move racer data
                        self::$races[$raceId]['racers'][$pid] = self::newRacer($otherRace['racers'][$pid]['name'] ?? $pid);
                        self::$contextRace[$pid] = $raceId;
                        // Switch context to new scope so future broadcasts reach them
                        $ctx->removeScope($oldScope);
                        $ctx->addScope($newScope);
                    }
                    // Clean up the now-empty race
                    unset(self::$races[$otherId]);
                }

                $app->broadcast($newScope);
            }, 'newRace');

            // ── Cleanup ───────────────────────────────────────────────────────

            $c->onCleanup(function () use ($contextId, $app): void {
                $raceId = self::$contextRace[$contextId] ?? null;
                unset(self::$contextRace[$contextId]);
                if ($raceId === null || !isset(self::$races[$raceId])) {
                    return;
                }
                unset(self::$races[$raceId]['racers'][$contextId]);
                if (self::$races[$raceId]['racers'] === []) {
                    if (self::$races[$raceId]['timerId'] !== null) {
                        Timer::clear(self::$races[$raceId]['timerId']);
                    }
                    unset(self::$races[$raceId]);
                } else {
                    self::endRaceIfAllFinished($raceId);
                    $app->broadcast(Scope::build('example:typerace', $raceId));
                }
            });

            // ── View ──────────────────────────────────────────────────────────

            $c->view('examples/type_race.html.twig', function () use ($contextId): array {
                $raceId = self::$contextRace[$contextId] ?? '';

                return [
                    'title' => 'Type Race',
                    'perWorker' => 'the races',
                    'description' => 'Join a race and type the PHP snippet faster than the other visitors. Each race is a custom scope, and every keystroke updates progress and WPM for all racers.',
                    'summary' => self::SUMMARY,
                    'anatomy' => self::ANATOMY,
                    'githubLinks' => self::GITHUB_LINKS,
                    'raceId' => $raceId,
                    'snippet' => self::$races[$raceId]['snippet'] ?? '',
                    'status' => self::$races[$raceId]['status'] ?? 'waiting',
                    'countdownValue' => self::$races[$raceId]['countdownValue'] ?? self::COUNTDOWN_SECONDS,
                    'racers' => self::$races[$raceId]['racers'] ?? [],
                ];
            }, block: 'demo');
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function joinRace(string $contextId, string $username): string {
        // Find an open race with space
        foreach (self::$races as $raceId => $race) {
            if ($race['status'] === 'waiting' && \count($race['racers']) < self::MAX_RACERS_PER_RACE) {
                self::$races[$raceId]['racers'][$contextId] = self::newRacer($username);
                self::$contextRace[$contextId] = $raceId;

                return $raceId;
            }
        }

        // Create a new race
        $raceId = 'race-' . (++self::$raceCounter);
        self::$races[$raceId] = [
            'status' => 'waiting',
            'snippet' => self::SNIPPETS[array_rand(self::SNIPPETS)],
            'racers' => [$contextId => self::newRacer($username)],
            'countdownValue' => self::COUNTDOWN_SECONDS,
            'timerId' => null,
            'startTime' => null,
            'finishCount' => 0,
        ];
        self::$contextRace[$contextId] = $raceId;

        return $raceId;
    }

    /** Read at call time: newRace can move a racer into another race after the page mounted. */
    private static function currentRace(string $contextId): ?string {
        $raceId = self::$contextRace[$contextId] ?? null;

        return $raceId !== null && isset(self::$races[$raceId]) ? $raceId : null;
    }

    /** @return array{name: string, progress: int, wpm: float, finished: bool, finishRank: int} */
    private static function newRacer(string $username): array {
        return ['name' => $username, 'progress' => 0, 'wpm' => 0.0, 'finished' => false, 'finishRank' => 0];
    }

    private static function endRaceIfAllFinished(string $raceId): void {
        $race = &self::$races[$raceId];
        if ($race['status'] !== 'racing' || $race['racers'] === []) {
            return;
        }
        foreach ($race['racers'] as $racer) {
            if (!$racer['finished']) {
                return;
            }
        }

        $race['status'] = 'done';
        if ($race['timerId'] !== null) {
            Timer::clear($race['timerId']);
            $race['timerId'] = null;
        }
    }

    private static function beginCountdown(string $raceId, Via $app): void {
        $race = &self::$races[$raceId];
        if ($race['status'] !== 'waiting') {
            return;
        }

        $race['status'] = 'countdown';
        $race['countdownValue'] = self::COUNTDOWN_SECONDS;

        $scope = Scope::build('example:typerace', $raceId);

        $race['timerId'] = Timer::tick(1000, function () use ($raceId, $scope, $app): void {
            if (!isset(self::$races[$raceId])) {
                return;
            }
            $race = &self::$races[$raceId];

            --$race['countdownValue'];

            if ($race['countdownValue'] <= 0) {
                $race['status'] = 'racing';
                $race['startTime'] = time();
                if ($race['timerId'] !== null) {
                    Timer::clear($race['timerId']);
                    $race['timerId'] = null;
                }
                // The browser keeps the last race's text: clear it before the textarea renders. A timer
                // gets no post-action send, so syncSignals() sends it, ahead of the broadcast's render.
                // The countdown shows no input, so no stale post can overwrite this.
                foreach ($app->getLocalContexts($scope) as $ctx) {
                    $ctx->getSignal('typedText')?->setValue('');
                    $ctx->syncSignals();
                }
            }

            $app->broadcast($scope);
            unset($race);
        });
    }
}
