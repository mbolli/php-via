<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class LiveAuctionExample {
    public const string SLUG = 'live-auction';

    private const string SCOPE = 'example:auction';

    private const int AUCTION_DURATION = 120; // seconds for a fresh auction
    private const int ANTISNIPE_SECONDS = 30; // extend to this if bid placed with less remaining

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Server-side countdown</strong>: the clock is a <code>$app->setInterval()</code> timer that decrements on the server every second and broadcasts to all viewers. No client-side drift, no JS timers.',
        '<strong>Anti-snipe protection</strong>: if a bid arrives with fewer than 30 seconds remaining, the clock resets to 30s. Classic auction UX, implemented in four lines of PHP.',
        '<strong>A custom scope</strong>, <code>example:auction</code>, carries every state change (bids, clock, sold status) to every viewer of the auction at once, whatever tab or session they are on.',
        '<strong>SESSION-scoped username</strong> persists across page refreshes and new tabs, giving each bidder a consistent identity throughout the auction lifecycle.',
        '<strong>Paused when unwatched</strong>: the countdown skips its tick while <code>countClients()</code> finds no viewer on any worker, and resumes from where it stopped when someone opens the page.',
        '<strong>Full auction lifecycle</strong>: active bidding, sold state with winner banner, and a reset action to restart the auction. All state transitions happen in PHP with no client logic.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'username', 'type' => 'string', 'scope' => 'SESSION', 'desc' => 'Bidder identity, auto-assigned on first visit. Persists across tabs and refreshes.'],
            ['name' => 'bidInput', 'type' => 'string', 'scope' => 'TAB', 'desc' => 'Draft bid amount. Private to this tab, not broadcast.'],
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
            $c->signal((string) (self::$topBid + 10), 'bidInput');

            // ── Actions ───────────────────────────────────────────────────────

            $c->action(function (Context $ctx) use ($app): void {
                if (self::$status === 'sold') {
                    return;
                }

                $amount = (int) $ctx->getSignal('bidInput')->getValue();
                if ($amount <= self::$topBid) {
                    return; // silently ignore invalid bids
                }

                self::$topBid = $amount;
                self::$topBidder = (string) $ctx->getSignal('username')->getValue();
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

            $c->action(function () use ($app): void {
                self::$timeLeft = self::AUCTION_DURATION;
                self::$topBid = 50;
                self::$topBidder = '';
                self::$status = 'active';
                self::$bidHistory = [];
                $app->broadcast(self::SCOPE);
            }, 'resetAuction');

            // ── View ──────────────────────────────────────────────────────────

            $c->view('examples/live_auction.html.twig', fn (): array => [
                'title' => 'Live Auction',
                'perWorker' => 'the auction',
                'description' => 'A timed auction with anti-snipe protection. Place a bid: the server clock, bid history, and winner banner update for every viewer in real time.',
                'summary' => self::SUMMARY,
                'anatomy' => self::ANATOMY,
                'githubLinks' => self::GITHUB_LINKS,
                'timeLeft' => self::$timeLeft,
                'topBid' => self::$topBid,
                'topBidder' => self::$topBidder,
                'status' => self::$status,
                'bidHistory' => self::$bidHistory,
                'username' => $usernameSignal->string(),
            ], block: 'demo');
        });

        $app->setInterval(fn () => self::tick($app), 1000);
    }

    // ── Timer ──────────────────────────────────────────────────────────────────

    private static function tick(Via $app): void {
        if (self::$status === 'sold' || $app->countClients(self::SCOPE) === 0) {
            return;
        }

        --self::$timeLeft;
        if (self::$timeLeft <= 0) {
            self::$timeLeft = 0;
            self::$status = 'sold';
        }

        $app->broadcast(self::SCOPE);
    }
}
