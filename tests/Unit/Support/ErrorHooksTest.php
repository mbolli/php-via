<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Support\ErrorHooks;

/**
 * @param list<string> $log filled with "level message" per log call
 */
function errorHooksWithLog(array &$log): ErrorHooks {
    return new ErrorHooks(static function (string $level, string $message, ?Context $context) use (&$log): void {
        $log[] = $level . ' ' . ($context?->getId() ?? '-') . ' ' . $message;
    });
}

describe('ErrorHooks', function (): void {
    test('without callbacks a report does nothing', function (): void {
        $log = [];
        errorHooksWithLog($log)->report(new RuntimeException('x'), null, ErrorPhase::Timer);

        expect($log)->toBe([]);
    });

    test('callbacks get the throwable, the context, the phase and the action, in the order added', function (): void {
        $log = [];
        $hooks = errorHooksWithLog($log);
        $calls = [];
        $hooks->add(function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$calls): void {
            $calls[] = ['first', $e->getMessage(), $c?->getId(), $phase, $action];
        });
        $hooks->add(function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$calls): void {
            $calls[] = ['second', $e->getMessage(), $c?->getId(), $phase, $action];
        });
        $context = new Context('ctx-hooks', '/h', createVia());

        $hooks->report(new RuntimeException('boom'), $context, ErrorPhase::Action, 'save');

        expect($calls)->toBe([
            ['first', 'boom', 'ctx-hooks', ErrorPhase::Action, 'save'],
            ['second', 'boom', 'ctx-hooks', ErrorPhase::Action, 'save'],
        ])->and($log)->toBe([]);
    });

    test('a throwing callback is logged with the context and the next one still runs', function (): void {
        $log = [];
        $hooks = errorHooksWithLog($log);
        $ran = false;
        $hooks->add(function (): void {
            throw new LogicException('tracker unreachable');
        });
        $hooks->add(function () use (&$ran): void {
            $ran = true;
        });
        $context = new Context('ctx-hooks', '/h', createVia());

        $hooks->report(new RuntimeException('boom'), $context, ErrorPhase::Render);

        expect($ran)->toBeTrue()
            ->and($log)->toHaveCount(1)
            ->and($log[0])->toStartWith('error ctx-hooks onError callback failed (phase render): LogicException: tracker unreachable at ')
        ;
    });

    test('a report made while the callbacks run in the same coroutine reaches none of them, and the next report does', function (): void {
        $log = [];
        $hooks = errorHooksWithLog($log);
        $seen = [];
        $hooks->add(function (Throwable $e) use ($hooks, &$seen): void {
            $seen[] = $e->getMessage();
            $hooks->report(new RuntimeException('nested'), null, ErrorPhase::Render);
        });

        $hooks->report(new RuntimeException('outer'), null, ErrorPhase::Task);
        $hooks->report(new RuntimeException('later'), null, ErrorPhase::Task);

        expect($seen)->toBe(['outer', 'later']);
    });

    test('the guard is lifted after a callback throws', function (): void {
        $log = [];
        $hooks = errorHooksWithLog($log);
        $calls = 0;
        $hooks->add(function () use (&$calls): void {
            ++$calls;

            throw new LogicException('always');
        });

        $hooks->report(new RuntimeException('a'), null, ErrorPhase::Timer);
        $hooks->report(new RuntimeException('b'), null, ErrorPhase::Timer);

        expect($calls)->toBe(2)->and($log)->toHaveCount(2);
    });
});
