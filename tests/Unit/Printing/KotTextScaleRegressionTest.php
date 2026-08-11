<?php

namespace Tests\Unit\Printing;

use App\Services\Printing\EscPosPayloadService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The layout screen's font size must reach the PRINTER, not just the browser preview.
 *
 * font_size / kot_font_size were CSS pixels and nothing else — the ESC/POS payload emitted no
 * size bytes at all, so Khatri's kitchen tickets printed at Font A single size no matter what the
 * setting said, and changing it appeared to do nothing.
 */
class KotTextScaleRegressionTest extends TestCase
{
    private function call(string $method, array $args)
    {
        $m = new ReflectionMethod(EscPosPayloadService::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(new EscPosPayloadService(), $args);
    }

    public function test_the_seeded_defaults_do_not_change_any_existing_tenants_output(): void
    {
        // Receipts seed at 12 and KOTs at 14. Both must stay at normal size, or every tenant's
        // tickets would change the moment this shipped.
        $this->assertSame(['w' => 1, 'h' => 1], $this->call('scaleFor', [12]));
        $this->assertSame(['w' => 1, 'h' => 1], $this->call('scaleFor', [14]));
        $this->assertSame(['w' => 1, 'h' => 1], $this->call('scaleFor', [null]));
    }

    public function test_raising_the_font_size_selects_a_bigger_printer_scale(): void
    {
        $this->assertSame(['w' => 1, 'h' => 2], $this->call('scaleFor', [16]));
        $this->assertSame(['w' => 2, 'h' => 2], $this->call('scaleFor', [18]));
        $this->assertSame(['w' => 3, 'h' => 3], $this->call('scaleFor', [24]));
    }

    public function test_the_size_command_encodes_width_in_the_high_nibble(): void
    {
        // GS ! n — high nibble width-1, low nibble height-1.
        $this->assertSame("\x1D\x21\x00", $this->call('sizeCommand', [1, 1]));
        $this->assertSame("\x1D\x21\x01", $this->call('sizeCommand', [1, 2]));
        $this->assertSame("\x1D\x21\x11", $this->call('sizeCommand', [2, 2]));
        $this->assertSame("\x1D\x21\x22", $this->call('sizeCommand', [3, 3]));
        // Out-of-range multipliers are clamped rather than emitting a garbage byte.
        $this->assertSame("\x1D\x21\x77", $this->call('sizeCommand', [99, 99]));
    }

    /**
     * The printer does NOT wrap for you. At double width only 21 characters fit, and the overflow
     * is printed mid-word on the next line or dropped depending on the model — a ticket reading
     * "BEEF CHANGEZI PULAO S" with the rest missing is worse than a small one.
     */
    public function test_text_wraps_to_the_width_the_scale_actually_leaves(): void
    {
        $out = $this->call('scaled', ['1 x BEEF CHANGEZI PULAO SPECIAL (1/2 KG)', ['w' => 2, 'h' => 2], true, false, 42]);
        // Strip the escape sequences FIRST — stripping control characters first would eat the
        // 0x1D/0x1B lead bytes and leave their arguments behind as visible text. Newlines are
        // kept deliberately: they are what the wrap assertion measures.
        $body = preg_replace(['/\x1D\x21./s', '/\x1B\x45./s'], '', $out);

        foreach (array_filter(explode("\n", trim($body))) as $line) {
            $this->assertLessThanOrEqual(21, mb_strlen($line), 'Double-width line overflowed: ' . $line);
        }
        // Nothing may be LOST to the wrap — the words may be redistributed across lines, so
        // compare with the line breaks normalised back to spaces.
        $this->assertSame(
            '1 x BEEF CHANGEZI PULAO SPECIAL (1/2 KG)',
            trim(preg_replace('/\s+/', ' ', $body))
        );
    }

    public function test_a_scaled_block_always_returns_the_printer_to_normal(): void
    {
        // Without the reset the next block inherits the size and the whole ticket prints huge.
        $out = $this->call('scaled', ['DELIVERY', ['w' => 2, 'h' => 2], true, true, 42]);

        $this->assertStringEndsWith("\x1D\x21\x00", $out);
        $this->assertStringContainsString("\x1B\x45\x01", $out, 'Bold must be switched on.');
        $this->assertStringContainsString("\x1B\x45\x00", $out, 'Bold must be switched back off.');
    }

    /**
     * A receipt may grow taller but never wider.
     *
     * Every money row is a 42-column two-column layout — label left, amount right. At double width
     * the line holds 21 characters and the amounts stop lining up under one another, which on a
     * customer's bill is worse than small text.
     */
    public function test_a_receipt_money_row_keeps_its_columns_at_every_height(): void
    {
        $row = str_pad('Subtotal', 42 - 8, ' ') . '2,060.00';

        foreach ([1, 2, 3] as $height) {
            $out = $this->call('scaled', [$row, ['w' => 1, 'h' => $height], false, false, 42]);
            $body = preg_replace(['/\x1D\x21./s', '/\x1B\x45./s'], '', $out);

            $this->assertSame($row, trim($body, "\n"), "Height {$height} must not re-wrap a money row.");
            $this->assertStringEndsWith('2,060.00', trim($body), 'The amount must stay right-aligned.');
        }
    }

    public function test_normal_scale_still_emits_plain_full_width_text(): void
    {
        $out = $this->call('scaled', ['CASHIER: Delivery Counter', ['w' => 1, 'h' => 1], false, false, 42]);

        $this->assertSame("\x1D\x21\x00CASHIER: Delivery Counter\n\x1D\x21\x00", $out);
    }
}
