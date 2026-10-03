<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\State\SessionTokens;

/**
 * The session of one request: its key, the cookie it goes by and what that cookie's response sets.
 *
 * Middleware gets it in the request attribute named after this class, so Via::regenerateSession()
 * reaches the response of the request it was handed.
 *
 * @internal
 */
final class RequestSession {
    /** The cookie a request without a valid one gets. */
    public const string NEW = 'new';

    /** Via::regenerateSession() asked for a new cookie. */
    public bool $rotate = false;

    /** The response's session cookie is decided. */
    public bool $written = false;

    /**
     * @param string                                                                     $key   the session key, which getSessionId() returns
     * @param string                                                                     $token the cookie the request sent, or the one issued to it
     * @param self::NEW|SessionTokens::CURRENT|SessionTokens::FRESH|SessionTokens::GRACE $state
     */
    public function __construct(
        public readonly string $key,
        public readonly string $token,
        public readonly string $state,
    ) {}
}
