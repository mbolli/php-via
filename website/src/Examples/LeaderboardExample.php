<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Broadcast;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * A shared, ranked list of pizza toppings. Every change broadcasts to the route, and the view
 * asks for a view transition, so rows glide to their new rank in every open tab at once.
 */
#[Broadcast(Scope::ROUTE)]
final class LeaderboardExample {
    public const string ROUTE = '/examples/leaderboard';

    private const int MAX_ITEMS = 12;
    private const int MAX_NAME_LENGTH = 24;
    private const int MAX_VOTES = 999;

    /** @var array<string, string> sort key => button label */
    private const array SORTS = ['votes' => 'Votes', 'name' => 'Name', 'newest' => 'Newest'];

    /** @var list<array{id: int, name: string, votes: int}> */
    private const array SEED = [
        ['id' => 1, 'name' => 'Mozzarella', 'votes' => 14],
        ['id' => 2, 'name' => 'Basil', 'votes' => 11],
        ['id' => 3, 'name' => 'Mushrooms', 'votes' => 9],
        ['id' => 4, 'name' => 'Pepperoni', 'votes' => 8],
        ['id' => 5, 'name' => 'Olives', 'votes' => 6],
        ['id' => 6, 'name' => 'Pineapple', 'votes' => 5],
        ['id' => 7, 'name' => 'Jalapenos', 'votes' => 3],
        ['id' => 8, 'name' => 'Anchovies', 'votes' => 2],
    ];

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>viewTransition: true</strong> on <code>view()</code> makes Datastar apply every update render inside <code>document.startViewTransition()</code>. The render on page load and on SSE connect has none.',
        '<strong>A view-transition-name per row</strong>, <code>lb-{id}</code>, lets the browser match each row before and after the update and move it to its new place. Rows without a partner slide in or fade out.',
        '<strong>#[Broadcast(Scope::ROUTE)]</strong> sends each vote, new topping and removal to every tab on this page, so all of them animate the same change at the same time.',
        '<strong>Sort per tab</strong>: <code>sort</code> is a TAB <code>#[Signal]</code>, so your order stays yours while the votes stay shared. Switching it animates too.',
        '<strong>One render per 400 ms</strong>: a new transition skips the one still running, so a burst of votes would jump instead of glide. <code>withBroadcastThrottle()</code> for this route\'s scope, set in the site\'s config, lets each transition finish. While one runs, its overlay takes the clicks.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'sort', 'type' => 'string', 'scope' => 'TAB', 'default' => 'votes', 'desc' => 'The order this tab shows: votes, name or newest.'],
            ['name' => 'draft', 'type' => 'string', 'scope' => 'TAB', 'default' => '', 'desc' => 'The topping you are typing. Private to this tab.'],
        ],
        'actions' => [
            ['name' => 'vote', 'desc' => 'Adds a vote to the topping in ?id= and broadcasts the list.'],
            ['name' => 'add', 'desc' => 'Adds the draft as a new topping, up to 12 toppings of at most 24 characters, and broadcasts.'],
            ['name' => 'remove', 'desc' => 'Removes the topping in ?id= and broadcasts.'],
            ['name' => 'reset', 'desc' => 'Puts back the eight starting toppings and their votes, and broadcasts.'],
            ['name' => 'sortBy', 'desc' => 'Sets this tab\'s sort from ?by= and renders this tab only.'],
        ],
        'views' => [
            ['name' => 'leaderboard.html.twig', 'desc' => 'The ranked list, with a view-transition-name on each row. view(..., viewTransition: true) animates every update.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View page class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/LeaderboardExample.php'],
        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/leaderboard.html.twig'],
    ];

    #[Signal]
    public string $sort = 'votes';

    #[Signal]
    public string $draft = '';

    /** @var list<array{id: int, name: string, votes: int}> */
    private static array $items = self::SEED;

    /** Starts above the seed's ids and only grows, so a higher id is a newer topping. */
    private static int $nextId = 100;

    public function view(Context $ctx): void {
        $ctx->view('examples/leaderboard.html.twig', fn (): array => [
            'title' => 'Leaderboard',
            'perWorker' => 'the toppings',
            'description' => 'Vote for pizza toppings, add your own or remove one, and open the page in a second window to watch both lists reorder at once. <code>view(..., viewTransition: true)</code> animates each update, every row has its own <code>view-transition-name</code>, and <code>#[Broadcast(Scope::ROUTE)]</code> sends each change to every tab.',
            'summary' => self::SUMMARY,
            'anatomy' => self::ANATOMY,
            'githubLinks' => self::GITHUB_LINKS,
            'items' => self::sorted($this->sort),
            'sorts' => self::SORTS,
            'currentSort' => $this->sort,
            'full' => \count(self::$items) >= self::MAX_ITEMS,
            'maxItems' => self::MAX_ITEMS,
            'maxNameLength' => self::MAX_NAME_LENGTH,
        ], block: 'demo', viewTransition: true);
    }

    #[Action]
    public function vote(Context $ctx): void {
        $id = (int) $ctx->input('id', 0);
        foreach (self::$items as $key => $item) {
            if ($item['id'] === $id) {
                self::$items[$key]['votes'] = min(self::MAX_VOTES, $item['votes'] + 1);
                $ctx->broadcast();

                return;
            }
        }
    }

    #[Action]
    public function add(Context $ctx): void {
        // Control characters out, runs of whitespace to one space; Twig escapes the rest on output.
        $name = mb_trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}/u', '', $this->draft)));
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        $this->draft = '';
        if ($name === '' || \count(self::$items) >= self::MAX_ITEMS) {
            return;
        }
        foreach (self::$items as $item) {
            if (mb_strtolower($item['name']) === mb_strtolower($name)) {
                return;
            }
        }

        self::$items[] = ['id' => self::$nextId++, 'name' => $name, 'votes' => 0];
        $ctx->broadcast();
    }

    #[Action]
    public function remove(Context $ctx): void {
        $id = (int) $ctx->input('id', 0);
        $before = \count(self::$items);
        self::$items = array_values(array_filter(self::$items, static fn (array $item): bool => $item['id'] !== $id));
        if (\count(self::$items) !== $before) {
            $ctx->broadcast();
        }
    }

    #[Action]
    public function reset(Context $ctx): void {
        self::$items = self::SEED;
        $ctx->broadcast();
    }

    #[Action]
    public function sortBy(Context $ctx): void {
        $by = (string) $ctx->input('by', 'votes');
        $this->sort = isset(self::SORTS[$by]) ? $by : 'votes';
        $ctx->sync();
    }

    public static function register(Via $app): void {
        $app->mount(self::class, self::ROUTE);
    }

    /**
     * The toppings in the order $sort asks for, each with its rank by votes.
     *
     * @return list<array{id: int, name: string, votes: int, rank: int}>
     */
    private static function sorted(string $sort): array {
        $rank = array_flip(array_column(self::order('votes'), 'id'));

        return array_map(static fn (array $item): array => $item + ['rank' => $rank[$item['id']] + 1], self::order($sort));
    }

    /**
     * @return list<array{id: int, name: string, votes: int}>
     */
    private static function order(string $sort): array {
        $items = self::$items;
        usort($items, match ($sort) {
            'name' => static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id'],
            'newest' => static fn (array $a, array $b): int => $b['id'] <=> $a['id'],
            default => static fn (array $a, array $b): int => $b['votes'] <=> $a['votes'] ?: $a['id'] <=> $b['id'],
        });

        return $items;
    }
}
