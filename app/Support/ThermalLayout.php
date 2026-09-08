<?php

namespace App\Support;

/**
 * THERMAL-ITEM-LAYOUT-1 — the one place that knows how wide a roll is and how a name is made to fit.
 *
 * The report reaches paper down TWO different roads: `EscPosPayloadService::buildReport()` writes
 * the bytes the printer actually eats, and `reports/center/print.blade.php` renders the preview and
 * the PDF. They have already drifted apart once — the roll indented with real spaces while the HTML
 * silently collapsed them, so the same report was legible on paper and flat on screen. Anything both
 * of them must agree on lives here, and neither is allowed its own copy.
 */
class ThermalLayout
{
    /** Characters a line holds at normal width. 72mm of print on 80mm paper is ~42. */
    public const COLS_80MM = 42;
    public const COLS_58MM = 32;

    public static function columns(?string $paper): int
    {
        return $paper === '58mm' ? self::COLS_58MM : self::COLS_80MM;
    }

    /**
     * Fit a name into `$width`, and NEVER let it wrap or overflow.
     *
     * The trailing "(size)" is kept and the HEAD is what gets cut. Dropping the tail instead is the
     * obvious reading of "truncate and add ...", and it is wrong here: Khatri sells
     * "Beef Khatri Biryani (1/2 kg)", "(1 kg)", "Special (1 kg)" and "Special (1/2 kg)". Cut plainly,
     * two pairs of those become the same string — two rows, one name, different figures, and a
     * report nobody can read. The size is the part that tells them apart, so the size survives.
     */
    public static function fit(string $name, int $width): string
    {
        $name = trim($name);
        if ($width < 4) {
            return mb_substr($name, 0, max(0, $width));
        }
        if (mb_strlen($name) <= $width) {
            return $name;
        }

        if (preg_match('/^(.*\S)\s*(\([^)]*\))\s*$/u', $name, $m)) {
            $tail = ' ' . $m[2];
            $head = $width - mb_strlen($tail) - 3;
            // Below ~6 characters the head stops being a name at all; then a plain cut reads better.
            if ($head >= 6) {
                return rtrim(mb_substr($m[1], 0, $head)) . '...' . $tail;
            }
        }

        return rtrim(mb_substr($name, 0, $width - 3)) . '...';
    }

    /**
     * How wide one Sold/Ret/Net column must be, measured from the figures THIS report will print.
     *
     * A guessed width is how the 58mm slip lost its "Qty" and "Amt" labels: three columns sized for
     * 80mm ate the whole line and left nothing for the label. So the figures are measured first and
     * the layout is built around them, never the other way round.
     *
     * @param list<string> $samples every already-formatted figure the report will print
     */
    public static function figureWidth(array $samples): int
    {
        $widest = 3;
        foreach ($samples as $s) {
            $widest = max($widest, mb_strlen((string) $s));
        }

        return $widest + 1;   // one space so two columns never touch
    }

    /**
     * The indents the paper can afford: [item, child]. Parent is always 0.
     *
     * "Qty" is 3 characters and wants a space after it, so an indent may only take what is left of
     * the label budget once the three figure columns have been paid for.
     */
    public static function indents(int $labelBudget): array
    {
        $item  = max(0, min(6, $labelBudget - 4));
        $child = max(0, min(2, intdiv($item, 3)));

        return [$item, $child];
    }

    /** A separator that closes a PARENT category — solid, the heaviest line on the slip. */
    public static function solid(int $cols): string
    {
        return str_repeat('=', max(1, $cols));
    }

    /** A separator that closes a CHILD category — dotted, and it starts at the child's own indent. */
    public static function dotted(int $cols, int $indent = 0): string
    {
        return str_repeat(' ', $indent) . str_repeat('.', max(1, $cols - $indent));
    }

    /** Left-pad to a width without ever growing past it. */
    public static function padLeft(string $s, int $width): string
    {
        return mb_strlen($s) >= $width ? $s : str_repeat(' ', $width - mb_strlen($s)) . $s;
    }

    /** Right-pad to a width, cutting anything that would overflow the line. */
    public static function padRight(string $s, int $width): string
    {
        return mb_strlen($s) >= $width ? mb_substr($s, 0, $width) : $s . str_repeat(' ', $width - mb_strlen($s));
    }

    /** `label` on the left, three figures right-aligned in their own columns. */
    public static function figureRow(string $label, array $cells, int $labelWidth, int $cellWidth): string
    {
        $out = self::padRight($label, $labelWidth);
        foreach ($cells as $c) {
            $out .= self::padLeft((string) $c, $cellWidth);
        }

        return $out;
    }
}
