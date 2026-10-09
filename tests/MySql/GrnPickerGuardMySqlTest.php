<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Models\Tenant\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-PICKER-GUARD-1 — a GRN may only offer, and only accept, what it can actually receive.
 *
 * The create screen loaded EVERY active product, menu included, and the Post did no check of its
 * own: receiving a line calls InventoryService::postIn(), whose ensureStockTracked() throws a raw
 * RuntimeException, and that reached the client as a 500 error page. Measured on 2026-10-01: of
 * the products the picker offered, 49 of 62 would 500 on Khatri, 912 of 918 on Kashif Kitchen,
 * and every single one on Kashif Food (212) and Tawakal (112) — so GRN was unusable there. Six
 * real 500s were logged that day before this was fixed.
 *
 * A purchasable-but-untracked product is a legitimate state (a freight charge; a drink whose
 * inventory has not been started yet), so the Post explains itself instead of crashing.
 */
class GrnPickerGuardMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders',
            'product_variants', 'products', 'categories', 'suppliers', 'branches',
        ]);

        $this->branchId   = $this->makeBranch(['status' => 'active']);
        $this->categoryId = $this->makeCategory();
        $this->supplierId = DB::connection('tenant')->table('suppliers')->insertGetId([
            'code' => 'SUP-GRN-1', 'name' => 'Packing Supplier', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        view()->share('errors', new ViewErrorBag);
    }

    private function controller(): GoodsReceiptController
    {
        return app(GoodsReceiptController::class);
    }

    /** The product ids the create screen actually offers. */
    private function offered(): array
    {
        $view = $this->controller()->create(Request::create('/goods-receipts/create', 'GET'));

        return collect($view->getData()['products'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_the_picker_offers_only_products_that_are_purchased(): void
    {
        $menuItem = $this->makeProduct($this->categoryId, [
            'name' => 'Beef Biryani', 'is_sellable' => 1, 'is_pos_visible' => 1,
            'is_purchasable' => 0, 'is_stock_tracked' => 0,
        ]);
        $supply = $this->makeProduct($this->categoryId, [
            'name' => 'Container 750 ML', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        $offered = $this->offered();

        $this->assertContains($supply, $offered, 'a purchased supply must be offered');
        $this->assertNotContains($menuItem, $offered,
            'a menu item nobody buys must not be offered — it was the bulk of the old list, and '
            . 'every one of them 500d on Post');
    }

    public function test_an_untracked_product_posts_as_a_purchase_only_instead_of_crashing(): void
    {
        // The live shape: a drink made purchasable so buying can be recorded, while its inventory is
        // deliberately not started. Until 6 Oct this was refused with a message; the owner then asked
        // for it to post (GRN-NON-STOCK-1). What this guard was born for still holds: no 500.
        $drink = $this->makeProduct($this->categoryId, [
            'name' => 'Cola Next 300 ml', 'is_sellable' => 1, 'is_pos_visible' => 1,
            'is_purchasable' => 1, 'is_stock_tracked' => 0,
        ]);

        $this->controller()->store($this->postFor($drink));

        $this->assertSame(1, (int) DB::connection('tenant')->table('goods_receipts')->count(), 'the receipt posts');
        $this->assertSame(0, (int) DB::connection('tenant')->table('goods_receipt_lines')->where('product_id', $drink)->value('affects_stock'),
            'and the line says it moved no stock');
        $this->assertSame(0.0, (float) DB::connection('tenant')->table('stock_balances')->where('product_id', $drink)->sum('quantity_on_hand'),
            'nothing enters stock');
    }
    public function test_a_tracked_product_still_posts_normally(): void
    {
        $supply = $this->makeProduct($this->categoryId, [
            'name' => 'Spoon', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);

        $this->controller()->store($this->postFor($supply));

        $this->assertSame(1, (int) DB::connection('tenant')->table('goods_receipts')->count(),
            'the guard must not get in the way of a receipt it has no business refusing');
        $this->assertGreaterThan(0, (float) DB::connection('tenant')->table('stock_balances')
            ->where('product_id', $supply)->sum('quantity_on_hand'), 'and the stock actually lands');
    }

    private function postFor(int $productId): Request
    {
        return Request::create('/goods-receipts', 'POST', [
            'supplier_id'  => $this->supplierId,
            'branch_id'    => $this->branchId,
            'receipt_date' => now()->toDateString(),
            'lines'        => [[
                'product_id'        => $productId,
                'quantity_received' => 10,
                'unit_cost'         => 19,
            ]],
        ]);
    }
}
