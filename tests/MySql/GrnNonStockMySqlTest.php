<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Models\Tenant\GoodsReceipt;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-NON-STOCK-1 — a purchase without stock: the GRN posts, inventory is not touched.
 *
 * Khatri buys cold drinks it sells but has not started counting (owner, 6 Oct). The GRN refused them,
 * and a bill can only be built from a GRN, so the purchase had nowhere to go. Now a purchase-only line
 * posts with no stock movement; its bill books it to 5100 cost (1400 would never be relieved — a sale
 * only takes COGS out of stock it holds), and a return takes it back out of cost.
 *
 * Mixed receipt: 1,000 containers @ 18.50 (stocked) + 100 Cola Next @ 50 (purchase only) + 235
 * cartage. Value 18,500 / 5,000 of 23,500, so the cartage splits 185 / 50.
 */
class GrnNonStockMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $container;
    private int $cola;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines',
            'purchase_bills', 'supplier_ledgers', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'goods_receipt_lines', 'goods_receipts', 'product_variants', 'products', 'categories', 'suppliers', 'branches', 'accounts',
        ]);
        foreach ([['1400', 'Inventory Asset', 'asset', 'debit'], ['2100', 'Accounts Payable', 'liability', 'credit'], ['5100', 'Product COGS', 'expense', 'debit']] as [$c, $n, $t, $nb]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->branchId = $this->makeBranch(['status' => 'active']);
        $cat = $this->makeCategory();
        $this->container = $this->makeProduct($cat, ['name' => 'Container 750 ML', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 1]);
        $this->cola = $this->makeProduct($cat, ['name' => 'Cola Next 300 ml', 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_purchasable' => 1, 'is_stock_tracked' => 0]);
        $this->supplierId = DB::table('suppliers')->insertGetId(['code' => 'SUP-CD', 'name' => 'Shahbaz cold drink', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        view()->share('errors', new ViewErrorBag);
    }

    /** @param list<array{0:int,1:float,2:float}> $lines product, qty, cost */
    private function receive(array $lines, float $charges = 0): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'receipt_date' => now()->toDateString(),
            'extra_charges' => $charges, 'extra_charges_note' => $charges > 0 ? 'Cartage' : null,
            'lines' => array_map(fn ($l) => ['product_id' => $l[0], 'quantity_received' => $l[1], 'unit_cost' => $l[2]], $lines),
        ]));

        return GoodsReceipt::on('tenant')->with('lines')->latest('id')->firstOrFail();
    }

    private function mixed(): GoodsReceipt
    {
        return $this->receive([[$this->container, 1000, 18.5], [$this->cola, 100, 50]], 235);
    }

    private function bill(GoodsReceipt $grn): object
    {
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', ['goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString()]));

        return DB::table('purchase_bills')->latest('id')->first();
    }

    /** account code => [debit, credit] of the journal for a source. */
    private function journal(string $type, int $id): array
    {
        $out = [];
        foreach (DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.source_type', $type)->where('e.source_id', $id)->get(['a.code', 'l.debit', 'l.credit']) as $l) {
            $out[$l->code] = [round((float) $l->debit, 2), round((float) $l->credit, 2)];
        }
        ksort($out);

        return $out;
    }

    private function onHand(int $product): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $product)->sum('quantity_on_hand');
    }

    public function test_a_mixed_receipt_stocks_only_the_tracked_line(): void
    {
        $grn = $this->mixed();

        $this->assertSame('posted', $grn->status, 'the receipt posts — no refusal, no 500');
        $this->assertSame(1000.0, $this->onHand($this->container));
        $this->assertSame(0.0, $this->onHand($this->cola), 'the drink enters no stock');
        $this->assertSame(0, DB::table('stock_ledgers')->where('product_id', $this->cola)->count());
        $this->assertFalse($grn->lines->firstWhere('product_id', $this->cola)->affects_stock);
        $this->assertTrue($grn->lines->firstWhere('product_id', $this->container)->affects_stock);
        // The stocked line carries only ITS share of the cartage (185), not the drink's.
        $this->assertEqualsWithDelta(18685.0, (float) DB::table('stock_ledgers')->where('product_id', $this->container)->sum('total_cost'), 0.06);
    }

    public function test_the_bill_books_the_purchase_only_part_to_cost(): void
    {
        $bill = $this->bill($this->mixed());

        $this->assertSame(23735.0, round((float) $bill->grand_total, 2), 'the supplier is owed for everything');
        $this->assertSame([
            '1400' => [18685.0, 0.0],   // = the stock ledger's value
            '2100' => [0.0, 23735.0],
            '5100' => [5050.0, 0.0],    // drinks 5,000 + their 50 of cartage
        ], $this->journal('purchase_bill', $bill->id));
        $this->assertSame(1, DB::table('supplier_ledgers')->where('reference_id', $bill->id)->where('entry_type', 'purchase_bill')->count());
    }

    public function test_a_purchase_only_receipt_bills_entirely_to_cost(): void
    {
        $bill = $this->bill($this->receive([[$this->cola, 240, 50]]));

        $this->assertSame(['2100' => [0.0, 12000.0], '5100' => [12000.0, 0.0]], $this->journal('purchase_bill', $bill->id),
            'no inventory line at all');
    }

    public function test_switching_track_stock_on_later_does_not_rebook_an_old_receipt(): void
    {
        $grn = $this->receive([[$this->cola, 240, 50]]);
        DB::table('products')->where('id', $this->cola)->update(['is_stock_tracked' => 1]);   // inventory started later

        $bill = $this->bill($grn);

        $this->assertSame(['2100' => [0.0, 12000.0], '5100' => [12000.0, 0.0]], $this->journal('purchase_bill', $bill->id),
            'the receipt moved no stock, so its bill still books cost — the flag was frozen at receipt');
    }

    public function test_a_stocked_receipt_bills_exactly_as_before(): void
    {
        $bill = $this->bill($this->receive([[$this->container, 1000, 18.5]], 400));

        $this->assertSame(['1400' => [18900.0, 0.0], '2100' => [0.0, 18900.0]], $this->journal('purchase_bill', $bill->id));
    }

    public function test_voiding_a_mixed_receipt_reverses_only_the_stock_it_moved(): void
    {
        $grn = $this->mixed();

        app(PurchasingService::class)->voidGrn($grn->fresh('lines'));

        $this->assertSame('voided', $grn->fresh()->status);
        $this->assertSame(0.0, $this->onHand($this->container));
        $this->assertSame(0, DB::table('stock_ledgers')->where('product_id', $this->cola)->count(), 'nothing to reverse for the drink');
    }

    public function test_returning_purchase_only_goods_takes_them_out_of_cost_not_stock(): void
    {
        $grn = $this->mixed();
        $colaLine = $grn->lines->firstWhere('product_id', $this->cola);
        $containerLine = $grn->lines->firstWhere('product_id', $this->container);

        $service = app(PurchaseReturnService::class);
        $return = $service->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString()],
            [
                ['product_id' => $this->cola, 'source_line_id' => $colaLine->id, 'quantity' => 20, 'unit_cost' => 50],
                ['product_id' => $this->container, 'source_line_id' => $containerLine->id, 'quantity' => 100, 'unit_cost' => 18.5],
            ]
        );
        $service->post($return);

        $this->assertSame(900.0, $this->onHand($this->container), 'the stocked goods go back out');
        $this->assertSame(0, DB::table('stock_ledgers')->where('product_id', $this->cola)->count(), 'the drink was never in stock — nothing leaves');
        // 1,000 drinks + 1,850 containers = 2,850; cost takes back 1,000 of it.
        $this->assertSame(['1400' => [0.0, 1850.0], '2100' => [2850.0, 0.0], '5100' => [0.0, 1000.0]], $this->journal('purchase_return', $return->id));
    }

    public function test_the_grn_page_marks_the_purchase_only_line(): void
    {
        $html = app(GoodsReceiptController::class)->show($this->mixed())->render();

        $this->assertSame(1, substr_count($html, 'Purchase only — no stock'), 'on the drink, not the containers');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // PNL-COST-BY-CATEGORY-1 — a category may send its purchase-only cost to its own account
    // ══════════════════════════════════════════════════════════════════════════════

    /** A cost account under a 5000 group head (created once). */
    private function costAccount(string $code, string $name): int
    {
        $head = $this->acc['5000'] ??= DB::table('accounts')->insertGetId(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'normal_balance' => 'debit', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounts')->where('id', $this->acc['5100'])->update(['parent_id' => $head]);

        return $this->acc[$code] ??= DB::table('accounts')->insertGetId(['code' => $code, 'name' => $name, 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => $head, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A purchase-only (no stock) product in a category of its own, optionally mapped to a cost account. */
    private function drink(string $name, ?int $account = null, ?int $parentCategory = null): int
    {
        $cat = $this->makeCategory(['name' => $name . ' cat', 'parent_id' => $parentCategory]);
        DB::table('categories')->where('id', $cat)->update(['cogs_account_id' => $account]);

        return $this->makeProduct($cat, ['name' => $name, 'is_sellable' => 1, 'is_pos_visible' => 1, 'is_purchasable' => 1, 'is_stock_tracked' => 0]);
    }

    private function tbDiff(): float
    {
        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) v')->value('v'), 4);
    }

    public function test_unmapped_categories_post_one_5100_line_as_before(): void
    {
        $a = $this->drink('Pakola 300');
        $b = $this->drink('Mineral Water 1.5 L');

        $bill = $this->bill($this->receive([[$a, 10, 100], [$b, 5, 100]], 150));

        $this->assertSame(['2100' => [0.0, 1650.0], '5100' => [1650.0, 0.0]], $this->journal('purchase_bill', $bill->id));
        $entry = DB::table('journal_entries')->where('source_type', 'purchase_bill')->where('source_id', $bill->id)->value('id');
        $this->assertSame(1, DB::table('journal_lines')->where('journal_entry_id', $entry)->where('account_id', $this->acc['5100'])->count(), 'exactly one 5100 line');
    }

    public function test_a_mapped_category_takes_its_lines_share_charges_pro_rata(): void
    {
        $bev = $this->costAccount('5110', 'Beverages');
        $a = $this->drink('Cola Next 1.5 L', $bev);      // mapped
        $b = $this->drink('Ice cubes');                    // unmapped

        // A 1,000 + B 500 (no stock) + containers 500 (stock) + 200 charges = 2,200.
        $bill = $this->bill($this->receive([[$a, 10, 100], [$b, 5, 100], [$this->container, 100, 5]], 200));

        $this->assertSame([
            '1400' => [550.0, 0.0],
            '2100' => [0.0, 2200.0],
            '5100' => [550.0, 0.0],
            '5110' => [1100.0, 0.0],
        ], $this->journal('purchase_bill', $bill->id));
        $this->assertSame(0.0, $this->tbDiff());

        $lines = DB::table('goods_receipt_lines')->whereIn('product_id', [$a, $b, $this->container])->pluck('cost_account_id', 'product_id');
        $this->assertSame($bev, (int) $lines[$a], 'the bill remembers where the drink went');
        $this->assertSame($this->acc['5100'], (int) $lines[$b]);
        $this->assertNull($lines[$this->container], 'a stocked line has no cost account');
    }

    public function test_rounding_lands_on_the_paisa(): void
    {
        $x = $this->drink('Drink X', $this->costAccount('5110', 'Beverages'));
        $y = $this->drink('Drink Y', $this->costAccount('5120', 'Water'));
        $z = $this->drink('Drink Z', $this->costAccount('5130', 'Juice'));

        // 3 × 100 + 700 charges = 1,000 → 333.3333… each.
        $bill = $this->bill($this->receive([[$x, 1, 100], [$y, 1, 100], [$z, 1, 100]], 700));

        $entry = DB::table('journal_entries')->where('source_type', 'purchase_bill')->where('source_id', $bill->id)->value('id');
        $sum = (float) DB::table('journal_lines')->where('journal_entry_id', $entry)->whereIn('account_id', [$this->acc['5110'], $this->acc['5120'], $this->acc['5130']])->sum('debit');
        $this->assertSame(1000.0, round($sum, 4), 'the three parts add up to exactly the purchase-only share');
        $this->assertSame(0.0, $this->tbDiff());
    }

    public function test_a_child_category_inherits_its_parents_account(): void
    {
        $bev = $this->costAccount('5110', 'Beverages');
        $parent = $this->makeCategory(['name' => 'Beverages']);
        DB::table('categories')->where('id', $parent)->update(['cogs_account_id' => $bev]);
        $soda = $this->drink('Soda', null, $parent);   // child, no account of its own

        $bill = $this->bill($this->receive([[$soda, 10, 50]]));

        $this->assertSame(['2100' => [0.0, 500.0], '5110' => [500.0, 0.0]], $this->journal('purchase_bill', $bill->id));
    }

    public function test_a_return_credits_the_account_its_bill_debited_even_after_remapping(): void
    {
        $bev = $this->costAccount('5110', 'Beverages');
        $a = $this->drink('Cola Next 1.5 L', $bev);
        $grn = $this->receive([[$a, 10, 100]]);
        $this->bill($grn);
        $line = $grn->lines->firstWhere('product_id', $a);

        $return = function () use ($grn, $a, $line) {
            $service = app(PurchaseReturnService::class);
            $r = $service->createDraft(
                ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString()],
                [['product_id' => $a, 'source_line_id' => $line->id, 'quantity' => 1, 'unit_cost' => 100]]
            );
            $service->post($r);

            return $r;
        };

        $first = $return();
        $this->assertSame(['2100' => [100.0, 0.0], '5110' => [0.0, 100.0]], $this->journal('purchase_return', $first->id), 'not 5100');

        // The owner re-maps the category; the bill already sat on 5110, so the return still does.
        DB::table('categories')->where('id', DB::table('products')->where('id', $a)->value('category_id'))
            ->update(['cogs_account_id' => $this->costAccount('5120', 'Soft Drinks')]);
        $second = $return();
        $this->assertSame(['2100' => [100.0, 0.0], '5110' => [0.0, 100.0]], $this->journal('purchase_return', $second->id));
        $this->assertSame(0.0, $this->tbDiff());
    }

    public function test_a_return_of_a_bill_from_before_the_mapping_goes_back_to_5100(): void
    {
        $a = $this->drink('Cola Next 1.5 L');
        $grn = $this->receive([[$a, 10, 100]]);
        $this->bill($grn);
        DB::table('goods_receipt_lines')->where('goods_receipt_id', $grn->id)->update(['cost_account_id' => null]);   // a bill posted before this change
        DB::table('categories')->where('id', DB::table('products')->where('id', $a)->value('category_id'))
            ->update(['cogs_account_id' => $this->costAccount('5110', 'Beverages')]);

        $service = app(PurchaseReturnService::class);
        $r = $service->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString()],
            [['product_id' => $a, 'source_line_id' => $grn->lines->first()->id, 'quantity' => 2, 'unit_cost' => 100]]
        );
        $service->post($r);

        $this->assertSame(['2100' => [200.0, 0.0], '5100' => [0.0, 200.0]], $this->journal('purchase_return', $r->id),
            'that bill debited 5100, so its return credits 5100');
    }

    public function test_the_backfill_dry_run_writes_nothing_and_yes_reclasses_once(): void
    {
        $code = $this->registerTenant();
        $a = $this->drink('Cola Next 1.5 L');
        $b = $this->drink('Ice cubes');
        $bill = $this->bill($this->receive([[$a, 10, 100], [$b, 5, 100]]));   // all to 5100 — mapped later
        $bev = $this->costAccount('5110', 'Beverages');
        DB::table('categories')->where('id', DB::table('products')->where('id', $a)->value('category_id'))->update(['cogs_account_id' => $bev]);
        $journals = DB::table('journal_entries')->count();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('finance:recost-nonstock-bills', ['tenant_code' => $code]));
        $this->assertStringContainsString('Dr 5110 1,000.00', \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame($journals, DB::table('journal_entries')->count(), 'dry run posts nothing');

        \Illuminate\Support\Facades\Artisan::call('finance:recost-nonstock-bills', ['tenant_code' => $code, '--yes' => true]);
        $this->assertSame(['5100' => [0.0, 1000.0], '5110' => [1000.0, 0.0]], $this->journal('purchase_bill_recost', $bill->id));
        $this->assertSame(['2100' => [0.0, 1500.0], '5100' => [1500.0, 0.0]], $this->journal('purchase_bill', $bill->id), 'the original journal is untouched');
        $this->assertSame(0.0, $this->tbDiff());
        $this->assertSame($bev, (int) DB::table('goods_receipt_lines')->where('product_id', $a)->value('cost_account_id'), 'a later return follows the reclass');

        \Illuminate\Support\Facades\Artisan::call('finance:recost-nonstock-bills', ['tenant_code' => $code, '--yes' => true]);
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'purchase_bill_recost')->count(), 'never twice');
    }

    public function test_the_pl_shows_beverages_as_its_own_cost_line(): void
    {
        $a = $this->drink('Cola Next 1.5 L', $this->costAccount('5110', 'Beverages'));
        $b = $this->drink('Ice cubes');
        $this->bill($this->receive([[$a, 10, 100], [$b, 5, 100], [$this->container, 100, 5]], 200));

        $html = app(\App\Http\Controllers\Tenant\Finance\ProfitLossController::class)
            ->index(Request::create('/finance/profit-loss', 'GET', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))->render();

        $this->assertMatchesRegularExpression('/5110 — Beverages<\/td><td class="text-end">1,100\.00</', $html);
        $this->assertMatchesRegularExpression('/5100 — Product COGS<\/td><td class="text-end">550\.00</', $html);
    }

    public function test_the_category_form_offers_and_checks_the_cost_account(): void
    {
        $bev = $this->costAccount('5110', 'Beverages');
        $cat = $this->makeCategory(['name' => 'Soft Drinks', 'code' => 'SOFT']);
        $controller = app(\App\Http\Controllers\Tenant\CategoryController::class);

        $html = $controller->edit(\App\Models\Tenant\Category::find($cat))->render();
        $this->assertMatchesRegularExpression('/<option value="' . $bev . '"[^>]*>\s*5110 — Beverages/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $this->acc['5000'] . '"/', $html, 'a group head is not offered');
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $this->acc['1400'] . '"[^>]*>\s*1400/', $html);

        $save = fn ($account) => $controller->update(Request::create('/categories/' . $cat, 'PUT',
            ['name' => 'Soft Drinks', 'code' => 'SOFT', 'sort_order' => 0, 'is_active' => 1, 'cogs_account_id' => $account]), \App\Models\Tenant\Category::find($cat));

        try {
            $save($this->acc['5000']);
            $this->fail('a group head was accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('cogs_account_id', $e->errors());
        }
        $save($bev);
        $this->assertSame($bev, (int) DB::table('categories')->where('id', $cat)->value('cogs_account_id'));
    }

    /** The command finds a tenant by code in master and activates its database — this test DB. */
    private function registerTenant(): string
    {
        $m = DB::connection('master');
        $code = 'recost' . strtolower(\Illuminate\Support\Str::random(5));
        $id = $m->table('tenants')->insertGetId(['tenant_code' => $code, 'business_name' => 'Recost', 'owner_name' => 'O',
            'owner_email' => $code . '@test.local', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('tenant_databases')->insert(['tenant_id' => $id, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $this->beforeApplicationDestroyed(function () use ($m, $id) {
            $m->table('tenant_databases')->where('tenant_id', $id)->delete();
            $m->table('tenants')->where('id', $id)->delete();
        });

        return $code;
    }
}
