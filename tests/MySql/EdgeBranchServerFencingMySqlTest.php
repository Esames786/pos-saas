<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Services\Departments\DepartmentConsumptionService;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\JournalService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-LOCAL-POS-1 (invariant 1) — defence-in-depth: on a Branch Server, every official Cloud-authoritative
 * GL / finance / FEFO / official-inventory MUTATION entry point fails CLOSED before writing a single row,
 * regardless of caller. Pure helpers (resolveVariant) remain usable. On Cloud the behaviour is unchanged.
 */
class EdgeBranchServerFencingMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $productId;
    private int $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['cash_bank_account_transactions', 'journal_lines', 'journal_entries', 'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders', 'products', 'categories', 'branches']);
        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct($this->makeCategory(), ['is_stock_tracked' => 1]);
        $this->saleId = (int) SalesOrder::create(['sale_no' => 'SO-' . Str::random(6), 'branch_id' => $this->branchId, 'sale_date' => now(), 'status' => 'paid', 'grand_total' => 100])->id;
    }

    protected function tearDown(): void
    {
        config(['app.role' => null]);
        parent::tearDown();
    }

    private function asBranchServer(): void
    {
        config(['app.role' => 'branch_server']);
    }

    private function assertRefusedAndNoRows(callable $call): void
    {
        $before = [
            'journal_entries' => DB::connection('tenant')->table('journal_entries')->count(),
            'journal_lines' => DB::connection('tenant')->table('journal_lines')->count(),
            'stock_ledgers' => DB::connection('tenant')->table('stock_ledgers')->count(),
            'stock_balances' => DB::connection('tenant')->table('stock_balances')->count(),
            'cash_bank_account_transactions' => DB::connection('tenant')->table('cash_bank_account_transactions')->count(),
        ];
        try {
            $call();
            $this->fail('Cloud-authoritative mutation must be refused on a Branch Server.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('Branch Server', $e->getMessage());
        }
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::connection('tenant')->table($table)->count(), "[$table] must be untouched by the refused call");
        }
    }

    public function test_gl_journal_post_is_refused_on_branch_server(): void
    {
        $this->asBranchServer();
        $this->assertRefusedAndNoRows(fn () => app(JournalService::class)->post('test', 1, 'X', 'x', now()->toDateString(), [['account_code' => '1000', 'debit' => 10, 'credit' => 0]]));
    }

    public function test_official_fefo_out_is_refused_on_branch_server(): void
    {
        $this->asBranchServer();
        $branch = Branch::on('tenant')->find($this->branchId);
        $product = Product::on('tenant')->find($this->productId);
        $this->assertRefusedAndNoRows(fn () => app(InventoryService::class)->postOutFefo($branch, $product, null, 1, 'sale'));
    }

    public function test_official_inventory_in_is_refused_on_branch_server(): void
    {
        $this->asBranchServer();
        $branch = Branch::on('tenant')->find($this->branchId);
        $product = Product::on('tenant')->find($this->productId);
        $this->assertRefusedAndNoRows(fn () => app(InventoryService::class)->postIn($branch, $product, null, 5, 10, 'purchase'));
    }

    public function test_official_transfer_is_refused_on_branch_server(): void
    {
        $this->asBranchServer();
        $branch = Branch::on('tenant')->find($this->branchId);
        $other = Branch::on('tenant')->find($this->makeBranch());
        $product = Product::on('tenant')->find($this->productId);
        $this->assertRefusedAndNoRows(fn () => app(InventoryService::class)->transfer($branch, $other, $product, null, 1, 10, 'transfer', 1, 'T-1'));
    }

    public function test_sale_finalize_and_finance_are_refused_on_branch_server(): void
    {
        $this->asBranchServer();
        $sale = SalesOrder::on('tenant')->find($this->saleId);
        $this->assertRefusedAndNoRows(fn () => app(SalesService::class)->finalizePaidSale($sale));
        $this->assertRefusedAndNoRows(fn () => app(JournalPostingService::class)->postPaidSale($sale));
        $this->assertRefusedAndNoRows(fn () => app(JournalPostingService::class)->postSalesCashBankMovement($sale));
        $this->assertRefusedAndNoRows(fn () => app(DepartmentConsumptionService::class)->processSaleOrder($sale));
    }

    public function test_pure_resolve_variant_helper_still_works_on_branch_server(): void
    {
        $this->asBranchServer();
        // A non-mutating helper must remain usable on a Branch Server (returns null for a no-variant product).
        $this->assertNull(app(InventoryService::class)->resolveVariant(Product::on('tenant')->find($this->productId), null));
    }

    // ── real Cloud behavioural proof: GL post + reverse WORK on Cloud, then reverse is REFUSED on Edge ──
    public function test_cloud_gl_post_and_reverse_work_but_branch_server_refuses_reverse(): void
    {
        config(['app.role' => null]); // Cloud
        $this->seedAccount('1000', 'asset', 'debit');
        $this->seedAccount('4000', 'income', 'credit');

        // Cloud: a REAL balanced journal posts and creates entry + 2 lines (guard must not touch Cloud).
        $entry = app(JournalService::class)->post('edge_fence_probe', 777, 'X', 'probe', now()->toDateString(), [
            ['account_code' => '1000', 'debit' => 10, 'credit' => 0],
            ['account_code' => '4000', 'debit' => 0, 'credit' => 10],
        ]);
        $this->assertNotNull($entry, 'Cloud GL post must still work');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(2, DB::connection('tenant')->table('journal_lines')->count());

        // Now branch_server: reverse() must be REFUSED — original untouched, no reversal entry/lines added.
        $this->asBranchServer();
        $entriesBefore = DB::connection('tenant')->table('journal_entries')->count();
        $linesBefore = DB::connection('tenant')->table('journal_lines')->count();
        try {
            app(JournalService::class)->reverse(\App\Models\Tenant\JournalEntry::on('tenant')->find($entry->id), 'test');
            $this->fail('reverse() must be refused on a Branch Server');
        } catch (RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('Branch Server', $e->getMessage());
        }
        $this->assertSame($entriesBefore, DB::connection('tenant')->table('journal_entries')->count(), 'no reversal entry created');
        $this->assertSame($linesBefore, DB::connection('tenant')->table('journal_lines')->count(), 'no reversal lines created');
        $this->assertNull(DB::connection('tenant')->table('journal_entries')->where('reversed_entry_id', $entry->id)->first(), 'original entry not reversed');
    }

    // ── value snapshot (not just counts): a refused official OUT/transfer leaves the real balance unchanged ──
    public function test_refused_official_stock_out_and_transfer_leave_balance_values_unchanged(): void
    {
        $branch = Branch::on('tenant')->find($this->branchId);
        $product = Product::on('tenant')->find($this->productId);
        $key = $this->branchId . '-' . $this->productId . '-0-0';
        DB::connection('tenant')->table('stock_balances')->insert(['balance_key' => $key, 'branch_id' => $this->branchId, 'product_id' => $this->productId, 'quantity_on_hand' => 25, 'average_cost' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $qtyBefore = (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand');
        $costBefore = (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('average_cost');
        $ledgerBefore = DB::connection('tenant')->table('stock_ledgers')->count();

        $this->asBranchServer();
        foreach ([
            fn () => app(InventoryService::class)->postOutFefo($branch, $product, null, 5, 'sale'),
            fn () => app(InventoryService::class)->transfer($branch, Branch::on('tenant')->find($this->makeBranch()), $product, null, 5, 7, 'transfer', 1, 'T-1'),
        ] as $call) {
            try {
                $call();
                $this->fail('official inventory mutation must be refused on a Branch Server');
            } catch (RuntimeException $e) {
                $this->assertStringContainsStringIgnoringCase('Branch Server', $e->getMessage());
            }
        }
        $this->assertSame($qtyBefore, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('quantity_on_hand'), 'quantity_on_hand VALUE unchanged');
        $this->assertSame($costBefore, (float) DB::connection('tenant')->table('stock_balances')->where('balance_key', $key)->value('average_cost'), 'average_cost VALUE unchanged');
        $this->assertSame($ledgerBefore, DB::connection('tenant')->table('stock_ledgers')->count(), 'no ledger movement');
    }

    private function seedAccount(string $code, string $type, string $normal): void
    {
        if (! DB::connection('tenant')->table('accounts')->where('code', $code)->exists()) {
            DB::connection('tenant')->table('accounts')->insert(['code' => $code, 'name' => 'Acct ' . $code, 'type' => $type, 'normal_balance' => $normal, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
