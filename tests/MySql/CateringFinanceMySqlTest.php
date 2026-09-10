<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
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

    /**
     * CATERING-OVERPAYMENT-1 (step 6) — a MINUS on the receipt box hands credit
     * back, and it does it by recording a REFUND.
     *
     * The owner asked for one door: "I take 20k and a few days later I put
     * -10,000 through the same screen." What must NOT happen underneath is a
     * negative CateringAdvance row. position() SUMS advances, so a minus row
     * would quietly redefine what "received" means, and it would slip past the
     * refundable cap — the cap that stops the business handing back money which
     * is covering a bill.
     */
    public function test_a_minus_amount_hands_credit_back_as_a_refund(): void
    {
        $event = $this->confirmedEvent();          // 100,000 billed
        $this->invoices->issue($event->refresh());

        $this->advances->record($event->refresh(), [
            'amount' => 150000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Paid the next booking forward',
        ]);

        $revenueBefore = $this->accountNet('4160');

        // The minus goes through the refund service — the same thing the screen
        // does with a negative amount.
        $refund = app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
            'amount' => 50000,
            'refund_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reason' => 'Customer asked for the extra back',
        ]);

        $this->assertNotNull($refund->refund_no);
        $this->assertSame(0, $this->tenant()->table('catering_advances')->where('amount', '<', 0)->count(),
            'money out is a refund, never a negative receipt');

        $this->assertEqualsWithDelta(0.0, $this->accountNet('2300'), 0.01,
            'the credit the business was holding is gone');
        $this->assertEqualsWithDelta(0.0, $this->accountNet('1300'), 0.01,
            'and the settled bill is left alone');
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01,
            'handing money back is not a loss of revenue either');

        $position = app(\App\Services\Catering\CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(0.0, $position['customer_credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $position['balance_due'], 0.01,
            'giving back only the credit must never recreate a balance due');
    }

    /**
     * The cap is the whole protection: only money that is not covering a bill
     * may be handed back. Refunding past it would recreate the balance due and
     * leave the booking looking paid.
     */
    public function test_more_than_the_credit_cannot_be_handed_back(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event->refresh());

        $this->advances->record($event->refresh(), [
            'amount' => 150000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Paid ahead',
        ]);

        $before = $this->tenant()->table('journal_entries')->count();

        try {
            app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
                'amount' => 75000,                 // 25,000 of this is the bill's
                'refund_date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethodId,
                'reason' => 'Too much',
            ]);
            $this->fail('refunding past the credit must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame($before, $this->tenant()->table('journal_entries')->count(),
                'a refused refund must leave the ledger exactly as it was');
        }

        $position = app(\App\Services\Catering\CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(50000.0, $position['customer_credit'], 0.01,
            'the credit is untouched');
        $this->assertEqualsWithDelta(0.0, $position['balance_due'], 0.01,
            'and the bill is still settled');
    }

    /**
     * CATERING-OVERPAYMENT-1 (step 5) — the statement says how much of a receipt
     * was never a payment.
     *
     * Money the business is holding must not be legible only as a bigger number
     * in the Money in column.
     */
    public function test_the_statement_says_how_much_of_a_receipt_is_held_as_credit(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event->refresh());

        $this->advances->record($event->refresh(), [
            'amount' => 150000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Paid the next booking forward',
        ]);

        $ledger = app(\App\Services\Catering\CateringFinancialPositionService::class)->ledger($event->refresh());
        $receipt = collect($ledger)->firstWhere('money_in', 150000.0);

        $this->assertNotNull($receipt, 'the receipt must appear on the statement');
        $this->assertStringContainsString('of which 50,000.00 held as credit', (string) $receipt['note']);
        $this->assertStringContainsString('Paid the next booking forward', (string) $receipt['note'],
            'and the reason it was taken travels with it');
    }

    /**
     * CATERING-OVERPAYMENT-1 (step 4, correction) — the Owner can actually
     * REACH the authority the migration creates.
     *
     * This guard exists because the thing it checks failed on production. The
     * permission is synthetic: no route carries its name. Both `deploy.sh` step
     * [5] and TenantOpsService::syncTenant() build the Owner's grant from the
     * master `route_catalogs` table, so neither of them can ever see it — a fact
     * I had written the OPPOSITE of in the migration's own docblock. Deployed on
     * 2026-09-09 the live Owner answered `can=no`, the checkbox rendered for
     * nobody, and a feature shipped dead.
     *
     * The migration is executed directly rather than through the migrator,
     * because in a freshly-migrated test tenant the roles do not exist yet —
     * which is the very condition that makes this easy to get wrong.
     */
    public function test_the_owner_can_reach_the_overpayment_authority(): void
    {
        $conn = $this->tenant();
        $permission = 'tenant.catering.advances.overpay';

        // Two roles, so the test can tell "granted to the Owner" apart from
        // "granted to everybody" — 000002's whole point was that this is not
        // handed out by default.
        //
        // Reused rather than inserted outright: setUp()'s cleanTenant() list does
        // not include `roles`, so an Owner left behind by an earlier test in the
        // suite is still present, and the table is unique on (name, guard_name).
        // Written as a bare insert this passed alone and errored inside the suite.
        $made = [];
        $roleId = function (string $name) use ($conn, &$made): int {
            $id = $conn->table('roles')->where('name', $name)->where('guard_name', 'tenant')->value('id');
            if ($id) {
                return (int) $id;
            }
            $made[] = $id = $conn->table('roles')->insertGetId([
                'name' => $name, 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) $id;
        };

        $ownerId = $roleId('Owner');
        $cashierId = $roleId('OverpayGuardCashier');

        $permissionId = $conn->table('permissions')->where('name', $permission)
            ->where('guard_name', 'tenant')->value('id');
        $this->assertNotNull($permissionId, 'migration 000002 must have created the permission');

        // Start from the state prod was actually in: the row exists, nobody holds it.
        $conn->table('role_has_permissions')->where('permission_id', $permissionId)->delete();

        $migration = require dirname(__DIR__, 2)
            .'/database/migrations/tenant/2026_09_09_000003_grant_catering_overpay_to_owner.php';
        $migration->up();

        $holders = $conn->table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('role_has_permissions.permission_id', $permissionId)
            ->pluck('roles.name')->all();

        $this->assertContains('Owner', $holders,
            'the Owner must be able to take more than the bill — deploy.sh cannot grant a routeless permission');
        $this->assertNotContains('OverpayGuardCashier', $holders,
            'and nobody else may get it merely by existing');

        // Running twice must not double-insert: deploys re-run migrations.
        $migration->up();
        $this->assertSame(1, $conn->table('role_has_permissions')
            ->where('permission_id', $permissionId)->where('role_id', $ownerId)->count());

        // Only what this test made — a pre-existing Owner belongs to whoever
        // put it there.
        $conn->table('role_has_permissions')->where('permission_id', $permissionId)
            ->whereIn('role_id', [$ownerId, $cashierId])->delete();
        if ($made) {
            $conn->table('roles')->whereIn('id', $made)->delete();
        }
    }

    /**
     * The other half of the same defect: a tenant provisioned tomorrow builds
     * its Owner from a hardcoded list, NOT from what the migrations granted —
     * its roles are created after the migrations have already run. So the name
     * has to appear in that list too, or every future tenant repeats today's
     * bug on its first day.
     */
    public function test_a_new_tenant_is_provisioned_with_the_overpayment_authority(): void
    {
        $provisioner = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/Tenancy/TenantProvisioner.php'
        );

        $this->assertStringContainsString("'tenant.catering.advances.overpay',", $provisioner,
            'a newly provisioned tenant must not have to wait for someone to notice this by hand');
    }

    /**
     * CATERING-REFUND-BEYOND-CREDIT-1 — the deposit on a live booking goes back,
     * and it comes out of 2300 because that is where it is sitting.
     *
     * This is the owner's own case: 5,000 taken against a booking that is still
     * going ahead, then handed back "without cancelling the order". Before this
     * change the only way out was to cancel the booking, which made the
     * quotation stop being the bill.
     *
     * No invoice exists, so the GL has applied nothing: every rupee received is
     * in 2300 regardless of what the quotation says is owed. 1300 must not be
     * touched at all — this booking has no receivable yet.
     */
    public function test_a_deposit_can_go_back_without_cancelling_the_booking(): void
    {
        $event = $this->confirmedEvent();          // 100,000 quoted, no invoice
        $this->advances->record($event->refresh(), [
            'amount' => 5000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $before = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(0.0, $before['refundable'], 0.01,
            'none of it is credit — it is all covering the bill');
        $this->assertEqualsWithDelta(5000.0, $before['refund_ceiling'], 0.01,
            'but all of it was received, so all of it can go back');

        $revenueBefore = $this->accountNet('4160');

        app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
            'amount' => 5000,
            'refund_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reason' => 'Customer asked for the deposit back, booking still on',
            'allow_beyond_credit' => true,
        ]);

        $this->assertEqualsWithDelta(0.0, $this->accountNet('2300'), 0.01,
            'the liability the deposit created is discharged');
        $this->assertEqualsWithDelta(0.0, $this->accountNet('1300'), 0.01,
            'and a booking with no invoice has no receivable to disturb');
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01,
            'handing money back is not a loss of revenue');

        $after = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(100000.0, $after['balance_due'], 0.01,
            'the whole bill is owed again, which is exactly what happened');
        $this->assertEqualsWithDelta(0.0, $after['refund_ceiling'], 0.01,
            'and there is nothing left to hand back');
    }

    /**
     * The posting that this whole change exists for.
     *
     * Once the invoice is issued, `advance_applied` has already moved the
     * deposit out of 2300 and into 1300. A refund that reaches past the credit
     * must therefore split: the part that was never applied comes out of 2300,
     * and the part that WAS covering the bill goes back onto 1300, because the
     * customer owes it again.
     *
     * Posting the whole thing to 2300 — which is what the plain refund does, and
     * what its comment says is "always" right — would drive a liability into a
     * debit balance AND leave the receivable understated. Both wrong, both
     * silent.
     */
    public function test_refunding_past_the_credit_puts_the_receivable_back(): void
    {
        $event = $this->confirmedEvent();          // 100,000 billed
        $this->invoices->issue($event->refresh());

        $this->advances->record($event->refresh(), [
            'amount' => 150000,                    // settles 100,000, holds 50,000
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Paid ahead',
        ]);

        $this->assertEqualsWithDelta(-50000.0, $this->accountNet('2300'), 0.01,
            'the excess is a liability');
        $this->assertEqualsWithDelta(0.0, $this->accountNet('1300'), 0.01,
            'and the bill is settled');

        $revenueBefore = $this->accountNet('4160');

        // 50,000 of this is the customer's own credit; 20,000 is money that
        // settled the bill.
        app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
            'amount' => 70000,
            'refund_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reason' => 'Customer wanted most of it back',
            'allow_beyond_credit' => true,
        ]);

        $this->assertEqualsWithDelta(0.0, $this->accountNet('2300'), 0.01,
            'the credit is gone, and 2300 must never be left in debit');
        $this->assertEqualsWithDelta(20000.0, $this->accountNet('1300'), 0.01,
            'the 20,000 that had settled the bill is owed again');
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01,
            'revenue was earned or it was not — a refund does not decide that');

        $position = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(20000.0, $position['balance_due'], 0.01,
            'and the booking agrees with the ledger to the rupee');
        $this->assertEqualsWithDelta(0.0, $position['customer_credit'], 0.01);
    }

    /**
     * The ceiling that never moves. No permission reaches past it, because there
     * is nothing behind it.
     */
    public function test_more_than_was_ever_received_cannot_be_handed_back(): void
    {
        $event = $this->confirmedEvent();
        $this->advances->record($event->refresh(), [
            'amount' => 5000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $before = $this->tenant()->table('journal_entries')->count();

        try {
            app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
                'amount' => 6000,
                'refund_date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethodId,
                'reason' => 'Trying to give back more than arrived',
                'allow_beyond_credit' => true,          // even WITH the authority
            ]);
            $this->fail('refunding more than was received must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('never arrived', $e->getMessage());
        }

        $this->assertSame($before, $this->tenant()->table('journal_entries')->count(),
            'a refused refund leaves the ledger exactly as it was');
        $this->assertSame(0, $this->tenant()->table('catering_refunds')->count());
    }

    /**
     * Without the authority, the old refusal still stands — and it must refuse
     * in the SERVICE, not merely on the screen, because a form post can be
     * written by hand.
     */
    public function test_going_past_the_credit_needs_the_authority(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event->refresh());
        $this->advances->record($event->refresh(), [
            'amount' => 150000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Paid ahead',
        ]);

        $before = $this->tenant()->table('journal_entries')->count();

        try {
            app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
                'amount' => 70000,
                'refund_date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethodId,
                'reason' => 'No authority for this',
                // allow_beyond_credit deliberately absent
            ]);
            $this->fail('going past the credit without the authority must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('authority', $e->getMessage());
        }

        $this->assertSame($before, $this->tenant()->table('journal_entries')->count());

        $position = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(50000.0, $position['customer_credit'], 0.01,
            'the credit is untouched');
        $this->assertEqualsWithDelta(0.0, $position['balance_due'], 0.01,
            'and the bill is still settled');
    }

    /**
     * Money survives a change of status, untouched — and the ledger does not
     * move a single rupee when a booking is cancelled.
     *
     * Receipts, refunds, invoices and journal entries are not part of a History
     * snapshot and no status transition writes to them. What DOES change is what
     * the system says the money is FOR: with no invoice, cancelling makes the
     * quotation stop being the bill, so a deposit that was covering a bill
     * becomes credit owed back to the customer. The rows are identical; their
     * meaning is not.
     */
    public function test_cancelling_a_booking_leaves_every_payment_exactly_as_it_was(): void
    {
        $event = $this->confirmedEvent();          // 100,000 quoted, no invoice
        $this->advances->record($event->refresh(), [
            'amount' => 30000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $rowsBefore = $this->tenant()->table('catering_advances')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $entriesBefore = $this->tenant()->table('journal_entries')->count();
        $linesBefore = $this->tenant()->table('journal_lines')->count();
        $cashBefore = $this->accountNet('2300');

        $before = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(70000.0, $before['balance_due'], 0.01);
        $this->assertEqualsWithDelta(0.0, $before['customer_credit'], 0.01,
            'while the booking stands, the deposit is covering the bill');

        app(CateringEstimateService::class)->cancelEvent($event->refresh(), 'Customer called it off');

        // The rows themselves: byte for byte what they were.
        $this->assertEquals($rowsBefore, $this->tenant()->table('catering_advances')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'a cancellation must not edit a receipt that already happened');
        $this->assertSame($entriesBefore, $this->tenant()->table('journal_entries')->count(),
            'and it must not post, reverse or delete a journal entry');
        $this->assertSame($linesBefore, $this->tenant()->table('journal_lines')->count());
        $this->assertEqualsWithDelta($cashBefore, $this->accountNet('2300'), 0.01,
            'the liability still stands — the business is still holding the money');
        $this->assertSame(0, $this->tenant()->table('catering_refunds')->count(),
            'cancelling is NOT refunding; the money does not walk out on its own');

        // What changed is the MEANING, and only because there is no invoice: the
        // quotation stopped being the bill, so the deposit is now the customer's.
        $after = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(30000.0, $after['gross_received'], 0.01,
            'the same money');
        $this->assertEqualsWithDelta(0.0, $after['balance_due'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $after['customer_credit'], 0.01,
            'now owed back to the customer, and refundable without any special authority');
        $this->assertEqualsWithDelta(30000.0, $after['refundable'], 0.01);
    }

    /**
     * …and an INVOICED booking cannot be cancelled at all, which is a stronger
     * protection than reinterpreting it would have been.
     *
     * Issuing the final invoice completes the event, and cancelEvent() refuses
     * `completed` and `closed` outright. So the cheapest imaginable attack on
     * the till — cancel an invoiced booking, watch billed() fall to zero, and
     * refund the whole settled amount as "credit" — cannot even be attempted.
     * The door is shut one step earlier than the arithmetic.
     */
    public function test_an_invoiced_booking_cannot_be_cancelled_at_all(): void
    {
        $event = $this->confirmedEvent();
        $this->invoices->issue($event->refresh());
        $this->advances->record($event->refresh(), [
            'amount' => 100000,                    // settles it exactly
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $entriesBefore = $this->tenant()->table('journal_entries')->count();
        $arBefore = $this->accountNet('1300');
        $revenueBefore = $this->accountNet('4160');
        $advancesBefore = $this->tenant()->table('catering_advances')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        try {
            app(CateringEstimateService::class)->cancelEvent($event->refresh(), 'Trying to unbill a settled booking');
            $this->fail('an invoiced booking must not be cancellable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be cancelled', $e->getMessage());
        }

        $after = app(CateringFinancialPositionService::class)->position($event->refresh());
        $this->assertEqualsWithDelta(100000.0, $after['billed'], 0.01,
            'the invoice is still the bill');
        $this->assertEqualsWithDelta(0.0, $after['customer_credit'], 0.01,
            'settled money stays settled and is NOT suddenly refundable');
        $this->assertEqualsWithDelta(0.0, $after['refund_ceiling'] - 100000.0, 0.01,
            'it can still be refunded deliberately, with the authority — but as a refund, not as credit');

        $this->assertEquals($advancesBefore, $this->tenant()->table('catering_advances')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($entriesBefore, $this->tenant()->table('journal_entries')->count());
        $this->assertEqualsWithDelta($arBefore, $this->accountNet('1300'), 0.01);
        $this->assertEqualsWithDelta($revenueBefore, $this->accountNet('4160'), 0.01,
            'and revenue already earned is not un-earned by a refused cancellation');
    }

    /**
     * CATERING-CUSTOMER-CREDIT-WORKLIST-1 — the cancelled booking that is
     * quietly holding somebody's money shows up.
     *
     * This is the whole reason the screen exists. close() already refuses to
     * finish a booking that still owes the customer, but a CANCELLED booking
     * never reaches close(), so that liability had nowhere to appear. Until
     * this list, the only way to find it was to already know it was there.
     */
    public function test_a_cancelled_booking_holding_money_appears_on_the_worklist(): void
    {
        $event = $this->confirmedEvent();          // 100,000 quoted
        $this->advances->record($event->refresh(), [
            'amount' => 30000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $position = app(CateringFinancialPositionService::class);

        // While the booking stands, the deposit is covering the bill — nothing
        // is owed back, and the list must not cry wolf.
        $this->assertCount(0, $position->owedToCustomers(),
            'a deposit on a live booking is not money owed back');

        app(CateringEstimateService::class)->cancelEvent($event->refresh(), 'Customer called it off');

        $rows = $position->owedToCustomers();
        $this->assertCount(1, $rows, 'the cancelled booking is holding 30,000 that belongs to the customer');
        $this->assertSame($event->id, $rows->first()['event']->id);
        $this->assertEqualsWithDelta(30000.0, $rows->first()['credit'], 0.01);
        $this->assertIsInt($rows->first()['days'], 'and how long it has been waiting');
    }

    /** Money handed back leaves the list — it is a worklist, not a log. */
    public function test_refunding_clears_the_booking_off_the_worklist(): void
    {
        $event = $this->confirmedEvent();
        $this->advances->record($event->refresh(), [
            'amount' => 30000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);
        app(CateringEstimateService::class)->cancelEvent($event->refresh(), 'Cancelled');

        $position = app(CateringFinancialPositionService::class);
        $this->assertCount(1, $position->owedToCustomers());

        app(\App\Services\Catering\CateringRefundService::class)->record($event->refresh(), [
            'amount' => 30000,
            'refund_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reason' => 'Deposit returned',
        ]);

        $this->assertCount(0, $position->owedToCustomers(),
            'once the money is back with the customer there is nothing left to chase');
    }

    /**
     * A booking that never took a payment must not cost a single query beyond
     * the first. The list is almost always empty, and an empty screen should not
     * be the most expensive one in the system.
     */
    public function test_the_worklist_does_not_walk_every_booking(): void
    {
        $this->confirmedEvent();
        $this->confirmedEvent();
        $this->confirmedEvent();

        \Illuminate\Support\Facades\DB::connection('tenant')->enableQueryLog();
        $rows = app(CateringFinancialPositionService::class)->owedToCustomers();
        $queries = count(\Illuminate\Support\Facades\DB::connection('tenant')->getQueryLog());
        \Illuminate\Support\Facades\DB::connection('tenant')->disableQueryLog();

        $this->assertCount(0, $rows);
        $this->assertSame(1, $queries,
            'with no money received anywhere, one query must answer the whole question');
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
