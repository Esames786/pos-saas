<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentCategoryMap;
use App\Models\Tenant\DepartmentHandover;
use App\Models\Tenant\JournalEntry;
use App\Services\Departments\DepartmentHandoverService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * THIRD-PARTY-DEPARTMENT-HANDOVER-1 — authoritative MySQL proof: a third-party department's sales are
 * reclassified to the owner's payable and settled by cash, balanced and reversible, with stock/COGS
 * never touched.
 */
class DepartmentHandoverTest extends MySqlTenantTestCase
{
    private int $branchId;
    private int $deptId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanTenant([
            'department_handovers', 'department_category_maps', 'departments',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'sales_order_lines', 'sales_orders', 'products', 'categories', 'units', 'accounts', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();

        $this->branchId = DB::connection('tenant')->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'timezone' => 'Asia/Karachi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $catId = DB::connection('tenant')->table('categories')->insertGetId([
            'name' => 'BBQ', 'code' => 'BBQ', 'slug' => 'bbq', 'is_active' => 1, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::connection('tenant')->table('products')->insertGetId([
            'category_id' => $catId, 'unit_id' => $unitId, 'sku' => 'BBQ1', 'name' => 'BBQ Platter', 'slug' => 'bbq-platter',
            'product_type' => 'simple', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_stock_tracked' => 0,
            'default_selling_price' => 5000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $dept = Department::create([
            'branch_id' => $this->branchId, 'code' => 'KASHIF', 'name' => 'Kashif Kitchen', 'status' => 'active',
            'is_third_party' => true, 'owner_name' => 'Kashif',
        ]);
        $this->deptId = $dept->id;
        DepartmentCategoryMap::create(['department_id' => $dept->id, 'category_id' => $catId, 'include_children' => true]);

        // A paid BBQ sale of 5000 today.
        $orderId = DB::connection('tenant')->table('sales_orders')->insertGetId([
            'sale_no' => 'S-1', 'branch_id' => $this->branchId, 'order_type' => 'dine_in',
            'sale_date' => now(), 'business_date' => now()->toDateString(),
            'subtotal' => 5000, 'grand_total' => 5000, 'paid_amount' => 5000, 'status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('sales_order_lines')->insert([
            'sales_order_id' => $orderId, 'product_id' => $productId, 'product_name' => 'BBQ Platter',
            'quantity' => 1, 'unit_price' => 5000, 'line_total' => 5000, 'cost_total' => 2000, 'discount_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): DepartmentHandoverService
    {
        return app(DepartmentHandoverService::class);
    }

    public function test_handover_reclassifies_sales_and_is_balanced_without_touching_stock_or_cogs(): void
    {
        $dept = Department::find($this->deptId);
        $today = now()->toDateString();

        $stockRowsBefore = DB::connection('tenant')->table('stock_balances')->count();

        $handover = $this->service()->postHandover($dept, $today, $today);

        $this->assertSame('5000.0000', (string) $handover->handover_total);
        $this->assertSame(DepartmentHandover::STATUS_PENDING_PAYOUT, $handover->status);

        // The reclass entry: Dr 4210 5000 / Cr payable 5000, balanced.
        $entry = JournalEntry::with('lines.account')->find($handover->reclass_journal_entry_id);
        $this->assertNotNull($entry);
        $byCode = $entry->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit]]);

        $this->assertEqualsWithDelta(5000, $byCode['4210']['d'], 0.001, '4210 must be debited 5000');
        $payable = Account::find($dept->fresh()->payable_account_id);
        $this->assertStringStartsWith('24', $payable->code, 'payable must be a 24xx child');
        $this->assertEqualsWithDelta(5000, $byCode[$payable->code]['c'], 0.001, 'payable must be credited 5000');
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.001, 'entry must balance');

        // NO inventory/COGS accounts touched by the handover.
        foreach (['1400', '5100', '5200'] as $code) {
            $this->assertArrayNotHasKey($code, $byCode->all(), "handover must not touch {$code}");
        }
        $this->assertSame($stockRowsBefore, DB::connection('tenant')->table('stock_balances')->count(), 'stock must be untouched');
    }

    public function test_handover_is_idempotent_per_period(): void
    {
        $dept = Department::find($this->deptId);
        $today = now()->toDateString();

        $this->service()->postHandover($dept, $today, $today);

        $this->expectExceptionMessage('already has a handover');
        $this->service()->postHandover($dept, $today, $today);
    }

    public function test_payout_moves_cash_and_reverse_restores_everything(): void
    {
        $dept = Department::find($this->deptId);
        $today = now()->toDateString();

        $cashCoa = Account::where('code', '1110')->first();
        $cash = CashBankAccount::create([
            'account_id' => $cashCoa->id, 'name' => 'Main Drawer', 'code' => 'CASH', 'account_type' => 'cash',
            'current_balance' => 10000, 'is_active' => 1,
        ]);

        $handover = $this->service()->postHandover($dept, $today, $today);
        $this->service()->postPayout($handover->fresh(), $cash->id);

        $handover->refresh();
        $this->assertSame(DepartmentHandover::STATUS_SETTLED, $handover->status);

        // Payout entry: Dr payable 5000 / Cr 1110 5000.
        $payout = JournalEntry::with('lines.account')->find($handover->payout_journal_entry_id);
        $byCode = $payout->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit]]);
        $this->assertEqualsWithDelta(5000, $byCode['1110']['c'], 0.001, 'cash 1110 credited 5000');

        $this->assertEqualsWithDelta(5000, (float) $cash->fresh()->current_balance, 0.001, 'cash balance down to 5000');

        // Reverse restores income + cash.
        $this->service()->reverse($handover->fresh(), 'test reversal');
        $this->assertSame(DepartmentHandover::STATUS_REVERSED, $handover->fresh()->status);
        $this->assertEqualsWithDelta(10000, (float) $cash->fresh()->current_balance, 0.001, 'cash restored to 10000');
    }
}
