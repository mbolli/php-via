<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Config;

/**
 * CSRF Origin check shared by action POSTs and the Dev Bar's write endpoints.
 *
 * - No Origin: allowed in dev mode or with Config::withAllowMissingOrigin(), denied otherwise,
 *   with or without an allowlist. Browsers send Origin on every POST, so only non-browser
 *   clients (curl, server-to-server calls, uptime checks) omit it.
 * - Origin with an allowlist (Config::withTrustedOrigins()): exact match.
 * - Origin without an allowlist: its host must equal the Host header, scheme ignored so it works
 *   behind a TLS-terminating proxy. No Host header: allowed in dev mode only.
 */
final class OriginPolicy {
    public static function allows(Config $config, ?string $origin, ?string $host): bool {
        $devMode = $config->getDevMode();

        if ($origin === null) {
            return $devMode || $config->getAllowMissingOrigin();
        }

        $trustedOrigins = $config->getTrustedOrigins();
        if ($trustedOrigins !== null) {
            return \in_array($origin, $trustedOrigins, strict: true);
        }

        if ($host === null) {
            return $devMode;
        }

        return preg_replace('#^https?://#', '', $origin) === $host;
    }
}
