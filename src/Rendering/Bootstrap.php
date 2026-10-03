<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

/**
 * The tags behind via_head and via_foot, which connect a page to php-via.
 *
 * @internal used by Context::viaHead() and viaFoot(), and by the Dev Bar for the nonce
 */
final class Bootstrap {
    /** Attribute that marks via_head's first tag, so HtmlBuilder can tell a page that has it */
    public const string MARKER = 'data-via-head';

    /** The local signal a stream sets before it ends when its tab should open a new one at once, not at the next interval */
    public const string RECONNECT_SIGNAL = '_via_reconnect';

    /**
     * The via_ctx signal, the import map when there is one, the SSE connect with its reconnect (every 15 s
     * without a stream, and at once when RECONNECT_SIGNAL changes) and connection state, and the beacon that
     * closes the context when the tab goes. In this order, because Datastar applies attributes in document
     * order and the connect needs via_ctx.
     *
     * @param string      $importMapTag the import map tag, '' for none
     * @param null|string $nonce        CSP nonce for every tag
     */
    public static function head(string $contextId, string $basePath, string $importMapTag, ?string $nonce): string {
        $nonceAttribute = self::nonceAttribute($nonce);
        $signals = json_encode(
            ['via_ctx' => $contextId, '_disconnected' => false],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
        $sse = self::jsString($basePath . '_sse');
        $close = self::jsString($basePath . '_session/close');
        $id = self::jsString($contextId);
        $reconnect = self::RECONNECT_SIGNAL;

        $tags = ['<meta ' . self::MARKER . " data-signals='{$signals}'{$nonceAttribute}>"];
        if ($importMapTag !== '') {
            $tags[] = $importMapTag;
        }
        $tags[] = <<<HTML
            <meta data-indicator="_connecting"
                data-on-interval__duration.15s.leading="!\$_connecting && @get({$sse})"
                data-on-signal-patch="@get({$sse})" data-on-signal-patch-filter="{include: /^{$reconnect}\$/}"
                data-on:datastar-fetch="el === evt.detail.el &&
                                   ((evt.detail.type.startsWith('datastar') && (\$_disconnected = false)) ||
                                   (['retrying', 'error', 'finished'].includes(evt.detail.type) && (\$_disconnected = true)))"{$nonceAttribute}>
            HTML;
        $tags[] = "<meta data-init=\"window.addEventListener('beforeunload', () => { navigator.sendBeacon({$close}, {$id}); });\"{$nonceAttribute}>";

        return implode("\n", $tags);
    }

    /**
     * The Datastar module script.
     *
     * @param null|string $nonce CSP nonce for the tag
     */
    public static function foot(string $datastarUrl, ?string $nonce): string {
        return '<script type="module" src="' . htmlspecialchars($datastarUrl, ENT_QUOTES, 'UTF-8') . '"' . self::nonceAttribute($nonce) . '></script>';
    }

    /**
     * ' nonce="..."', or '' without a nonce.
     */
    public static function nonceAttribute(?string $nonce): string {
        return $nonce === null || $nonce === '' ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * A single-quoted JavaScript string for a double-quoted attribute.
     */
    private static function jsString(string $value): string {
        return htmlspecialchars("'" . addcslashes($value, "\\'\n\r") . "'", ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
    }
}
