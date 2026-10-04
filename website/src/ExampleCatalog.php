<?php

declare(strict_types=1);

namespace PhpVia\Website;

/**
 * The examples by category. The examples sidebar, the phone menu, the overview cards and the
 * multiplayer badge in each example's header all read this list, through Twig's constant().
 */
final class ExampleCatalog {
    /**
     * A group is multiplayer when every example in it shares state between visitors.
     *
     * @var list<array{name: string, multiplayer: bool, items: list<array{route: string, label: string, blurb: string}>}>
     */
    public const array GROUPS = [
        ['name' => 'Basics', 'multiplayer' => false, 'items' => [
            ['route' => 'examples/counter', 'label' => 'Counter', 'blurb' => 'Count up and down by a step size you set.'],
            ['route' => 'examples/greeter', 'label' => 'Greeter', 'blurb' => 'Click a name and the server changes the greeting.'],
            ['route' => 'examples/path-params', 'label' => 'Path Parameters', 'blurb' => 'Open article links whose year, month and slug come from the URL.'],
            ['route' => 'examples/live-search', 'label' => 'Live Search', 'blurb' => 'Search PHP functions as you type, filtered on the server.'],
            ['route' => 'examples/components', 'label' => 'Components', 'blurb' => 'Click three counters that each keep their own count.'],
            ['route' => 'examples/theme-builder', 'label' => 'Theme Builder', 'blurb' => 'Pick colours for a preview card, then undo and redo.'],
        ]],
        ['name' => 'Forms and sessions', 'multiplayer' => false, 'items' => [
            ['route' => 'examples/wizard', 'label' => 'Multi-step Form', 'blurb' => 'Fill in a three-step form that checks your input on the server.'],
            ['route' => 'examples/contact-form', 'label' => 'Contact Form', 'blurb' => 'Send a form with a file and see an error next to each wrong field.'],
            ['route' => 'examples/login', 'label' => 'Login Flow', 'blurb' => 'Log in to reach two protected pages, then log out.'],
            ['route' => 'examples/shopping-cart', 'label' => 'Shopping Cart', 'blurb' => 'Fill a cart that every tab of your browser shares.'],
            ['route' => 'examples/file-upload', 'label' => 'SharedWorker Upload', 'blurb' => 'Start an upload and keep it running while you move between pages.'],
        ]],
        ['name' => 'Shared state', 'multiplayer' => true, 'items' => [
            ['route' => 'examples/all-scopes', 'label' => 'All Scopes', 'blurb' => 'Compare a status for everyone, a counter per page and a message per tab.'],
            ['route' => 'examples/todo', 'label' => 'Todo List', 'blurb' => 'Add, tick off and delete todos on a list every visitor shares.'],
            ['route' => 'examples/chat-room', 'label' => 'Chat Room', 'blurb' => 'Chat with other visitors in one of several rooms.'],
            ['route' => 'examples/client-monitor', 'label' => 'Client Monitor', 'blurb' => 'Watch open connections appear and disappear as tabs come and go.'],
            ['route' => 'examples/spreadsheet', 'label' => 'Spreadsheet', 'blurb' => 'Edit cells together with others and see where their cursors are.'],
        ]],
        ['name' => 'Live data and games', 'multiplayer' => true, 'items' => [
            ['route' => 'examples/stock-ticker', 'label' => 'Stock Ticker', 'blurb' => 'Watch simulated prices and charts change every two seconds.'],
            ['route' => 'examples/live-auction', 'label' => 'Live Auction', 'blurb' => 'Bid against other visitors before the clock runs out.'],
            ['route' => 'examples/game-of-life', 'label' => 'Game of Life', 'blurb' => 'Draw on a shared board and watch the patterns evolve.'],
            ['route' => 'examples/type-race', 'label' => 'Type Race', 'blurb' => 'Race other visitors to type a PHP snippet first.'],
        ]],
        ['name' => 'Several servers', 'multiplayer' => true, 'items' => [
            ['route' => 'examples/mission-control', 'label' => 'NATS Visualizer', 'blurb' => 'Stop and restart simulated services and watch their events cross NATS.'],
        ]],
    ];
}
