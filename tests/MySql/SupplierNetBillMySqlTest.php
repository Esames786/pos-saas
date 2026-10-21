<?php

namespace Tests\MySql;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchaseBillService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Purchasing\SupplierRunningAccountService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\RacesWorkers;

/**
 * SUPPLIER-NET-BILL-1 (owner, 21 Oct): record the ACTUAL supplier invoice. A return made before the bill must be
 * credited exactly once, whether the supplier bills everything received (1,000, the return stays a credit) or bills
 * already less the return (800). Both end at payable 800 — never 600. The old return is never edited or deleted.
 *
 * Through the real services: postGrn → createDraft + post (return) → PurchaseBillService → SupplierPayableService.
 */
class SupplierNetBillMySqlTest extends MySqlTenantTestCase
{
    use RacesWorkers;
    use \Tests\MySql\Support\ParsesInlineScripts;
    use \Tests\MySql\Support\AssertsPlainEnglish;

    private int $branchId;

    private int $supplierId;

    private int $productId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_credit_allocations', 'supplier_payments',
            'purchase_bill_return_nettings', 'supplier_credit_reallocations', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills', 'purchasing_settings',
            'goods_receipt_lines', 'goods_receipts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'units', 'suppliers', 'accounts', 'branches', 'users',
        ]);
        (new DefaultChartOfAccountsSeeder)->run();
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);   // as on every live tenant
        $c = DB::connection('tenant');
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $unit = $c->table('units')->insertGetId(['code' => 'PCS', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $c->table('categories')->insertGetId(['name' => 'Goods', 'slug' => 'goods-' . Str::random(5), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = Product::create(['category_id' => $cat, 'unit_id' => $unit, 'sku' => 'NB-' . Str::upper(Str::random(5)), 'name' => 'Net Bill Item',
            'slug' => 'net-bill-' . Str::random(5), 'item_kind' => 'finished_good', 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => true, 'is_purchasable' => true, 'status' => 'active'])->id;
        $this->supplierId = Supplier::create(['code' => 'NB-' . Str::upper(Str::random(4)), 'name' => 'Net Bill Supplier', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0])->id;
        $this->userId = $c->table('users')->insertGetId(['name' => 'Accounts', 'email' => 'nb' . uniqid() . '@test.local', 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_an_invoice_for_everything_received_keeps_the_return_as_a_credit_payable_800(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);

        $bill = $this->bill($grn, 'separate');

        $this->assertEqualsWithDelta(1000, (float) $bill->grand_total, 0.001, 'the invoice as the supplier wrote it');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001);
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001, 'the return credit settles it once');
        $this->assertBooksAgree(800);
    }

    public function test_an_invoice_already_less_the_return_ends_at_800_and_the_credit_is_used_once(): void
    {
        $grn = $this->receive([[10, 100]]);
        $return = $this->returnOf($grn, 2);

        $bill = $this->bill($grn, 'netted');

        $this->assertEqualsWithDelta(800, (float) $bill->grand_total, 0.001, 'the invoice as the supplier wrote it: 1,000 less the 200 returned');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001, 'not 600');
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001);
        $this->assertBooksAgree(800);
        $this->assertSame(1, DB::table('purchase_bill_return_nettings')->where('purchase_return_id', $return->id)->count(), 'linked once');
        $this->assertEmpty(collect(app(SupplierRunningAccountService::class)->unallocatedCredits($this->supplierId))
            ->where('type', 'return')->where('id', $return->id)->all(), 'the 200 is never offered again');
        $this->assertSame('posted', $return->fresh()->status, 'the return itself is untouched');
        $this->assertEqualsWithDelta(200, (float) $return->fresh()->grand_total, 0.001);
    }

    public function test_a_net_invoice_paid_in_full_settles_everything(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $bill = $this->bill($grn, 'netted');

        $this->pay($bill, 800);

        $this->assertEqualsWithDelta(0, $this->supplierOwes(), 0.001);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertEqualsWithDelta(0, $this->gl('2100'), 0.001, 'payable cleared');
        $this->assertEqualsWithDelta(800, $this->gl('1400'), 0.001, 'inventory = 8 × 100');
    }

    public function test_a_return_that_had_settled_an_older_open_bill_moves_only_on_an_authorized_confirmation(): void
    {
        // An older open bill of 500 for the same supplier: the return made before the new bill settled it (oldest first).
        $older = $this->receive([[5, 100]]);
        $olderBill = $this->bill($older, null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $this->assertEqualsWithDelta(300, (float) $olderBill->fresh()->balance_due, 0.001, 'the 200 went to the older bill');
        $preview = app(PurchaseBillService::class)->preview($grn);
        $this->assertSame(['bill_no' => $olderBill->bill_no, 'amount' => 200.0, 'due_now' => 300.0, 'due_after' => 500.0, 'settled' => false],
            collect($preview['returns'][0]['allocations'][0])->only(['bill_no', 'amount', 'due_now', 'due_after', 'settled'])->all(), 'the screen says where the credit is');

        // Owner, 21 Oct: never moved silently — the confirmation names the bill and its outstanding before and after.
        try {
            $this->bill($grn, 'netted');
            $this->fail('moving a credit off another bill needs an explicit confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Rs 200.00 of this credit will be moved off ' . $olderBill->bill_no . ': its outstanding goes from 300.00 to 500.00', $e->errors()['confirm_reallocation'][0]);
        }
        try {
            $this->bill($grn, 'netted', confirm: true, mayReallocate: false);
            $this->fail('only an authorized person may move it');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('void supplier payments', $e->errors()['confirm_reallocation'][0]);
        }
        $this->assertSame(0, PurchaseBill::where('goods_receipt_id', $grn->id)->count(), 'nothing posted by either refusal');
        $this->assertEqualsWithDelta(300, (float) $olderBill->fresh()->balance_due, 0.001, 'and nothing moved');

        $bill = $this->bill($grn, 'netted', confirm: true);

        $this->assertEqualsWithDelta(500, (float) $olderBill->fresh()->balance_due, 0.001, 'the supplier never credited the older bill — it owes 500 again');
        $this->assertEqualsWithDelta(800, (float) $bill->fresh()->balance_due, 0.001);
        $this->assertEqualsWithDelta(1300, $this->supplierOwes(), 0.001, '500 + 800');
        $this->assertEqualsWithDelta(1300, (float) PurchaseBill::where('supplier_id', $this->supplierId)->sum('balance_due'), 0.001, 'bills agree with the ledger');
        $this->assertBooksAgree(1300, 1300);
        $audit = DB::table('supplier_credit_reallocations')->where('purchase_bill_id', $bill->id)->get();
        $this->assertCount(1, $audit, 'the move is written down');
        $this->assertSame([(int) $olderBill->id, 200.0, 300.0, 500.0, $this->userId],
            [(int) $audit[0]->from_purchase_bill_id, (float) $audit[0]->amount, (float) $audit[0]->from_due_before, (float) $audit[0]->from_due_after, (int) $audit[0]->confirmed_by]);
    }

    public function test_a_credit_that_settled_a_fully_paid_bill_is_never_taken_back(): void
    {
        // The older bill of 200 is fully settled by the return's credit: reopening it is refused, and said why.
        $older = $this->receive([[2, 100]]);
        $olderBill = $this->bill($older, null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $this->assertSame('paid', $olderBill->fresh()->status, 'the 200 credit paid the older bill in full');

        $preview = app(PurchaseBillService::class)->preview($grn);
        $this->assertStringContainsString('fully paid', (string) $preview['blocked']);
        try {
            $this->bill($grn, 'netted', confirm: true);
            $this->fail('a settled bill is never reopened');
        } catch (ValidationException $e) {
            $why = $e->errors()['returns_on_invoice'][0];
            $this->assertStringContainsString('fully paid', $why);
            // review, 21 Oct: never pushed to a full invoice the supplier did not send
            $this->assertStringContainsString('If the supplier deducted this return on this invoice, do not record it as a full invoice', $why);
            $this->assertStringContainsString('leave this goods receipt without an invoice', $why);
        }
        $this->assertSame('paid', $olderBill->fresh()->status, 'untouched');

        $bill = $this->bill($grn, 'separate');   // the honest way: the invoice for everything, the return stays a credit
        $this->assertEqualsWithDelta(1000, (float) $bill->fresh()->balance_due, 0.001, 'its credit is already spent on the older bill');
        $this->assertEqualsWithDelta(1000, $this->supplierOwes(), 0.001);
    }

    public function test_the_screen_shows_the_credit_the_bill_will_really_use(): void
    {
        // The summary's "Paid now (supplier credit)" comes from preview(): it must be what posting actually does.
        foreach (['separate' => 200.0, 'netted' => 0.0] as $choice => $expected) {
            // a supplier of its own each time: the first round's credit must not settle the second round's bill
            $this->supplierId = Supplier::create(['code' => 'NB-' . Str::upper(Str::random(5)), 'name' => 'Preview ' . $choice, 'status' => 'active',
                'opening_balance' => 0, 'current_balance' => 0])->id;
            $grn = $this->receive([[10, 100]]);
            $this->returnOf($grn, 2);
            $credit = app(PurchaseBillService::class)->preview($grn)['credit'][$choice];
            $this->assertEqualsWithDelta($expected, $credit, 0.001, $choice);
            $bill = $this->bill($grn, $choice);
            $this->assertEqualsWithDelta($credit, (float) $bill->fresh()->amount_paid, 0.001, $choice . ': the preview is what posted');
        }
    }

    public function test_free_goods_returned_leave_nothing_to_net(): void
    {
        $grn = $this->receive([[10, 100], [2, 0]]);
        $this->returnOf($grn, 2, 1);   // the free line

        $this->assertSame([], app(PurchaseBillService::class)->returnsToAsk($grn), 'no money was credited — nothing to ask');
        $bill = $this->bill($grn, null);
        $this->assertEqualsWithDelta(1000, (float) $bill->grand_total, 0.001);
        $this->assertEqualsWithDelta(1000, $this->supplierOwes(), 0.001);
    }

    public function test_the_question_is_asked_only_when_the_receipt_has_returns_and_never_answered_for_the_user(): void
    {
        $grn = $this->receive([[10, 100]]);
        try {
            $this->bill($grn, 'netted');
            $this->fail('no return before this bill — nothing can be "already less"');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('returns_on_invoice', $e->errors());
        }

        $this->returnOf($grn, 2);
        $this->assertCount(1, app(PurchaseBillService::class)->returnsToAsk($grn));
        try {
            $this->bill($grn, null);
            $this->fail('a receipt with a return before its bill must say how the invoice treats it');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('returns_on_invoice', $e->errors());
        }
        $this->assertSame(0, PurchaseBill::count(), 'nothing posted');

        // Without returns and without an answer: exactly as before.
        $plain = $this->receive([[3, 100]]);
        $this->assertEqualsWithDelta(300, (float) $this->bill($plain, null)->grand_total, 0.001);
    }

    public function test_two_bills_for_one_receipt_at_the_same_moment_make_one(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $job = ['mode' => 'bill_post', 'grn_id' => $grn->id, 'user_id' => $this->userId, 'choice' => 'netted'];

        [$out, $queued] = $this->race('SELECT id FROM goods_receipts WHERE id = ? FOR UPDATE', [$grn->id], [$job, $job]);

        $this->assertSame(2, $queued, 'both were in flight together, queued behind the receipt: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'POSTED:'), 'one bill: ' . implode(' | ', $out));
        $this->assertSame(1, $this->countOut($out, 'REFUSED:'), 'the other is told a bill exists: ' . implode(' | ', $out));
        $this->assertSame(1, PurchaseBill::where('goods_receipt_id', $grn->id)->count());
        $this->assertSame(1, DB::table('purchase_bill_return_nettings')->count(), 'the return netted once');
        $this->assertEqualsWithDelta(800, $this->supplierOwes(), 0.001);
    }

    // ── review, 21 Oct: accounting failure, partial deduction ────────────────

    public function test_an_accounting_entry_that_cannot_post_saves_nothing_and_posting_again_works(): void
    {
        // A move off an older open bill is in play too, so the audit row and the credit move must roll back as well.
        $olderBill = $this->bill($this->receive([[5, 100]]), null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $before = $this->snapshot($olderBill);
        DB::table('accounts')->where('code', '2100')->update(['is_active' => 0]);   // a real failure: payable cannot be posted to

        $response = $this->postInvoice($grn, ['returns_on_invoice' => 'netted', 'confirm_reallocation' => '1', 'supplier_invoice_no' => 'INV-77']);

        $this->assertTrue($response->isRedirect(), 'back to the form');
        $this->assertStringContainsString('could not be posted, so nothing was saved', session('errors')->first('goods_receipt_id'));
        $this->assertSame('INV-77', session()->getOldInput('supplier_invoice_no'), 'what was typed comes back');
        $this->assertSame($before, $this->snapshot($olderBill), 'no invoice, line, ledger row, journal, netting or audit row; no credit moved');

        // A plain invoice (no return, so no netting entry to fail as well): its own journal is what is checked.
        $plain = $this->receive([[3, 100]]);
        try {
            $this->bill($plain, null);
            $this->fail('an invoice whose accounting entry did not post must not be saved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('accounting entry for this invoice could not be posted', $e->getMessage());
        }
        $this->assertSame(0, PurchaseBill::where('goods_receipt_id', $plain->id)->count());

        DB::table('accounts')->where('code', '2100')->update(['is_active' => 1]);
        $bill = $this->bill($grn, 'netted', confirm: true);   // posting again, once the cause is fixed
        $this->assertEqualsWithDelta(800, (float) $bill->grand_total, 0.001);
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'purchase_bill')->where('source_id', $bill->id)->count());
        $this->assertBooksAgree(1300, 1300);
    }

    public function test_a_deducted_return_whose_entry_cannot_post_saves_nothing_either(): void
    {
        // Only the netting journal fails (the invoice's own journal posts): still all or nothing.
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $this->app->bind(\App\Services\Finance\JournalPostingService::class, fn ($app) => new class($app->make(\App\Services\Finance\JournalService::class)) extends \App\Services\Finance\JournalPostingService {
            public function postPurchaseBillReturnNetting(\App\Models\Tenant\PurchaseBillReturnNetting $netting, ?int $userId = null): ?\App\Models\Tenant\JournalEntry
            {
                return null;   // as its safe-catch does when the entry cannot post
            }
        });
        $journals = DB::table('journal_entries')->count();

        try {
            $this->bill($grn, 'netted');
            $this->fail('an invoice whose deducted return has no accounting entry must not be saved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deducted return could not be posted, so nothing was saved', $e->getMessage());
        }
        $this->assertSame(0, PurchaseBill::count());
        $this->assertSame(0, DB::table('purchase_bill_return_nettings')->count());
        $this->assertSame($journals, DB::table('journal_entries')->count(), 'the invoice journal that did post is rolled back too');
        $this->assertEqualsWithDelta(-200, $this->supplierOwes(), 0.001, 'only the return, as before');
    }

    public function test_an_invoice_number_already_taken_draws_another(): void
    {
        // "BILL-<second>-<3 digits>": two invoices in one second clash about 1 in 900 — that made a test flaky, and a
        // person would have seen a database error. The number generator hands out a taken number first here.
        $first = $this->bill($this->receive([[1, 100]]), null);
        $taken = (string) $first->bill_no;
        $this->app->bind(PurchasingService::class, fn ($app) => new class($app->make(\App\Services\Inventory\InventoryService::class), $taken) extends PurchasingService {
            private int $calls = 0;

            public function __construct(\App\Services\Inventory\InventoryService $inventory, private string $taken)
            {
                parent::__construct($inventory);
            }

            public function nextBillNo(): string
            {
                return $this->calls++ === 0 ? $this->taken : parent::nextBillNo();
            }
        });

        $second = $this->bill($this->receive([[2, 100]]), null);

        $this->assertNotSame($taken, $second->bill_no, 'it drew another number');
        $this->assertSame(2, PurchaseBill::count());
        $this->assertEqualsWithDelta(200, (float) $second->grand_total, 0.001);
    }

    public function test_deducting_only_the_unused_credit_never_touches_a_settled_bill(): void
    {
        // The return's 200: 150 settled an older invoice (now paid), 50 is unused. Deducting 50 takes only the unused 50.
        $olderBill = $this->bill($this->receive([[3, 50]]), null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $this->assertSame('paid', $olderBill->fresh()->status);

        $bill = $this->bill($grn, 'netted', deducted: 50);

        $this->assertSame('paid', $olderBill->fresh()->status, 'the settled invoice is not reopened');
        $this->assertSame(0, DB::table('supplier_credit_reallocations')->count(), 'nothing moved');
        $this->assertEqualsWithDelta(950, (float) $bill->fresh()->balance_due, 0.001);
        $this->assertEqualsWithDelta(950, $this->supplierOwes(), 0.001);
        $this->assertBooksAgree(950, 950);
    }

    public function test_a_supplier_who_deducted_only_part_of_the_return(): void
    {
        // Return 200; the supplier took only 100 off this invoice.
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $bill = $this->bill($grn, 'netted', deducted: 100)->fresh();

        $this->assertEqualsWithDelta(900, (float) $bill->grand_total, 0.001, 'the invoice as the supplier wrote it');
        $this->assertEqualsWithDelta(100, (float) $bill->amount_paid, 0.001, 'the other 100 stays supplier credit and is applied');
        $this->assertEqualsWithDelta(800, (float) $bill->balance_due, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) DB::table('purchase_bill_return_nettings')->value('amount'), 0.0001, 'never more than deducted');
        $this->assertEqualsWithDelta(0.0, (float) DB::table('purchase_bills')->where('id', $bill->id)->value('discount_total'), 0.0001, 'not recorded as a discount');
        $this->assertBooksAgree(800, 800);
    }

    public function test_a_partial_deduction_moves_only_what_it_needs_off_another_bill(): void
    {
        $olderBill = $this->bill($this->receive([[5, 100]]), null);   // 500; the return's 200 then pays it down to 300
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);

        try {
            $this->bill($grn, 'netted', deducted: 100);
            $this->fail('a move needs the confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Rs 100.00 of this credit will be moved off ' . $olderBill->bill_no . ': its outstanding goes from 300.00 to 400.00',
                $e->errors()['confirm_reallocation'][0]);
        }
        $bill = $this->bill($grn, 'netted', confirm: true, deducted: 100);

        $this->assertEqualsWithDelta(400, (float) $olderBill->fresh()->balance_due, 0.001, 'only 100 came back off it; 100 still pays it');
        $this->assertEqualsWithDelta(900, (float) $bill->fresh()->balance_due, 0.001);
        $this->assertEqualsWithDelta(1300, $this->supplierOwes(), 0.001, '400 + 900');
        $this->assertEqualsWithDelta(1300, (float) PurchaseBill::where('supplier_id', $this->supplierId)->sum('balance_due'), 0.001);
        $this->assertBooksAgree(1300, 1300);
        $audit = DB::table('supplier_credit_reallocations')->where('purchase_bill_id', $bill->id)->sole();
        $this->assertSame([100.0, 300.0, 400.0], [(float) $audit->amount, (float) $audit->from_due_before, (float) $audit->from_due_after]);
    }

    public function test_a_deduction_must_be_more_than_zero_and_not_more_than_the_return(): void
    {
        $grn = $this->receive([[10, 100]]);
        $return = $this->returnOf($grn, 2);
        foreach ([250, 0, -5] as $wrong) {
            try {
                $this->bill($grn, 'netted', deducted: $wrong);
                $this->fail("deducted {$wrong} of a 200 return");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('not more than the return (200.00)', $e->errors()['netted_amounts.' . $return->id][0]);
            }
        }
        $this->assertSame(0, PurchaseBill::count(), 'nothing posted');
    }

    public function test_ui_every_state_reads_in_plain_english(): void
    {
        $normal = $this->receive([[10, 100]]);
        $this->assertNoRomanUrdu($this->billPage($normal), 'normal invoice');
        $this->assertNoRomanUrdu($this->billPage(null), 'choose a goods receipt');

        $this->bill($this->receive([[5, 100]]), null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $this->assertNoRomanUrdu($this->billPage($grn, $this->userWith('tenant.supplier-payments.void')), 'return asked, credit move with the tick');
        $this->assertNoRomanUrdu($this->billPage($grn, $this->userWith(null)), 'credit move without the authority');

        // every message the server can send back, shown on the form
        $messages = [];
        foreach ([
            fn () => $this->bill($grn, null),
            fn () => $this->bill($grn, 'netted'),
            fn () => $this->bill($grn, 'netted', confirm: true, mayReallocate: false),
            fn () => $this->bill($grn, 'netted', confirm: true, deducted: 999),
        ] as $attempt) {
            try {
                $attempt();
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $m) {
                    $messages[$key . '#' . count($messages)] = $m[0];
                }
            }
        }
        $this->assertCount(4, $messages, 'each attempt refused with its message');
        $this->assertNoRomanUrdu('<p>' . implode('</p><p>', $messages) . '</p>', 'validation and confirmation messages');
        $this->assertNoRomanUrdu($this->billPage($grn, $this->userWith('tenant.supplier-payments.void'), ['due_date' => 'The due date cannot be before the invoice date.']
            + array_combine(array_map(fn ($k) => explode('#', $k)[0], array_keys($messages)), array_values($messages))), 'the form showing them');

        // a supplier of its own: the return's credit then pays (and settles) that supplier's only other invoice
        $this->supplierId = Supplier::create(['code' => 'NB-' . Str::upper(Str::random(5)), 'name' => 'Blocked case', 'status' => 'active',
            'opening_balance' => 0, 'current_balance' => 0])->id;
        $this->bill($this->receive([[2, 100]]), null);
        $blocked = $this->receive([[10, 100]]);
        $this->returnOf($blocked, 2);
        $html = $this->billPage($blocked);
        $this->assertStringContainsString('Net invoice is not available', $html);
        $this->assertNoRomanUrdu($html, 'net invoice blocked');
    }

    /** What a failed post must leave exactly as it was. */
    private function snapshot(PurchaseBill $olderBill): array
    {
        return [
            'bills' => DB::table('purchase_bills')->count(), 'lines' => DB::table('purchase_bill_lines')->count(),
            'ledger' => DB::table('supplier_ledgers')->count(), 'journals' => DB::table('journal_entries')->count(),
            'nettings' => DB::table('purchase_bill_return_nettings')->count(), 'audit' => DB::table('supplier_credit_reallocations')->count(),
            'allocations' => [DB::table('supplier_credit_allocations')->count(), round((float) DB::table('supplier_credit_allocations')->sum('amount'), 4)],
            'supplier_owes' => $this->supplierOwes(), 'older_due' => round((float) $olderBill->fresh()->balance_due, 4),
        ];
    }

    /** The invoice form posted through its controller, as an authorized person would. */
    private function postInvoice(GoodsReceipt $grn, array $fields): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->userWith('tenant.supplier-payments.void');
        $request = \Illuminate\Http\Request::create('/purchase-bills', 'POST', ['goods_receipt_id' => $grn->id, 'bill_date' => now()->toDateString()] + $fields);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        auth('tenant')->setUser($user);
        $this->app->instance('request', $request);

        return app(\App\Http\Controllers\Tenant\PurchaseBillController::class)->store($request);
    }

    // ── UI (the real bill screen, rendered through its controller) ───────────

    public function test_ui_a_normal_bill_asks_nothing_and_shows_what_it_will_post(): void
    {
        $html = $this->billPage($this->receive([[10, 100]]));

        $this->assertStringNotContainsString('id="returns-on-invoice"', $html, 'no extra question on a normal bill');
        $this->assertMatchesRegularExpression('/id="bill-total-preview" data-base="1000(\.0+)?">1,000\.00</', $html, 'the invoice the bill will post');
        $this->assertStringContainsString('<button type="button" class="pb-btn" id="pb-review">Review invoice</button>', $html, 'one dominant action');
        $this->assertSame(2, substr_count($html, 'pb-num pb-sel'), 'discount and tax select their value on focus');
        $this->assertStringContainsString('<span>Goods receipt <b>', $html, 'the compact receipt strip');
        $this->assertMatchesRegularExpression('/<details class="htu" id="how-to-use">\s*<summary>How to use this screen<\/summary>/', $html, 'the guide is there, closed');
        $this->assertStringContainsString('/ Piece</span>', $html, 'rate basis shown');
        $this->assertInlineScriptsParse($html, 'supplier bill', 'pb-form');
    }

    public function test_ui_a_receipt_with_a_return_asks_and_picks_nothing(): void
    {
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $html = $this->billPage($grn);

        $this->assertStringContainsString('id="returns-on-invoice"', $html);
        $this->assertStringContainsString('<b>Full invoice — before returns</b>', $html);
        $this->assertStringContainsString('<b>Net invoice — returns already deducted</b>', $html);
        $this->assertMatchesRegularExpression('/<input id="ded-\d+" name="netted_amounts\[\d+\]"[^>]*value="200.00"/', $html, 'what was deducted, filled with the whole return');
        $this->assertSame(2, preg_match_all('/<input[^>]*name="returns_on_invoice"/', $html), 'two options');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="returns_on_invoice"[^>]*\schecked/', $html, 'nothing pre-picked');
        $this->assertMatchesRegularExpression('/id="bill-total-preview" data-base="1000(\.0+)?">—</', $html, 'no total until the question is answered');
        $this->assertStringContainsString('data-credit-separate="200"', $html, 'separate: the return credit pays 200 of it');
        $this->assertStringContainsString('data-credit-netted="0"', $html, 'netted: no credit left to use');
    }

    public function test_ui_a_credit_on_another_bill_is_spelled_out_and_needs_an_authorized_tick(): void
    {
        $olderBill = $this->bill($this->receive([[5, 100]]), null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);

        $html = $this->billPage($grn, $this->userWith('tenant.supplier-payments.void'));
        $this->assertStringContainsString('A net invoice moves 200.00 off it: its outstanding goes from 300.00 to 500.00. You confirm this on the review.', $html, 'said on the screen');
        $this->assertStringContainsString('"bill_no":"' . $olderBill->bill_no . '"', $html, 'the review is built from the same plan');
        $this->assertStringContainsString('name="confirm_reallocation"', $html, 'the tick for an authorized person');

        $plain = $this->billPage($grn, $this->userWith(null));
        $this->assertStringNotContainsString('name="confirm_reallocation"', $plain, 'no tick for anyone else');
        $this->assertStringContainsString('Only a person who can void supplier payments can move a credit between invoices.', $plain);
    }

    public function test_ui_a_settled_bill_blocks_the_net_option_with_the_reason(): void
    {
        $this->bill($this->receive([[2, 100]]), null);
        $grn = $this->receive([[10, 100]]);
        $this->returnOf($grn, 2);
        $html = $this->billPage($grn);

        $this->assertMatchesRegularExpression('/id="roi-netted"[^>]*\bdisabled\b/', $html, 'the net option cannot be picked');
        $this->assertStringContainsString('which is now fully paid', $html, 'and the screen says why');
        $this->assertDoesNotMatchRegularExpression('/id="roi-separate"[^>]*\bdisabled\b/', $html, 'the other way stays open');
    }

    /** @param array<string,string> $errors messages to show on the form, as after a refused post */
    private function billPage(?GoodsReceipt $grn, ?\App\Models\Tenant\User $user = null, array $errors = []): string
    {
        $request = \Illuminate\Http\Request::create('/purchase-bills/create', 'GET', $grn ? ['goods_receipt_id' => $grn->id] : []);
        if ($user) {
            auth('tenant')->setUser($user);
            $request->setUserResolver(fn () => $user);
        }
        view()->share('errors', (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag($errors)));

        return app(\App\Http\Controllers\Tenant\PurchaseBillController::class)->create($request)->render();
    }

    private function userWith(?string $permission): \App\Models\Tenant\User
    {
        $user = \App\Models\Tenant\User::create(['name' => 'Bills ' . uniqid(), 'email' => 'b' . uniqid() . '@test.local', 'password' => bcrypt('x'), 'status' => 'active']);
        if ($permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'tenant');
            $user->givePermissionTo($permission);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array{0:float,1:float}> $lines qty, unit cost */
    private function receive(array $lines): GoodsReceipt
    {
        $grn = GoodsReceipt::create(['grn_no' => 'GRN-' . Str::upper(Str::random(8)), 'branch_id' => $this->branchId, 'supplier_id' => $this->supplierId,
            'receipt_date' => now()->toDateString(), 'status' => 'posted']);
        foreach ($lines as [$qty, $cost]) {
            $grn->lines()->create(['product_id' => $this->productId, 'quantity_received' => $qty, 'unit_cost' => $cost, 'affects_stock' => true]);
        }
        app(PurchasingService::class)->postGrn($grn->fresh(['lines.product', 'lines.variant', 'branch']));

        return $grn->fresh();
    }

    private function bill(GoodsReceipt $grn, ?string $returnsOnInvoice, bool $confirm = false, bool $mayReallocate = true, ?float $deducted = null): PurchaseBill
    {
        // $deducted: what the supplier took off this invoice for the receipt's (first) return — null = the whole return
        $amounts = $deducted === null ? null : [(int) \App\Models\Tenant\PurchaseReturn::where('goods_receipt_id', $grn->id)->orderBy('id')->value('id') => (string) $deducted];

        return app(PurchaseBillService::class)->createFromReceipt($grn, array_filter([
            'bill_date' => now()->toDateString(), 'returns_on_invoice' => $returnsOnInvoice, 'confirm_reallocation' => $confirm ? '1' : null,
            'netted_amounts' => $amounts,
        ], fn ($v) => $v !== null), $this->userId, $mayReallocate);
    }

    private function returnOf(GoodsReceipt $grn, float $qty, int $lineIndex = 0): PurchaseReturn
    {
        $lineId = (int) $grn->lines()->orderBy('id')->skip($lineIndex)->value('id');
        $svc = app(PurchaseReturnService::class);
        $draft = $svc->createDraft(
            ['branch_id' => $this->branchId, 'supplier_id' => $this->supplierId, 'goods_receipt_id' => $grn->id, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged'],
            [['product_id' => $this->productId, 'source_line_id' => $lineId, 'quantity' => $qty]],
        );

        return $svc->post($draft);
    }

    private function pay(PurchaseBill $bill, float $amount): void
    {
        $bank = \App\Models\Tenant\CashBankAccount::create(['code' => 'BANK-' . Str::random(4), 'name' => 'Bank', 'account_type' => 'bank',
            'account_id' => (int) DB::table('accounts')->where('code', '1200')->value('id'), 'branch_id' => $this->branchId,
            'opening_balance' => 100000, 'current_balance' => 100000, 'is_active' => true]);
        app(\App\Services\Finance\SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $bank->id,
            'purchase_bill_id' => $bill->id, 'payment_date' => now()->toDateString(), 'amount' => $amount, 'payment_method' => 'bank_transfer',
        ]);
    }

    /** Stock value, 1400 and 2100 all say the same thing as the supplier ledger. */
    private function assertBooksAgree(float $payable, ?float $inventory = null): void
    {
        $stockValue = (float) DB::table('stock_balances')->where('product_id', $this->productId)->selectRaw('SUM(quantity_on_hand * average_cost) v')->value('v');
        $this->assertEqualsWithDelta($inventory ?? $stockValue, $this->gl('1400'), 0.001, 'inventory GL = stock value');
        $this->assertEqualsWithDelta(-$payable, $this->gl('2100'), 0.001, 'payable GL = what is owed');
        $this->assertEqualsWithDelta($payable, $this->supplierOwes(), 0.001, 'supplier ledger = what is owed');
    }

    private function supplierOwes(): float
    {
        return round((float) Supplier::findOrFail($this->supplierId)->current_balance, 4);
    }

    private function gl(string $code): float
    {
        return round((float) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', $code)->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 4);
    }
}
