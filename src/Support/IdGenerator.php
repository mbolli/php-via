<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * Unique ID generation utilities.
 *
 * Generates various types of IDs for contexts, clients, and identicons.
 */
class IdGenerator {
    /**
     * Generate random ID (16 characters hex).
     */
    public static function generate(): string {
        return bin2hex(random_bytes(8));
    }

    /**
     * Generate short ID (8 characters hex).
     */
    public static function generateShort(): string {
        return bin2hex(random_bytes(4));
    }

    /**
     * Generate ID with prefix.
     *
     * @param null|string $prefix Optional prefix for the ID
     */
    public static function generateWithPrefix(?string $prefix = null): string {
        $random = self::generateShort();

        return $prefix ? "{$prefix}-{$random}" : $random;
    }

    /**
     * Generate unique client ID.
     */
    public static function generateClientId(): string {
        return self::generateShort();
    }

    /**
     * Generate SVG identicon based on ID.
     *
     * Creates a 5x5 symmetric pattern based on the input ID.
     *
     * @param string $id Input ID to generate identicon from
     *
     * @return string Data URI containing SVG identicon
     */
    public static function generateIdenticon(string $id): string {
        // Use ID to seed colors and pattern
        $hash = hash('sha256', $id);

        // Extract color from hash
        $hue = hexdec(substr($hash, 0, 2)) / 255 * 360;
        $color = "hsl({$hue}, 70%, 50%)";
        $bgColor = "hsl({$hue}, 70%, 90%)";

        // Generate 5x5 pattern (symmetric, so only need 3 columns)
        $size = 5;
        $cells = [];
        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < 3; ++$x) {
                $index = $y * 3 + $x;
                $cells[$y][$x] = (bool) (hexdec($hash[$index % 64]) % 2);
            }
        }

        // Build SVG
        $cellSize = 20;
        $svgSize = $size * $cellSize;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $svgSize . '" height="' . $svgSize . '" viewBox="0 0 ' . $svgSize . ' ' . $svgSize . '">';
        $svg .= '<rect width="' . $svgSize . '" height="' . $svgSize . '" fill="' . $bgColor . '"/>';

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                // Mirror pattern
                $cellX = $x < 3 ? $x : 4 - $x;
                if ($cells[$y][$cellX]) {
                    $posX = $x * $cellSize;
                    $posY = $y * $cellSize;
                    $svg .= '<rect x="' . $posX . '" y="' . $posY . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="' . $color . '"/>';
                }
            }
        }

        $svg .= '</svg>';

        // Return base64 data URI
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
