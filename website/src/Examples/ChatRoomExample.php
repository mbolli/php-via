<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class ChatRoomExample {
    public const string SLUG = 'chat-room';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Custom scopes</strong> isolate each room. Messages in "lobby" never leak to "general" — each room has its own broadcast channel built with <code>Scope::build()</code>.',
        '<strong>Session-scoped usernames</strong> persist across tabs. Your username is stored in SESSION scope, so switching rooms or opening a new tab keeps the same identity.',
        '<strong>Presence + typing</strong> indicators update in real time. When a user disconnects, the <code>onDisconnect</code> hook removes them from the room\'s user list.',
        '<strong>addScope()</strong> lets a context join a broadcast channel mid-flight. The room page starts in TAB scope for private input, then adds the room scope for shared messages.',
        '<strong>SQLite persistence</strong> keeps message history across server restarts. Each room\'s messages are stored in <code>chat.db</code> and the last 50 are loaded on connect — no in-memory state required.',
        '<strong>Multi-room architecture</strong> — open two rooms side by side. Each room\'s scope is independent, so typing in Lobby has no effect on General.',
    ];

    /** @var array<string, list<array<string, string>>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'username', 'type' => 'string', 'scope' => 'SESSION', 'desc' => 'Persists across tabs. Same identity whether you switch rooms or open new tabs.'],
            ['name' => 'messageInput', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Current message draft. Private to this tab.'],
            ['name' => 'typingIndicator', 'type' => 'string', 'scope' => 'Custom', 'desc' => 'Custom room scope. Shows "User is typing..." to everyone in the same room.'],
        ],
        'actions' => [
            ['name' => 'sendMessage', 'desc' => 'Appends message to the room, clears input, resets typing indicator, and broadcasts to room.'],
            ['name' => 'updateTyping', 'desc' => 'Sets the typing indicator with username and broadcasts to room.'],
        ],
        'views' => [
            ['name' => 'chat_room.html.twig', 'desc' => 'Sidebar room list + chat panel with message list, user presence, and typing indicator. Uses onDisconnect for cleanup.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ChatRoomExample.php'],
        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/chat_room.html.twig'],
    ];

    /** @var array<string, array{name: string}> */
    private static array $rooms = [
        'lobby' => ['name' => 'Lobby'],
        'general' => ['name' => 'General'],
        'random' => ['name' => 'Random'],
    ];

    /** @var array<string, array<string, string>> room => [sessionId => username] */
    private static array $roomUsers = [];

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
        $sessionId = $c->getSessionId();

        self::$roomUsers[$room] ??= [];
        $wasNewUser = !isset(self::$roomUsers[$room][$sessionId]);
        self::$roomUsers[$room][$sessionId] = $username;

        $messageInput = $c->signal('', 'messageInput');
        $roomScope = Scope::build('example:chat', $room);
        $c->addScope($roomScope);
        $typingIndicator = $c->signal('', 'typingIndicator', $roomScope, false);

        $sendMessage = $c->action(function (Context $ctx) use ($room, $messageInput, $typingIndicator, $roomScope): void {
            $sessionId = $ctx->getSessionId();
            $username = self::$roomUsers[$room][$sessionId] ?? 'Unknown';
            $message = trim($messageInput->getValue());
            if ($message === '') {
                return;
            }

            self::addMessage($room, $username, $message);

            $messageInput->setValue('');
            $typingIndicator->setValue('');
            self::$app?->broadcast($roomScope);
        }, 'sendMessage');

        $updateTyping = $c->action(function (Context $ctx) use ($room, $typingIndicator, $roomScope): void {
            $sessionId = $ctx->getSessionId();
            $username = self::$roomUsers[$room][$sessionId] ?? 'Unknown';
            $typingIndicator->setValue($username . ' is typing...');
            self::$app?->broadcast($roomScope);
        }, 'updateTyping');

        $c->onDisconnect(function () use ($room, $roomScope, $sessionId): void {
            if (isset(self::$roomUsers[$room][$sessionId])) {
                unset(self::$roomUsers[$room][$sessionId]);
                self::$app?->broadcast($roomScope);
            }
        });

        $rooms = [];
        foreach (self::$rooms as $id => $data) {
            $rooms[] = [
                'id' => $id,
                'name' => $data['name'],
                'userCount' => \count(self::$roomUsers[$id] ?? []),
            ];
        }

        $c->view(fn (): string => $c->render('examples/chat_room.html.twig', [
            'title' => '💬 Chat Room',
            'description' => 'Chat: ' . self::$rooms[$room]['name'],
            'summary' => self::SUMMARY,
            'anatomy' => self::ANATOMY,
            'githubLinks' => self::GITHUB_LINKS,
            'room' => $room,
            'rooms' => $rooms,
            'roomName' => self::$rooms[$room]['name'],
            'username' => $username,
            'contextId' => $contextId,
            'messages' => self::getMessages($room),
            'messageInputId' => $messageInput->id(),
            'typingIndicatorId' => $typingIndicator->id(),
            'users' => array_values(self::$roomUsers[$room] ?? []),
            'sendMessageUrl' => $sendMessage->url(),
            'updateTypingUrl' => $updateTyping->url(),
        ]), block: 'demo');

        if ($wasNewUser) {
            $app->broadcast($roomScope);
        }
    }

    private static function db(): \SQLite3 {
        if (self::$db === null) {
            self::$db = new \SQLite3(__DIR__ . '/../../chat.db');
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
