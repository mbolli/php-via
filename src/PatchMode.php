<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * How Context::patchElements() applies its HTML to the page, as Datastar's patch modes.
 */
enum PatchMode: string {
    /** Morph the target element, matched by $selector or by the element's id. */
    case Outer = 'outer';

    /** Morph the target's children. */
    case Inner = 'inner';

    /** Replace the target element without morphing. */
    case Replace = 'replace';

    /** Insert as the target's first child. */
    case Prepend = 'prepend';

    /** Insert as the target's last child. */
    case Append = 'append';

    /** Insert before the target. */
    case Before = 'before';

    /** Insert after the target. */
    case After = 'after';

    /** Remove the target; no HTML needed. */
    case Remove = 'remove';
}
