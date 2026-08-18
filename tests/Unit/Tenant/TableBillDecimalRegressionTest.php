<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * The Table Bill / Open Check preview must drop a forced ".00" exactly like the printed receipt
 * (RECEIPT-ROUND, 014a6f2). It was the one money surface still calling number_format(x, 2) directly,
 * so whole rupees showed "1,120.00" while the receipt showed "1,120".
 */
class TableBillDecimalRegressionTest extends TestCase
{
    public function test_table_bill_money_drops_forced_decimals(): void
    {
        $blade = file_get_contents(base_path('resources/views/tenant/pos/partials/table-bill-preview.blade.php'));

        $this->assertStringContainsString('$fmtMoney', $blade, 'the table bill must format money through the drop-.00 helper');

        // The ONLY number_format(..., 2) allowed is inside the $fmtMoney helper itself.
        $this->assertSame(
            1,
            substr_count($blade, 'number_format((float) $v, 2)'),
            'no money field may force ".00" — every amount must go through $fmtMoney',
        );
        $this->assertStringNotContainsString('number_format((float) $sale->grand_total, 2)', $blade,
            'grand total must use $fmtMoney, not a forced two-decimal format');
    }
}
