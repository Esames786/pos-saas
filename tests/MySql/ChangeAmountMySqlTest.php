<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Sales\SalesService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * CHANGE-AMOUNT-1 (found on Khatri prod, sale HS-…-878): the POS caps a payment's applied
 * 'amount' at the bill, so sale-level change computed as paid − grand was ALWAYS 0 even when
 * the customer tendered more cash (5,000 on a 3,600 bill printed "Change 0.00" while the
 * per-payment row correctly held change 1,400). The sale's change_amount must come from the
 * grounded per-payment change (tendered − applied), and the receipt must tell the drawer
 * story: Cash <tendered> / Change <change>.
 */
class ChangeAmountMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'accounts', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
    }

    public function test_change_comes_from_tendered_cash_and_prints_on_the_receipt(): void
    {
        $userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CH' . Str::random(4)]);
        $terminalId = $this->makeTerminal($this->branchId);
        $shift = app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);
        $cash = $this->makePaymentMethod(['method_type' => 'cash']);
        $productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'none', 'is_stock_tracked' => 0]);

        $saleId = $this->makeSale($this->branchId, [
            'status' => 'draft', 'terminal_id' => $terminalId, 'shift_id' => $shift->id,
            'business_date' => $shift->business_date->toDateString(), 'created_by_user_id' => $userId,
            'subtotal' => 3600, 'grand_total' => 3600,
        ]);
        $this->makeSaleLine($saleId, $productId, ['quantity' => 4, 'unit_price' => 900, 'line_total' => 3600]);
        // Grounded POS payload: applied amount capped at the bill; physical cash in 'tendered'.
        DB::connection('tenant')->table('sale_payments')->insert([
            'sales_order_id' => $saleId, 'payment_method_id' => $cash,
            'amount' => 3600, 'tendered_amount' => 5000, 'change_amount' => 1400,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sale = app(SalesService::class)->finalizePaidSale(SalesOrder::findOrFail($saleId));

        $this->assertSame(3600.0, (float) $sale->paid_amount, 'paid = applied amount (settles the bill exactly)');
        $this->assertSame(1400.0, (float) $sale->change_amount, 'change = tendered 5000 − applied 3600');

        $payload = app(EscPosPayloadService::class)->build(PrintJob::findOrFail($this->makePrintJob(null, ['document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null, 'reference_type' => 'sales_order', 'reference_id' => $saleId, 'branch_id' => $this->branchId])));
        $this->assertStringContainsString('Change', $payload);
        $this->assertStringContainsString('1,400.00', $payload, 'receipt prints the real change');
        $this->assertStringContainsString('5,000.00', $payload, 'cash line shows the tendered amount (drawer story)');
        $this->assertStringContainsString("\x1D\x56", $payload, 'ESC/POS auto-cut command is appended');
        $this->assertStringContainsString('BingooPos', $payload, 'receipt branding defaults ON');

        // Exact-cash sale keeps change 0 (no tendered → applied-only rule).
        $exactId = $this->makeSale($this->branchId, [
            'status' => 'draft', 'terminal_id' => $terminalId, 'shift_id' => $shift->id,
            'business_date' => $shift->business_date->toDateString(), 'created_by_user_id' => $userId,
            'subtotal' => 900, 'grand_total' => 900,
        ]);
        $this->makeSaleLine($exactId, $productId, ['quantity' => 1, 'unit_price' => 900, 'line_total' => 900]);
        DB::connection('tenant')->table('sale_payments')->insert([
            'sales_order_id' => $exactId, 'payment_method_id' => $cash,
            'amount' => 900, 'tendered_amount' => null, 'change_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $exact = app(SalesService::class)->finalizePaidSale(SalesOrder::findOrFail($exactId));
        $this->assertSame(0.0, (float) $exact->change_amount);
    }
}
