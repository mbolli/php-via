<?php

declare(strict_types=1);

// Disable Xdebug for long-running SSE connections
ini_set('xdebug.mode', 'off');

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * Chat Room Example - Demonstrating Scoped Signals.
 *
 * Features:
 * - Multiple chat rooms with room-scoped state
 * - TAB-scoped signal for individual message input (private to each user)
 * - Scoped signal for typing indicator (shared across all users in same room)
 * - Real-time message delivery with auto-broadcast
 * - User list showing active participants
 * - Message history
 *
 * Scope Architecture:
 * - "room:{$room}": All state shared within a room (messages, typing indicator, users)
 * - SESSION scope: Username persists across routes within same browser session (cookie-based)
 * - TAB scope: Private message input per user
 */

// Global shared state for chat rooms
class ChatState {
    /** @var array<string, array{name: string, messages: array}> */
    public static array $rooms = [
        'lobby' => [
            'name' => 'Lobby',
            'messages' => [],
        ],
        'general' => [
            'name' => 'General',
            'messages' => [],
        ],
        'random' => [
            'name' => 'Random',
            'messages' => [],
        ],
    ];

    /** @var array<string, array<string, string>> */
    public static array $roomUsers = []; // room => [sessionId => username]

    public static ?Via $app = null;
}

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3006)
    ->withDevMode(true)
    ->withLogLevel('debug')
    ->withTemplateDir(__DIR__ . '/../templates')
;

// Create the application
$app = new Via($config);
ChatState::$app = $app;

// Room list page
$app->page('/', function (Context $c): void {
    // SESSION-scoped username - persists across routes in the same browser session
    error_log('ROOT: Session ID: ' . $c->getSessionId());
    $usernameSignal = $c->signal('', 'username', Scope::SESSION);
    error_log('ROOT: Signal ID: ' . $usernameSignal->id() . ', Value: ' . $usernameSignal->getValue());

    // Generate unique username for this session if not set (in production, use auth)
    if ($usernameSignal->getValue() === '') {
        $uniqueId = substr(bin2hex(random_bytes(3)), 0, 4);
        $usernameSignal->setValue('User' . strtoupper($uniqueId));
        error_log('ROOT: Generated username: ' . $usernameSignal->getValue());
    }

    $rooms = array_map(fn ($roomId) => [
        'id' => $roomId,
        'name' => ChatState::$rooms[$roomId]['name'],
    ], array_keys(ChatState::$rooms));

    $c->view('chat_room_list.html.twig', [
        'rooms' => $rooms,
        'username' => $usernameSignal->getValue(),
    ]);
});

