<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3007)
        ->withDevMode(true)
        ->withTemplateDir(__DIR__ . '/templates')
);

// Static chat state (persists in server memory)
$rooms = [
    'lobby' => ['name' => 'Lobby', 'messages' => []],
    'general' => ['name' => 'General', 'messages' => []],
    'random' => ['name' => 'Random', 'messages' => []],
];
$roomUsers = []; // room => [sessionId => username]

// Room list page
$app->page('/', function (Context $c) use (&$rooms): void {
    $username = $c->signal('', 'username', Scope::SESSION);
    if ($username->getValue() === '') {
        $username->setValue('User' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)));
    }

    $roomList = array_map(fn (string $id) => [
        'id' => $id,
        'name' => $rooms[$id]['name'],
    ], array_keys($rooms));

    $c->view('chat_list.html.twig', [
        'rooms' => $roomList,
        'username' => $username->getValue(),
    ]);
});

// Individual chat room — scoped per room
$app->page('/room/{room}', function (Context $c, string $room) use ($app, &$rooms, &$roomUsers): void {
    if (!isset($rooms[$room])) {
        $c->view(fn () => '<h1>Room not found</h1>');
        return;
    }

    $username = $c->signal('', 'username', Scope::SESSION);
    if ($username->getValue() === '') {
        $username->setValue('User' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)));
    }

    $sessionId = $c->getSessionId();
    $wasNewUser = !isset($roomUsers[$room][$sessionId]);
    $roomUsers[$room][$sessionId] = $username->getValue();

    $messageInput = $c->signal('', 'messageInput');
    $roomScope = Scope::build('chat', $room);
    $c->addScope($roomScope);
    $typingIndicator = $c->signal('', 'typingIndicator', $roomScope, false);

    $sendMessage = $c->action(function (Context $ctx) use ($room, $messageInput, $typingIndicator, $roomScope, $app, &$rooms, &$roomUsers): void {
        $sessionId = $ctx->getSessionId();
        $user = $roomUsers[$room][$sessionId] ?? 'Unknown';
        $msg = trim($messageInput->getValue());
        if ($msg === '') return;

        $rooms[$room]['messages'][] = [
            'username' => $user,
            'message' => $msg,
            'timestamp' => date('H:i:s'),
        ];
        $messageInput->setValue('');
        $typingIndicator->setValue('');
        $app->broadcast($roomScope);
    }, 'sendMessage');

    $updateTyping = $c->action(function (Context $ctx) use ($room, $typingIndicator, $roomScope, $app, &$roomUsers): void {
        $user = $roomUsers[$room][$ctx->getSessionId()] ?? 'Unknown';
        $typingIndicator->setValue($user . ' is typing...');
        $app->broadcast($roomScope);
    }, 'updateTyping');

    // Clean up when user disconnects
    $c->onDisconnect(function () use ($room, $roomScope, $sessionId, $app, &$roomUsers): void {
        unset($roomUsers[$room][$sessionId]);
        $app->broadcast($roomScope);
    });

    $c->view(function () use ($c, $room, $username, $messageInput, $typingIndicator, $sendMessage, $updateTyping, &$rooms, &$roomUsers): string {
        return $c->render('chat_room.html.twig', [
            'room' => $room,
            'roomName' => $rooms[$room]['name'],
            'username' => $username->getValue(),
            'contextId' => $c->getId(),
            'messages' => $rooms[$room]['messages'],
            'messageInputId' => $messageInput->id(),
            'typingIndicatorId' => $typingIndicator->id(),
            'users' => array_values($roomUsers[$room] ?? []),
            'sendMessageUrl' => $sendMessage->url(),
            'updateTypingUrl' => $updateTyping->url(),
        ]);
    });

    if ($wasNewUser) {
        $app->broadcast($roomScope);
    }
});

$app->start();
