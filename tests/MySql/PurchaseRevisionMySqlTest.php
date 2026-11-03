<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptRevision;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Models\Tenant\User;
use App\Services\Finance\SupplierPayableService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\SupplierRunningAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * PURCHASE-EDIT-1 (3 Nov) — a posted receipt and its supplier invoice corrected in place, through the real routes as the
 * store's owner. The client: "rate baad mein badalna ho to reverse na karna pade — GRN kholein, rate daalein, sab khud
 * seedha ho jaye; date ghalat ho to date badlein, entry sahi din par chali jaye."
 *
 * After every change the books must still agree: the trial balance balances, the supplier's AP in the GL equals the
 * supplier ledger and the supplier's balance, and an item's stock (quantity and value) equals its stock ledger.
 * Each test uses its own supplier, items and 2025 dates.
 */
class PurchaseRevisionMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'pedtest';
    private const STORE_SUFFIX = '_ped';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'purchase_workspace' => true,
        'purchase_edit' => true, 'supplier_ledger_daily' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => false]);
    }

    public function test_a_rate_change_corrects_the_invoice_the_supplier_and_the_gl_on_the_invoice_date(): void
    {
        $supplier = $this->newSupplier('PED Rate Supplier');
        $drink = $this->item('PED Rate Drink', false);
        [$grn, $bill] = $this->purchase($supplier, [[$drink, 10, 100]], '2025-06-10', '2025-06-10');
        $original = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)->firstOrFail();

        $this->revise($grn, [$this->lineOf($grn, $drink)->id => [10, 90]])->assertRedirect($this->url('/goods-receipts/' . $grn->id));

        $bill->refresh();
        $this->assertEqualsWithDelta(900, (float) $bill->grand_total, 0.001, 'the invoice follows the receipt');
        $this->assertEqualsWithDelta(900, (float) $bill->balance_due, 0.001);
        $this->assertSame($bill->bill_no, PurchaseBill::find($bill->id)->bill_no, 'same invoice, same number');
        $this->assertEqualsWithDelta(900, (float) $supplier->fresh()->current_balance, 0.001);

        $row = SupplierLedger::where('supplier_id', $supplier->id)->where('entry_type', 'purchase_bill_revision')->sole();
        $this->assertSame(['credit', 100.0, '2025-06-10'], [$row->direction, (float) $row->amount, $row->entry_date->toDateString()],
            'the supplier ledger moves by the difference, on the invoice\'s date');

        $reversal = JournalEntry::where('reversed_entry_id', $original->id)->sole();
        $this->assertSame('2025-06-10', $reversal->entry_date->toDateString(), 'the old entry is wiped on its own date');
        $now = $this->correctedJournals($grn)->sole();
        $this->assertSame('2025-06-10', $now->entry_date->toDateString(), 'the corrected invoice on its date');
        $this->assertEqualsWithDelta(900, (float) $now->total_credit, 0.001);

        $revision = GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->sole();
        $this->assertContains('PED Rate Drink: rate 100 → 90', $revision->changes);
        $this->assertContains('Invoice total 1,000.00 → 900.00', $revision->changes);
        $this->assertSame(1, (int) $grn->fresh()->revision_no);

        $this->owner()->get($this->url('/goods-receipts/' . $grn->id))->assertOk()
            ->assertSee('Change history')->assertSee('PED Rate Drink: rate 100 → 90');
        $this->owner()->get($this->url('/purchase-bills/' . $bill->id))->assertOk()->assertSee('data-bill-edited', false);
        $this->assertBooksAgree($supplier);
    }

    public function test_an_invoice_date_change_wipes_it_on_the_old_day_and_posts_it_on_the_new_day(): void
    {
        $supplier = $this->newSupplier('PED Date Supplier');
        $drink = $this->item('PED Date Drink', false);
        [$grn, $bill] = $this->purchase($supplier, [[$drink, 5, 200]], '2025-06-20', '2025-06-20');
        $original = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)->firstOrFail();

        $this->revise($grn, [], ['bill_date' => '2025-06-18', 'receipt_date' => '2025-06-18'])->assertRedirect();

        $this->assertSame('2025-06-18', $bill->fresh()->bill_date->toDateString());
        $this->assertSame('2025-06-20', JournalEntry::where('reversed_entry_id', $original->id)->sole()->entry_date->toDateString());
        $this->assertSame('2025-06-18', $this->correctedJournals($grn)->sole()->entry_date->toDateString());
        $rows = SupplierLedger::where('supplier_id', $supplier->id)->where('entry_type', 'purchase_bill_revision')->orderBy('id')->get();
        $this->assertSame([['credit', 1000.0, '2025-06-20'], ['debit', 1000.0, '2025-06-18']],
            $rows->map(fn ($r) => [$r->direction, (float) $r->amount, $r->entry_date->toDateString()])->all(),
            'off on the old day, on on the new day');
        $this->assertEqualsWithDelta(1000, (float) $supplier->fresh()->current_balance, 0.001, 'what is owed did not change');
        $this->assertContains('Invoice date 20 Jun 2025 → 18 Jun 2025', GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->sole()->changes);
        $this->assertBooksAgree($supplier);
    }

    public function test_a_stock_line_change_comes_out_and_goes_in_today_with_the_average_restored(): void
    {
        $supplier = $this->newSupplier('PED Stock Supplier');
        $oil = $this->item('PED Stock Oil', true);
        $this->purchase($supplier, [[$oil, 10, 100]], '2025-06-01', '2025-06-01');           // 10 @ 100
        [$grn, $bill] = $this->purchase($supplier, [[$oil, 10, 120]], '2025-06-02', '2025-06-02');  // 10 @ 120 → avg 110
        $this->assertEqualsWithDelta(110, $this->average($oil), 0.0001);
        $in = StockLedger::where('reference_type', GoodsReceipt::class)->where('reference_id', $grn->id)->where('direction', 'in')->sole();

        $this->revise($grn, [$this->lineOf($grn, $oil)->id => [8, 140]])->assertRedirect();

        $out = StockLedger::where('reversal_of_id', $in->id)->sole();
        $this->assertSame([10.0, 120.0, now()->toDateString()], [(float) $out->quantity, (float) $out->unit_cost, $out->created_at->toDateString()],
            'its own entry comes back out, at the cost it came in at, today');
        $this->assertEqualsWithDelta(18, $this->stock($oil), 0.001);
        $this->assertEqualsWithDelta((1000 + 1120) / 18, $this->average($oil), 0.0001,
            'as if 10 @ 120 had never come in: 10 @ 100 + 8 @ 140');
        $this->assertEqualsWithDelta(1120, (float) $bill->fresh()->grand_total, 0.001);
        $this->assertBooksAgree($supplier, [$oil]);
    }

    public function test_a_receipt_without_an_invoice_is_corrected_in_stock_only(): void
    {
        $supplier = $this->newSupplier('PED Goods Only Supplier');
        $soap = $this->item('PED Goods Only Soap', true);
        [$grn] = $this->purchase($supplier, [[$soap, 12, 50]], '2025-06-05', null);
        $journals = JournalEntry::count();

        $this->revise($grn, [$this->lineOf($grn, $soap)->id => [10, 50]])->assertRedirect();

        $this->assertEqualsWithDelta(10, $this->stock($soap), 0.001);
        $this->assertSame($journals, JournalEntry::count(), 'a receipt alone has no journal, so none is written');
        $this->assertSame(0, SupplierLedger::where('supplier_id', $supplier->id)->count(), 'nor any supplier ledger row');
        $this->assertBooksAgree($supplier, [$soap]);
    }

    public function test_a_line_at_zero_comes_off_and_a_second_edit_reverses_the_first_correction(): void
    {
        $supplier = $this->newSupplier('PED Twice Supplier');
        $cola = $this->item('PED Twice Cola', false);
        [$grn, $bill] = $this->purchase($supplier, [[$cola, 3, 50], [$cola, 2, 50]], '2025-06-25', '2025-06-25');   // the same item twice
        [$first, $second] = GoodsReceipt::find($grn->id)->lines()->orderBy('id')->get()->all();

        $this->revise($grn, [$first->id => [5, 50], $second->id => [0, 50]])->assertRedirect();
        $this->assertSame(1, GoodsReceipt::find($grn->id)->lines()->count(), 'the line at 0 is gone');
        $this->assertContains('PED Twice Cola: taken off (was 2 × 50)', GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->sole()->changes);

        $this->revise($grn->fresh(), [$first->id => [5, 40]], [], 1)->assertRedirect();

        $this->assertEqualsWithDelta(200, (float) $bill->fresh()->grand_total, 0.001);
        $sources = fn ($q) => $q->where(fn ($q) => $q->whereIn('source_type', ['purchase_bill', 'purchase_bill_reversal'])->where('source_id', $bill->id))
            ->orWhere(fn ($q) => $q->whereIn('source_type', ['purchase_bill_revision', 'purchase_bill_revision_reversal'])
                ->whereIn('source_id', GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->pluck('id')));
        $all = JournalEntry::query()->where($sources)->get();
        $this->assertSame(3, $all->where('is_reversal', false)->count(), 'the invoice, then one corrected entry per edit');
        $this->assertSame(2, $all->where('is_reversal', true)->count(), 'each earlier one reversed exactly once');
        $inForce = $all->where('is_reversal', false)->filter(fn ($e) => ! $all->contains('reversed_entry_id', $e->id));
        $this->assertCount(1, $inForce);
        $this->assertEqualsWithDelta(200, (float) $inForce->first()->total_credit, 0.001, 'only the latest one is in force');
        $this->assertBooksAgree($supplier);
    }

    public function test_a_paid_invoice_cut_below_its_payments_leaves_the_excess_on_no_invoice(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);
        $supplier = $this->newSupplier('PED Paid Supplier');
        $rice = $this->item('PED Paid Rice', false);
        [$grn, $first] = $this->purchase($supplier, [[$rice, 10, 100]], '2025-07-01', '2025-07-01');   // 1,000
        [, $second] = $this->purchase($supplier, [[$rice, 5, 100]], '2025-07-02', '2025-07-02');        //   500, open
        $this->pay($supplier, 1000, '2025-07-03', $first->id);
        $this->assertEqualsWithDelta(0, (float) $first->fresh()->balance_due, 0.001);

        $this->revise($grn, [$this->lineOf($grn, $rice)->id => [7, 100]])->assertRedirect();

        $first->refresh();
        $this->assertEqualsWithDelta(700, (float) $first->amount_paid, 0.001, 'it keeps only what it now costs');
        $this->assertEqualsWithDelta(0, (float) $first->balance_due, 0.001);
        $this->assertEqualsWithDelta(500, (float) $second->fresh()->balance_due, 0.001, 'the other invoice is never paid silently');
        $this->assertEqualsWithDelta(300, array_sum(array_column(app(SupplierRunningAccountService::class)->unallocatedCredits($supplier->id), 'remaining')),
            0.001, 'the 300 is "Not on any invoice"');
        $this->assertEqualsWithDelta(200, (float) $supplier->fresh()->current_balance, 0.001, '500 owed less 300 paid ahead');
        $this->assertTrue(collect(GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->sole()->changes)
            ->contains(fn ($c) => str_contains($c, 'Rs 300.00 already paid came off this invoice')));
        $this->assertBooksAgree($supplier);
    }

    public function test_the_guards_refuse_in_words_and_write_nothing(): void
    {
        $supplier = $this->newSupplier('PED Guard Supplier');
        $drink = $this->item('PED Guard Drink', false);
        [$grn, $bill] = $this->purchase($supplier, [[$drink, 4, 100]], '2025-08-01', '2025-08-01');
        $line = $this->lineOf($grn, $drink);
        $nothing = fn () => [(float) $bill->fresh()->grand_total, GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->count(),
            JournalEntry::count(), SupplierLedger::count()];
        $before = $nothing();

        $this->revise($grn, [$line->id => [4, 90]], [], 5)->assertSessionHasErrors('revision_no');
        $this->revise($grn, [$line->id => [4, 100]])->assertSessionHasErrors(['lines' => 'Nothing was changed.']);
        $this->revise($grn, [$line->id => [0, 100]])->assertSessionHasErrors('lines');
        $this->assertSame($before, $nothing(), 'a refusal writes nothing');

        // Running account off and a payment on the invoice: void the payment first.
        $this->pay($supplier, 100, '2025-08-02', $bill->id);
        $this->owner()->get($this->url('/goods-receipts/' . $grn->id))->assertOk()
            ->assertSee('data-grn-blocked', false)->assertSee('Void that payment first')->assertDontSee('data-grn-edit>', false);
        $this->revise($grn, [$line->id => [4, 90]])->assertSessionHasErrors('revise');
        $this->assertEqualsWithDelta(400, (float) $bill->fresh()->grand_total, 0.001);

        // Goods already sold from this receipt: its stock cannot come back at its cost.
        $flour = $this->item('PED Guard Flour', true);
        $this->purchase($supplier, [[$flour, 10, 1]], '2025-08-03', '2025-08-03');
        [$dear] = $this->purchase($supplier, [[$flour, 10, 100]], '2025-08-04', '2025-08-04');
        $variantId = StockLedger::where('product_id', $flour->id)->where('direction', 'in')->value('product_variant_id');
        app(InventoryService::class)->postOutFefo(\App\Models\Tenant\Branch::findOrFail($this->branchId()), $flour,
            $variantId ? \App\Models\Tenant\ProductVariant::find($variantId) : null, 9, 'adjustment_out');
        $this->revise($dear, [$this->lineOf($dear, $flour)->id => [10, 90]])->assertSessionHasErrors('revise');
        $this->assertSame(0, GoodsReceiptRevision::where('goods_receipt_id', $dear->id)->count());
        $this->assertBooksAgree($supplier, [$flour]);

        // Switch off: no Edit button, and the save is not there.
        $this->setFeatures(array_diff_key(self::STORE_FEATURES, ['purchase_edit' => true]));
        [$other] = $this->purchase($supplier, [[$drink, 1, 100]], '2025-08-05', '2025-08-05');
        $this->owner()->get($this->url('/goods-receipts/' . $other->id))->assertOk()->assertDontSee('data-grn-edit', false);
        $this->revise($other, [$this->lineOf($other, $drink)->id => [2, 100]])->assertNotFound();
        $this->setFeatures(self::STORE_FEATURES);

        // No permission: refused at the door.
        $clerk = $this->roleUser('PED Receipt Clerk', ['tenant.goods-receipts.index', 'tenant.goods-receipts.show']);
        $this->actingAs($clerk, 'tenant')->post($this->url('/goods-receipts/' . $other->id . '/revise'), [])->assertForbidden();
    }

    public function test_void_takes_the_receipt_and_its_invoice_off_on_the_chosen_day(): void
    {
        $supplier = $this->newSupplier('PED Void Supplier');
        $tin = $this->item('PED Void Tin', true);
        $drink = $this->item('PED Void Drink', false);
        [$grn, $bill] = $this->purchase($supplier, [[$tin, 4, 50], [$drink, 2, 100]], '2025-08-10', '2025-08-10');   // 400
        $original = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)->firstOrFail();

        $this->owner()->post($this->url('/goods-receipts/' . $grn->id . '/void'), ['revision_no' => 0, 'void_reason' => 'wrong supplier'])
            ->assertSessionHasErrors('void_date_mode');
        $this->owner()->post($this->url('/goods-receipts/' . $grn->id . '/void'), ['revision_no' => 0, 'void_reason' => 'wrong supplier',
            'void_date_mode' => 'original'])->assertRedirect();

        $this->assertSame('voided', $grn->fresh()->status);
        $bill->refresh();
        $this->assertSame(['voided', 0.0, 0.0], [$bill->status, (float) $bill->grand_total, (float) $bill->balance_due],
            'the invoice stays on record, at 0');
        $this->assertEqualsWithDelta(0, $this->stock($tin), 0.001, 'the goods came back out');
        $this->assertSame('2025-08-10', JournalEntry::where('reversed_entry_id', $original->id)->sole()->entry_date->toDateString(),
            'a wrong entry wiped on its own date');
        $row = SupplierLedger::where('supplier_id', $supplier->id)->where('entry_type', 'purchase_bill_void')->sole();
        $this->assertSame(['credit', 400.0, '2025-08-10'], [$row->direction, (float) $row->amount, $row->entry_date->toDateString()]);
        $this->assertEqualsWithDelta(0, (float) $supplier->fresh()->current_balance, 0.001);
        $this->owner()->get($this->url('/purchase-bills/' . $bill->id))->assertOk()->assertSee('data-bill-voided', false);
        $this->assertBooksAgree($supplier, [$tin]);

        [$later, $laterBill] = $this->purchase($supplier, [[$drink, 3, 100]], '2025-08-11', '2025-08-11');
        $this->owner()->post($this->url('/goods-receipts/' . $later->id . '/void'), ['revision_no' => 0, 'void_reason' => 'returned it all',
            'void_date_mode' => 'today'])->assertRedirect();
        $laterJournal = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $laterBill->id)->firstOrFail();
        $this->assertSame(now()->toDateString(), JournalEntry::where('reversed_entry_id', $laterJournal->id)->sole()->entry_date->toDateString(),
            'reversed today when it was right and is undone now');
        $this->assertBooksAgree($supplier, [$tin]);
    }

    public function test_the_page_offers_edit_and_void_and_opens_the_boxes(): void
    {
        $supplier = $this->newSupplier('PED Page Supplier');
        $drink = $this->item('PED Page Drink', false);
        [$grn, $bill] = $this->purchase($supplier, [[$drink, 6, 70]], '2025-09-01', '2025-09-01');
        $line = $this->lineOf($grn, $drink);

        $this->owner()->get($this->url('/goods-receipts/' . $grn->id))->assertOk()
            ->assertSee('data-grn-edit', false)->assertSee('data-grn-void', false)->assertSee('Which day should this be undone on?');
        $this->owner()->get($this->url('/goods-receipts/' . $grn->id . '?edit=1'))->assertOk()
            ->assertSee('data-grn-edit-form', false)
            ->assertSee('name="lines[' . $line->id . '][quantity]"', false)
            ->assertSee('name="lines[' . $line->id . '][unit_cost]"', false)
            ->assertSee('name="bill_date"', false)
            ->assertSee('Save changes');
        $this->owner()->get($this->url('/purchase-bills/' . $bill->id))->assertOk()->assertSee('data-bill-edit', false);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * A purchase through the workspace: receipt (+ invoice when $billDate), typed today, dated as given.
     *
     * @param  list<array{0: Product, 1: int|float, 2: int|float}>  $lines
     * @return array{0: GoodsReceipt, 1: ?PurchaseBill}
     */
    private function purchase(Supplier $supplier, array $lines, string $receiptDate, ?string $billDate): array
    {
        $this->owner()->postJson($this->url('/purchases'), ['start' => 'new', '_entry_v2' => '1', 'branch_id' => $this->branchId(),
            'supplier_id' => $supplier->id, 'receipt_date' => $receiptDate,
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'buy_pack' => 'base', 'price' => (string) $l[2], 'packs' => ['base' => (string) $l[1]]], $lines),
            'record_invoice' => $billDate ? '1' : '0'] + ($billDate ? ['invoice' => ['bill_date' => $billDate]] : []))->assertOk();
        $grn = GoodsReceipt::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();

        return [$grn, PurchaseBill::where('goods_receipt_id', $grn->id)->first()];
    }

    /** POST the edit: $lines = [line id => [qty, rate]]; lines not given are sent unchanged. */
    private function revise(GoodsReceipt $grn, array $lines, array $header = [], ?int $revisionNo = null)
    {
        $grn = GoodsReceipt::with('lines')->findOrFail($grn->id);
        $bill = PurchaseBill::where('goods_receipt_id', $grn->id)->first();
        $sent = [];
        foreach ($grn->lines as $l) {
            [$q, $c] = $lines[$l->id] ?? [(float) $l->quantity_received, (float) $l->unit_cost];
            $sent[$l->id] = ['quantity' => $q, 'unit_cost' => $c];
        }

        return $this->owner()->from($this->url('/goods-receipts/' . $grn->id . '?edit=1'))
            ->post($this->url('/goods-receipts/' . $grn->id . '/revise'), $header + [
                'revision_no'  => $revisionNo ?? (int) $grn->revision_no,
                'receipt_date' => $grn->receipt_date->toDateString(),
                'bill_date'    => $bill?->bill_date?->toDateString(),
                'supplier_invoice_no' => $bill?->supplier_invoice_no,
                'extra_charges' => (float) $grn->extra_charges,
                'notes'        => $grn->notes,
                'lines'        => $sent,
            ]);
    }

    private function pay(Supplier $supplier, float $amount, string $date, ?int $billId = null)
    {
        return app(SupplierPayableService::class)->recordPayment(['supplier_id' => $supplier->id, 'branch_id' => $this->branchId(),
            'purchase_bill_id' => $billId, 'payment_method' => 'cash', 'payment_date' => $date, 'amount' => $amount,
            'cash_bank_account_id' => (int) DB::connection('tenant')->table('cash_bank_accounts')->where('is_active', 1)->orderBy('id')->value('id')]);
    }

    private function item(string $name, bool $stocked): Product
    {
        $p = $this->product($name, $this->piece()->id, ['default_selling_price' => 500]);
        if (! $stocked) {
            $p->update(['is_stock_tracked' => false]);
        }

        return $p->fresh();
    }

    private function lineOf(GoodsReceipt $grn, Product $p)
    {
        return GoodsReceipt::find($grn->id)->lines()->where('product_id', $p->id)->orderBy('id')->firstOrFail();
    }

    private function newSupplier(string $name): Supplier
    {
        return Supplier::create(['code' => 'PED-' . strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0]);
    }

    /** The corrected invoice journals of THIS receipt's edits (not of other tests' receipts). */
    private function correctedJournals(GoodsReceipt $grn)
    {
        return JournalEntry::where('source_type', 'purchase_bill_revision')->where('is_reversal', false)
            ->whereIn('source_id', GoodsReceiptRevision::where('goods_receipt_id', $grn->id)->pluck('id'));
    }

    private function average(Product $p): float
    {
        return (float) DB::connection('tenant')->table('stock_balances')->where('branch_id', $this->branchId())->where('product_id', $p->id)->value('average_cost');
    }

    /**
     * The books still agree: TB balances; this supplier's AP in the GL = its supplier ledger = its balance; each item's
     * stock quantity and value = its stock ledger.
     *
     * @param  list<Product>  $items
     */
    private function assertBooksAgree(Supplier $supplier, array $items = []): void
    {
        $db = DB::connection('tenant');
        $tb = $db->table('journal_lines')->selectRaw('ROUND(SUM(debit),4) d, ROUND(SUM(credit),4) c')->first();
        $this->assertEqualsWithDelta((float) $tb->d, (float) $tb->c, 0.001, 'the trial balance balances');

        $ap = (int) $db->table('accounts')->where('code', '2100')->value('id');
        $gl = (float) $db->table('journal_lines')->where('account_id', $ap)->where('supplier_id', $supplier->id)->sum(DB::raw('credit - debit'));
        $sub = (float) $db->table('supplier_ledgers')->where('supplier_id', $supplier->id)
            ->sum(DB::raw("CASE WHEN direction = 'debit' THEN amount ELSE -amount END"));
        $this->assertEqualsWithDelta($sub, $gl, 0.01, 'AP in the GL = the supplier ledger');
        $this->assertEqualsWithDelta($sub, (float) $supplier->fresh()->current_balance, 0.01, 'the supplier ledger = the supplier\'s balance');

        foreach ($items as $p) {
            $l = $db->table('stock_ledgers')->where('branch_id', $this->branchId())->where('product_id', $p->id)
                ->selectRaw("SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END) q, SUM(CASE WHEN direction='in' THEN total_cost ELSE -total_cost END) v")->first();
            $this->assertEqualsWithDelta((float) $l->q, $this->stock($p), 0.001, $p->name . ': stock = its ledger');
            $this->assertEqualsWithDelta((float) $l->v, $this->stock($p) * $this->average($p), 0.05, $p->name . ': stock value = its ledger');
        }
    }

    private function roleUser(string $roleName, array $permissions): User
    {
        DB::setDefaultConnection('tenant');
        $role = Role::findOrCreate($roleName, 'tenant');
        $role->syncPermissions($permissions);
        $user = User::on('tenant')->firstOrCreate(['email' => strtolower(str_replace(' ', '.', $roleName)) . '@pedtest.test'],
            ['name' => $roleName, 'password' => Hash::make('secret123'), 'status' => 'active']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
