<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use OpenSwoole\Http\Request;

/**
 * Reads the Datastar signals a request carries.
 *
 * @internal
 */
final class SignalParser {
    /** @var null|\WeakMap<Request, array<string, mixed>> the signals of each request read, as the forwarder, the handler and a revival read them */
    private static ?\WeakMap $read = null;

    /**
     * @return array<string, mixed>
     */
    public static function read(Request $request): array {
        self::$read ??= new \WeakMap();

        return self::$read[$request] ??= self::parse($request->get ?? [], $request->post ?? [], $request->getContent());
    }

    /**
     * Signal source priority:
     *  1. GET  ?datastar=<json>:           Datastar GET actions
     *  2. Raw JSON body:                   Datastar POST/PATCH actions (application/json)
     *  3. POST datastar=<json> field:      Datastar POST via multipart/form-data or
     *                                      application/x-www-form-urlencoded
     *
     * @param array<string, mixed> $get  Parsed GET parameters
     * @param array<string, mixed> $post Parsed POST parameters
     * @param false|string         $body Raw request body
     *
     * @return array<string, mixed>
     */
    public static function parse(array $get, array $post, false|string $body): array {
        if (isset($get['datastar'])) {
            $signals = json_decode((string) $get['datastar'], true);

            return \is_array($signals) ? $signals : [];
        }

        if ($body) {
            $signals = json_decode($body, true);
            if (\is_array($signals)) {
                return $signals;
            }
        }

        if (isset($post['datastar'])) {
            $signals = json_decode((string) $post['datastar'], true);

            return \is_array($signals) ? $signals : [];
        }

        return [];
    }
}
