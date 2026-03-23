<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

final class LiveAuctionExample {
    public const string SLUG = 'live-auction';

    private const string SCOPE = 'example:auction';

    private const int AUCTION_DURATION = 120; // seconds for a fresh auction
    private const int ANTISNIPE_SECONDS = 30; // extend to this if bid placed with less remaining

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Server-side countdown</strong> — the clock is an OpenSwoole <code>Timer::tick()</code> that decrements on the server every second and broadcasts to all viewers. No client-side drift, no JS timers.',
        '<strong>Anti-snipe protection</strong> — if a bid arrives with fewer than 30 seconds remaining, the clock resets to 30s. Classic auction UX, implemented in four lines of PHP.',
        '<strong>GLOBAL scope</strong> broadcasts every state change (bids, clock, sold status) to every connected viewer simultaneously, regardless of which tab or session they are on.',
        '<strong>SESSION-scoped username</strong> persists across page refreshes and new tabs, giving each bidder a consistent identity throughout the auction lifecycle.',
        '<strong>Lazy timer</strong> — the countdown only runs while at least one viewer is connected. Zero viewers means zero CPU cost; the auction resumes from saved state when someone reconnects.',
        '<strong>Full auction lifecycle</strong> — active bidding, sold state with winner banner, and a reset action to restart the auction. All state transitions happen in PHP with no client logic.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'username', 'type' => 'string', 'scope' => 'SESSION', 'desc' => 'Bidder identity, auto-assigned on first visit. Persists across tabs and refreshes.'],
            ['name' => 'bidInput', 'type' => 'string', 'scope' => 'TAB', 'desc' => 'Draft bid amount. Private to this tab — not broadcast.'],
        ],
        'actions' => [
            ['name' => 'placeBid', 'desc' => 'Validates bid > current top, updates top bid/bidder, resets clock if anti-snipe triggered, broadcasts to all viewers.'],
            ['name' => 'resetAuction', 'desc' => 'Restarts auction: resets clock, clears bids, sets status back to active. Useful for demo looping.'],
        ],
        'views' => [
            ['name' => 'live_auction.html.twig', 'desc' => 'Item card, live clock, top bid panel, bid history, and bid form. Full re-render on each broadcast via block: demo.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/LiveAuctionExample.php'],
        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/live_auction.html.twig'],
    ];

    // ── Auction state ──────────────────────────────────────────────────────────

    private static int $timeLeft = self::AUCTION_DURATION;
    private static int $topBid = 50;
    private static string $topBidder = '';
    private static string $status = 'active'; // 'active' | 'sold'

    /** @var list<array{bidder: string, amount: int, time: string}> */
    private static array $bidHistory = [];

    private static ?int $timerId = null;

    // ── Registration ───────────────────────────────────────────────────────────

    public static function register(Via $app): void {
        $app->page('/examples/live-auction', function (Context $c) use ($app): void {
            // Session-scoped identity
            $usernameSignal = $c->signal('', 'username', Scope::SESSION);
            if ($usernameSignal->getValue() === '') {
                $adjectives = ['Swift', 'Bold', 'Keen', 'Deft', 'Sage', 'Wily', 'Cool', 'Zeal'];
                $animals = ['Fox', 'Owl', 'Lynx', 'Wolf', 'Bear', 'Hawk', 'Deer', 'Hare'];
                $name = $adjectives[array_rand($adjectives)] . $animals[array_rand($animals)];
                $usernameSignal->setValue($name);
            }

            $c->addScope(self::SCOPE);

            // TAB-only bid input
            $bidInput = $c->signal((string) (self::$topBid + 10), 'bidInput');

            // ── Actions ───────────────────────────────────────────────────────

            $placeBid = $c->action(function () use (
                $bidInput,
                $usernameSignal,
                $app,
            ): void {
                if (self::$status === 'sold') {
                    return;
                }

                $amount = (int) $bidInput->getValue();
                if ($amount <= self::$topBid) {
                    return; // silently ignore invalid bids
                }

                self::$topBid = $amount;
                self::$topBidder = $usernameSignal->getValue();
                self::$bidHistory[] = [
                    'bidder' => self::$topBidder,
                    'amount' => $amount,
                    'time' => date('H:i:s'),
                ];
                // Keep last 15 entries only
                if (\count(self::$bidHistory) > 15) {
                    array_shift(self::$bidHistory);
                }

                // Anti-snipe: extend to ANTISNIPE_SECONDS if nearly expired
                if (self::$timeLeft < self::ANTISNIPE_SECONDS) {
                    self::$timeLeft = self::ANTISNIPE_SECONDS;
                }

                $app->broadcast(self::SCOPE);
            }, 'placeBid');

            $resetAuction = $c->action(function () use ($app): void {
                self::$timeLeft = self::AUCTION_DURATION;
                self::$topBid = 50;
                self::$topBidder = '';
                self::$status = 'active';
                self::$bidHistory = [];
                $app->broadcast(self::SCOPE);
                self::maybeStartTimer($app);
            }, 'resetAuction');

            // ── View ──────────────────────────────────────────────────────────

            $c->view(fn (): string => $c->render('examples/live_auction.html.twig', [
                'title' => '🔨 Live Auction',
                'description' => 'A timed auction with anti-snipe protection. Place a bid — the server clock, bid history, and winner banner update for every viewer in real time.',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'timeLeft' => self::$timeLeft,
                'topBid' => self::$topBid,
                'topBidder' => self::$topBidder,
                'status' => self::$status,
                'bidHistory' => self::$bidHistory,
                'bidInputId' => $bidInput->id(),
                'username' => $usernameSignal->getValue(),
                'placeBidUrl' => $placeBid->url(),
                'resetUrl' => $resetAuction->url(),
            ]), block: 'demo', cacheUpdates: false);

            // Start timer lazily on first viewer
            self::maybeStartTimer($app);
        });
    }

    // ── Timer ──────────────────────────────────────────────────────────────────

    public static function startTimer(Via $app): void {
        self::maybeStartTimer($app);
    }

    public static function stopTimer(): void {
        if (self::$timerId !== null) {
            Timer::clear(self::$timerId);
            self::$timerId = null;
        }
    }

    private static function maybeStartTimer(Via $app): void {
        if (self::$timerId !== null || self::$status === 'sold') {
            return;
        }
        self::$timerId = Timer::tick(1000, function () use ($app): void {
            // Stop ticking when nobody is watching
            if ($app->getContextsByScope(self::SCOPE) === []) {
                return;
            }

            if (self::$status === 'sold') {
                self::stopTimer();

                return;
            }

            --self::$timeLeft;

            if (self::$timeLeft <= 0) {
                self::$timeLeft = 0;
                self::$status = 'sold';
                self::stopTimer();
            }

            $app->broadcast(self::SCOPE);
        });
    }
}
