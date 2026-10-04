<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

/**
 * The cookies of one simulated browser, shared by its tabs.
 *
 * @internal
 */
final class CookieJar {
    /** @var array<string, string> */
    private array $cookies = [];

    /**
     * @return array<string, string> what the browser sends with a request
     */
    public function all(): array {
        return $this->cookies;
    }

    /**
     * Keep the cookies a response sets, and drop those it deletes.
     */
    public function take(TestResponse $response): void {
        foreach ($response->cookies as $name => $value) {
            if ($value === null) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }
}
