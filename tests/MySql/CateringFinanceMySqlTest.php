<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-GO-LIVE-READINESS-1 (§5/§6): the full accounting contract on real
 * GL rows — advance liability, invoice AR/revenue, advance clearing,
 * settlement, zero-difference reconciliation, replay idempotency, conflict
 * refusal, and cash/bank movement without duplicates.
 */
class CateringFinanceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

    private CateringFinalInvoiceService $invoices;

    private int $branchId;

    private int $cashAccountId;      // cash_bank_accounts.id

    private int $paymentMethodId;    // mapped to the cash account

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases', 'catering_refunds', 'catering_final_invoices',
            'catering_advances', 'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'sale_payments', 'sales_ledgers', 'sales_order_lines', 'sales_orders',
            'shifts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'customers', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->advances = app(CateringAdvanceService::class);
        $this->invoices = app(CateringFinalInvoiceService::class);

        $this->branchId = $this->makeBranch();

        // Real cash/bank account mapped to the 1110 Main Cash Drawer CoA account.
        $this->cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashAccountId]);
    }

    /** Confirmed 100,000 event ready for billing. */
    private function confirmedEvent(): CateringEvent
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 400]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Finance Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 200,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $productId, 'item_name' => 'Catering Package', 'quantity' => 100, 'rate' => 1000],
        ]); // grand 100,000
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return $event->refresh();
    }

    /**
     * A receipt row written straight to the table.
     *
     * CATERING-OVERPAYMENT-1 step 2 is about the POSTING, and the model still
     * refuses an amount beyond the balance — that door opens in step 3. Going
     * through CateringAdvance::create() here would be testing the guard, which
     * is a different question with its own tests.
     */
    private function receiptRow(CateringEvent $event, float $amount, float $credit, string $postingType): \App\Models\Tenant\CateringAdvance
    {
        $id = $this->tenant()->table('catering_advances')->insertGetId([
            'advance_uuid' => (string) \Illuminate\Support\Str::ulid(),
            'catering_event_id' => $event->id,
            'amount' => $amount,
            'credit_portion' => $credit,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'cash_bank_account_id' => $this->cashAccountId,
            'posting_type' => $postingType,
            'overpayment_reason' => 'Customer paid the next booking forward',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $advance = \App\Models\Tenant\CateringAdvance::findOrFail($id);
        $advance->setRelation('event', $event);

        return $advance;
    }

    /** Net GL movement for an account code: debits − credits. */
    private function accountNet(string $code): float
    {
        $accountId = Account::where('code', $code)->value('id');
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('account_id', $accountId)->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    public function test_full_accounting_lifecycle_reconciles_to_zero_difference(): void
    {
        $event = $this->confirmedEvent();

        // ── A. Advance receipt 30,000 (pre-invoice) ─────────────────────────
        $advance = $this->advances->record($event, [
            'amount' => 30000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId, 'reference' => 'ADV-1',
        ]);

        $this->assertSame('advance', $advance->posting_type);
        $this->assertNotNull($advance->journal_entry_id);
        $this->assertSame(-30000.0, $this->accountNet('2300'), 'Cr 2300 liability exists from the advance');
        $this->assertSame(30000.0, $this->accountNet('1110'), 'Dr cash drawer via payment-method mapping');
        $this->assertEqualsWithDelta(30000.0, (float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 0.001, 'cash/bank balance moved');

        // ── B + C. Final invoice 100,000: revenue/AR + advance clearing ─────
        $invoice = $this->invoices->issue($event->refresh());

        $this->assertNotNull($invoice->journal_entry_id, 'invoice GL linked');
        $this->assertNotNull($invoice->advance_application_journal_entry_id, 'advance application GL linked');
        $this->assertSame(100000.0, -$this->accountNet('4160'), '100,000 catering revenue recognized');
        $this->assertSame(0.0, $this->accountNet('2300'), '30,000 advance liability fully cleared against AR');
        $this->assertSame(70000.0, $this->accountNet('1300'), '70,000 remains due on AR');
        $this->assertSame('70000.00', (string) $invoice->balance_due);

        // ── D. Settlement 70,000 (post-invoice) ─────────────────────────────
        $settlement = $this->advances->record($event->refresh(), [
            'amount' => 70000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId, 'reference' => 'FINAL',
        ]);

        $this->assertSame('settlement', $settlement->posting_type, 'post-invoice receipt settles AR, not the advance liability');
        $this->assertSame(0.0, $this->accountNet('1300'), 'customer AR balance is ZERO');
        $this->assertSame(0.0, $this->accountNet('2300'), 'advance liability stays zero');
        $this->assertSame(100000.0, $this->accountNet('1110'), 'cash holds the full 100,000');
        $this->assertEqualsWithDelta(100000.0, (float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 0.001);

        // Event closes at zero balance.
        $this->invoices->close($event->refresh());
        $this->assertSame(CateringEvent::STATUS_CLOSED, $event->refresh()->status);

        // ── Reconciliation: every entry balanced; books net to zero ─────────
        foreach ($this->tenant()->table('journal_entries')->get() as $entry) {
            $this->assertEqualsWithDelta((float) $entry->total_debit, (float) $entry->total_credit, 0.001,
                "journal {$entry->entry_no} must balance");
        }
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();
        $this->assertEqualsWithDelta((float) $row->d, (float) $row->c, 0.001,
            'the whole catering ledger reconciles to zero difference');

        // ── Replay every posting: no duplicate journals / cash-bank rows ────
        $journalCount = (int) $this->tenant()->table('journal_entries')->count();
        $cashTxnCount = (int) $this->tenant()->table('cash_bank_account_transactions')->count();

        $posting = app(JournalPostingService::class);
        $advance->setRelation('event', $event);
        $settlement->setRelation('event', $event);
        $posting->postCateringAdvance($advance->refresh()->setRelation('event', $event));
        $posting->postCateringSettlement($settlement->refresh()->setRelation('event', $event));
        $posting->postCateringFinalInvoice($invoice->refresh()->load('event'));
        $posting->applyCateringAdvance($invoice);

        $this->assertSame($journalCount, (int) $this->tenant()->table('journal_entries')->count(),
            'exact replays return existing postings — never duplicates');
        $this->assertSame($cashTxnCount, (int) $this->tenant()->table('cash_bank_account_transactions')->count());

        // No POS involvement anywhere.
        foreach (['sales_orders', 'sale_payments', 'shifts', 'stock_ledgers'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count());
        }
    }

    /**
     * CATERING-OVERPAYMENT-1 (steps 1-2) — a receipt that pays a bill AND leaves
     * credit is posted as the two different things it is.
     *
     * Why this method has to exist at all: a receipt taken after an invoice
     * exists posts entirely to 1300 Accounts Receivable. That is correct while
     * the money is paying a bill and wrong the moment it exceeds one — 20,000
     * against a 10,000 invoice would leave Accounts Receivable at MINUS 10,000,
     * and a negative receivable states that the customer owes less than nothing.
     *
     * The excess is a LIABILITY. The single most important assertion here is the
     * one about 4160: money the business has not billed for has not been earned,
     * whatever the bank balance says.
     *
     * Nothing calls this yet — the model still refuses overpayment — so this
     * posts through the service directly, which is the real path the caller will
     * take when the door opens.
     */
    public function test_a_receipt_beyond_the_bill_splits_between_receivable_and_liability(): void
    {
        $event = $this->confirmedEvent();                 // 100,000 billed
        $invoice = $this->invoices->issue($event);
        $this->assertEqualsWithDelta(100000.0, (float) $invoice->balance_due, 0.01);

        $revenueBefore = $this->accountNet('4160');

        // The operator takes 150,000 against a 100,000 bill.
        $advance = $this->receiptRow($event, 150000, 50000, \App\Models\Tenant\CateringAdvance::POSTING_SETTLEMENT);

        $entry = app(JournalPostingService::class)->postCateringSplitReceipt($advance, 100000, 50000);

        // The whole receipt reached the drawer, once.
        $this->assertEqualsWithDelta(150000.0, (float) $entry->total_debit, 0.01);

        // What was owed is now settled — and NOT a rupee more.
        $this->assertEqualsWithDelta(0.0, $this->accountNet('1300'), 0.01,
            'Accounts Receivable must land exactly on zero, never below it');

        // The rest is a debt the business now carries.
        $this->assertEqualsWithDelta(-50000.0, $this->accountNet('2300'), 0.01,
            'the excess sits in Customer Advances as money owed back');

        // THE assertion. Taking more money is not earning more money.
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01,
            'revenue must not move when a customer overpays');
    }

    /**
     * CATERING-OVERPAYMENT-1 (step 3) — the door is shut by default, and opening
     * it takes two deliberate acts.
     *
     * The refusal is not a technical limit, it is a position: a receipt is the
     * wrong instrument for money the business has not billed for. So it stays
     * the default, and stepping past it requires BOTH a caller that meant to and
     * a reason recorded against the money. A flag on its own would let a stray
     * call through; a reason on its own could be filled in by a form post that
     * never meant it.
     */
    public function test_overpayment_is_refused_unless_it_is_deliberate_and_explained(): void
    {
        $event = $this->confirmedEvent();          // 100,000 billed
        $this->invoices->issue($event->refresh());

        // 1 — the plain path still refuses, exactly as before.
        try {
            $this->advances->record($event->refresh(), [
                'amount' => 150000, 'received_date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethodId,
            ]);
            $this->fail('a receipt beyond the balance must be refused by default');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeds the outstanding balance', $e->getMessage());
        }

        // 2 — deciding without saying why is not deciding.
        try {
            $this->advances->record($event->refresh(), [
                'amount' => 150000, 'received_date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethodId,
                'allow_overpayment' => true,
            ]);
            $this->fail('overpayment without a reason must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a reason recorded against it', $e->getMessage());
        }

        $this->assertSame(0, $this->tenant()->table('catering_advances')->count(),
            'neither refusal may leave a receipt behind');
    }

    /**
     * CATERING-OVERPAYMENT-1 (step 3) — a deliberate overpayment, end to end.
     *
     * This is the whole feature in one test: the money arrives once, the bill is
     * settled exactly, the excess becomes a debt the business carries, and
     * REVENUE DOES NOT MOVE. Taking more money is not earning more money.
     */
    public function test_a_deliberate_overpayment_settles_the_bill_and_holds_the_rest(): void
    {
        $event = $this->confirmedEvent();          // 100,000 billed
        $this->invoices->issue($event->refresh());
        $revenueBefore = $this->accountNet('4160');

        $advance = $this->advances->record($event->refresh(), [
            'amount' => 150000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Customer paid the next booking forward',
        ]);

        $this->assertEqualsWithDelta(50000.0, (float) $advance->credit_portion, 0.01,
            'the receipt records how much of itself was never a payment');

        $this->assertEqualsWithDelta(0.0, $this->accountNet('1300'), 0.01,
            'Accounts Receivable lands on zero, never below it');
        $this->assertEqualsWithDelta(-50000.0, $this->accountNet('2300'), 0.01,
            'the excess is a debt the business now carries');
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01,
            'revenue must not move when a customer overpays');

        // The one authority every screen reads agrees.
        $position = app(\App\Services\Catering\CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(0.0, $position['balance_due'], 0.01);
        $this->assertEqualsWithDelta(50000.0, $position['customer_credit'], 0.01);
        $this->assertEqualsWithDelta(50000.0, $position['refundable'], 0.01,
            'and only the credit may be handed back');
    }

    /**
     * Paying the bill exactly still behaves exactly as it always did — the door
     * changes nothing for the ordinary case.
     */
    public function test_paying_the_bill_exactly_is_untouched_by_the_new_door(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event->refresh());

        $advance = $this->advances->record($event->refresh(), [
            'amount' => 100000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $advance->credit_portion, 0.01,
            'nothing was held back, so nothing is recorded as held');
        $this->assertSame('settlement', $advance->posting_type);
        $this->assertEqualsWithDelta(0.0, $this->accountNet('1300'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->accountNet('2300'), 0.01,
            'an exact payment leaves no customer advance behind');
    }

    /** A receipt is posted whole or not at all. */
    public function test_a_split_that_does_not_add_up_is_refused(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event);

        $advance = $this->receiptRow($event, 150000, 50000, \App\Models\Tenant\CateringAdvance::POSTING_SETTLEMENT);

        $before = $this->tenant()->table('journal_entries')->count();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be posted whole or not at all');

        try {
            // 100,000 + 40,000 is not 150,000.
            app(JournalPostingService::class)->postCateringSplitReceipt($advance, 100000, 40000);
        } finally {
            $this->assertSame($before, $this->tenant()->table('journal_entries')->count(),
                'a refused split must leave the ledger exactly as it was');
        }
    }

    /**
     * An invoice already settled in full, and the customer pays anyway: every
     * rupee is credit and there is no receivable line to write.
     */
    public function test_a_receipt_against_a_settled_invoice_is_all_credit(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event);
        $this->advances->record($event, [
            'amount' => 100000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $receivableBefore = $this->accountNet('1300');
        $revenueBefore = $this->accountNet('4160');

        $advance = $this->receiptRow($event, 25000, 25000, \App\Models\Tenant\CateringAdvance::POSTING_ADVANCE);

        $entry = app(JournalPostingService::class)->postCateringSplitReceipt($advance, 0, 25000);

        $this->assertSame(2, $entry->lines()->count(),
            'with nothing owed there is no Accounts Receivable line to write');
        $this->assertEqualsWithDelta($receivableBefore, $this->accountNet('1300'), 0.01,
            'a settled receivable must not move');
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01);
    }

    public function test_conflicting_replay_refuses_and_unmapped_method_uses_undeposited_funds(): void
    {
        $event = $this->confirmedEvent();

        // Unmapped receipt: GL Dr 1500 Undeposited Funds, NO cash/bank movement.
        $advance = $this->advances->record($event, [
            'amount' => 10000, 'received_date' => now()->toDateString(),
        ]);
        $this->assertSame(10000.0, $this->accountNet('1500'), 'unmapped receipt debits Undeposited Funds');
        $this->assertSame(0, (int) $this->tenant()->table('cash_bank_account_transactions')->count(),
            'no cash/bank account, no cash/bank movement');

        // Same identity + conflicting payload → REFUSED, nothing merged.
        $this->tenant()->table('catering_advances')->where('id', $advance->id)->update(['amount' => 99999]);
        try {
            app(JournalPostingService::class)->postCateringAdvance(
                \App\Models\Tenant\CateringAdvance::find($advance->id)->setRelation('event', $event)
            );
            $this->fail('a replay with a conflicting financial payload must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('conflicting financial payloads', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->tenant()->table('journal_entries')->count(), 'nothing extra was posted');
    }
}
