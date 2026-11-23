<?php

namespace App\Services\Edge;

use InvalidArgumentException;

/**
 * Edge 0.9 stream D (counter setup) — a small, dependency-free QR Code encoder (ISO/IEC 18004, byte mode, versions 1-40,
 * error-correction levels L/M/Q/H, automatic mask selection) that renders an inline SVG. Used by the phone onboarding
 * partial to show the Branch Server login URL (and the branch-CA download link) as a QR without any CDN or JS library.
 *
 * Only PUBLIC text is ever encoded (LAN URLs). The algorithm follows the reference structure of Project Nayuki's
 * QR Code generator (MIT); the output was verified by decoding it with an independent decoder (jsQR 1.4.0) — the
 * known-answer test in EdgeCounterSetupQrCodeTest pins that verified matrix.
 */
final class EdgeCounterSetupQrCode
{
    private const ECC_CODEWORDS_PER_BLOCK = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** Two-bit format value of each error-correction level (ISO 18004 table 12). */
    private const FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    private int $size;

    /** @var list<list<bool>> [y][x] */
    private array $modules = [];

    /** @var list<list<bool>> [y][x] */
    private array $isFunction = [];

    private function __construct(private readonly int $version, private readonly string $ecl)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    /**
     * The module matrix of $text: list of rows, each a list of booleans (true = dark), WITHOUT the quiet zone.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $text, string $ecl = 'M'): array
    {
        return self::encode($text, $ecl)->modules;
    }

    /** The QR version (1-40) that $text needs at $ecl. */
    public static function versionFor(string $text, string $ecl = 'M'): int
    {
        return self::encode($text, $ecl)->version;
    }

    /** Inline SVG (black modules on a white square incl. a 4-module quiet zone). Safe to echo unescaped. */
    public static function svg(string $text, string $ecl = 'M', int $pixelSize = 200, ?string $label = null): string
    {
        $qr = self::encode($text, $ecl);
        $border = 4;
        $dim = $qr->size + 2 * $border;
        $path = '';
        foreach ($qr->modules as $y => $row) {
            $x = 0;
            while ($x < $qr->size) {
                if (! $row[$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $qr->size && $row[$x]) {
                    $x++;
                }
                $len = $x - $start;
                $path .= 'M' . ($start + $border) . ',' . ($y + $border) . 'h' . $len . 'v1h-' . $len . 'z';
            }
        }
        $aria = htmlspecialchars($label ?? ('QR code: ' . $text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $px = max(64, $pixelSize);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $px . '" height="' . $px . '"'
            . ' shape-rendering="crispEdges" role="img" aria-label="' . $aria . '">'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
            . '<path d="' . $path . '" fill="#000000"/></svg>';
    }

    /** Maximum byte-mode payload of a version at an error-correction level (the ISO capacity table). */
    public static function byteCapacity(int $version, string $ecl = 'M'): int
    {
        self::assertEcl($ecl);
        if ($version < 1 || $version > 40) {
            throw new InvalidArgumentException('QR version must be 1..40.');
        }
        $ccBits = $version <= 9 ? 8 : 16;

        return intdiv(self::numDataCodewords($version, $ecl) * 8 - 4 - $ccBits, 8);
    }

    // ---- encoding ------------------------------------------------------------------------------------------------

    private static function encode(string $text, string $ecl): self
    {
        self::assertEcl($ecl);
        $bytes = $text === '' ? [] : array_values(unpack('C*', $text));
        $count = count($bytes);

        $version = 0;
        for ($v = 1; $v <= 40; $v++) {
            if ($count <= self::byteCapacity($v, $ecl)) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Text is too long for a QR code.');
        }

        // Segment: byte mode (0100), character count, data.
        $bits = [];
        self::appendBits($bits, 0x4, 4);
        self::appendBits($bits, $count, $version <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            self::appendBits($bits, $b, 8);
        }
        $capacityBits = self::numDataCodewords($version, $ecl) * 8;
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bits, $pad, 8);
        }

        $data = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) {
            $data[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        $qr = new self($version, $ecl);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($data));

        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->applyMask($mask);
            $qr->drawFormatBits($mask);
            $penalty = $qr->penaltyScore();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
            $qr->applyMask($mask); // XOR undo
        }
        $qr->applyMask($bestMask);
        $qr->drawFormatBits($bestMask);

        return $qr;
    }

    private static function assertEcl(string $ecl): void
    {
        if (! isset(self::FORMAT_BITS[$ecl])) {
            throw new InvalidArgumentException('Error-correction level must be L, M, Q or H.');
        }
    }

    /** @param list<int> $bits */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function numRawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords(int $version, string $ecl): int
    {
        return intdiv(self::numRawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecl][$version] * self::NUM_ERROR_CORRECTION_BLOCKS[$ecl][$version];
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecl][$this->version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecl][$this->version];
        $rawCodewords = intdiv(self::numRawDataModules($this->version), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::reedSolomonDivisor($blockEccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::reedSolomonRemainder($dat, $divisor);
            if ($i < $numShortBlocks) {
                $dat[] = 0;
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
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param  list<int>  $data
     * @param  list<int>  $divisor
     * @return list<int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMultiply($coef, $factor);
            }
        }

        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    // ---- drawing -------------------------------------------------------------------------------------------------

    private function setFunctionModule(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }

        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        $positions = $this->alignmentPatternPositions();
        $n = count($positions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                $this->drawAlignmentPattern($positions[$i], $positions[$j]);
            }
        }

        $this->drawFormatBits(0); // placeholder, overwritten once the mask is chosen
        $this->drawVersion();
    }

    private function drawFinderPattern(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunctionModule($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignmentPattern(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPatternPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [];
        for ($i = 0; $i < $numAlign - 1; $i++) {
            $result[] = $this->size - 7 - $i * $step;
        }
        $result[] = 6;

        return array_reverse($result);
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, self::bit($bits, $i));
        }
        $this->setFunctionModule(8, 7, self::bit($bits, 6));
        $this->setFunctionModule(8, 8, self::bit($bits, 7));
        $this->setFunctionModule(7, 8, self::bit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, self::bit($bits, $i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, self::bit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, self::bit($bits, $i));
        }
        $this->setFunctionModule(8, $this->size - 8, true); // the always-dark module
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
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = self::bit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $dark);
            $this->setFunctionModule($b, $a, $dark);
        }
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $totalBits = count($data) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip the vertical timing column
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (! $this->isFunction[$y][$x] && $i < $totalBits) {
                        $this->modules[$y][$x] = self::bit($data[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** ISO 18004 §8.8.2 penalty rules N1-N4 (lower is better); only steers the mask choice. */
    private function penaltyScore(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $finderA = [true, false, true, true, true, false, true, false, false, false, false];
        $finderB = array_reverse($finderA);

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = [];
                for ($b = 0; $b < $n; $b++) {
                    $line[] = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                }
                $run = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $line[$b] === $line[$b - 1]) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                for ($b = 0; $b + 11 <= $n; $b++) {
                    $slice = array_slice($line, $b, 11);
                    if ($slice === $finderA || $slice === $finderB) {
                        $score += 40;
                    }
                }
            }
        }

        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($y + 1 < $n && $x + 1 < $n) {
                    $c = $m[$y][$x];
                    if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $total = $n * $n;
        $score += intdiv(abs($dark * 100 - $total * 50), $total * 5) * 10;

        return $score;
    }

    private static function bit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) !== 0;
    }
}
