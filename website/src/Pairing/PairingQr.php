<?php

declare(strict_types=1);

namespace PhpVia\Website\Pairing;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * A QR code as inline SVG with square modules and violet finder rings. It is dark on light in both
 * colour schemes, because some phone cameras do not read inverted codes.
 */
final class PairingQr {
    /** The quiet zone the QR spec asks for, in modules */
    private const int QUIET_ZONE = 4;

    /**
     * @param int $modulePx width of one module in CSS pixels; whole pixels keep the squares crisp
     */
    public static function svg(string $data, int $modulePx = 4): string {
        $options = new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => false]);
        $matrix = (new QRCode($options))->addByteSegment($data)->getQRMatrix();
        $modules = $matrix->getBooleanMatrix();

        $dataPath = '';
        $finderPath = '';
        foreach ($modules as $y => $row) {
            $x = 0;
            $count = \count($row);
            while ($x < $count) {
                if (!$row[$x]) {
                    ++$x;

                    continue;
                }
                // One subpath per horizontal run of dark modules of the same kind
                $isFinder = self::isFinder($matrix, $x, $y);
                $start = $x;
                while ($x < $count && $row[$x] && self::isFinder($matrix, $x, $y) === $isFinder) {
                    ++$x;
                }
                $run = \sprintf('M%d %dh%dv1h-%dz', $start + self::QUIET_ZONE, $y + self::QUIET_ZONE, $x - $start, $x - $start);
                if ($isFinder) {
                    $finderPath .= $run;
                } else {
                    $dataPath .= $run;
                }
            }
        }

        $size = \count($modules) + 2 * self::QUIET_ZONE;

        return \sprintf(
            '<svg class="px-qr-svg" xmlns="http://www.w3.org/2000/svg" width="%4$d" height="%4$d" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges" aria-hidden="true">'
            . '<rect class="px-qr-paper" width="%1$d" height="%1$d" fill="#f3f4fa"/>'
            . '<path class="px-qr-data" fill="#10122e" d="%2$s"/>'
            . '<path class="px-qr-finder" fill="#5a3fd6" d="%3$s"/>'
            . '</svg>',
            $size,
            $dataPath,
            $finderPath,
            $size * $modulePx,
        );
    }

    private static function isFinder(QRMatrix $matrix, int $x, int $y): bool {
        // The ring only: the 3x3 centre is typed M_FINDER_DOT and is drawn with the data
        return $matrix->checkType($x, $y, QRMatrix::M_FINDER);
    }
}
