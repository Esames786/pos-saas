<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\Cloud\QrSvg;
use PHPUnit\Framework\TestCase;

/**
 * EDGE 0.9 (K7 phone page) — the dependency-free QR encoder produces symbols a standard reader decodes. No decoder is
 * installed on the build box, so this test DECODES the symbol itself with independently written code and published spec
 * constants (ISO/IEC 18004 alignment-pattern table, error-correction block table for level M, format/version information
 * strings): format info (both copies) → mask; function-pattern map built from the spec table; zigzag read; block
 * de-interleave; Reed–Solomon syndromes (GF(256) log tables) all zero; byte-mode parse returns the exact input.
 */
class EdgeSetupQrCodeTest extends TestCase
{
    /** ISO 18004 Annex E — alignment pattern centre coordinates. */
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38],
        8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    /** ISO 18004 Table 9, level M — [ecc codewords per block, [[blocks, data codewords per block], …]]. */
    private const BLOCKS_M = [1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]]];
    /** Format information strings for level M, masks 0–7 (after the 101010000010010 XOR). */
    private const FORMAT_M = ['101010000010010', '101000100100101', '101111001111100', '101101101001011',
        '100010111111001', '100000011001110', '100111110010111', '100101010100000'];
    /** Version information (versions 7–10). */
    private const VERSION_INFO = [7 => '000111110010010100', 8 => '001000010110111100', 9 => '001001101010011001', 10 => '001010010011010011'];

    public function test_symbols_of_several_sizes_decode_back_to_the_exact_text(): void
    {
        $texts = [
            'A',
            'https://192.168.100.52:8443/edge/local/login',
            'https://bingoo-edge.local:8443/edge/local/pos/health?branch=12&x=' . str_repeat('y', 40),
            'https://khatri.bingoopos.com/settings/offline-edge/branches/1/ca-public.crt?fp=0123456789abcdef&expires=1795000000&signature=' . str_repeat('a1b2c3d4', 8),
        ];
        $versions = [];
        foreach ($texts as $t) {
            $qr = QrSvg::encode($t);
            $versions[] = $qr->version;
            $this->assertSame($t, $this->decode($qr), "round trip at version {$qr->version}");
        }
        $this->assertContains(1, $versions);
        $this->assertTrue(max($versions) >= 7, 'a symbol with version information is exercised');
    }

    public function test_every_mask_decodes(): void
    {
        $t = 'https://192.168.100.52:8443/edge/local/login';
        for ($m = 0; $m < 8; $m++) {
            $qr = QrSvg::encode($t, $m);
            $this->assertSame($m, $qr->mask);
            $this->assertSame($t, $this->decode($qr), "mask {$m}");
        }
    }

    public function test_the_svg_is_self_contained_black_on_white(): void
    {
        $svg = QrSvg::encode('https://192.168.1.10:8443/edge/local/login')->svg(200, 'Login "QR"');
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg);
        $this->assertStringContainsString('fill="#000000"', $svg);
        $this->assertStringContainsString('aria-label="Login &quot;QR&quot;"', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('http://192', $svg);
    }

    /* ── an independent decoder for the symbols under test ─────────────── */

    private function decode(QrSvg $qr): string
    {
        $n = $qr->size;
        $v = ($n - 17) / 4;
        $this->assertArrayHasKey($v, self::ALIGN, 'test decoder covers versions 1–10');
        $m = fn (int $x, int $y) => $qr->isDark($x, $y);

        // Finder patterns at the three corners.
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
            for ($dy = 0; $dy < 7; $dy++) {
                for ($dx = 0; $dx < 7; $dx++) {
                    $ring = max(abs($dx - 3), abs($dy - 3));
                    $this->assertSame($ring !== 2, $m($ox + $dx, $oy + $dy), 'finder pattern');
                }
            }
        }
        // Format information — copy 1 and copy 2 agree and name level M + a mask.
        $f1 = '';
        foreach ([[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]] as $i => [$x, $y]) {
            $f1 = ($m($x, $y) ? '1' : '0') . $f1;   // bit i (LSB first)
        }
        $f2 = '';
        for ($i = 0; $i < 15; $i++) {
            [$x, $y] = $i < 8 ? [$n - 1 - $i, 8] : [8, $n - 15 + $i];
            $f2 = ($m($x, $y) ? '1' : '0') . $f2;
        }
        $this->assertSame($f1, $f2, 'both format copies');
        $mask = array_search($f1, self::FORMAT_M, true);
        $this->assertNotFalse($mask, "format {$f1} is a valid level-M format string");
        $this->assertTrue($m(8, $n - 8), 'dark module');

        // Version information (v ≥ 7), both copies.
        if ($v >= 7) {
            $a = '';
            $b = '';
            for ($i = 0; $i < 18; $i++) {
                $a = ($m($n - 11 + $i % 3, intdiv($i, 3)) ? '1' : '0') . $a;
                $b = ($m(intdiv($i, 3), $n - 11 + $i % 3) ? '1' : '0') . $b;
            }
            $this->assertSame(self::VERSION_INFO[$v], $a);
            $this->assertSame(self::VERSION_INFO[$v], $b);
        }

        // Function-pattern map from the spec.
        $fn = [];
        $mark = function (int $x, int $y) use (&$fn, $n) {
            if ($x >= 0 && $y >= 0 && $x < $n && $y < $n) {
                $fn[$y][$x] = true;
            }
        };
        // Finder + separator + format area: 9×9 top-left, 8 wide × 9 tall top-right, 9 wide × 8 tall bottom-left.
        for ($i = 0; $i < 9; $i++) {
            for ($j = 0; $j < 9; $j++) {
                $mark($i, $j);
                if ($i < 8) {
                    $mark($n - 1 - $i, $j);
                    $mark($j, $n - 1 - $i);
                }
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $mark(6, $i);
            $mark($i, 6);
        }
        for ($i = 0; $i < 8; $i++) {
            $mark($n - 1 - $i, 8);
            $mark(8, $n - 1 - $i);
        }
        $al = self::ALIGN[$v];
        foreach ($al as $ay) {
            foreach ($al as $ax) {
                if (($ax === 6 && $ay === 6) || ($ax === 6 && $ay === end($al)) || ($ax === end($al) && $ay === 6)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $mark($ax + $dx, $ay + $dy);
                    }
                }
            }
        }
        if ($v >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $mark($n - 11 + $j, $i);
                    $mark($i, $n - 11 + $j);
                }
            }
        }

        // Zigzag read with the mask removed.
        $bits = [];
        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            $upward = (intdiv($n - 1 - $col, 2) % 2) === 0;
            for ($k = 0; $k < $n; $k++) {
                $y = $upward ? $n - 1 - $k : $k;
                foreach ([$col, $col - 1] as $x) {
                    if (! isset($fn[$y][$x])) {
                        $bits[] = ($m($x, $y) xor $this->maskAt((int) $mask, $x, $y)) ? 1 : 0;
                    }
                }
            }
        }
        $codewords = [];
        for ($i = 0; $i + 8 <= count($bits); $i += 8) {
            $codewords[] = bindec(implode('', array_slice($bits, $i, 8)));
        }

        // De-interleave per the spec block table and check every block's RS syndromes.
        [$ecc, $groups] = self::BLOCKS_M[$v];
        $lens = [];
        foreach ($groups as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $lens[] = $len;
            }
        }
        $blocks = array_fill(0, count($lens), []);
        $p = 0;
        for ($i = 0; $i < max($lens); $i++) {
            foreach ($lens as $b => $len) {
                if ($i < $len) {
                    $blocks[$b][] = $codewords[$p++];
                }
            }
        }
        $eccs = array_fill(0, count($lens), []);
        for ($i = 0; $i < $ecc; $i++) {
            foreach ($lens as $b => $len) {
                $eccs[$b][] = $codewords[$p++];
            }
        }
        [$exp, $log] = $this->gfTables();
        $data = [];
        foreach ($blocks as $b => $dat) {
            $word = array_merge($dat, $eccs[$b]);
            for ($r = 0; $r < $ecc; $r++) {
                $s = 0;
                foreach ($word as $c) {   // Horner: s = s·α^r + c
                    $s = ($s === 0 ? 0 : $exp[($log[$s] + $r) % 255]) ^ $c;
                }
                $this->assertSame(0, $s, "RS syndrome {$r} of block {$b} (version {$v})");
            }
            $data = array_merge($data, $dat);
        }

        // Byte-mode parse.
        $bitstr = implode('', array_map(fn ($c) => str_pad(decbin($c), 8, '0', STR_PAD_LEFT), $data));
        $this->assertSame('0100', substr($bitstr, 0, 4), 'byte mode');
        $cntBits = $v <= 9 ? 8 : 16;
        $count = bindec(substr($bitstr, 4, $cntBits));
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= chr(bindec(substr($bitstr, 4 + $cntBits + 8 * $i, 8)));
        }

        return $out;
    }

    private function maskAt(int $mask, int $j, int $i): bool
    {
        // ISO 18004 Table 10 — i = row, j = column.
        return match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => (($i * $j) % 2) + (($i * $j) % 3) === 0,
            6 => ((($i * $j) % 2) + (($i * $j) % 3)) % 2 === 0,
            7 => ((($i + $j) % 2) + (($i * $j) % 3)) % 2 === 0,
        };
    }

    /** @return array{0: array<int,int>, 1: array<int,int>} */
    private function gfTables(): array
    {
        $exp = [];
        $log = [];
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }

        return [$exp, $log];
    }
}
