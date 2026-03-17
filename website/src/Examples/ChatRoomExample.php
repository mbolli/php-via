<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class ChatRoomExample
{
    public const string SLUG = 'chat-room';

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Custom scopes</strong> isolate each room. Messages in "lobby" never leak to "general" — each room has its own broadcast channel built with <code>Scope::build()</code>.',
        '<strong>Session-scoped usernames</strong> persist across tabs. Your username is stored in SESSION scope, so switching rooms or opening a new tab keeps the same identity.',
        '<strong>Presence + typing</strong> indicators update in real time. When a user disconnects, the <code>onDisconnect</code> hook removes them from the room\'s user list.',
        '<strong>addScope()</strong> lets a context join a broadcast channel mid-flight. The room page starts in TAB scope for private input, then adds the room scope for shared messages.',
        '<strong>In-memory message store</strong> keeps this demo self-contained. Messages persist as long as the server runs — no database required for a working chat.',
        '<strong>Multi-room architecture</strong> — open two rooms side by side. Each room\'s scope is independent, so typing in Lobby has no effect on General.',
    ];

    /** @var array<string, array{name: string, messages: array<array{username: string, message: string, timestamp: string}>}> */
    private static array $rooms = [
        'lobby' => ['name' => 'Lobby', 'messages' => []],
        'general' => ['name' => 'General', 'messages' => []],
        'random' => ['name' => 'Random', 'messages' => []],
    ];

    /** @var array<string, array<string, string>> room => [sessionId => username] */
    private static array $roomUsers = [];

    private static ?Via $app = null;

    public static function register(Via $app): void
    {
        self::$app = $app;

        // Room list
        $app->page('/examples/chat-room', function (Context $c): void {
            $usernameSignal = $c->signal('', 'username', Scope::SESSION);
            if ($usernameSignal->getValue() === '') {
                $uniqueId = substr(bin2hex(random_bytes(3)), 0, 4);
                $usernameSignal->setValue('User' . strtoupper($uniqueId));
            }

            $rooms = array_map(fn (string $roomId) => [
                'id' => $roomId,
                'name' => self::$rooms[$roomId]['name'],
            ], array_keys(self::$rooms));

            $c->view('examples/chat_room_list.html.twig', [
                'title' => '💬 Chat Room',
                'description' => 'Multi-user chat with room-scoped state, typing indicators, and session usernames.',
                'summary' => self::SUMMARY,
                'sourceFile' => 'chat_room.php',
                'templateFiles' => ['chat_room_list.html.twig', 'chat_room.html.twig'],
                'rooms' => $rooms,
                'username' => $usernameSignal->getValue(),
            ]);
        });

        // Chat room page
        $app->page('/examples/chat-room/room/{room}', function (Context $c, string $room) use ($app): void {
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

                self::$rooms[$room]['messages'][] = [
                    'username' => $username,
                    'message' => $message,
                    'timestamp' => date('H:i:s'),
                ];

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

            $c->view(function () use ($c, $room, $username, $contextId, $messageInput, $typingIndicator, $sendMessage, $updateTyping): string {
                return $c->render('examples/chat_room.html.twig', [
                    'title' => '💬 Chat Room',
                    'description' => 'Chat: ' . self::$rooms[$room]['name'],
                    'summary' => self::SUMMARY,
                    'sourceFile' => 'chat_room.php',
                    'templateFiles' => ['chat_room_list.html.twig', 'chat_room.html.twig'],
                    'room' => $room,
                    'roomName' => self::$rooms[$room]['name'],
                    'username' => $username,
                    'contextId' => $contextId,
                    'messages' => self::$rooms[$room]['messages'],
                    'messageInputId' => $messageInput->id(),
                    'typingIndicatorId' => $typingIndicator->id(),
                    'users' => array_values(self::$roomUsers[$room] ?? []),
                    'sendMessageUrl' => $sendMessage->url(),
                    'updateTypingUrl' => $updateTyping->url(),
                ]);
            });

            if ($wasNewUser) {
                $app->broadcast($roomScope);
            }
        });
    }
}
