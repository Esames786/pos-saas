<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Sales\SalesTotalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * DELIVERY-CHARGE-1 — the customer-facing delivery charge, end to end on real MySQL:
 * totals (delivery orders only), GL posting (credit 4150, revenue never inflated, balanced journal),
 * receipt payload line, zero effect on non-delivery sales, and the Edge/offline REFUSAL (delivery is
 * Cloud-only; the field must never slip into a local sale's intent).
 */
class DeliveryChargeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'accounts', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch();
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
    }

    public function test_totals_include_delivery_charge_only_when_passed(): void
    {
        $svc = app(SalesTotalsService::class);
        $lines = [['product_id' => 1, 'category_id' => null, 'quantity' => 2, 'unit_price' => 450, 'discount_amount' => 0, 'tax_amount' => 0, 'line_kind' => 'standard']];

        $with = $svc->calculate($lines, 'none', 0, $this->branchId, 'delivery', null, 0, 120.0);
        $this->assertSame(120.0, $with['delivery_charge_amount']);
        $this->assertSame(1020.0, $with['grand_total'], '900 + 120 delivery');

        $without = $svc->calculate($lines, 'none', 0, $this->branchId, 'quick_sale', null, 0, 0);
        $this->assertSame(0.0, $without['delivery_charge_amount']);
        $this->assertSame(900.0, $without['grand_total']);
    }

    public function test_manual_discount_is_capped_at_remaining_merchandise_value(): void
    {
        $svc = app(SalesTotalsService::class);
        $lines = [['quantity' => 1, 'unit_price' => 100, 'discount_amount' => 10, 'tax_amount' => 0]];

        $fixed = $svc->calculate($lines, 'fixed', 500, $this->branchId, 'quick_sale');
        $percent = $svc->calculate($lines, 'percent', 500, $this->branchId, 'quick_sale');

        $this->assertSame(100.0, $fixed['discount_amount']);
        $this->assertSame(0.0, $fixed['grand_total']);
        $this->assertSame(100.0, $percent['discount_amount']);
        $this->assertSame(0.0, $percent['grand_total']);
    }

    public function test_gl_posting_credits_delivery_income_and_balances(): void
    {
        $cashMethod = $this->makePaymentMethod(['method_type' => 'cash']);
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'paid', 'order_type' => 'delivery', 'subtotal' => 900,
            'tax_amount' => 0, 'service_charge_amount' => 0, 'tip_amount' => 0,
            'delivery_charge_amount' => 120, 'grand_total' => 1020, 'paid_amount' => 1020,
        ]);
        DB::connection('tenant')->table('sale_payments')->insert([
            'sales_order_id' => $saleId, 'payment_method_id' => $cashMethod, 'amount' => 1020,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(\App\Services\Finance\JournalPostingService::class)->postPaidSale(SalesOrder::findOrFail($saleId));

        $lines = DB::connection('tenant')->table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source_type', 'sales_order_paid')->where('journal_entries.source_id', $saleId)
            ->get(['accounts.code', 'journal_lines.debit', 'journal_lines.credit']);

        $delivery = $lines->firstWhere('code', '4150');
        $this->assertNotNull($delivery, 'delivery charge must credit 4150 Delivery Charges Income');
        $this->assertSame(120.0, (float) $delivery->credit);
        $revenue = $lines->firstWhere('code', '4120'); // dine_in/takeaway/delivery revenue account
        $this->assertSame(900.0, (float) $revenue->credit, 'product revenue is NEVER inflated by the delivery charge');
        $this->assertSame(0.0, round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2), 'journal balances');
    }

    public function test_receipt_renders_the_delivery_charge_line(): void
    {
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'paid', 'order_type' => 'delivery', 'subtotal' => 900,
            'delivery_charge_amount' => 120, 'grand_total' => 1020, 'paid_amount' => 1020,
        ]);
        $productId = $this->makeProduct($this->makeCategory());
        $this->makeSaleLine($saleId, $productId, ['quantity' => 2, 'unit_price' => 450, 'line_total' => 900]);
        $jobId = $this->makePrintJob(null, ['document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null, 'reference_type' => 'sales_order', 'reference_id' => $saleId, 'branch_id' => $this->branchId]);

        $payload = app(EscPosPayloadService::class)->build(\App\Models\Tenant\PrintJob::findOrFail($jobId));
        // Short money: the delivery charge line prints "120", never "120.00".
        $this->assertStringContainsString("Delivery Charge", $payload);
        $this->assertStringNotContainsString("120.00", $payload);

        // and a non-delivery sale never shows the line.
        $plainId = $this->makeSale($this->branchId, ['status' => 'paid', 'subtotal' => 900, 'grand_total' => 900]);
        $this->makeSaleLine($plainId, $productId, ['quantity' => 2, 'unit_price' => 450, 'line_total' => 900]);
        $plainJob = $this->makePrintJob(null, ['document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null, 'reference_type' => 'sales_order', 'reference_id' => $plainId, 'branch_id' => $this->branchId]);
        $this->assertStringNotContainsString('Delivery Charge', app(EscPosPayloadService::class)->build(\App\Models\Tenant\PrintJob::findOrFail($plainJob)));
    }

    public function test_edge_offline_sale_refuses_a_delivery_charge(): void
    {
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_local_meta']);
        $userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'DC' . Str::random(4)]);
        $terminalId = $this->makeTerminal($this->branchId);
        $productId = $this->makeProduct($this->makeCategory(), ['is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $cash = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->asBranchServerRuntime();
        $user = \App\Models\Tenant\User::on('tenant')->find($userId);
        \Illuminate\Support\Facades\Auth::guard('tenant')->setUser($user);
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        try {
            app(\App\Services\Edge\EdgeLocalPosService::class)->completePaidSale([
                'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(),
                'delivery_charge_amount' => 50,
                'lines' => [['product_id' => $productId, 'quantity' => 1]],
                'payments' => [['payment_method_id' => $cash, 'amount' => 150]],
            ], $user, $terminalId);
            $this->fail('an offline sale must REFUSE a delivery charge');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('delivery_charge_amount', $e->errors());
        } finally {
            $this->resetRuntimeRole();
        }
        $this->assertSame(0, SalesOrder::on('tenant')->count(), 'nothing persisted');
    }
}
