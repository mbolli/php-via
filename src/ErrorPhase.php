<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * Where a throw that Via::onError() reports came from.
 */
enum ErrorPhase: string {
    /** An action threw, or something it called, such as a sync() whose view threw. */
    case Action = 'action';

    /**
     * A page handler or view threw on page load or revival, a view on a stream's first sync or in a broadcast,
     * or a Context::download() source.
     */
    case Render = 'render';

    /** A Context::setInterval() or Via::setInterval() callback threw. */
    case Timer = 'timer';

    /** A Context::spawn() task threw. */
    case Task = 'task';

    /** A Via::route() handler or its middleware threw. */
    case Route = 'route';
}
