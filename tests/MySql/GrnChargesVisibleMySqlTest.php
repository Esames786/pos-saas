<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Http\Controllers\Tenant\PurchaseOrderController;
use App\Models\Tenant\GoodsReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GRN-CHARGES-VISIBLE-1 — the Extra Charges have to show up in the totals people look at.
 *
 * Khatri typed 500 of cartage on a GRN and the footer still said 500 — it summed the goods alone —
 * so the counter decided the charge "was not going into the bill" and did not post. The money
 * path was right; the screens did not say so. Every guard here renders the real page.
 *
 * The live recalculation (typing a charge / discount) was checked in headless Chrome on the
 * rendered pages when this was built; PHPUnit cannot run that script, so what is guarded here
 * is the markup it needs and the figures the server prints.
 */
class GrnChargesVisibleMySqlTest extends MySqlTenantTestCase
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
            'journal_lines', 'journal_entries', 'purchase_bill_lines', 'purchase_bills',
            'stock_ledgers', 'stock_balances', 'goods_receipt_lines', 'goods_receipts',
            'products', 'categories', 'suppliers', 'branches', 'accounts',
        ]);
        foreach ([['1400', 'Inventory Asset', 'asset', 'debit'], ['2100', 'Accounts Payable', 'liability', 'credit']] as [$code, $name, $type, $normal]) {
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->branchId = $this->makeBranch(['status' => 'active']);
        $this->productId = $this->makeProduct($this->makeCategory(), [
            'name' => 'Container 750 ML', 'is_sellable' => 0, 'is_pos_visible' => 0,
            'is_purchasable' => 1, 'is_stock_tracked' => 1,
        ]);
        $this->supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-CV-1', 'name' => 'Waqar packing', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        view()->share('errors', new ViewErrorBag);
    }

    /** Receive 1,000 @ 18.50 = 18,500 of goods through the real controller. */
    private function receive(float $charges): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId,
            'receipt_date' => now()->toDateString(),
            'extra_charges' => $charges, 'extra_charges_note' => $charges > 0 ? 'Cartage' : null,
            'lines' => [['product_id' => $this->productId, 'quantity_received' => 1000, 'unit_cost' => 18.5]],
        ]));

        return GoodsReceipt::on('tenant')->latest('id')->firstOrFail();
    }

    private function grnPage(GoodsReceipt $grn): string
    {
        return app(GoodsReceiptController::class)->show($grn)->render();
    }

    private function billForm(GoodsReceipt $grn): string
    {
        return app(PurchaseBillController::class)
            ->create(Request::create('/purchase-bills/create', 'GET', ['goods_receipt_id' => $grn->id]))->render();
    }

    public function test_the_grn_form_footer_carries_the_charge_and_a_total(): void
    {
        $html = app(GoodsReceiptController::class)->create(Request::create('/goods-receipts/create', 'GET'))->render();

        $this->assertStringContainsString('data-extra-charges-input="extra_charges"', $html, 'the footer is wired to the charge box');
        $this->assertStringContainsString('data-extra-charges-note-input="extra_charges_note"', $html);
        $this->assertStringContainsString('purchase-summary-charges', $html);
        $this->assertStringContainsString('Total (goods + charges)', $html);
        $this->assertStringContainsString('purchase-summary-total', $html);
    }

    public function test_the_purchase_order_form_is_unchanged(): void
    {
        // Same partial; a PO has no charge box, so it must not grow rows that would always read 0.
        $html = app(PurchaseOrderController::class)->create()->render();

        $this->assertStringNotContainsString('data-extra-charges-input', $html);
        $this->assertStringNotContainsString('Total (goods + charges)', $html);
        $this->assertStringContainsString('purchase-summary-grand', $html, 'its own footer is still there');
    }

    public function test_the_grn_page_totals_goods_and_charge(): void
    {
        $html = $this->grnPage($this->receive(400));

        $this->assertStringContainsString('18,500.00', $html, 'goods');
        $this->assertMatchesRegularExpression('/Extra Charges\s*<span class="text-muted">&mdash; Cartage<\/span>/', $html);
        $this->assertStringContainsString('<td id="grn-total">18,900.00</td>', $html, 'goods + cartage');
    }

    public function test_a_grn_without_charges_totals_the_goods_alone(): void
    {
        $html = $this->grnPage($this->receive(0));

        $this->assertStringContainsString('<td id="grn-total">18,500.00</td>', $html);
        $this->assertStringNotContainsString('Extra Charges', $html, 'no charge, no charge row');
    }

    /** The preview must be the figure the bill is actually posted at — same formula, proven by posting. */
    public function test_the_bill_form_shows_what_the_bill_will_post_at(): void
    {
        $grn = $this->receive(400);
        $html = $this->billForm($grn);

        $this->assertMatchesRegularExpression('/id="bill-total-preview" data-base="18900(\.0+)?">18,900\.00</', $html);

        app(PurchaseBillController::class)->store(Request::create('/purchase-bills', 'POST', [
            'goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString(),
        ]));
        $this->assertSame(18900.0, round((float) DB::table('purchase_bills')->latest('id')->value('grand_total'), 2),
            'the bill posts at exactly what its form showed');
    }

    public function test_the_bill_form_keeps_a_typed_discount_and_tax_after_a_failed_submit(): void
    {
        $grn = $this->receive(400);
        // A failed submit returns with old input; the initial figure must already reflect it.
        $this->app['session.store']->flashInput(['discount_amount' => 50, 'tax_amount' => 10]);
        request()->setLaravelSession($this->app['session.store']);

        $this->assertStringContainsString('>18,860.00<', $this->billForm($grn), '18,900 − 50 + 10');
    }
}
