<?php

declare(strict_types=1);

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Via;

test('Config, Signal, Action and Scope are final; Via and Context stay open for test doubles', function (): void {
    foreach ([Config::class, Signal::class, Action::class, Scope::class] as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeTrue($class);
    }
    foreach ([Via::class, Context::class] as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeFalse($class);
    }
});
