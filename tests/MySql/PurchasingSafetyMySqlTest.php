<?php

namespace Tests\MySql;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchasingService;
use App\Support\SubmissionKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * W3/W4 slice S0 (23 Oct) — purchasing safety fixes, for every tenant (no switch), each through the real routes:
 *  - a goods receipt posted twice with one form key is ONE receipt and the stock comes in once; the same key with other
 *    lines is refused; a form without a key posts as before; the old screen is posted once too;
 *  - a voided receipt cannot be invoiced;
 *  - a receipt or payment number that is already taken draws another instead of failing the post;
 *  - a partly received order becomes "received" when the rest arrives;
 *  - a payment against another supplier's invoice is refused, and that invoice stays unpaid.
 */
class PurchasingSafetyMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 's0test';
    private const STORE_SUFFIX = '_s0';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_a_receipt_posted_twice_with_one_form_key_is_one_receipt_and_its_stock_comes_in_once(): void
    {
        $soap = $this->product('S0 Soap Twice', $this->piece()->id, ['default_selling_price' => 90]);
        $key = SubmissionKey::fresh();
        $before = GoodsReceipt::count();

        $first = $this->grn($soap, '5', $key)->assertOk()->json();
        $this->assertTrue($first['ok']);
        $this->assertArrayHasKey('next_key', $first, 'the screen gets a new key for the next document');
        $this->assertEqualsWithDelta(5, $this->stock($soap), 0.001);

        // The answer never arrived; the screen says "press Post again" — same form, same key.
        $again = $this->grn($soap, '5', $key)->assertOk()->json();
        $this->assertTrue($again['already'] ?? false, 'the first receipt comes back');
        $this->assertSame($first['number'], $again['number']);
        $this->assertSame($before + 1, GoodsReceipt::count(), 'one receipt, not two');
        $this->assertEqualsWithDelta(5, $this->stock($soap), 0.001, 'the stock came in once');

        // The same key with other lines: refused, nothing posted, a new key handed back.
        $other = $this->grn($soap, '6', $key)->assertStatus(422)->json();
        $this->assertStringContainsString('already posted as goods receipt ' . $first['number'], $other['message']);
        $this->assertNotEmpty($other['next_key']);
        $this->assertSame($before + 1, GoodsReceipt::count());
        $this->assertEqualsWithDelta(5, $this->stock($soap), 0.001);

        // A form without a key (a tab opened before this release) posts exactly as before.
        $this->grn($soap, '5', null)->assertOk();
        $this->assertSame($before + 2, GoodsReceipt::count());
        $this->assertEqualsWithDelta(10, $this->stock($soap), 0.001);

        // The old screen's form is posted once too.
        $oldKey = SubmissionKey::fresh();
        $old = fn () => $this->owner()->post($this->url('/goods-receipts'), ['branch_id' => $this->branchId(), 'supplier_id' => $this->supplier()->id,
            'receipt_date' => now()->toDateString(), 'submission_key' => $oldKey,
            'lines' => [['product_id' => $soap->id, 'quantity_received' => 3, 'unit_cost' => 60]]]);
        $old()->assertRedirect()->assertSessionHasNoErrors();
        $old()->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains((string) $s, 'was already posted — nothing was posted twice'));
        $this->assertSame($before + 3, GoodsReceipt::count());
        $this->assertEqualsWithDelta(13, $this->stock($soap), 0.001);
    }

    public function test_a_voided_receipt_cannot_be_invoiced(): void
    {
        $tea = $this->product('S0 Tea Voided', $this->piece()->id, ['default_selling_price' => 50]);
        $grn = GoodsReceipt::findOrFail($this->grn($tea, '4', SubmissionKey::fresh())->assertOk()->json('id'));
        $this->owner()->post($this->url('/goods-receipts/' . $grn->id . '/void'), ['void_reason' => 'wrong supplier'])->assertSessionHasNoErrors();
        $this->assertSame('voided', $grn->fresh()->status);

        $this->owner()->post($this->url('/purchase-bills'), ['goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString()])
            ->assertSessionHasErrors();
        $this->assertSame(0, PurchaseBill::where('goods_receipt_id', $grn->id)->count(), 'no invoice for goods that never came in');
        $this->assertStringContainsString('voided', (string) collect(session('errors')?->all())->implode(' '));
    }

    public function test_a_receipt_or_payment_number_already_taken_draws_another(): void
    {
        $oil = $this->product('S0 Oil Numbers', $this->piece()->id, ['default_selling_price' => 400]);
        $supplier = $this->supplier();
        $taken = ['GRN-S0-' . Str::upper(Str::random(6)), 'PAY-S0-' . Str::upper(Str::random(6))];
        $free = ['GRN-S0-' . Str::upper(Str::random(6)), 'PAY-S0-' . Str::upper(Str::random(6))];
        DB::connection('tenant')->table('goods_receipts')->insert(['grn_no' => $taken[0], 'branch_id' => $this->branchId(), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'status' => 'voided', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('supplier_payments')->insert(['payment_no' => $taken[1], 'branch_id' => $this->branchId(), 'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(), 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // The next number drawn is the taken one; the one after it is free.
        $numbers = new class(app(\App\Services\Inventory\InventoryService::class), $taken, $free) extends PurchasingService
        {
            private array $grn;
            private array $pay;

            public function __construct(\App\Services\Inventory\InventoryService $inventory, array $taken, array $free)
            {
                parent::__construct($inventory);
                $this->grn = [$taken[0], $free[0]];
                $this->pay = [$taken[1], $free[1]];
            }

            public function nextGrnNo(): string { return array_shift($this->grn) ?? parent::nextGrnNo(); }

            public function nextPaymentNo(): string { return array_shift($this->pay) ?? parent::nextPaymentNo(); }
        };
        $this->app->instance(PurchasingService::class, $numbers);

        $grn = $this->grn($oil, '2', SubmissionKey::fresh())->assertOk()->json();
        $this->assertSame($free[0], $grn['number'], 'a taken receipt number drew another; the post went through');

        // Invoice the 2 × 60 so the supplier is owed something, then pay part of it.
        $this->owner()->post($this->url('/purchase-bills'), ['goods_receipt_id' => $grn['id'], 'bill_date' => now()->toDateString()])->assertSessionHasNoErrors();
        $billId = (int) PurchaseBill::where('goods_receipt_id', $grn['id'])->value('id');
        $this->owner()->post($this->url('/supplier-payments'), $this->payment($supplier->id, $billId, 100))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::connection('tenant')->table('supplier_payments')->where('payment_no', $free[1])->count(), 'a taken payment number drew another');
    }

    public function test_a_partly_received_order_becomes_received_when_the_rest_arrives(): void
    {
        $rice = $this->product('S0 Rice Order', $this->piece()->id, ['default_selling_price' => 300]);
        $c = DB::connection('tenant');
        $po = $c->table('purchase_orders')->insertGetId(['po_no' => 'PO-S0-' . Str::upper(Str::random(6)), 'supplier_id' => $this->supplier()->id,
            'branch_id' => $this->branchId(), 'order_date' => now()->toDateString(), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $line = $c->table('purchase_order_lines')->insertGetId(['purchase_order_id' => $po, 'product_id' => $rice->id, 'quantity_ordered' => 10,
            'unit_cost' => 200, 'created_at' => now(), 'updated_at' => now()]);

        $this->grn($rice, '4', SubmissionKey::fresh(), ['purchase_order_line_id' => $line], ['purchase_order_id' => $po])->assertOk();
        $this->assertSame('partially_received', $c->table('purchase_orders')->where('id', $po)->value('status'));

        $this->grn($rice, '6', SubmissionKey::fresh(), ['purchase_order_line_id' => $line], ['purchase_order_id' => $po])->assertOk();
        $this->assertSame('received', $c->table('purchase_orders')->where('id', $po)->value('status'), 'all 10 arrived');
    }

    public function test_a_payment_against_another_suppliers_invoice_is_refused_and_that_invoice_stays_unpaid(): void
    {
        $other = Supplier::firstOrCreate(['code' => 'S0TEST-S2'], ['name' => 'Second Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
        $salt = $this->product('S0 Salt Invoice', $this->piece()->id, ['default_selling_price' => 40]);
        $grn = GoodsReceipt::findOrFail($this->grn($salt, '10', SubmissionKey::fresh(), [], ['supplier_id' => $other->id])->assertOk()->json('id'));
        $this->owner()->post($this->url('/purchase-bills'), ['goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString()])->assertSessionHasNoErrors();
        $bill = PurchaseBill::where('goods_receipt_id', $grn->id)->firstOrFail();
        $payments = DB::connection('tenant')->table('supplier_payments')->count();

        // Our first supplier is paid "against" the second supplier's invoice.
        $this->owner()->post($this->url('/supplier-payments'), $this->payment($this->supplier()->id, $bill->id, 100))
            ->assertSessionHasErrors(['amount' => 'That supplier invoice belongs to another supplier. Choose one of this supplier\'s invoices, or pay without one.']);
        $this->assertSame($payments, DB::connection('tenant')->table('supplier_payments')->count(), 'nothing was paid');
        $this->assertEqualsWithDelta(0.0, (float) $bill->fresh()->amount_paid, 0.001, 'the other supplier\'s invoice stays unpaid');
    }

    /** A goods receipt on the new screen, posted as the screen posts it (JSON). */
    private function grn($product, string $count, ?string $key, array $lineExtra = [], array $extra = [])
    {
        return $this->owner()->postJson($this->url('/goods-receipts'), $extra + ['_entry_v2' => '1', 'branch_id' => $this->branchId(),
            'supplier_id' => $this->supplier()->id, 'receipt_date' => now()->toDateString(),
            'lines' => [$lineExtra + ['product_id' => $product->id, 'buy_pack' => 'base', 'price' => '60', 'packs' => ['base' => $count]]]]
            + ($key ? ['submission_key' => $key] : []));
    }

    private function payment(int $supplierId, ?int $billId, float $amount): array
    {
        return ['supplier_id' => $supplierId, 'branch_id' => $this->branchId(), 'purchase_bill_id' => $billId, 'payment_date' => now()->toDateString(),
            'amount' => $amount, 'payment_method' => 'cash',
            'cash_bank_account_id' => (int) DB::connection('tenant')->table('cash_bank_accounts')->where('is_active', 1)->orderBy('id')->value('id')];
    }
}