// Chat room page
$app->page('/room/{room}', function (Context $c, string $room): void {
    if (!isset(ChatState::$rooms[$room])) {
        $c->view(fn () => '<h1>Room not found</h1>');

        return;
    }

    // Don't use scoped rendering - each user sees different content (their username, etc.)
    // Keep TAB scope so each context renders independently without caching
    // Note: We still use room scope for signals/actions that need to be shared

    // SESSION-scoped username - persists across routes in the same browser session
    error_log('ROOM: Session ID: ' . $c->getSessionId());
    $usernameSignal = $c->signal('', 'username', Scope::SESSION);
    error_log('ROOM: Signal ID: ' . $usernameSignal->id() . ', Value: ' . $usernameSignal->getValue());
    if ($usernameSignal->getValue() === '') {
        $uniqueId = substr(bin2hex(random_bytes(3)), 0, 4);
        $usernameSignal->setValue('User' . strtoupper($uniqueId));
        error_log('ROOM: Generated username: ' . $usernameSignal->getValue());
    }
    $username = $usernameSignal->getValue();

    $contextId = $c->getId();
    $sessionId = $c->getSessionId();

    // Track user in this room by sessionId to prevent duplicates on reload
    ChatState::$roomUsers[$room] ??= [];
    $wasNewUser = !isset(ChatState::$roomUsers[$room][$sessionId]);
    ChatState::$roomUsers[$room][$sessionId] = $username;

    // TAB-scoped signal: private to this user's browser tab
    $messageInput = $c->signal('', 'messageInput');

    // Scoped signal: shared across all users in this room
    $roomScope = Scope::build('room', $room);
    // Add this context to the room scope so it receives broadcasts
    $c->addScope($roomScope);

    // Disable auto-broadcast for typing indicator since we manually broadcast in the action
    $typingIndicator = $c->signal('', 'typingIndicator', $roomScope, false);

    // Action: Send message - pass scope directly to action, don't set it on context
    $sendMessage = $c->action(function (Context $ctx) use ($room, $messageInput, $typingIndicator, $roomScope): void {
        $sessionId = $ctx->getSessionId();
        $username = ChatState::$roomUsers[$room][$sessionId] ?? 'Unknown';
        $message = trim($messageInput->getValue());
        if ($message === '') {
            return;
        }

        // Add message to history
        $newMessage = [
            'username' => $username,
            'message' => $message,
            'timestamp' => date('H:i:s'),
        ];

        ChatState::$rooms[$room]['messages'][] = $newMessage;
        var_dump(ChatState::$rooms[$room]['messages']);

        // Clear message input (TAB-scoped, only affects this user)
        $messageInput->setValue('');

        // Clear typing indicator (scoped signal, affects all users in room)
        $typingIndicator->setValue('');

        // Broadcast to all users in this room - triggers full re-render
        ChatState::$app->broadcast($roomScope);
    }, 'sendMessage');

    // Action: Update typing indicator - only broadcast when state changes
    $updateTyping = $c->action(function (Context $ctx) use ($room, $typingIndicator, $roomScope): void {
        $sessionId = $ctx->getSessionId();
        $username = ChatState::$roomUsers[$room][$sessionId] ?? 'Unknown';

        $typingIndicator->setValue($username . ' is typing...');
        ChatState::$app->broadcast($roomScope);
    }, 'updateTyping');

    // Handle user disconnect - remove from user list and broadcast
    $c->onDisconnect(function (Context $ctx) use ($room, $roomScope, $sessionId): void {
        if (isset(ChatState::$roomUsers[$room][$sessionId])) {
            unset(ChatState::$roomUsers[$room][$sessionId]);
            var_dump(ChatState::$roomUsers[$room], $sessionId);

            // Broadcast updated user list to remaining room members
            ChatState::$app?->broadcast($roomScope);
        } else {
            error_log("DISCONNECT: User with session ID {$sessionId} not found in room {$room}");
        }
    });

    // Use a callable view to fetch fresh data on every render (including broadcasts)
    $c->view(function (bool $isUpdate) use ($c, $room, $username, $contextId, $messageInput, $typingIndicator, $sendMessage, $updateTyping) {
        // Fetch current state fresh on every render
        $activeUsers = array_values(ChatState::$roomUsers[$room] ?: []);

        return $c->render('chat_room.html.twig', [
            'room' => $room,
            'roomName' => ChatState::$rooms[$room]['name'],
            'username' => $username,
            'contextId' => $contextId,
            'messages' => ChatState::$rooms[$room]['messages'],
            'messageInputId' => $messageInput->id(),
            'typingIndicatorId' => $typingIndicator->id(),
            'users' => $activeUsers,
            'sendMessageUrl' => $sendMessage->url(),
            'updateTypingUrl' => $updateTyping->url(),
        ]);
    });

    // Broadcast to all other users in the room when a new user connects
    if ($wasNewUser) {
        ChatState::$app->broadcast($roomScope);
    }
});

// Start the server
echo "Starting Chat Server on http://0.0.0.0:3006\n";
echo "Open multiple browsers to test multi-user chat\n";
echo "Press Ctrl+C to stop\n";
$app->start();
