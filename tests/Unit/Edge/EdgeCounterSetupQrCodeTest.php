<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeCounterSetupQrCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 stream D — the dependency-free QR encoder used on the phone onboarding page.
 * Structure is checked against ISO/IEC 18004 (capacity table, finder/timing patterns, format + version information);
 * the known-answer matrix below was decoded back to its text with an independent decoder (jsQR 1.4.0) on 21 Nov 2026,
 * together with 41 other texts covering versions 1-40 at levels L/M/Q/H.
 */
class EdgeCounterSetupQrCodeTest extends TestCase
{
    private const LOGIN_URL = 'https://192.168.100.52:8443/edge/local/login';

    /** sha256 of the module rows ('1' = dark, rows joined by "\n") of LOGIN_URL at level M; verified with jsQR 1.4.0. */
    private const LOGIN_URL_MATRIX_SHA256 = 'e2e4435188c6384982bebea8f66581a7a18a01ee0170c637d6917f696ad6126a';

    public static function capacities(): array
    {
        // ISO/IEC 18004 table 7, byte mode.
        return [
            'v1-L' => [1, 'L', 17], 'v1-M' => [1, 'M', 14], 'v1-Q' => [1, 'Q', 11], 'v1-H' => [1, 'H', 7],
            'v2-M' => [2, 'M', 26], 'v3-M' => [3, 'M', 42], 'v4-M' => [4, 'M', 62], 'v5-M' => [5, 'M', 84],
            'v6-M' => [6, 'M', 106], 'v7-M' => [7, 'M', 122], 'v8-M' => [8, 'M', 152], 'v9-M' => [9, 'M', 180],
            'v10-M' => [10, 'M', 213], 'v10-L' => [10, 'L', 271], 'v10-H' => [10, 'H', 119],
            'v40-L' => [40, 'L', 2953], 'v40-M' => [40, 'M', 2331], 'v40-Q' => [40, 'Q', 1663], 'v40-H' => [40, 'H', 1273],
        ];
    }

    #[DataProvider('capacities')]
    public function test_byte_capacity_matches_the_iso_table(int $version, string $ecl, int $bytes): void
    {
        $this->assertSame($bytes, EdgeCounterSetupQrCode::byteCapacity($version, $ecl));
    }

    public function test_login_url_matrix_is_the_independently_decoded_one(): void
    {
        $rows = array_map(fn (array $r) => implode('', array_map(fn (bool $b) => $b ? '1' : '0', $r)), EdgeCounterSetupQrCode::matrix(self::LOGIN_URL, 'M'));

        $this->assertSame(4, EdgeCounterSetupQrCode::versionFor(self::LOGIN_URL, 'M'));
        $this->assertSame(self::LOGIN_URL_MATRIX_SHA256, hash('sha256', implode("\n", $rows)));
    }

    public static function texts(): array
    {
        return [
            'login url' => [self::LOGIN_URL, 'M'],
            'short' => ['A', 'H'],
            'version 7+ (version info)' => [str_repeat('x', 150), 'M'],
            'long signed url' => ['https://shop.bingoopos.com/settings/offline-edge/branches/12/ca?expires=1795000000&signature=' . str_repeat('ab12', 16), 'Q'],
        ];
    }

    #[DataProvider('texts')]
    public function test_function_patterns_and_format_information_are_valid(string $text, string $ecl): void
    {
        $m = EdgeCounterSetupQrCode::matrix($text, $ecl);
        $version = EdgeCounterSetupQrCode::versionFor($text, $ecl);
        $size = 17 + 4 * $version;
        $this->assertCount($size, $m);
        foreach ($m as $row) {
            $this->assertCount($size, $row);
        }

        // Finder patterns (7x7: dark ring, light ring, 3x3 dark core) in three corners.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fx, $fy]) {
            for ($dy = 0; $dy < 7; $dy++) {
                for ($dx = 0; $dx < 7; $dx++) {
                    $ring = max(abs($dx - 3), abs($dy - 3));
                    $this->assertSame($ring !== 2, $m[$fy + $dy][$fx + $dx], "finder at $fx,$fy");
                }
            }
        }
        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i]);
            $this->assertSame($i % 2 === 0, $m[$i][6]);
        }
        // Always-dark module.
        $this->assertTrue($m[$size - 8][8]);

        // Format information: both copies equal, valid BCH(15,5), and carrying the requested level.
        $bit = fn (int $x, int $y) => $m[$y][$x] ? 1 : 0;
        $first = 0;
        $second = 0;
        $coordsA = [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]];
        foreach ($coordsA as $i => [$x, $y]) {
            $first |= $bit($x, $y) << $i;
        }
        for ($i = 0; $i < 8; $i++) {
            $second |= $bit($size - 1 - $i, 8) << $i;
        }
        for ($i = 8; $i < 15; $i++) {
            $second |= $bit(8, $size - 15 + $i) << $i;
        }
        $this->assertSame($first, $second);
        $format = $first ^ 0x5412;
        $data = $format >> 10;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $this->assertSame($format, ($data << 10) | ($rem & 0x3FF), 'format BCH code');
        $this->assertSame(['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2][$ecl], $data >> 3);

        // Version information (v7+): both 6x3 blocks carry the version.
        if ($version >= 7) {
            $a = 0;
            $b = 0;
            for ($i = 0; $i < 18; $i++) {
                $a |= $bit($size - 11 + $i % 3, intdiv($i, 3)) << $i;
                $b |= $bit(intdiv($i, 3), $size - 11 + $i % 3) << $i;
            }
            $this->assertSame($a, $b);
            $this->assertSame($version, $a >> 12);
        }
    }

    public function test_svg_is_well_formed_inline_markup_with_an_escaped_label(): void
    {
        $svg = EdgeCounterSetupQrCode::svg(self::LOGIN_URL, 'M', 180, 'QR <2> & "login"');

        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml);
        $this->assertSame('svg', $xml->getName());
        $this->assertSame('0 0 41 41', (string) $xml['viewBox']); // v4 = 33 modules + 2 x 4 quiet zone
        $this->assertSame('QR <2> & "login"', (string) $xml['aria-label']);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('href', $svg);
    }

    public function test_rejects_text_that_does_not_fit_and_unknown_levels(): void
    {
        try {
            EdgeCounterSetupQrCode::matrix(str_repeat('x', 2954), 'L');
            $this->fail('too long text must be refused');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        EdgeCounterSetupQrCode::matrix('x', 'X');
    }
}
