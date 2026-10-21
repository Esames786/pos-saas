<?php

namespace Tests\MySql;

use App\Console\Commands\InventoryExpensePurchaseStockCommand;
use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\ProductController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Services\Finance\ProfitLossService;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KHATRI-STOCK-TO-PNL-1 — cooked food bought from the kitchen and used the same day was received as
 * stock, so every bill went into 1400 Inventory and never reached the P&L. The command makes such an
 * item purchase-only: each bill's share moves to the category cost head ON THE BILL DATE, the stock left
 * is written out, later purchases go straight to cost. Fixture: Beef 10 @ 100 billed 10 Sep, 5 @ 120
 * billed 5 Oct, 2 written off by hand (an adjustment that never touched 1400). 1400 holds 1,600.
 */
class InventoryExpensePurchaseStockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $cooked;      // category → 5100-3
    private int $beef;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines',
            'purchase_bills', 'supplier_ledgers', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'goods_receipt_lines', 'goods_receipts', 'product_translations', 'product_variants', 'products', 'categories',
            'suppliers', 'branches', 'accounts',
        ]);
        foreach ([['1400', 'Inventory Asset', 'asset', 'debit', null], ['2100', 'Accounts Payable', 'liability', 'credit', null],
            ['5100', 'Product COGS', 'expense', 'debit', null], ['5100-3', 'Finish Goods Kitchen', 'expense', 'debit', '5100']] as [$c, $n, $t, $nb, $p]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb,
                'parent_id' => $p ? $this->acc[$p] : null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->branchId = $this->makeBranch(['status' => 'active']);
        $this->cooked = $this->makeCategory(['name' => 'Cooked food', 'cogs_account_id' => $this->acc['5100-3']]);
        $this->beef = $this->product('Beef Biryani');
        $this->supplierId = DB::table('suppliers')->insertGetId(['code' => 'SUP-K', 'name' => 'Kitchen', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        view()->share('errors', new ViewErrorBag);

        $this->bill($this->receive($this->beef, 10, 100, '2026-09-10'), '2026-09-10');
        $this->bill($this->receive($this->beef, 5, 120, '2026-10-05'), '2026-10-05');
        app(InventoryService::class)->postOutFefo(Branch::findOrFail($this->branchId), Product::findOrFail($this->beef), null, 2, 'adjustment_out');
    }

    private function product(string $name, ?int $category = null): int
    {
        return $this->makeProduct($category ?? $this->cooked, ['name' => $name, 'is_purchasable' => 1, 'is_stock_tracked' => 1]);
    }

    private function receive(int $product, float $qty, float $cost, string $date): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'receipt_date' => $date,
            'lines' => [['product_id' => $product, 'quantity_received' => $qty, 'unit_cost' => $cost]],
        ]));

        return GoodsReceipt::on('tenant')->with('lines')->latest('id')->firstOrFail();
    }

    private function bill(GoodsReceipt $grn, string $date, float $discount = 0): object
    {
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', ['goods_receipt_id' => $grn->id, 'bill_date' => $date, 'discount_amount' => $discount]));

        return DB::table('purchase_bills')->latest('id')->first();
    }

    private function cmd(array $args): string
    {
        Artisan::call('inventory:expense-purchase-stock', ['tenant_code' => $this->tenantCode()] + $args);
        DB::setDefaultConnection('tenant');

        return Artisan::output();
    }

    private function gl(string $code): float
    {
        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->where('l.account_id', $this->acc[$code])->sum(DB::raw('l.debit - l.credit')), 4);
    }

    private function counts(): array
    {
        return [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('stock_ledgers')->count(),
            (float) DB::table('stock_balances')->sum('quantity_on_hand'), DB::table('products')->where('is_stock_tracked', 1)->count()];
    }

    public function test_a_dry_run_writes_nothing_and_shows_the_plan(): void
    {
        $before = $this->counts();
        $out = $this->cmd(['--products' => (string) $this->beef]);

        $this->assertSame($before, $this->counts(), 'journals, lines, stock rows, on hand and the stock flag untouched');
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('2026-09 Dr 1,000.00 · 2026-10 Dr 600.00', $out);
        $this->assertStringContainsString('stock written out: 13', $out);
    }

    public function test_each_bill_moves_on_its_own_date_to_the_category_head(): void
    {
        $this->assertSame(1600.0, $this->gl('1400'), 'the stuck inventory');
        $this->cmd(['--products' => (string) $this->beef, '--yes' => true]);

        $moves = DB::table('journal_entries')->where('source_type', InventoryExpensePurchaseStockCommand::SOURCE)->orderBy('entry_date')->get();
        $this->assertSame(['2026-09-10', '2026-10-05'], $moves->map(fn ($e) => substr((string) $e->entry_date, 0, 10))->all(), 'each on its bill date');
        foreach ([[$moves[0], 1000.0], [$moves[1], 600.0]] as [$e, $amount]) {
            $lines = DB::table('journal_lines')->where('journal_entry_id', $e->id)->get();
            $this->assertEqualsWithDelta($amount, (float) $lines->firstWhere('account_id', $this->acc['5100-3'])->debit, 0.0001);
            $this->assertEqualsWithDelta($amount, (float) $lines->firstWhere('account_id', $this->acc['1400'])->credit, 0.0001);
        }
        $this->assertSame(0.0, $this->gl('1400'), 'nothing left stuck in inventory');
        $this->assertSame(1600.0, $this->gl('5100-3'));
        $this->assertEqualsWithDelta(0.0, (float) DB::table('journal_lines')->sum(DB::raw('debit - credit')), 0.0001, 'tb_diff = 0');
    }

    public function test_the_stock_left_is_written_out_and_no_row_is_deleted(): void
    {
        $rows = DB::table('stock_ledgers')->count();
        $this->cmd(['--products' => (string) $this->beef, '--yes' => true]);

        $this->assertSame(0.0, (float) DB::table('stock_balances')->where('product_id', $this->beef)->sum('quantity_on_hand'));
        $this->assertSame($rows + 1, DB::table('stock_ledgers')->count(), 'one row added, none deleted');
        $out = DB::table('stock_ledgers')->where('movement_type', InventoryExpensePurchaseStockCommand::MOVEMENT)->sole();
        $this->assertSame(['out', 13.0], [$out->direction, (float) $out->quantity]);
        $p = DB::table('products')->find($this->beef);
        $this->assertSame([0, 'none', 1], [(int) $p->is_stock_tracked, $p->inventory_consumption_method, (int) $p->is_purchasable]);
    }

    public function test_a_later_purchase_goes_straight_to_the_cost_head(): void
    {
        $this->cmd(['--products' => (string) $this->beef, '--yes' => true]);
        $grn = $this->receive($this->beef, 4, 110, '2026-10-20');
        $bill = $this->bill($grn, '2026-10-20');

        $this->assertFalse((bool) $grn->lines->first()->affects_stock);
        $codes = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.source_type', 'purchase_bill')->where('e.source_id', $bill->id)->pluck('l.debit', 'a.code')->map(fn ($v) => (float) $v)->all();
        $this->assertSame(440.0, $codes['5100-3'] ?? null);
        $this->assertArrayNotHasKey('1400', $codes, 'no inventory line');
    }

    public function test_it_refuses_what_it_cannot_do_safely_and_still_does_the_rest(): void
    {
        $noHead = $this->product('Chicken', $this->makeCategory(['name' => 'No head']));
        $this->bill($this->receive($noHead, 3, 100, '2026-10-06'), '2026-10-06');
        $unbilled = $this->product('Rice');
        $grnRice = $this->receive($unbilled, 6, 50, '2026-10-07');
        $discounted = $this->product('Dal');
        $this->bill($this->receive($discounted, 10, 50, '2026-10-08'), '2026-10-08', 50);
        $before = DB::table('stock_ledgers')->whereIn('product_id', [$noHead, $unbilled, $discounted])->count();

        $out = $this->cmd(['--all-purchased' => true, '--yes' => true]);

        $this->assertMatchesRegularExpression('/SKIPPED Chicken.*\n.*no cost head/', $out);
        $this->assertMatchesRegularExpression('/SKIPPED Rice.*\n.*not billed yet: ' . preg_quote($grnRice->grn_no, '/') . '/', $out);
        $this->assertMatchesRegularExpression('/SKIPPED Dal.*\n.*disagree: its bills put 450\.00 into 1400, its receipts brought 500\.00/', $out);
        $this->assertStringContainsString('Products made purchase-only: 1 · skipped: 3', $out);
        $this->assertSame(3, DB::table('products')->whereIn('id', [$noHead, $unbilled, $discounted])->where('is_stock_tracked', 1)->count(), 'the skipped stay as they were');
        $this->assertSame($before, DB::table('stock_ledgers')->whereIn('product_id', [$noHead, $unbilled, $discounted])->count());
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', InventoryExpensePurchaseStockCommand::SOURCE)
            ->whereIn('source_id', DB::table('goods_receipt_lines')->whereIn('product_id', [$noHead, $unbilled, $discounted])->pluck('id'))->count());
        $this->assertSame(0, (int) DB::table('products')->where('id', $this->beef)->value('is_stock_tracked'), 'Beef was done');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->cmd(['--products' => (string) $this->beef, '--yes' => true]);
        $after = $this->counts();
        $out = $this->cmd(['--products' => (string) $this->beef, '--yes' => true]);

        $this->assertSame($after, $this->counts());
        $this->assertStringContainsString('already purchase-only', $out);
    }

    public function test_the_pnl_shows_the_cost_in_each_month(): void
    {
        $pnl = app(ProfitLossService::class);
        $sep = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];
        $oct = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];
        [$sepBefore, $octBefore] = [$pnl->statement($sep)['net_profit'], $pnl->statement($oct)['net_profit']];

        $this->cmd(['--all-purchased' => true, '--yes' => true]);

        $s = $pnl->statement($sep);
        $this->assertEqualsWithDelta($sepBefore - 1000, $s['net_profit'], 0.0001, 'September carries September\'s cost');
        $this->assertSame(['5100-3'], collect($s['cogs_rows'])->pluck('code')->map(fn ($c) => (string) $c)->all());
        $this->assertEqualsWithDelta($octBefore - 600, $pnl->statement($oct)['net_profit'], 0.0001);
    }

    public function test_the_old_product_form_refuses_switching_stock_off_with_stock_on_hand(): void
    {
        $p = Product::findOrFail($this->beef);
        $payload = ['sku' => $p->sku, 'name' => $p->name, 'product_type' => 'simple', 'default_selling_price' => 0, 'status' => 'active',
            'category_id' => $p->category_id, 'is_purchasable' => 1];   // "Track Stock" unticked

        try {
            app(ProductController::class)->update(Request::create('/products/' . $p->id, 'PUT', $payload), $p);
            $this->fail('the old form switched stock off with 13 on hand');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('still has 13 in stock', $e->errors()['is_stock_tracked'][0]);
        }
        $this->assertSame(1, (int) DB::table('products')->where('id', $this->beef)->value('is_stock_tracked'), 'unchanged');
    }

    public function test_it_needs_a_tenant_and_a_product_list(): void
    {
        $this->assertSame(1, Artisan::call('inventory:expense-purchase-stock', ['tenant_code' => 'no-such-tenant', '--all-purchased' => true]));
        $this->assertSame(1, Artisan::call('inventory:expense-purchase-stock', ['tenant_code' => $this->tenantCode()]));
        DB::setDefaultConnection('tenant');
    }

    private ?string $code = null;

    /**
     * Register this test's DB as a tenant so the command can activate it — and put master back as it was afterwards
     * (master persists between runs; a mapping left behind breaks the next test that registers the same DB).
     */
    private function tenantCode(): string
    {
        if ($this->code) {
            return $this->code;
        }
        $master = DB::connection('master');
        $previous = $master->table('tenant_databases')->where('db_database', $this->tenantDb)->first();   // another test's, if any
        $code = 'exptest' . substr(md5($this->tenantDb), 0, 6);
        $master->table('tenants')->updateOrInsert(['tenant_code' => $code], ['business_name' => 'Expense Stock Test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $tenantId = $master->table('tenants')->where('tenant_code', $code)->value('id');
        $cfg = config('database.connections.tenant');
        $master->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $master->table('tenant_databases')->insert([
            'tenant_id' => $tenantId, 'db_connection' => 'tenant', 'db_host' => $cfg['host'] ?? '127.0.0.1', 'db_port' => $cfg['port'] ?? 3306,
            'db_database' => $this->tenantDb, 'db_username' => $cfg['username'] ?? 'root',
            'db_password' => Crypt::encryptString((string) ($cfg['password'] ?? '')),
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->beforeApplicationDestroyed(function () use ($master, $tenantId, $previous) {
            $master->table('tenant_databases')->where('tenant_id', $tenantId)->delete();
            $master->table('tenants')->where('id', $tenantId)->delete();
            if ($previous && (int) $previous->tenant_id !== (int) $tenantId) {
                $master->table('tenant_databases')->insert((array) $previous);
            }
        });

        return $this->code = $code;
    }
}
