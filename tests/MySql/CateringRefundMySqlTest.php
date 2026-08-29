<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringRefund;
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
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — giving the money back.
 *
 * A refund is the one catering action that takes money OUT, so what is protected
 * here is not that it works but that it cannot be made to lie:
 *
 *   - the receipt it settles is never edited, deleted or negated
 *   - the ledger and the cash drawer move together or not at all
 *   - it can never draw on money that is covering a bill
 *   - the same refund submitted twice pays out once
 *
 * The figures are Kashif's booking: 492,500 received against a 458,250
 * quotation, leaving 34,250 owed back.
 */
class CateringRefundMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

    private CateringRefundService $refunds;

    private CateringFinancialPositionService $position;

    private int $branchId;

    private int $productId;

    private int $cashAccountId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines',
            'catering_material_issues', 'catering_production_release_lines', 'catering_production_releases',
            'catering_refunds', 'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->advances = app(CateringAdvanceService::class);
        $this->refunds = app(CateringRefundService::class);
        $this->position = app(CateringFinancialPositionService::class);

        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct($this->makeCategory(), ['default_purchase_price' => 400]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashAccountId]);
    }

    private function bookingQuotedAt(float $total): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Refund Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 300,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return $event->refresh();
    }

    private function receive(CateringEvent $event, float $amount): CateringAdvance
    {
        return $this->advances->record($event->refresh(), [
            'amount' => $amount,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reference' => 'ADV-'.$amount,
        ]);
    }

    /** Kashif's booking: 492,500 taken, then re-quoted at 458,250. */
    private function bookingInCredit(): CateringEvent
    {
        $event = $this->bookingQuotedAt(600000);
        $this->receive($event, 250000);
        $this->receive($event, 242500);

        $revised = $this->estimates->revise($event->currentEstimate()->first());
        $this->estimates->saveDraftLines($revised, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => 458250],
        ]);
        $this->estimates->markSent($revised->refresh());

        return $event->refresh();
    }

    private function refund(CateringEvent $event, float $amount, string $reason = 'Quotation revised down'): CateringRefund
    {
        return $this->refunds->record($event->refresh(), [
            'amount' => $amount,
            'refund_date' => now()->toDateString(),
            'reason' => $reason,
            'payment_method_id' => $this->paymentMethodId,
            'reference' => 'RF-'.$amount,
        ], null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B. Real money out, through the approved authority.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_refund_posts_money_out_and_clears_the_credit(): void
    {
        $event = $this->bookingInCredit();
        $this->assertSame(34250.0, $this->position->position($event)['customer_credit']);

        $refund = $this->refund($event, 34250);

        // The posting: Dr 2300 Customer Advances / Cr 1110 the cash it left from.
        $this->assertNotNull($refund->journal_entry_id, 'a refund without a journal is not a refund');
        $lines = $this->tenant()->table('journal_lines')
            ->where('journal_entry_id', $refund->journal_entry_id)->get();

        $this->assertCount(2, $lines);
        $this->assertSame(34250.0, $this->lineFor($lines, '2300', 'debit'),
            'the liability falls by exactly what was handed back');
        $this->assertSame(34250.0, $this->lineFor($lines, '1110', 'credit'),
            'and the cash account it left from falls by the same');

        // The drawer: 492,500 in, 34,250 out.
        $this->assertSame(458250.0, round((float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 2));

        $movement = $this->tenant()->table('cash_bank_account_transactions')
            ->where('reference_type', 'catering_refund')->where('reference_id', $refund->id)->first();
        $this->assertSame('out', $movement->direction);
        $this->assertSame('customer_refund', $movement->transaction_type);

        // And the position closes.
        $position = $this->position->position($event->refresh());
        $this->assertSame(0.0, $position['customer_credit']);
        $this->assertSame(0.0, $position['balance_due']);
        $this->assertSame(458250.0, $position['net_received']);
    }

    /** The receipt being settled must come through completely untouched. */
    public function test_the_original_receipts_survive_the_refund_unchanged(): void
    {
        $event = $this->bookingInCredit();
        $before = $this->tenant()->table('catering_advances')
            ->where('catering_event_id', $event->id)->orderBy('id')
            ->get(['id', 'amount', 'journal_entry_id'])->toArray();

        $this->refund($event, 34250);

        $after = $this->tenant()->table('catering_advances')
            ->where('catering_event_id', $event->id)->orderBy('id')
            ->get(['id', 'amount', 'journal_entry_id'])->toArray();

        $this->assertEquals($before, $after,
            'a refund records what happened next; it never edits what happened before');
        $this->assertSame(492500.0, $this->position->position($event->refresh())['gross_received'],
            'and the money that came in is still on the record as having come in');
    }

    /** The advance journal entries are equally untouched. */
    public function test_the_original_advance_journals_are_not_reversed_or_rewritten(): void
    {
        $event = $this->bookingInCredit();
        $advanceEntryIds = $event->advances()->pluck('journal_entry_id')->all();
        $before = $this->tenant()->table('journal_lines')
            ->whereIn('journal_entry_id', $advanceEntryIds)->orderBy('id')->get()->toArray();

        $this->refund($event, 34250);

        $after = $this->tenant()->table('journal_lines')
            ->whereIn('journal_entry_id', $advanceEntryIds)->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'posted history is not editable, only extendable');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C. The limit.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_refund_cannot_exceed_the_credit_owed(): void
    {
        $event = $this->bookingInCredit();

        try {
            $this->refund($event, 34250.01);
            $this->fail('a refund beyond the credit owed must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }

        $this->assertSame(0, CateringRefund::count(), 'and nothing may be left behind by the refusal');
        $this->assertSame(492500.0, round((float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 2),
            'the drawer must not have moved');
    }

    /** Money that is covering an unpaid bill is not the customer's to take back. */
    public function test_an_underpaid_booking_has_nothing_to_refund(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing to refund/i');

        $this->refund($event, 1000);
    }

    public function test_a_refund_must_be_for_a_positive_amount(): void
    {
        $event = $this->bookingInCredit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/positive amount/i');

        $this->refund($event, 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E / F. Partial, then full.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_partial_refund_leaves_the_remainder_owed(): void
    {
        $event = $this->bookingInCredit();

        $this->refund($event, 10000, 'Part settled in cash today');

        $position = $this->position->position($event->refresh());
        $this->assertSame(10000.0, $position['refunded']);
        $this->assertSame(24250.0, $position['customer_credit'], '34,250 less 10,000 still owed');
        $this->assertSame(24250.0, $position['refundable']);
        $this->assertSame('Credit owed to customer', $this->position->headline($event->refresh())['label']);
    }

    public function test_refunding_the_remainder_settles_the_booking(): void
    {
        $event = $this->bookingInCredit();

        $this->refund($event, 10000, 'Part settled in cash today');
        $this->refund($event, 24250, 'Balance of the credit settled');

        $position = $this->position->position($event->refresh());
        $this->assertSame(34250.0, $position['refunded']);
        $this->assertSame(0.0, $position['customer_credit']);
        $this->assertSame(0.0, $position['refundable']);

        $headline = $this->position->headline($event->refresh());
        $this->assertTrue($headline['settled']);
        $this->assertSame(0.0, $headline['amount']);

        // 2300 nets to zero: 492,500 credited in, 458,250 never applied here
        // because no invoice was issued — so the whole 34,250 came back out.
        $this->assertSame(2, CateringRefund::count());
    }

    /** A third refund of the same credit has nothing left to draw on. */
    public function test_the_credit_cannot_be_refunded_twice_over(): void
    {
        $event = $this->bookingInCredit();
        $this->refund($event, 34250);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing to refund/i');

        $this->refund($event, 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // H / I. Cancellation and the invoice.
    // ─────────────────────────────────────────────────────────────────────────

    /** A cancelled booking is exactly when someone wants their money back. */
    public function test_a_cancelled_booking_can_refund_what_it_is_holding(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);
        $this->estimates->cancelEvent($event->refresh(), 'Customer postponed indefinitely');

        $refund = $this->refund($event, 30000, 'Booking cancelled by customer');

        $this->assertSame(0.0, $this->position->position($event->refresh())['refundable']);
        $this->assertSame(0.0, round((float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 2));
        $this->assertNotNull($refund->journal_entry_id);
        $this->assertSame('cancelled', $event->refresh()->status,
            'and the booking stays cancelled — a refund settles money, not status');
    }

    /** After an invoice, only the part it could not absorb is refundable. */
    public function test_after_invoicing_only_the_unapplied_excess_can_be_refunded(): void
    {
        $event = $this->bookingInCredit();
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->refund($event, 34250, 'Overpayment returned after invoicing');

        // 2300: 492,500 credited by receipts, 458,250 debited by the application,
        // 34,250 debited by the refund. Nothing held, nothing owed.
        $this->assertSame(0.0, $this->accountNet('2300'));
        $this->assertSame(0.0, $this->position->position($event->refresh())['customer_credit']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The document.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_refund_carries_its_own_number_reason_and_author(): void
    {
        $event = $this->bookingInCredit();
        $refund = $this->refund($event, 5000, 'Customer collected part of the credit');

        $this->assertStringStartsWith('CR-'.now()->format('Ymd').'-', $refund->refund_no);
        $this->assertSame('Customer collected part of the credit', $refund->reason);
        $this->assertNotNull($refund->refund_date);
        $this->assertNotNull($refund->gl_posted_at);
    }

    /** History, not a working record: it cannot be edited or deleted. */
    public function test_a_recorded_refund_can_neither_be_edited_nor_deleted(): void
    {
        $event = $this->bookingInCredit();
        $refund = $this->refund($event, 5000);

        try {
            $refund->update(['amount' => 1]);
            $this->fail('a refund amount must not be editable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $refund->delete();
            $this->fail('a refund must not be deletable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stays on the record', $e->getMessage());
        }

        $this->assertSame(5000.0, round((float) $refund->fresh()->amount, 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // J. No parallel accounting.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every ledger row a refund produces must come through JournalService, the
     * one approved posting authority. A service that wrote journal tables itself
     * would balance today and drift the first time the posting rules changed.
     */
    public function test_the_refund_path_never_writes_the_journal_tables_itself(): void
    {
        foreach ([
            'app/Services/Catering/CateringRefundService.php',
            'app/Models/Tenant/CateringRefund.php',
            'app/Http/Controllers/Tenant/Catering/CateringRefundController.php',
            'app/Services/Catering/CateringFinancialPositionService.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertNotFalse($source, "{$path} must be readable");
            $this->assertNotSame('', trim($source), "{$path} must not be empty");

            foreach (['journal_entries', 'journal_lines', 'JournalEntry::create', 'JournalLine::create'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source,
                    "{$path} must reach the ledger through JournalPostingService, never the tables");
            }
        }
    }

    /** The refund posts through the same authority the receipts do. */
    public function test_the_refund_journal_entry_is_sourced_and_replay_safe(): void
    {
        $event = $this->bookingInCredit();
        $refund = $this->refund($event, 34250);

        $entry = $this->tenant()->table('journal_entries')->where('id', $refund->journal_entry_id)->first();
        $this->assertSame('catering_refund', $entry->source_type);
        $this->assertSame($refund->id, (int) $entry->source_id);
        $this->assertSame($refund->refund_no, $entry->source_no);

        // Re-posting the same refund returns the same entry rather than a second.
        $again = app(\App\Services\Finance\JournalPostingService::class)->postCateringRefund($refund->fresh());
        $this->assertSame((int) $entry->id, (int) $again->id, 'one refund, one posting, forever');
        $this->assertSame(1, $this->tenant()->table('journal_entries')
            ->where('source_type', 'catering_refund')->count());
    }

    /** Debits equal credits across everything this booking produced. */
    public function test_the_books_still_balance_after_a_refund(): void
    {
        $event = $this->bookingInCredit();
        app(CateringFinalInvoiceService::class)->issue($event->refresh());
        $this->refund($event, 34250);

        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        $this->assertSame(round((float) $row->d, 2), round((float) $row->c, 2),
            'a zero-difference trial balance is the whole point of posting through one authority');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Money out must name the account it leaves from.
    //
    // The cash/bank movement used to sit behind `if ($cashBankAccountId)` while
    // the payment method was optional. A refund naming no method, or naming an
    // active method nobody had linked to an account, produced a refund row and a
    // ledger entry with the drawer untouched: the books said money left, the
    // balance said it had not. Each refusal below is proved to cost nothing —
    // no refund, no posting, no movement, and the balance exactly as it was.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int, 2: int, 3: float} */
    private function financialState(): array
    {
        return [
            CateringRefund::count(),
            (int) $this->tenant()->table('journal_entries')->where('source_type', 'catering_refund')->count(),
            (int) $this->tenant()->table('cash_bank_account_transactions')->where('reference_type', 'catering_refund')->count(),
            round((float) $this->tenant()->table('cash_bank_accounts')->where('id', $this->cashAccountId)->value('current_balance'), 2),
        ];
    }

    /** Attempt a refund that must be refused, and prove nothing at all happened. */
    private function assertRefusedAndInert(callable $attempt, string $expected): void
    {
        $before = $this->financialState();

        try {
            $attempt();
            $this->fail('the refund must be refused: '.$expected);
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression($expected, $e->getMessage());
        }

        $this->assertSame($before, $this->financialState(),
            'a refusal must leave no refund, no posting, no movement and the balance untouched');
        $this->assertSame(492500.0, $before[3], 'and the drawer must still hold every rupee received');
    }

    /** A. No payment method at all. */
    public function test_a_refund_without_a_payment_method_is_refused(): void
    {
        $event = $this->bookingInCredit();

        $this->assertRefusedAndInert(
            fn () => $this->refunds->record($event->refresh(), [
                'amount' => 1000, 'refund_date' => now()->toDateString(), 'reason' => 'No method given',
            ]),
            '/where the money is leaving from/i'
        );
    }

    /** B. A method that has been retired. */
    public function test_a_refund_through_an_inactive_payment_method_is_refused(): void
    {
        $event = $this->bookingInCredit();
        $retired = $this->makePaymentMethod([
            'cash_bank_account_id' => $this->cashAccountId, 'is_active' => 0, 'name' => 'Retired Cash',
        ]);

        $this->assertRefusedAndInert(
            fn () => $this->refunds->record($event->refresh(), [
                'amount' => 1000, 'refund_date' => now()->toDateString(),
                'reason' => 'Retired method', 'payment_method_id' => $retired,
            ]),
            '/no longer in use/i'
        );
    }

    /** C. Active, but nobody ever linked it to an account. */
    public function test_a_refund_through_an_unmapped_payment_method_is_refused(): void
    {
        $event = $this->bookingInCredit();
        $unmapped = $this->makePaymentMethod(['cash_bank_account_id' => null, 'name' => 'Unmapped Wallet']);

        $this->assertRefusedAndInert(
            fn () => $this->refunds->record($event->refresh(), [
                'amount' => 1000, 'refund_date' => now()->toDateString(),
                'reason' => 'Unmapped method', 'payment_method_id' => $unmapped,
            ]),
            '/not linked to a cash or bank account/i'
        );
    }

    /** D. Mapped, but the account behind it has been closed. */
    public function test_a_refund_from_a_closed_cash_account_is_refused(): void
    {
        $event = $this->bookingInCredit();

        $closedAccount = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Closed Drawer', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $method = $this->makePaymentMethod(['cash_bank_account_id' => $closedAccount, 'name' => 'Closed Drawer Cash']);

        $this->assertRefusedAndInert(
            fn () => $this->refunds->record($event->refresh(), [
                'amount' => 1000, 'refund_date' => now()->toDateString(),
                'reason' => 'Closed account', 'payment_method_id' => $method,
            ]),
            '/missing or closed/i'
        );
    }

    /** D2. Mapped to a live account that has no chart-of-accounts link. */
    public function test_a_refund_from_an_unposted_cash_account_is_refused(): void
    {
        $event = $this->bookingInCredit();

        $unlinked = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Petty Tin', 'account_type' => 'cash',
            'account_id' => null,
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $method = $this->makePaymentMethod(['cash_bank_account_id' => $unlinked, 'name' => 'Petty Tin Cash']);

        $this->assertRefusedAndInert(
            fn () => $this->refunds->record($event->refresh(), [
                'amount' => 1000, 'refund_date' => now()->toDateString(),
                'reason' => 'Unposted account', 'payment_method_id' => $method,
            ]),
            '/not mapped to a general-ledger account/i'
        );
    }

    /**
     * Defence in depth: the posting authority refuses too, so the guard is not
     * one caller deep. A refund reaching it unmapped must never quietly land in
     * 1500 Undeposited Funds — a phrase that means nothing about money going out.
     */
    public function test_the_posting_authority_itself_refuses_an_unmapped_refund(): void
    {
        $event = $this->bookingInCredit();
        $refund = $this->refund($event, 5000);

        // A copy standing in for a refund that reached the ledger unmapped.
        $orphan = new CateringRefund;
        $orphan->forceFill($refund->only(['refund_no', 'catering_event_id', 'amount', 'refund_date', 'reason']));
        $orphan->id = $refund->id + 9000;
        $orphan->cash_bank_account_id = null;
        $orphan->setRelation('event', $event);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must name the account it left from/i');

        app(\App\Services\Finance\JournalPostingService::class)->postCateringRefund($orphan);
    }

    /** E. The whole of a successful refund lands, or none of it does. */
    public function test_a_valid_refund_lands_as_one_complete_money_out_transaction(): void
    {
        $event = $this->bookingInCredit();

        $refund = $this->refund($event, 34250);

        $this->assertSame(1, CateringRefund::count());
        $this->assertSame(1, (int) $this->tenant()->table('journal_entries')
            ->where('source_type', 'catering_refund')->count());

        $movements = $this->tenant()->table('cash_bank_account_transactions')
            ->where('reference_type', 'catering_refund')->get();
        $this->assertCount(1, $movements);
        $this->assertSame('out', $movements[0]->direction);
        $this->assertSame($this->cashAccountId, (int) $movements[0]->cash_bank_account_id);
        $this->assertSame(34250.0, round((float) $movements[0]->amount, 2));

        $this->assertSame($this->cashAccountId, (int) $refund->cash_bank_account_id,
            'the refund records the account it actually left from');
        $this->assertSame(458250.0, round((float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 2),
            '492,500 in less 34,250 out — the drawer agrees with the books to the rupee');
    }

    /** No refund may ever credit Undeposited Funds. */
    public function test_no_refund_ever_credits_undeposited_funds(): void
    {
        $event = $this->bookingInCredit();
        $this->refund($event, 34250);

        $undepositedId = Account::where('code', '1500')->value('id');
        $refundEntryIds = $this->tenant()->table('journal_entries')
            ->where('source_type', 'catering_refund')->pluck('id');

        $this->assertSame(0, (int) $this->tenant()->table('journal_lines')
            ->whereIn('journal_entry_id', $refundEntryIds)
            ->where('account_id', $undepositedId)->count(),
            'money going out never leaves an account for money that has not been banked yet');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // G. The statement.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The whole point of a statement is that it agrees with the headline. If a
     * customer can add up the rows and reach a different number from the one on
     * the screen, one of them is lying and nobody can tell which.
     */
    public function test_the_ledger_closes_on_exactly_the_headline_position(): void
    {
        $event = $this->bookingInCredit();
        $this->refund($event, 10000, 'Part settled in cash');

        $ledger = $this->position->ledger($event->refresh());
        $position = $this->position->position($event->refresh());

        $last = end($ledger);
        $this->assertSame(24250.0, $last['running'],
            'the last running figure is the credit still owed');
        $this->assertSame(round($position['customer_credit'] - $position['balance_due'], 2), $last['running'],
            'and it is the same number the summary shows, by construction');
    }

    /** Every row on the statement stands for a record that exists. */
    public function test_every_ledger_row_traces_to_a_real_document(): void
    {
        $event = $this->bookingInCredit();
        $this->refund($event, 10000, 'Part settled in cash');

        $types = array_column($this->position->ledger($event->refresh()), 'type');

        $this->assertSame(2, count(array_filter($types, fn ($t) => $t === 'Advance received')),
            'two receipts were taken, so two rows appear');
        $this->assertSame(1, count(array_filter($types, fn ($t) => $t === 'Refund paid')));
        $this->assertContains('Quotation Q2', $types, 'the revised quotation is what is being charged');
    }

    /** An invoiced booking shows the invoice and what it absorbed. */
    public function test_the_ledger_shows_the_invoice_and_the_advance_it_absorbed(): void
    {
        $event = $this->bookingInCredit();
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $ledger = $this->position->ledger($event->refresh());
        $types = array_column($ledger, 'type');

        $this->assertContains('Final invoice issued', $types);
        $this->assertContains('Advance applied to invoice', $types);

        $applied = collect($ledger)->firstWhere('type', 'Advance applied to invoice');
        $this->assertTrue($applied['informational'],
            'applying an advance moves no money — it records what money already held is now for');
        $this->assertSame(0.0, $applied['money_in'] + $applied['money_out']);

        $this->assertSame(34250.0, end($ledger)['running'],
            'and the statement still ends on the credit the invoice could not absorb');
    }

    /** An ordinary underpaid booking reads as a balance due, not a credit. */
    public function test_the_ledger_of_an_underpaid_booking_ends_negative(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);

        $ledger = $this->position->ledger($event->refresh());

        $this->assertSame(-70000.0, end($ledger)['running'],
            'the customer owes 70,000, and the statement says so in the other direction');
    }

    private function lineFor($lines, string $code, string $column): float
    {
        $accountId = Account::where('code', $code)->value('id');

        return round((float) $lines->firstWhere('account_id', $accountId)?->{$column}, 2);
    }

    private function accountNet(string $code): float
    {
        $accountId = Account::where('code', $code)->value('id');
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('account_id', $accountId)->first();

        return round((float) $row->d - (float) $row->c, 2);
    }
}
