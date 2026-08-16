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
