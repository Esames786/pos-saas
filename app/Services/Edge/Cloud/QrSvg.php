<?php

namespace App\Services\Edge\Cloud;

use InvalidArgumentException;

/**
 * EDGE 0.9 (K7 phone page) — a small, dependency-free QR Code encoder (ISO/IEC 18004) rendering inline SVG, so the
 * Offline Edge phone page can show the Branch Server's IP login URL and the CA download link as QR codes without a CDN
 * script or a new Composer package (every page asset of the app is local).
 *
 * Byte mode, error-correction level M, versions 1–40, Reed–Solomon over GF(256) (poly 0x11D), standard block interleaving,
 * function patterns (finders, separators, timing, alignment, format + version information) and mask selection by the
 * standard penalty rules 1, 2 and 4 (rule 3 omitted: any of the eight masks is a valid, decodable symbol — the score only
 * picks a prettier one). The algorithm follows the public-domain reference structure of Project Nayuki's QR generator.
 */
final class QrSvg
{
    private const ECC_PER_BLOCK_M = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private const NUM_BLOCKS_M = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];
    /** Format-information ECC level bits: L=1, M=0, Q=3, H=2. */
    private const ECL_M_FORMAT = 0;

    public readonly int $version;
    public readonly int $size;
    public readonly int $mask;
    /** @var array<int, array<int, bool>> [y][x] */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];

    private function __construct(int $version, array $dataCodewords, ?int $forcedMask = null)
    {
        $this->version = $version;
        $this->size = $version * 4 + 17;
        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->isFunction[$y] = array_fill(0, $this->size, false);
        }
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($dataCodewords));

        if ($forcedMask !== null) {
            $best = $forcedMask;
        } else {
            $best = 0;
            $minPenalty = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $this->applyMask($m);
                $this->drawFormatBits($m);
                $p = $this->penalty();
                if ($p < $minPenalty) {
                    $best = $m;
                    $minPenalty = $p;
                }
                $this->applyMask($m); // XOR again = undo
            }
        }
        $this->mask = $best;
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    /** Encode text (UTF-8 bytes, byte mode, ECC level M) into the smallest fitting version. */
    public static function encode(string $text, ?int $forcedMask = null): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        for ($ver = 1; $ver <= 40; $ver++) {
            $capacityBits = self::numDataCodewords($ver) * 8;
            $countBits = $ver <= 9 ? 8 : 16;
            $usedBits = 4 + $countBits + 8 * count($bytes);
            if ($usedBits <= $capacityBits && count($bytes) < (1 << $countBits)) {
                return new self($ver, self::dataCodewords($bytes, $ver), $forcedMask);
            }
        }
        throw new InvalidArgumentException('QR: text too long');
    }

    public function isDark(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size && $this->modules[$y][$x];
    }

    /** An inline SVG (4-module quiet zone, crisp edges, black on white regardless of the page theme). */
    public function svg(int $pixels = 240, string $title = 'QR code'): string
    {
        $border = 4;
        $dim = $this->size + 2 * $border;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            $x = 0;
            while ($x < $this->size) {
                if (! $this->modules[$y][$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $this->size && $this->modules[$y][$x]) {
                    $x++;
                }
                $path .= 'M' . ($start + $border) . ',' . ($y + $border) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $pixels . '" height="' . $pixels
            . '" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '">'
            . '<rect width="100%" height="100%" fill="#ffffff"/><path d="' . $path . '" fill="#000000"/></svg>';
    }

    /* ── data ───────────────────────────────────────────────────────────── */

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK_M[$ver] * self::NUM_BLOCKS_M[$ver];
    }

    /** @param list<int> $bytes  @return list<int> */
    private static function dataCodewords(array $bytes, int $ver): array
    {
        $bits = [];
        $append = function (int $val, int $len) use (&$bits) {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $append(0x4, 4);                                   // byte mode
        $append(count($bytes), $ver <= 9 ? 8 : 16);         // character count
        foreach ($bytes as $b) {
            $append($b, 8);
        }
        $capacity = self::numDataCodewords($ver) * 8;
        $append(0, min(4, $capacity - count($bits)));       // terminator
        $append(0, (8 - count($bits) % 8) % 8);             // byte align
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }
        $out = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) {
                $v = ($v << 1) | $bits[$i + $j];
            }
            $out[] = $v;
        }

        return $out;
    }

    /** @param list<int> $data  @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $ver = $this->version;
        $numBlocks = self::NUM_BLOCKS_M[$ver];
        $blockEccLen = self::ECC_PER_BLOCK_M[$ver];
        $rawCodewords = intdiv(self::numRawDataModules($ver), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($blockEccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShortBlocks) {
                $dat[] = 0;   // placeholder, skipped when interleaving
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }

        return $result;
    }

    /** @param list<int> $data  @param list<int> $divisor  @return list<int> */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }

        return $result;
    }

    public static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /* ── function patterns ──────────────────────────────────────────────── */

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                $this->drawAlignment($pos[$i], $pos[$j]);
            }
        }
        $this->drawFormatBits(0);   // reserve the format areas (redrawn with the real mask)
        $this->drawVersion();
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    public function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    public static function formatBits(int $mask): int
    {
        $data = (self::ECL_M_FORMAT << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }

        return (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;
    }

    private function drawFormatBits(int $mask): void
    {
        $bits = self::formatBits($mask);
        $bit = fn (int $i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);   // the always-dark module
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | ($rem & 0xFFF);
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /* ── data placement + masking ───────────────────────────────────────── */

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (! $this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    public static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
            default => throw new InvalidArgumentException('mask'),
        };
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (! $this->isFunction[$y][$x] && self::maskBit($mask, $x, $y)) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalty rules 1 (runs of ≥5), 2 (2×2 blocks) and 4 (dark/light balance). */
    private function penalty(): int
    {
        $n = $this->size;
        $p = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $run = 1;
                for ($b = 1; $b < $n; $b++) {
                    $cur = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                    $prev = $pass === 0 ? $this->modules[$a][$b - 1] : $this->modules[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $p += $run - 2;
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $p += $run - 2;
                }
            }
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        $dark = 0;
        foreach ($this->modules as $row) {
            foreach ($row as $m) {
                $dark += $m ? 1 : 0;
            }
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $p += max(0, $k) * 10;

        return $p;
    }
}
