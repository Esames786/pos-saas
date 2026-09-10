<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringRefundService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-NEGATIVE-PAYMENT-1 — the owner's required financial matrix, whole.
 *
 * The numbers here are the owner's own, deliberately: invoice 10,000, receipt
 * 15,000, credit 5,000, then refunds of 2,000 / 3,000 / 7,000. Keeping the
 * spec's arithmetic rather than translating it means a reader can check the
 * test against the requirement without doing any sums.
 *
 * The one that matters is the beyond-credit case. Posting the whole refund to
 * Customer Advances would drive a LIABILITY into a debit balance and leave the
 * receivable understated — economically invalid, and silent. It must split.
 */
class CateringNegativePaymentMatrixMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

    private CateringFinalInvoiceService $invoices;

    private CateringRefundService $refunds;

    private CateringFinancialPositionService $position;

    private int $branchId;

    private int $cashAccountId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_event_revisions',
            'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'products', 'categories', 'customers', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->advances = app(CateringAdvanceService::class);
        $this->invoices = app(CateringFinalInvoiceService::class);
        $this->refunds = app(CateringRefundService::class);
        $this->position = app(CateringFinancialPositionService::class);

        $this->branchId = $this->makeBranch();
        $this->cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Matrix Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashAccountId]);
    }

    // ═══ §2 — the overpayment receipt ══════════════════════════════════════

    /** Invoice 10,000 + receipt 15,000 → 10,000 settles AR, 5,000 is a liability. */
    public function test_an_overpayment_receipt_splits_into_receivable_and_customer_credit(): void
    {
        $event = $this->overpaidBooking();

        $this->assertEqualsWithDelta(0.0, $this->net('1300'), 0.01,
            'the invoice is settled — 10,000 billed, 10,000 applied');
        $this->assertEqualsWithDelta(-5000.0, $this->net('2300'), 0.01,
            'and the excess is a LIABILITY, credit balance');
        $this->assertEqualsWithDelta(-10000.0, $this->net('4160'), 0.01,
            'revenue is the invoice only — never the overpayment');

        $p = $this->position($event);
        $this->assertEqualsWithDelta(5000.0, $p['customer_credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $p['balance_due'], 0.01);
        $this->assertJournalsBalanced();
    }

    // ═══ §3 — refund within credit ═════════════════════════════════════════

    /**
     * Refund 2,000 then the remaining 3,000. AR must never move: this is the
     * customer's own money going back, not a bill being un-paid.
     */
    public function test_refunds_within_credit_draw_only_on_customer_advances(): void
    {
        $event = $this->overpaidBooking();

        $this->refund($event, 2000, 'Partial return');

        $this->assertEqualsWithDelta(-3000.0, $this->net('2300'), 0.01, 'advance 5,000 -> 3,000');
        $this->assertEqualsWithDelta(0.0, $this->net('1300'), 0.01, 'AR remains 0');
        $this->assertEqualsWithDelta(13000.0, $this->cashMoved(), 0.01,
            '15,000 in less 2,000 out');

        $p = $this->position($event);
        $this->assertEqualsWithDelta(3000.0, $p['customer_credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $p['balance_due'], 0.01, 'the invoice stays settled');

        // …and the rest of it.
        $this->refund($event, 3000, 'Returning the remainder');

        $this->assertEqualsWithDelta(0.0, $this->net('2300'), 0.01, 'advance -> 0');
        $this->assertEqualsWithDelta(0.0, $this->net('1300'), 0.01, 'AR still 0');
        $this->assertEqualsWithDelta(10000.0, $this->cashMoved(), 0.01,
            '15,000 in less 5,000 out — exactly the invoice is left in the drawer');

        $p = $this->position($event->refresh());
        $this->assertEqualsWithDelta(0.0, $p['customer_credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $p['balance_due'], 0.01,
            'returning a customer their own money must never create a bill');
        $this->assertJournalsBalanced();
    }

    // ═══ §4 — refund beyond credit ═════════════════════════════════════════

    /**
     * THE ONE THIS FEATURE EXISTS FOR.
     *
     * Credit 5,000, refund 7,000. The 2,000 beyond the credit was money that
     * had SETTLED the invoice, so the receivable comes back — the customer owes
     * it again. Posting all 7,000 to Customer Advances would leave a liability
     * account 2,000 in DEBIT, which is not a thing that can be true.
     */
    public function test_a_refund_beyond_credit_reopens_the_receivable(): void
    {
        $event = $this->overpaidBooking();

        $this->refund($event, 7000, 'Customer wanted more than the credit back', beyond: true);

        $this->assertEqualsWithDelta(0.0, $this->net('2300'), 0.01,
            'the credit is spent — and 2300 must never be left in debit');
        $this->assertEqualsWithDelta(2000.0, $this->net('1300'), 0.01,
            'the 2,000 that had settled the invoice is a receivable again');
        $this->assertEqualsWithDelta(8000.0, $this->cashMoved(), 0.01, '15,000 in less 7,000 out');
        $this->assertEqualsWithDelta(-10000.0, $this->net('4160'), 0.01,
            'revenue is untouched — a refund does not un-earn a sale');

        $p = $this->position($event);
        $this->assertEqualsWithDelta(0.0, $p['customer_credit'], 0.01, 'customer credit = 0');
        $this->assertEqualsWithDelta(2000.0, $p['balance_due'], 0.01, 'invoice 2,000 due again');
        $this->assertJournalsBalanced();

        // …and taking the 2,000 again settles it, with no trace left behind.
        $this->advances->record($event->refresh(), [
            'amount' => 2000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $this->assertEqualsWithDelta(0.0, $this->net('1300'), 0.01, 'AR -> 0');
        $this->assertEqualsWithDelta(0.0, $this->net('2300'), 0.01,
            'and settling a reopened bill must not invent new credit');

        $p = $this->position($event->refresh());
        $this->assertEqualsWithDelta(0.0, $p['balance_due'], 0.01);
        $this->assertEqualsWithDelta(0.0, $p['customer_credit'], 0.01);
        $this->assertJournalsBalanced();
    }

    /** The invalid posting, stated as a test so it can never be "simplified" back. */
    public function test_a_beyond_credit_refund_never_posts_everything_to_customer_advances(): void
    {
        $event = $this->overpaidBooking();
        $this->refund($event, 7000, 'Beyond the credit', beyond: true);

        $advanceLines = $this->tenant()->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', '2300')->sum('journal_lines.debit');

        $this->assertEqualsWithDelta(5000.0, (float) $advanceLines, 0.01,
            'exactly the credit was drawn from 2300 — never the whole refund');
    }

    // ═══ §11 — the rest of the required proofs ═════════════════════════════

    /** A refund posts once. Replaying it must not double the money out. */
    public function test_a_refund_cannot_post_twice(): void
    {
        $event = $this->overpaidBooking();
        $refund = $this->refund($event, 7000, 'Beyond the credit', beyond: true);

        $entries = $this->tenant()->table('journal_entries')->count();
        $movements = $this->tenant()->table('cash_bank_account_transactions')->count();

        // The posting authority, called again with the same document.
        app(\App\Services\Finance\JournalPostingService::class)
            ->postCateringSplitRefund($refund->refresh(), 5000, 2000);

        $this->assertSame($entries, $this->tenant()->table('journal_entries')->count(),
            'a replayed refund must return the entry it already made, not make another');
        $this->assertSame($movements, $this->tenant()->table('cash_bank_account_transactions')->count(),
            'and the drawer must move exactly once');
        $this->assertEqualsWithDelta(8000.0, $this->cashMoved(), 0.01, '15,000 in less 7,000 out');
    }

    /** Money on one booking is not money on another. */
    public function test_a_refund_touches_only_its_own_booking(): void
    {
        $a = $this->overpaidBooking();
        $b = $this->overpaidBooking();

        $this->refund($a, 7000, 'Beyond the credit', beyond: true);

        $pb = $this->position($b);
        $this->assertEqualsWithDelta(5000.0, $pb['customer_credit'], 0.01,
            "the other booking's credit is untouched");
        $this->assertEqualsWithDelta(0.0, $pb['balance_due'], 0.01,
            'and its invoice is still settled');
        $this->assertSame(0, $this->tenant()->table('catering_refunds')
            ->where('catering_event_id', $b->id)->count());
    }

    /** A negative amount may never become a receipt row. */
    public function test_a_negative_normal_receipt_is_refused(): void
    {
        $event = $this->overpaidBooking();

        try {
            $this->advances->record($event->refresh(), [
                'amount' => -1000,
                'received_date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethodId,
            ]);
            $this->fail('a receipt may never be negative');
        } catch (RuntimeException $e) {
            // The model guard is the authority; the message is its business.
        }

        $this->assertSame(0, $this->tenant()->table('catering_advances')->where('amount', '<', 0)->count(),
            'money out is a refund document, never a minus receipt');
    }

    /** Without the authority, a beyond-credit refund is refused server-side. */
    public function test_beyond_credit_without_authority_is_refused_and_posts_nothing(): void
    {
        $event = $this->overpaidBooking();
        $entries = $this->tenant()->table('journal_entries')->count();

        try {
            $this->refund($event, 7000, 'No authority', beyond: false);
            $this->fail('going past the credit without the authority must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('authority', $e->getMessage());
        }

        $this->assertSame($entries, $this->tenant()->table('journal_entries')->count());
        $this->assertEqualsWithDelta(-5000.0, $this->net('2300'), 0.01, 'the credit is untouched');
        $this->assertEqualsWithDelta(0.0, $this->net('1300'), 0.01);
    }

    /** §10 — a refund survives the booking moving backwards. */
    public function test_a_refund_survives_a_status_rollback(): void
    {
        $event = $this->overpaidBooking();
        $this->refund($event, 7000, 'Beyond the credit', beyond: true);

        $refundRows = $this->tenant()->table('catering_refunds')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $entries = $this->tenant()->table('journal_entries')->count();
        $ar = $this->net('1300');

        // An invoiced booking cannot be moved back, which is itself the
        // protection — the refusal must leave the money exactly as it was.
        try {
            app(\App\Services\Catering\CateringEventStatusService::class)
                ->moveBack($event->refresh(), 'Trying to move a billed booking back');
            $this->fail('an invoiced booking must not move back');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertEquals($refundRows, $this->tenant()->table('catering_refunds')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'the refund is history and stays exactly as recorded');
        $this->assertSame($entries, $this->tenant()->table('journal_entries')->count());
        $this->assertEqualsWithDelta($ar, $this->net('1300'), 0.01);
    }

    /** §9 — the worklist follows the money in both directions. */
    public function test_the_money_owed_worklist_follows_the_refunds(): void
    {
        $event = $this->overpaidBooking();

        $rows = $this->position->owedToCustomers();
        $this->assertCount(1, $rows, '5,000 of the customer\'s money is being held');
        $this->assertEqualsWithDelta(5000.0, $rows->first()['credit'], 0.01);

        // Within credit: the amount owed back falls, no receivable is invented.
        $this->refund($event, 2000, 'Partial');
        $rows = $this->position->owedToCustomers();
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(3000.0, $rows->first()['credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->position($event)['balance_due'], 0.01,
            'a within-credit refund must NOT show a false invoice receivable');

        // Beyond credit: nothing is owed TO the customer any more — they owe US.
        $this->refund($event, 5000, 'The rest and then some', beyond: true);
        $this->assertCount(0, $this->position->owedToCustomers(),
            'a booking that owes the customer nothing must leave the list');
        $this->assertEqualsWithDelta(2000.0, $this->position($event)['balance_due'], 0.01,
            'and the reopened receivable is money owed the other way');

        // Settled again: still nothing owed either way, and no duplicate row.
        $this->advances->record($event->refresh(), [
            'amount' => 2000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);
        $this->assertCount(0, $this->position->owedToCustomers());
        $this->assertEqualsWithDelta(0.0, $this->position($event)['balance_due'], 0.01);
    }

    // ═══ helpers ═══════════════════════════════════════════════════════════

    /** Invoice 10,000, receipt 15,000, credit 5,000 — the owner's own figures. */
    private function overpaidBooking(): CateringEvent
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 40]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 40, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Matrix Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 100,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $productId, 'item_name' => 'Package', 'quantity' => 100, 'rate' => 100],
        ]); // 10,000
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());
        $this->invoices->issue($event->refresh());

        $this->advances->record($event->refresh(), [
            'amount' => 15000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'allow_overpayment' => true,
            'overpayment_reason' => 'Customer paid more than the bill',
        ]);

        return $event->refresh();
    }

    private function refund(CateringEvent $event, float $amount, string $reason, bool $beyond = false)
    {
        return $this->refunds->record($event->refresh(), [
            'amount' => $amount,
            'refund_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reason' => $reason,
            'allow_beyond_credit' => $beyond,
        ]);
    }

    private function position(CateringEvent $event): array
    {
        return $this->position->position($event->refresh());
    }

    /** Debits less credits on a CoA code. Negative = credit balance. */
    private function net(string $code): float
    {
        $id = Account::where('code', $code)->value('id');
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')
            ->where('account_id', $id)->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /**
     * Net movement through the cash/bank account. Negative = money left.
     *
     * The table stores every amount POSITIVE and carries the sign in a
     * `direction` column ('in' / 'out'). Summing `amount` alone reads a
     * refund as more money arriving — the first version of this helper did
     * exactly that and reported 17,000 where 2,000 had left.
     */
    private function cashMoved(): float
    {
        $row = $this->tenant()->table('cash_bank_account_transactions')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) total")
            ->where('cash_bank_account_id', $this->cashAccountId)->first();

        return round((float) $row->total, 2);
    }

    /** Every entry balances. Nothing else in this file means anything if not. */
    private function assertJournalsBalanced(): void
    {
        $unbalanced = $this->tenant()->table('journal_lines')
            ->selectRaw('journal_entry_id, ROUND(SUM(debit) - SUM(credit), 2) diff')
            ->groupBy('journal_entry_id')
            ->havingRaw('ROUND(SUM(debit) - SUM(credit), 2) <> 0')
            ->get();

        $this->assertCount(0, $unbalanced,
            'every journal entry must balance: '.$unbalanced->pluck('journal_entry_id')->implode(', '));
    }
}
