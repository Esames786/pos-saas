<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * BILL-BARCODE-1 — a Code 128 (set B) barcode as an inline SVG, for the bill number printed big on a bill or a
 * KOT, so the billing counter scans it to bring the held bill up. No library: the 107 bar patterns below are the
 * standard Code 128 table (checked bar-for-bar against picqer/php-barcode-generator in the test suite notes).
 *
 * Set B covers printable ASCII 32–126, which is all a bill code ("BL20339") needs.
 */
final class Code128
{
    /** Bar/space widths of each symbol, bar first (value 0–102, 103–105 start A/B/C, 106 stop). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const STOP = 106;

    /**
     * The module widths of the whole symbol (bar, space, bar, …), without quiet zones.
     *
     * @return int[]
     */
    public static function widths(string $text): array
    {
        if ($text === '') {
            throw new InvalidArgumentException('Nothing to encode.');
        }
        $codes = [self::START_B];
        $sum = self::START_B;
        foreach (str_split($text) as $i => $ch) {
            $value = ord($ch) - 32;
            if ($value < 0 || $value > 94) {
                throw new InvalidArgumentException('Code 128 B cannot encode "' . $ch . '".');
            }
            $codes[] = $value;
            $sum += $value * ($i + 1);
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        $widths = [];
        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $w) {
                $widths[] = (int) $w;
            }
        }

        return $widths;
    }

    /** An inline SVG: black bars on white, a 10-module quiet zone each side, crisp on thermal printers. */
    public static function svg(string $text, int $height = 56, float $module = 2.0): string
    {
        $widths = self::widths($text);
        $x = 10 * $module;
        $rects = '';
        foreach ($widths as $i => $w) {
            $width = $w * $module;
            if ($i % 2 === 0) {
                $rects .= '<rect x="' . self::n($x) . '" y="0" width="' . self::n($width) . '" height="' . $height . '"/>';
            }
            $x += $width;
        }
        $total = $x + 10 * $module;

        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Barcode ' . htmlspecialchars($text, ENT_QUOTES) . '"'
            . ' width="' . self::n($total) . '" height="' . $height . '" viewBox="0 0 ' . self::n($total) . ' ' . $height . '"'
            . ' shape-rendering="crispEdges" style="max-width:100%;height:auto"><rect width="100%" height="100%" fill="#fff"/>'
            . '<g fill="#000">' . $rects . '</g></svg>';
    }

    /** The code a bill carries: "BL" + its id (letters and digits only — every scanner reads it the same). */
    public static function billCode(int $saleId): string
    {
        return 'BL' . $saleId;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
