<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\DevBar;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/**
 * Builds the per-context signal manifest the Dev Bar's Signals panel needs.
 *
 * The browser's Datastar store holds live signal *values* but not their scope
 * or write-ability. This manifest, injected at page load, supplies that
 * metadata so the panel can group signals by scope and know which are editable.
 */
final class SignalManifest {
    /**
     * @return list<array{id: string, name: string, scope: string, value: mixed, clientWritable: bool}>
     */
    public static function build(Context $context): array {
        $manifest = [];

        foreach ($context->getNamedSignals() as $name => $signal) {
            $manifest[] = [
                'id' => $signal->id(),
                'name' => $name,
                'scope' => $signal->getScope() ?? Scope::TAB,
                'value' => $signal->getValue(),
                'clientWritable' => $signal->isClientWritable(),
            ];
        }

        return $manifest;
    }
}
