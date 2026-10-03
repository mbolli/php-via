<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Core\Settings;

/**
 * Shared CSRF Origin check. Browsers send Origin on every POST, so a missing one means a non-browser client.
 */
final class OriginPolicy {
    public static function allows(Settings $settings, ?string $origin, ?string $host): bool {
        $devMode = $settings->devMode;

        if ($origin === null) {
            return $devMode || $settings->allowMissingOrigin;
        }

        $trustedOrigins = $settings->trustedOrigins;
        if ($trustedOrigins !== null) {
            return \in_array($origin, $trustedOrigins, strict: true);
        }

        if ($host === null) {
            return $devMode;
        }

        return preg_replace('#^https?://#', '', $origin) === $host;
    }
}
