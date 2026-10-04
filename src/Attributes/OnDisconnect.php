<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Removed in php-via 0.14: use #[OnCleanup], which runs at the same moment.
 *
 * The class stays for one release because PHP silently ignores an attribute whose class does
 * not exist, so deleting it would drop the cleanup without a sound. Mounting a class that
 * still carries it throws a LogicException naming #[OnCleanup].
 *
 * @deprecated since 0.14, use {@see OnCleanup}
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class OnDisconnect {}
