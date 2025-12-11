<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * Defines the scope of state and rendering for a page.
 *
 * Scope determines:
 * - How many times views are rendered (once for all users vs per user)
 * - Whether view output is cached
 * - How broadcasts are handled
 */
enum Scope: string {
    /**
     * Global scope - shared across ALL routes and clients.
     * Not yet implemented - reserved for future use.
     */
    case GLOBAL = 'global';

    /**
     * Route scope - shared across all clients on the same route.
     *
     * Characteristics:
     * - View is rendered ONCE and cached
     * - Same HTML sent to all connected clients
     * - Efficient for multiplayer/collaborative apps
     * - Auto-detected when page uses ONLY route actions, no context signals
     *
     * Use cases:
     * - Game of Life (shared board)
     * - Collaborative whiteboards
     * - Live dashboards
     * - Shared todo lists
     */
    case ROUTE = 'route';

    /**
     * Session scope - shared across all tabs for the same browser session.
     * Not yet implemented - reserved for future use.
     */
    case SESSION = 'session';

    /**
     * Tab scope - isolated to a single browser tab/context (default).
     *
     * Characteristics:
     * - View is rendered PER CONTEXT
     * - Each user gets their own state
     * - No caching (each render is unique)
     * - Auto-detected when page uses context signals
     *
     * Use cases:
     * - User profiles
     * - Personal settings
     * - Individual counters
     * - Per-user forms
     */
    case TAB = 'tab';
}
