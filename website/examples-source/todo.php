<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

// Shared state (persists in memory across connections)
class TodoState {
    /** @var array<int, array{id: int, text: string, completed: bool}> */
    public static array $todos = [
        ['id' => 1, 'text' => 'Buy milk', 'completed' => false],
        ['id' => 2, 'text' => 'Walk dog', 'completed' => false],
        ['id' => 3, 'text' => 'Learn php-via', 'completed' => true],
    ];
    public static int $nextId = 4;
}

$app = new Via(
    (new Config())
        ->withPort(3004)
        ->withDevMode(true)
        ->withTemplateDir(__DIR__ . '/templates')
);

$app->page('/', function (Context $c) use ($app): void {
    // ROUTE scope = shared across all tabs on this URL
    $c->scope(Scope::ROUTE);

    // Each user's input is private (TAB scope)
    $newTodo = $c->signal('', 'newTodo', Scope::TAB);

    $addTodo = $c->action(function (Context $ctx) use ($app): void {
        $newTodo = $ctx->getSignal('newTodo');
        $task = $newTodo ? mb_trim($newTodo->string()) : '';
        if ($task !== '') {
            TodoState::$todos[] = [
                'id' => TodoState::$nextId++,
                'text' => $task,
                'completed' => false,
            ];
            $newTodo->setValue('');
            $app->broadcast(Scope::ROUTE);
        }
    }, 'addTodo');

    $deleteTodo = $c->action(function (Context $ctx) use ($app): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        TodoState::$todos = array_values(
            array_filter(TodoState::$todos, fn ($t) => $t['id'] !== $id)
        );
        $app->broadcast(Scope::ROUTE);
    }, 'deleteTodo');

    $toggleTodo = $c->action(function (Context $ctx) use ($app): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        foreach (TodoState::$todos as &$todo) {
            if ($todo['id'] === $id) {
                $todo['completed'] = !$todo['completed'];

                break;
            }
        }
        $app->broadcast(Scope::ROUTE);
    }, 'toggleTodo');

    $c->view(fn (): string => $c->render('todo.html.twig', [
        'todos' => TodoState::$todos,
        'newTodo' => $newTodo,
        'addTodo' => $addTodo,
        'deleteTodo' => $deleteTodo,
        'toggleTodo' => $toggleTodo,
    ]), block: 'demo', cacheUpdates: false);
});

$app->start();
