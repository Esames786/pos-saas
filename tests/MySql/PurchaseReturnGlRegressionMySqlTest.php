<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\JournalLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierLedger;
use App\Services\Finance\JournalPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchasingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PURCHASE-RETURN-GL-REGRESSION — purchase return ka journal SACH ME post hota hai.
 *
 * `GL-BUSINESS-DATE-1` (`6e3e67c`, 6 Sep, prod par live) me `postPurchaseReturn()` ek
 * `PurchaseReturn` ko `bookOnReturn(SalesReturn $return)` me bhejne laga. Nateeja:
 *
 *   har bar TypeError  ->  us method ka apna `catch (Throwable) { report(); return null; }`
 *   usay nigal leta    ->  `null` laut-ta                ->  journal KABHI nahi banta
 *
 * Aur `PurchaseReturnService::post()` GL ko `if ($entry)` me lapet-ta hai, is liye document
 * "posted" ho jata tha magar `journal_entry_id` **NULL** reh jata — AP control subledger se
 * hat jata aur Trial Balance jhoot bolta.
 *
 * Kharabi teen din chhupi rahi kyunke KISI guard ne ye nahi poocha ke "journal bana ya nahi".
 * Ye file wohi sawal poochti hai, aur poore ASLI raaste se: `createDraft()` + `post()`, na ke
 * `postPurchaseReturn()` ko seedha bula kar.
 *
 * ⚠️ Wo fail-soft `catch` jaan-boojh kar mojood hai (bill/return ka purana contract: "GL ka
 * hichki operational document ko roll back na kare"). Ye file us contract ko NAHI badalti —
 * wo owner ka faisla hai. Ye sirf us khaali jagah ko band karti hai jis ne kharabi chhupayi:
 * ab `journal_entry_id` NULL rehna ek FAIL hai.
 */
class PurchaseReturnGlRegressionMySqlTest extends MySqlTenantTestCase
{
    private int $branchId;
    private int $supplierId;
    private int $productId;
    private int $unitId;

    private const OPENING   = 50000.0;   // supplier ka opening payable
    private const BILL      = 30000.0;   // purchase bill
    private const RECEIVED  = 100.0;     // stock IN (qty)
    private const UNIT_COST = 300.0;     // 100 x 300 = 30,000 — bill ke barabar
    private const RETURN_Q  = 10.0;      // 10 x 300 = 3,000 wapas

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // Asli raasta — createDraft() + post()
    // ══════════════════════════════════════════════════════════════════════════════

    public function test_official_purchase_return_ka_journal_banta_hai_aur_har_jagah_sahi_baithta_hai(): void
    {
        $this->postBill();

        $supplierBefore = (float) Supplier::find($this->supplierId)->current_balance;
        $stockBefore    = $this->onHand();
        $this->assertSame(self::OPENING + self::BILL, $supplierBefore, 'bill ke baad payable 80,000');
        $this->assertSame(self::RECEIVED, $stockBefore, 'stock par 100 mojood');

        $returnDate = now()->subDay()->toDateString();   // aaj se ALAG, taake tareekh ka imtihan sacha ho
        $expected   = self::RETURN_Q * self::UNIT_COST;  // 3,000

        $return = $this->postOfficialReturn($returnDate);

        // ── 1. operational document ────────────────────────────────────────────────
        $this->assertSame('posted', $return->status, 'return posted hai');
        $this->assertNotNull($return->posted_at);
        $this->assertSame($expected, (float) $return->grand_total, 'grand_total 3,000');

        // ── 2. YEHI wo assertion hai jo regression pakad-ti ───────────────────────
        $this->assertNotNull(
            $return->journal_entry_id,
            'purchase_returns.journal_entry_id NULL hai — yani GL post nahi hua aur nakaami '
            . 'chup-chaap nigal li gayi. Bilkul yehi 6 Sep se 9 Sep tak ho raha tha.'
        );

        // ── 3. GL journal mojood, aur wohi jo document par likha hai ──────────────
        $entry = JournalEntry::where('source_type', 'purchase_return')
            ->where('source_id', $return->id)->sole();
        $this->assertSame((int) $return->journal_entry_id, (int) $entry->id,
            'document ka journal_entry_id usi entry par ishara karta hai');
        $this->assertSame('posted', $entry->status);
        $this->assertFalse((bool) $entry->is_reversal);

        // ── 4. balanced ───────────────────────────────────────────────────────────
        $this->assertEqualsWithDelta((float) $entry->total_debit, (float) $entry->total_credit, 0.0001,
            'journal balanced hai');
        $this->assertSame($expected, (float) $entry->total_debit, 'kul debit 3,000');

        // ── 5. Dr 2100 AP / Cr 1400 Inventory — theek utna ────────────────────────
        $entry->loadMissing('lines.account');
        $byCode = $entry->lines->mapWithKeys(fn ($l) => [
            $l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit],
        ]);
        $this->assertSame($expected, $byCode['2100']['d'] ?? null, 'Dr Accounts Payable');
        $this->assertSame($expected, $byCode['1400']['c'] ?? null, 'Cr Inventory Asset');
        $this->assertCount(2, $entry->lines, 'do satrein — koi expense/income nahi');
        $this->assertSame(['asset', 'liability'], $entry->lines->pluck('account.type')->unique()->sort()->values()->all(),
            'maal wapas karna P&L ko chhoota hi nahi');

        // ── 6. tareekh — return ke DIN par, post karne ke din par nahi ────────────
        $this->assertSame($returnDate, $entry->entry_date->toDateString(),
            'journal return_date par baithta hai; ye assertion isi liye kal ki tareekh par chalti hai');

        // ── 7. supplier subledger ─────────────────────────────────────────────────
        $row = SupplierLedger::where('reference_type', PurchaseReturn::class)
            ->where('reference_id', $return->id)->sole();
        $this->assertSame('purchase_return', $row->entry_type);
        $this->assertSame('credit', $row->direction, 'return payable GHATATA hai');
        $this->assertSame($expected, (float) $row->amount);
        $this->assertSame($supplierBefore - $expected, (float) $row->balance_after);
        $this->assertSame($supplierBefore - $expected, (float) Supplier::find($this->supplierId)->current_balance,
            'payable 80,000 -> 77,000');

        // ── 8. AP control == subledger (yehi wo farq tha jo regression ne banaya) ─
        $this->assertSame($this->subledgerTotal(), $this->apControl(),
            'SUPPLIER SUBLEDGER == ACCOUNTS PAYABLE CONTROL');

        // ── 9. stock — official semantics ────────────────────────────────────────
        $this->assertSame($stockBefore - self::RETURN_Q, $this->onHand(), 'stock 100 -> 90');

        // ⚠️ `stock_ledgers` par `quantity_out` KOI column NAHI hai — wahan `direction` enum
        // ('in'/'out') aur ek `quantity` hai. Pehli koshish me maine quantity_out jama kiya aur
        // 0.0 mila: assertion galat column par chal rahi thi, stock bilkul theek nikla tha.
        $out = StockLedger::where('reference_type', 'purchase_return')
            ->where('reference_id', $return->id)->get();
        $this->assertNotEmpty($out, 'stock_ledgers par purchase_return ki satar hai');
        $this->assertSame(['out'], $out->pluck('direction')->unique()->all(), 'harkat OUT hai');
        $this->assertSame(['purchase_return'], $out->pluck('movement_type')->unique()->all());
        $this->assertSame(self::RETURN_Q, round((float) $out->sum('quantity'), 4),
            'theek 10 nikla — na kam, na zyada');

        // Aur koi doosri stock harkat is return ke naam par nahi.
        $this->assertSame(1, StockLedger::where('reference_type', 'purchase_return')->count(),
            'ek hi satar — FEFO ne ek hi batch se nikala');
    }

    /**
     * Wohi baat doosri taraf se: translator KHUD `null` na de.
     *
     * Regression ki asal shakl yehi thi — `postPurchaseReturn()` chup-chaap `null` laut-ta tha.
     * Oopar wala guard `journal_entry_id` par chalta hai (document ki taraf se); ye seedha
     * translator par, taake pata chale kharabi kis parat me hai.
     */
    public function test_translator_null_nahi_deta_aur_koi_typeerror_nigla_nahi_jata(): void
    {
        $this->postBill();
        $return = $this->postOfficialReturn(now()->toDateString());

        // Dobara chalao — idempotent hai, is liye WOHI entry milni chahiye, null nahi.
        $again = app(JournalPostingService::class)->postPurchaseReturn($return->fresh(), null);

        $this->assertNotNull($again,
            'postPurchaseReturn() ne null diya — yani andar koi Throwable (regression me TypeError) '
            . 'nigla gaya. `catch (Throwable) { report(); return null; }` isay chhupa deta hai.');
        $this->assertInstanceOf(JournalEntry::class, $again);
        $this->assertSame((int) $return->journal_entry_id, (int) $again->id, 'wohi entry, nayi nahi');
        $this->assertSame(1, JournalEntry::where('source_type', 'purchase_return')->count(),
            'replay par doosri entry nahi bani');
    }

    /**
     * Purana bartaao chhooa nahi: bill ka GL aur opening balance ka GL jaise the waise hain.
     */
    public function test_bill_aur_opening_ka_gl_waisa_hi_hai(): void
    {
        app(JournalPostingService::class)->postSupplierOpeningBalance(Supplier::find($this->supplierId));
        $bill = $this->postBill();

        $billEntry = JournalEntry::where('source_type', 'purchase_bill')->where('source_id', $bill->id)->sole();
        $billEntry->loadMissing('lines.account');
        $byCode = $billEntry->lines->mapWithKeys(fn ($l) => [
            $l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit],
        ]);
        $this->assertSame(self::BILL, $byCode['2100']['c'] ?? null, 'bill AP ko CREDIT karta hai');

        $openEntry = JournalEntry::where('source_type', 'supplier_opening_balance')->sole();
        $openEntry->loadMissing('lines.account');
        $openCodes = $openEntry->lines->pluck('account.code')->sort()->values()->all();
        $this->assertSame(['2100', '3300'], $openCodes,
            'opening ab bhi Dr 3300 EQUITY / Cr 2100 — kabhi P&L nahi');
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════════════

    /** ASLI raasta: createDraft() phir post(). `postPurchaseReturn()` ko seedha nahi bulaya. */
    private function postOfficialReturn(string $returnDate): PurchaseReturn
    {
        $svc = app(PurchaseReturnService::class);

        $draft = $svc->createDraft(
            [
                'branch_id'   => $this->branchId,
                'supplier_id' => $this->supplierId,
                'return_date' => $returnDate,
                'reason_code' => 'damaged',
                'notes'       => 'regression proof',
            ],
            [[
                'product_id' => $this->productId,
                'quantity'   => self::RETURN_Q,
                'unit_cost'  => self::UNIT_COST,
            ]],
            null
        );

        $this->assertSame('draft', $draft->status);

        return $svc->post($draft, null);
    }

    private function postBill(): PurchaseBill
    {
        $bill = PurchaseBill::create([
            'bill_no'     => 'BILL-' . Str::upper(Str::random(6)),
            'supplier_id' => $this->supplierId,
            'branch_id'   => $this->branchId,
            'bill_date'   => now()->toDateString(),
            'subtotal'    => self::BILL,
            'grand_total' => self::BILL,
            'amount_paid' => 0,
            'balance_due' => self::BILL,
            'status'      => 'posted',
        ]);

        app(PurchasingService::class)->postBill($bill, null);

        return $bill->fresh();
    }

    private function onHand(): float
    {
        return round((float) StockBalance::where('branch_id', $this->branchId)
            ->where('product_id', $this->productId)->sum('quantity_on_hand'), 4);
    }

    /** credit payable ghatata hai, debit barhata — is liye debit minus credit. */
    private function subledgerTotal(): float
    {
        return round((float) SupplierLedger::where('supplier_id', $this->supplierId)
            ->sum(DB::raw("CASE WHEN direction = 'debit' THEN amount ELSE -amount END")), 2);
    }

    /** 2100 credit-normal hai — credit minus debit. */
    private function apControl(): float
    {
        $apId = (int) Account::where('code', '2100')->value('id');

        return round((float) JournalLine::where('account_id', $apId)->sum(DB::raw('credit - debit')), 2);
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_payments',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills',
            'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'units', 'suppliers', 'accounts', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $c = DB::connection('tenant');

        $this->branchId = $c->table('branches')->insertGetId([
            'name' => 'Main', 'code' => 'MAIN', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->unitId = $c->table('units')->insertGetId([
            'code' => 'PCS', 'name' => 'Pieces', 'unit_type' => 'quantity',
            'base_factor' => 1, 'is_base' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $categoryId = $c->table('categories')->insertGetId([
            'name' => 'Raw', 'slug' => 'raw-' . Str::random(5), 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Purchase return ke liye product STOCK-TRACKED hona chahiye — warna postOutFefo()
        // ka `ensureStockTracked()` hi rok deta hai. Supplier-finance ke baqi guards service
        // products par chalte hain; ye wo farq hai.
        $this->productId = Product::create([
            'category_id'        => $categoryId,
            'unit_id'            => $this->unitId,
            'sku'                => 'RAW-' . Str::upper(Str::random(5)),
            'name'               => 'Returnable Raw Item',
            'slug'               => 'returnable-raw-' . Str::random(5),
            // `item_kind` ENUM hai: ingredient|finished_good|both — `raw_material` us me NAHI.
            'product_kind'                  => 'stocked',
            'item_kind'                     => 'ingredient',
            'inventory_consumption_method'  => 'stock_item',
            'is_stock_tracked'              => true,
            'is_purchasable'     => true,
            'is_sellable'        => false,
            'status'             => 'active',
        ])->id;

        $supplier = Supplier::create([
            'code' => 'SUP-' . Str::upper(Str::random(4)), 'name' => 'Return Test Supplier',
            'status' => 'active', 'opening_balance' => self::OPENING, 'current_balance' => self::OPENING,
        ]);
        $this->supplierId = $supplier->id;

        // Opening ki subledger satar bhi — SupplierController::store() yehi karta hai; iske
        // baghair AP-reconciliation ka imtihan opening ke barabar farq se fail hota hai.
        SupplierLedger::create([
            'supplier_id' => $supplier->id, 'entry_type' => 'opening_balance', 'direction' => 'debit',
            'amount' => self::OPENING, 'balance_after' => self::OPENING, 'reference_no' => 'OPENING',
        ]);

        // ...aur uska GL bhi (Dr 3300 EQUITY / Cr 2100). `SupplierController::store()` DONO likhta
        // hai. Pehle maine sirf subledger ki satar likhi thi, jis se AP-reconciliation ka imtihan
        // theek OPENING ke barabar (50,000) farq se fail hua: subledger 77,000, AP control 27,000.
        // Kharabi system me nahi, meri fixture me thi — asli raasta poora likhta hai.
        app(JournalPostingService::class)->postSupplierOpeningBalance($supplier, null);

        // Stock IN — wohi authority jo GRN istemal karta hai (PurchasingService::postGrn).
        app(InventoryService::class)->postIn(
            Branch::find($this->branchId),
            Product::find($this->productId),
            null,
            self::RECEIVED,
            self::UNIT_COST,
            'purchase',
            'goods_receipt',
            null,
            'GRN-SEED',
            null,
            null,
            'seed stock for return',
            null
        );
    }
}
