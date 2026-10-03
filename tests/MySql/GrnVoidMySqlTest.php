<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Models\Tenant\GoodsReceipt;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-VOID-1 — a receipt that has not been billed yet can be taken back.
 *
 * `status` was enum('posted') with no second value: a wrong receipt could only be lived with. It
 * is still not EDITABLE, and deliberately so — changing a quantity afterwards would rewrite stock
 * history that FEFO layers, average cost and any sale since then already lean on, with nothing on
 * the record to say it happened. Voiding is the honest alternative: the original stays exactly as
 * posted, and the stock is undone by its own reversal entries.
 *
 * Voided rather than deleted, because the reversal rows reference the receipt — deleting it would
 * leave them pointing at nothing.
 */
class GrnVoidMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'purchase_bill_lines', 'purchase_bills', 'stock_ledgers', 'stock_balances',
            'goods_receipt_lines', 'goods_receipts', 'products', 'categories', 'suppliers',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'users', 'branches',
        ]);

        $this->branchId  = $this->makeBranch(['status' => 'active']);
        $categoryId      = $this->makeCategory();
        $this->productId = $this->makeProduct($categoryId, [
            'name' => 'Container 750 ML', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);
        $this->supplierId = DB::connection('tenant')->table('suppliers')->insertGetId([
            'code' => 'SUP-V-1', 'name' => 'Packing Supplier', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        view()->share('errors', new ViewErrorBag);
    }

    private function receive(float $qty = 1000, float $charges = 0): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id'   => $this->supplierId,
            'branch_id'     => $this->branchId,
            'receipt_date'  => now()->toDateString(),
            'extra_charges' => $charges,
            'lines'         => [[
                'product_id' => $this->productId, 'quantity_received' => $qty, 'unit_cost' => 18.5,
            ]],
        ]));

        return GoodsReceipt::on('tenant')->with('lines.product', 'bill')->latest('id')->firstOrFail();
    }

    /**
     * A tenant user holding the void permission.
     *
     * The button sits behind @can('tenant.goods-receipts.void'), so rendering the screen without a
     * permitted user proves nothing about the button — it would be absent either way. This also
     * checks the permission NAME matches the route, which is the part that silently breaks.
     */
    private function permittedUser(): \App\Models\Tenant\User
    {
        DB::setDefaultConnection('tenant');
        $uid = $this->makeUser(['default_branch_id' => $this->branchId]);
        \Spatie\Permission\Models\Permission::findOrCreate('tenant.goods-receipts.void', 'tenant');
        $user = \App\Models\Tenant\User::on('tenant')->find($uid);
        $user->givePermissionTo('tenant.goods-receipts.void');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return \App\Models\Tenant\User::on('tenant')->find($uid);
    }
    private function onHand(): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $this->productId)->sum('quantity_on_hand');
    }

    private function ledgerQty(): float
    {
        return (float) DB::connection('tenant')->table('stock_ledgers')
            ->where('product_id', $this->productId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END),0) q")
            ->value('q');
    }

    public function test_voiding_takes_the_stock_back_out_and_keeps_the_receipt(): void
    {
        $grn = $this->receive(1000);
        $this->assertSame(1000.0, $this->onHand());

        app(PurchasingService::class)->voidGrn($grn, null, 'wrong supplier');

        $this->assertSame(0.0, $this->onHand(), 'the stock it brought in is gone');
        $this->assertSame(0.0, $this->ledgerQty(), 'and the ledger agrees');

        $fresh = GoodsReceipt::on('tenant')->findOrFail($grn->id);
        $this->assertSame('voided', $fresh->status, 'the receipt stays on record, marked');
        $this->assertSame('wrong supplier', $fresh->void_reason);
        $this->assertNotNull($fresh->voided_at);
        $this->assertSame(1, (int) DB::connection('tenant')->table('goods_receipt_lines')
            ->where('goods_receipt_id', $grn->id)->count(), 'its lines are not erased either');
    }

    public function test_the_reversal_is_a_new_entry_pointing_at_the_original(): void
    {
        $grn = $this->receive(1000);
        app(PurchasingService::class)->voidGrn($grn, null, null);

        $in  = DB::connection('tenant')->table('stock_ledgers')->where('direction', 'in')->first();
        $out = DB::connection('tenant')->table('stock_ledgers')->where('direction', 'out')->first();

        $this->assertNotNull($in, 'the original receipt entry is still there — history is not erased');
        $this->assertNotNull($out);
        $this->assertSame((int) $in->id, (int) $out->reversal_of_id, 'the reversal names what it reverses');
        // Reversed at the cost it was received at. Recomputing it would quietly value the reversal
        // differently from the receipt and leave stock carrying a cost nobody booked.
        $this->assertSame(round((float) $in->total_cost, 4), round((float) $out->total_cost, 4));
    }

    public function test_the_landed_cost_of_a_charge_comes_back_out_too(): void
    {
        // 18,500 of goods + 400 cartage went in; all of it must come back out.
        $grn = $this->receive(1000, 400);
        app(PurchasingService::class)->voidGrn($grn, null, null);

        $value = (float) DB::connection('tenant')->table('stock_ledgers')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN total_cost ELSE -total_cost END),0) v")
            ->value('v');

        $this->assertEqualsWithDelta(0.0, $value, 0.01, 'no value may be left behind');
    }

    public function test_a_billed_receipt_cannot_be_voided(): void
    {
        $grn = $this->receive(1000);
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', [
            'goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString(),
        ]));

        try {
            app(PurchasingService::class)->voidGrn($grn->fresh()->load('bill', 'lines.product'), null, null);
            $this->fail('a billed receipt should not be voidable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('purchase bill', $e->getMessage());
            $this->assertStringContainsString('Purchase Return', $e->getMessage(), 'it says what to do instead');
        }

        $this->assertSame(1000.0, $this->onHand(), 'and nothing moved');
    }

    public function test_a_receipt_whose_goods_are_gone_cannot_be_voided(): void
    {
        $grn = $this->receive(1000);

        // Someone used 400 of them — only 600 left, so the receipt cannot be unreceived.
        DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $this->productId)->decrement('quantity_on_hand', 400);

        try {
            app(PurchasingService::class)->voidGrn($grn, null, null);
            $this->fail('voiding should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer has the quantity', $e->getMessage());
            $this->assertStringContainsString('Container 750 ML', $e->getMessage(), 'it names the product');
        }

        $this->assertSame(600.0, $this->onHand(), 'and it did not go negative');
        $this->assertSame('posted', GoodsReceipt::on('tenant')->find($grn->id)->status);
    }

    public function test_voiding_twice_cannot_double_reverse(): void
    {
        $grn = $this->receive(1000);
        app(PurchasingService::class)->voidGrn($grn, null, null);

        try {
            app(PurchasingService::class)->voidGrn($grn->fresh()->load('bill', 'lines.product'), null, null);
            $this->fail('a voided receipt should not be voidable again');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already voided', $e->getMessage());
        }

        $this->assertSame(0.0, $this->onHand(), 'stock stays at zero, not minus one thousand');
    }

    public function test_the_screen_offers_void_only_while_it_is_allowed(): void
    {
        \Illuminate\Support\Facades\Auth::guard('tenant')->login($this->permittedUser());

        $grn = $this->receive(1000);
        $show = fn (GoodsReceipt $g) => app(GoodsReceiptController::class)->show($g)->render();

        $this->assertStringContainsString('Void Receipt', $show($grn));

        app(PurchasingService::class)->voidGrn($grn, null, null);
        $after = $show(GoodsReceipt::on('tenant')->findOrFail($grn->id));
        $this->assertStringNotContainsString('Void Receipt', $after, 'not offered twice');
        $this->assertStringContainsString('This receipt is voided', $after, 'and it says so plainly');
    }

    public function test_the_list_offers_void_on_exactly_the_rows_that_allow_it(): void
    {
        \Illuminate\Support\Facades\Auth::guard('tenant')->login($this->permittedUser());

        $voidable = $this->receive(100);          // no bill, posted → offerable
        $billed   = $this->receive(200);          // billed → not offerable
        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', [
            'goods_receipt_id' => $billed->id, 'bill_date' => now()->toDateString(),
        ]));
        $already  = $this->receive(50);
        app(PurchasingService::class)->voidGrn($already, null, null);   // voided → not offerable

        $html = app(GoodsReceiptController::class)
            ->index(Request::create('/goods-receipts', 'GET'))->render();

        // One form per voidable receipt — the button must follow the SAME three conditions the
        // receipt's own screen uses, or the list offers an action that then refuses.
        $this->assertStringContainsString('/goods-receipts/' . $voidable->id . '/void', $html);
        $this->assertStringNotContainsString('/goods-receipts/' . $billed->id . '/void', $html,
            'a billed receipt must not be offered');
        $this->assertStringNotContainsString('/goods-receipts/' . $already->id . '/void', $html,
            'an already-voided receipt must not be offered');
        $this->assertStringContainsString('Voided', $html, 'and it reads as voided in the list');
    }
}
