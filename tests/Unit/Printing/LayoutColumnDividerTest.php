<?php

namespace Tests\Unit\Printing;

use App\Services\Printing\EscPosPayloadService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PRINT-LAYOUT-ROWS-1 — the column builders gain an optional `|` divider between columns, and the
 * receipt width measurement reserves the wider gap so the name column shrinks by exactly the right
 * amount. Off by default, so a tenant that never enables dividers prints byte-for-byte as before.
 */
class LayoutColumnDividerTest extends TestCase
{
    private function call(string $method, array $args)
    {
        $m = new ReflectionMethod(EscPosPayloadService::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(new EscPosPayloadService(), $args);
    }

    public function test_qty_item_columns_add_a_divider_only_when_asked(): void
    {
        $plain = $this->call('qtyItemColumns', ['2', 'CHICKEN BIRYANI', 42, false]);
        $ruled = $this->call('qtyItemColumns', ['2', 'CHICKEN BIRYANI', 42, true]);

        $this->assertStringNotContainsString('|', $plain, 'no divider by default');
        $this->assertStringContainsString(' | ', $ruled, 'a | divides Qty from Item when enabled');
        // The item name survives either way — the divider steals width from the name column, never the name.
        $this->assertStringContainsString('CHICKEN BIRYANI', $plain);
        $this->assertStringContainsString('CHICKEN BIRYANI', $ruled);
    }

    public function test_receipt_item_columns_ruled_between_all_four_columns(): void
    {
        [$nameW, $qtyW, $rateW, $amountW] = $this->call('receiptColumnWidths', [[['2', '330', '660']], 42, true]);
        $row = $this->call('itemColumns', ['Chicken Biryani', '2', '330', '660', $nameW, $qtyW, $rateW, $amountW, true]);

        // Three dividers: Item | Qty | Rate | Amount.
        $this->assertSame(3, substr_count($row, ' | '), 'Item | Qty | Rate | Amount → three dividers');
        $this->assertStringContainsString('660', $row, 'the amount is still there');
    }

    public function test_dividers_reserve_their_width_from_the_name_column(): void
    {
        [$namePlain] = $this->call('receiptColumnWidths', [[['2', '330', '660']], 42, false]);
        [$nameRuled] = $this->call('receiptColumnWidths', [[['2', '330', '660']], 42, true]);

        // Each of the 3 gaps grows from 1 char to 3 → the name column loses exactly 6.
        $this->assertSame($namePlain - 6, $nameRuled, 'the name column gives up exactly the extra gap width');
    }
}
