<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Sales\SalesReturnService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

class SalesReturnIntegrityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'sales_ledgers',
            'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines',
            'sales_orders', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'accounts', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
    }

    public function test_return_prorates_original_discount_and_tax(): void
    {
        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
        $userId = $this->makeUser();
        $saleId = $this->makeSale($branchId, [
            'created_by_user_id' => $userId,
            'subtotal' => 200,
            'discount_amount' => 30,
            'tax_amount' => 18,
            'grand_total' => 188,
            'paid_amount' => 188,
        ]);
        $lineId = $this->makeSaleLine($saleId, $productId, [
            'line_kind' => 'standard',
            'quantity' => 2,
            'unit_price' => 100,
            'discount_amount' => 20,
            'tax_amount' => 18,
            'line_total' => 198,
        ]);

        // A refund method is now mandatory — a return with none posts into Undeposited Funds and
        // never moves the cash, which is how a live day ended with money stuck in suspense.
        $return = app(SalesReturnService::class)->processReturn(
            SalesOrder::findOrFail($saleId),
            [['sales_order_line_id' => $lineId, 'quantity' => 1]],
            'Test return',
            'cash',
            null,
            $userId,
        );

        $this->assertSame(100.0, (float) $return->subtotal);
        $this->assertSame(15.0, (float) $return->discount_amount);
        $this->assertSame(9.0, (float) $return->tax_amount);
        $this->assertSame(94.0, (float) $return->grand_total);
        $this->assertSame(94.0, (float) $return->lines()->first()->line_total);

        $journalLines = DB::connection('tenant')->table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source_type', 'sales_return')
            ->where('journal_entries.source_id', $return->id)
            ->get(['accounts.code', 'journal_lines.debit', 'journal_lines.credit']);

        $this->assertSame(100.0, (float) $journalLines->firstWhere('code', '4110')->debit);
        $this->assertSame(15.0, (float) $journalLines->firstWhere('code', '4200')->credit);
        $this->assertSame(9.0, (float) $journalLines->firstWhere('code', '2200')->debit);
        $this->assertSame(0.0, round((float) $journalLines->sum('debit') - (float) $journalLines->sum('credit'), 2));
    }

    public function test_component_rows_cannot_be_returned_independently(): void
    {
        $branchId = $this->makeBranch();
        $productId = $this->makeProduct($this->makeCategory(), ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
        $userId = $this->makeUser();
        $saleId = $this->makeSale($branchId, ['created_by_user_id' => $userId, 'subtotal' => 50, 'grand_total' => 50]);
        $lineId = $this->makeSaleLine($saleId, $productId, ['line_kind' => 'component', 'line_total' => 50]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be returned separately');

        // Pass a valid refund method so this proves the COMPONENT rule, not the refund-method rule.
        app(SalesReturnService::class)->processReturn(
            SalesOrder::findOrFail($saleId),
            [['sales_order_line_id' => $lineId, 'quantity' => 1]],
            null,
            'cash',
            null,
            $userId,
        );
    }

    /** Goods coming back always means money going back — the method is not optional. */
    public function test_a_return_cannot_be_posted_without_a_refund_method(): void
    {
        $branchId = $this->makeBranch();
        $productId = $this->makeProduct($this->makeCategory(), ['is_stock_tracked' => 0, 'inventory_consumption_method' => 'none']);
        $userId = $this->makeUser();
        $saleId = $this->makeSale($branchId, ['created_by_user_id' => $userId, 'subtotal' => 50, 'grand_total' => 50, 'paid_amount' => 50]);
        $lineId = $this->makeSaleLine($saleId, $productId, ['quantity' => 1, 'unit_price' => 50, 'line_total' => 50]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be posted without a refund method');

        app(SalesReturnService::class)->processReturn(
            SalesOrder::findOrFail($saleId),
            [['sales_order_line_id' => $lineId, 'quantity' => 1]],
            'No method chosen',
            null,
            null,
            $userId,
        );
    }
}
