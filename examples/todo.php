<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

// Global shared state
class TodoState {
    /** @var array<int, array{id: int, text: string, completed: bool}> */
    public static array $todos = [
        ['id' => 1, 'text' => 'Buy milk', 'completed' => false],
        ['id' => 2, 'text' => 'Walk dog', 'completed' => false],
        ['id' => 3, 'text' => 'Learn php-via', 'completed' => true],
    ];
    public static int $nextId = 4;
}

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3004)
    ->withTemplateDir(__DIR__ . '/../templates')
    ->withLogLevel('debug')
;

// Create the application
$app = new Via($config);

$app->page('/', function (Context $c) use ($app): void {
    // Set ROUTE scope for shared todo list actions
    $c->scope(Scope::ROUTE);

    // Note: $newTodo is a TAB-scoped signal because each user needs their own input value
    // Must explicitly set to Scope::TAB since context defaults to ROUTE
    $newTodo = $c->signal('', 'newTodo', Scope::TAB);

    $addTodo = $c->action(function (Context $ctx) use ($app): void {
        // Get TAB-scoped signal from the executing context (not from definition context)
        $newTodo = $ctx->getSignal('newTodo');
        $task = $newTodo ? mb_trim($newTodo->string()) : '';
        if ($task !== '') {
            $app->log('info', "Adding new todo item: {$task}");
            TodoState::$todos[] = ['id' => TodoState::$nextId++, 'text' => $task, 'completed' => false];
            $newTodo->setValue(''); // Clear the signal value after processing
            $app->broadcast(Scope::ROUTE);
        } else {
            $app->log('debug', 'Attempted to add empty todo item, ignoring.' . $newTodo?->id());
        }
    }, 'addTodo');

    $deleteTodo = $c->action(function (Context $ctx) use ($app): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        TodoState::$todos = array_filter(TodoState::$todos, fn (array $todo) => $todo['id'] !== $id);
        TodoState::$todos = array_values(TodoState::$todos); // Re-index array
        $app->broadcast(Scope::ROUTE);
    }, 'deleteTodo');

    $toggleTodo = $c->action(function (Context $ctx) use ($app): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        foreach (TodoState::$todos as $key => $todo) {
            if ($todo['id'] === $id) {
                TodoState::$todos[$key]['completed'] = !TodoState::$todos[$key]['completed'];

                break;
            }
        }
        $app->broadcast(Scope::ROUTE);
    }, 'toggleTodo');

    $c->view(fn (bool $isUpdate = false): string => $c->render('todo.html.twig', [
        'todos' => TodoState::$todos,
        'newTodo' => $newTodo,
        'addTodo' => $addTodo,
        'deleteTodo' => $deleteTodo,
        'toggleTodo' => $toggleTodo,
    ], $isUpdate ? 'content' : null), cacheUpdates: false); // Disable caching for updates to ensure fresh data
});

echo "Starting Via Todo List on http://0.0.0.0:3004\n";
$app->start();
