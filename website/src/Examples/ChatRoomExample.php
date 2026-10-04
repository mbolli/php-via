<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

final class ChatRoomExample {
    public const string SLUG = 'chat-room';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Custom scopes</strong> isolate each room. Messages in "lobby" never leak to "general": each room has its own broadcast channel built with <code>Scope::build()</code>.',
        '<strong>Session-scoped usernames</strong> persist across tabs. Your username is stored in SESSION scope, so switching rooms or opening a new tab keeps the same identity.',
        '<strong>Presence + typing</strong> indicators update in real time. When a tab closes, the <code>onCleanup</code> hook removes its user from the room\'s list.',
        '<strong>addScope()</strong> joins the room\'s scope, so the room\'s broadcasts re-render the page. The page itself stays in TAB scope, which keeps the message draft private.',
        '<strong>SQLite persistence</strong> keeps message history across server restarts. Each room\'s messages are stored in <code>chat.db</code>, and the view loads the last 50 on every render.',
        '<strong>Multi-room architecture</strong>: open two rooms side by side. Each room\'s scope is independent, so typing in Lobby has no effect on General.',
    ];

    /** @var array<string, list<array<string, string>>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'username', 'type' => 'string', 'scope' => 'SESSION', 'desc' => 'Persists across tabs. Same identity whether you switch rooms or open new tabs.'],
            ['name' => 'messageInput', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Current message draft. Private to this tab.'],
            ['name' => 'typingIndicator', 'type' => 'array', 'scope' => 'Custom', 'desc' => 'Custom room scope. Who is typing and when that expires. Shows "User is typing..." to everyone in the same room until the server clears it.'],
        ],
        'actions' => [
            ['name' => 'sendMessage', 'desc' => 'Appends message to the room, clears input, clears the sender\'s typing indicator, and broadcasts to room.'],
            ['name' => 'updateTyping', 'desc' => 'Sets the typing indicator with username and broadcasts to room. The server clears it 5 s after the last keystroke.'],
        ],
        'views' => [
            ['name' => 'chat_room.html.twig', 'desc' => 'Sidebar room list + chat panel with message list, user presence, and typing indicator. Uses onCleanup for cleanup.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ChatRoomExample.php'],
        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/chat_room.html.twig'],
    ];

    private const int TYPING_TIMEOUT_MS = 5000;

    /** @var array{user: string, until: int} */
    private const array NOBODY_TYPING = ['user' => '', 'until' => 0];

    /** @var array<string, array{name: string}> */
    private static array $rooms = [
        'lobby' => ['name' => 'Lobby'],
        'general' => ['name' => 'General'],
        'random' => ['name' => 'Random'],
    ];

    /** @var array<string, array<string, string>> room => [contextId => username] */
    private static array $roomUsers = [];

    /** @var array<string, string> room => contextId that typed there last on this worker */
    private static array $typingTabs = [];

    /** @var array<string, int> room => this worker's timer for the room's typing expiry */
    private static array $typingTimers = [];

    /** @var array<string, string> contextId => the message that tab sent last */
    private static array $lastSent = [];

    private static ?Via $app = null;

    private static ?\SQLite3 $db = null;

    public static function register(Via $app): void {
        self::$app = $app;
        self::db(); // initialize DB on registration

        // Base URL defaults to lobby
        $app->page('/examples/chat-room', function (Context $c) use ($app): void {
            self::handleRoom($c, $app, 'lobby');
        });

        // Room-specific URL
        $app->page('/examples/chat-room/room/{room}', function (Context $c, string $room) use ($app): void {
            self::handleRoom($c, $app, $room);
        });
    }

    private static function handleRoom(Context $c, Via $app, string $room): void {
        if (!isset(self::$rooms[$room])) {
            $c->view(fn () => '<h1>Room not found</h1>');

            return;
        }

        $usernameSignal = $c->signal('', 'username', Scope::SESSION);
        if ($usernameSignal->getValue() === '') {
            $uniqueId = substr(bin2hex(random_bytes(3)), 0, 4);
            $usernameSignal->setValue('User' . strtoupper($uniqueId));
        }
        $username = $usernameSignal->getValue();
        $contextId = $c->getId();

        self::$roomUsers[$room] ??= [];
        $wasNewUser = !isset(self::$roomUsers[$room][$contextId]);
        self::$roomUsers[$room][$contextId] = $username;

        $c->signal('', 'messageInput');
        $roomScope = Scope::build('example:chat', $room);
        $c->addScope($roomScope);
        // Broadcast by hand: mutate() broadcasts even when it keeps the value, and only a change needs one.
        $c->signal(self::NOBODY_TYPING, 'typingIndicator', $roomScope, autoBroadcast: false);

        $c->action(function (Context $ctx) use ($room, $username, $roomScope, $contextId): void {
            $message = trim($ctx->getSignal('messageInput')->getValue());
            if ($message === '') {
                return;
            }

            // Time the INSERT as a `db.*` span in the Dev Bar trace.
            $ctx->span('db.insert_message', fn () => self::addMessage($room, $username, $message), ['room' => $room]);

            $ctx->getSignal('messageInput')->setValue('');
            self::$lastSent[$contextId] = $message;
            self::stopTyping($room, $roomScope, $username);
            self::$app?->broadcast($roomScope);
        }, 'sendMessage');

        $c->action(function (Context $ctx) use ($room, $username, $roomScope, $contextId): void {
            $draft = trim($ctx->getSignal('messageInput')->getValue());
            // A letter released after Enter posts a keyup carrying the sent text, or '' once the clear arrived.
            if ($draft === '' || $draft === (self::$lastSent[$contextId] ?? null)) {
                return;
            }
            $ctx->getSignal('typingIndicator')->setValue(['user' => $username, 'until' => self::nowMs() + self::TYPING_TIMEOUT_MS]);
            self::$typingTabs[$room] = $contextId;
            self::watchTyping($room, $roomScope);
            self::$app?->broadcast($roomScope);
        }, 'updateTyping');

        $c->onCleanup(function () use ($room, $roomScope, $contextId, $username): void {
            unset(self::$lastSent[$contextId]);
            $changed = false;
            if (isset(self::$roomUsers[$room][$contextId])) {
                unset(self::$roomUsers[$room][$contextId]);
                $changed = true;
            }
            if ((self::$typingTabs[$room] ?? null) === $contextId) {
                unset(self::$typingTabs[$room]);
                $changed = self::stopTyping($room, $roomScope, $username) || $changed;
            }
            if ($changed) {
                self::$app?->broadcast($roomScope);
            }
        });

        $c->view('examples/chat_room.html.twig', fn (): array => [
            'title' => '💬 Chat Room',
            'description' => 'Chat: ' . self::$rooms[$room]['name'],
            'summary' => self::SUMMARY,
            'anatomy' => self::ANATOMY,
            'githubLinks' => self::GITHUB_LINKS,
            'room' => $room,
            'rooms' => array_map(
                fn (string $id, array $data): array => [
                    'id' => $id,
                    'name' => $data['name'],
                    'userCount' => \count(array_unique(self::$roomUsers[$id] ?? [])),
                ],
                array_keys(self::$rooms),
                self::$rooms
            ),
            'roomName' => self::$rooms[$room]['name'],
            'username' => $username,
            'messages' => $c->span('db.select_messages', fn () => self::getMessages($room), ['room' => $room, 'limit' => 50]),
            'users' => array_values(array_unique(self::$roomUsers[$room] ?? [])),
        ], block: 'demo');

        // The worker whose timer would clear the indicator may have restarted since.
        self::watchTyping($room, $roomScope);

        if ($wasNewUser) {
            $app->broadcast($roomScope);
        }
    }

    /**
     * Clear the room's typing indicator if it still names $username, so another user who typed
     * since keeps theirs. Returns whether it was cleared.
     */
    private static function stopTyping(string $room, string $roomScope, string $username): bool {
        $cleared = self::clearTyping($roomScope, static fn (array $entry): bool => $entry['user'] === $username);
        self::watchTyping($room, $roomScope);

        return $cleared;
    }

    /**
     * Clear the room's typing indicator if it has expired, or arm this worker's timer for when it
     * does. The expiry is part of the shared value, so a timer lost with its worker cannot pin it.
     */
    private static function watchTyping(string $room, string $roomScope): void {
        if (isset(self::$typingTimers[$room])) {
            Timer::clear(self::$typingTimers[$room]);
            unset(self::$typingTimers[$room]);
        }

        $until = self::typingEntry(self::$app?->getScopedSignalByName($roomScope, 'typingIndicator')?->getValue())['until'];
        if ($until === 0) {
            return;
        }

        $remaining = $until - self::nowMs();
        if ($remaining > 0) {
            $timerId = Timer::after($remaining, static function () use ($room, $roomScope): void {
                unset(self::$typingTimers[$room]);
                self::watchTyping($room, $roomScope);
            });
            if (\is_int($timerId)) {
                self::$typingTimers[$room] = $timerId;
            }
        } elseif (self::clearTyping($roomScope, static fn (array $entry): bool => $entry['until'] === $until)) {
            self::$app?->broadcast($roomScope);
        }
    }

    /**
     * Clear the room's typing indicator if $matches accepts it, as one locked step so an entry
     * another worker writes in between survives. Returns whether it was cleared.
     *
     * @param callable(array{user: string, until: int}): bool $matches
     */
    private static function clearTyping(string $roomScope, callable $matches): bool {
        $indicator = self::$app?->getScopedSignalByName($roomScope, 'typingIndicator');
        if ($indicator === null || !$matches(self::typingEntry($indicator->getValue()))) {
            return false;
        }

        $cleared = false;
        $indicator->mutate(static function (mixed $value) use ($matches, &$cleared): mixed {
            $cleared = $matches(self::typingEntry($value));

            return $cleared ? self::NOBODY_TYPING : $value;
        });

        return $cleared;
    }

    /**
     * @return array{user: string, until: int}
     */
    private static function typingEntry(mixed $value): array {
        return \is_array($value) && \is_string($value['user'] ?? null) && \is_int($value['until'] ?? null)
            ? ['user' => $value['user'], 'until' => $value['until']]
            : self::NOBODY_TYPING;
    }

    private static function nowMs(): int {
        return (int) (microtime(true) * 1000);
    }

    private static function db(): \SQLite3 {
        if (self::$db === null) {
            self::$db = new \SQLite3(__DIR__ . '/../../chat.db');
            // Every worker opens the file at start: wait for another worker's lock instead of failing.
            self::$db->busyTimeout(1000);
            self::$db->exec('PRAGMA journal_mode=WAL');
            self::$db->exec('PRAGMA synchronous=NORMAL');
            self::$db->exec(
                'CREATE TABLE IF NOT EXISTS messages (
                    id        INTEGER PRIMARY KEY AUTOINCREMENT,
                    room      TEXT    NOT NULL,
                    username  TEXT    NOT NULL,
                    message   TEXT    NOT NULL,
                    timestamp TEXT    NOT NULL
                )'
            );
        }

        return self::$db;
    }

    private static function addMessage(string $room, string $username, string $message): void {
        $stmt = self::db()->prepare(
            'INSERT INTO messages (room, username, message, timestamp) VALUES (:room, :username, :message, :timestamp)'
        );
        $stmt->bindValue(':room', $room, SQLITE3_TEXT);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', date('H:i:s'), SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * @return list<array{username: string, message: string, timestamp: string}>
     */
    private static function getMessages(string $room, int $limit = 50): array {
        $stmt = self::db()->prepare(
            'SELECT username, message, timestamp FROM messages WHERE room = :room ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':room', $room, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = [
                'username' => (string) $row['username'],
                'message' => (string) $row['message'],
                'timestamp' => (string) $row['timestamp'],
            ];
        }

        return array_reverse($rows); // oldest first
    }
}
