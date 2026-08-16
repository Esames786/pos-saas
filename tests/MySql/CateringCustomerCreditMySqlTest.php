<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringRefund;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — the booking that owes the customer money.
 *
 * Kashif's own booking is the specification here. EV-20260816-0001 quoted
 * 458,250, took 492,500 across two receipts, and was then revised downwards. The
 * screen showed a balance of 0.00 and offered no action, because the only
 * calculation in the system clamped at zero and the only instrument was a
 * receipt.
 *
 * The numbers below are that booking, scaled to nothing — they are the real
 * ones. What is protected is that the 34,250 is visible, is named as owed to the
 * customer rather than by them, cannot be taken further, and can be handed back
 * without a single historical row being touched.
 */
class CateringCustomerCreditMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

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

    /** A confirmed booking quoted at $total. */
    private function bookingQuotedAt(float $total): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Credit Test Customer',
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

    private function receive(CateringEvent $event, float $amount): void
    {
        $this->advances->record($event->refresh(), [
            'amount' => $amount,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reference' => 'ADV-'.$amount,
        ]);
    }

    /** Revise the quotation down to $total, the way the screen does. */
    private function requoteAt(CateringEvent $event, float $total): void
    {
        $revised = $this->estimates->revise($event->currentEstimate()->first());
        $this->estimates->saveDraftLines($revised, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($revised->refresh());
    }

    /** Kashif's booking, reproduced: the quotation drops below what was paid. */
    private function bookingInCredit(): CateringEvent
    {
        $event = $this->bookingQuotedAt(600000);
        $this->receive($event, 250000);
        $this->receive($event, 242500);
        $this->requoteAt($event, 458250);

        return $event->refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A. The quotation may fall below what has already been received.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The real defect was never an excessive receipt — every receipt was valid
     * when it was taken. The quotation moved underneath them afterwards, and
     * that revision must still be allowed.
     */
    public function test_a_quotation_may_be_revised_below_the_money_already_received(): void
    {
        $event = $this->bookingInCredit();

        $this->assertSame(458250.0, round((float) $event->currentEstimate->grand_total, 2),
            'the revision is allowed to stand — it is the truth about what was agreed');

        $position = $this->position->position($event);

        $this->assertSame(492500.0, $position['gross_received']);
        $this->assertSame(458250.0, $position['billed']);
        $this->assertSame(34250.0, $position['customer_credit']);
        $this->assertSame(0.0, $position['balance_due'],
            'nothing is owed BY the customer — the debt runs the other way');
    }

    /** Credit is named as credit, never rendered as a balance the customer owes. */
    public function test_credit_is_named_as_owed_to_the_customer(): void
    {
        $headline = $this->position->headline($this->bookingInCredit());

        $this->assertSame('Credit owed to customer', $headline['label']);
        $this->assertSame(34250.0, $headline['amount']);
        $this->assertFalse($headline['settled']);
    }

    /** A plain unpaid booking still reads the ordinary way round. */
    public function test_an_underpaid_booking_still_shows_a_balance_due(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);

        $headline = $this->position->headline($event->refresh());

        $this->assertSame('Balance due', $headline['label']);
        $this->assertSame(70000.0, $headline['amount']);
    }

    /** Revising downwards must not disturb the receipts or their journals. */
    public function test_the_original_receipts_and_their_journals_survive_the_revision(): void
    {
        $event = $this->bookingInCredit();

        $advances = $event->advances()->orderBy('id')->get();
        $this->assertCount(2, $advances);
        $this->assertSame([250000.0, 242500.0], $advances->map(fn ($a) => (float) $a->amount)->all());

        foreach ($advances as $advance) {
            $this->assertNotNull($advance->journal_entry_id, 'each receipt keeps its posting');
            $entry = $this->tenant()->table('journal_entries')->where('id', $advance->journal_entry_id)->first();
            $this->assertSame(round((float) $advance->amount, 2), round((float) $entry->total_debit, 2),
                'and that posting still says exactly what it said when it was made');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The receipt is the wrong instrument once credit exists.
    // ─────────────────────────────────────────────────────────────────────────

    /** No further money may be taken while the business already owes some back. */
    public function test_no_further_payment_can_be_taken_while_credit_is_outstanding(): void
    {
        $event = $this->bookingInCredit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/credit owed to the customer/i');

        $this->receive($event, 1000);
    }

    /** Overpaying a booking outright is still refused, and says why. */
    public function test_a_receipt_beyond_the_outstanding_balance_is_refused(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 60000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds the outstanding balance/i');

        $this->receive($event, 50000);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // I. The invoice absorbs its own value and no more.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The invoice used to clear the entire advance out of Customer Advances,
     * including the part it did not account for. The liability disappeared while
     * the obligation remained.
     */
    public function test_an_invoice_only_absorbs_its_own_value_and_the_rest_stays_credit(): void
    {
        $event = $this->bookingInCredit();

        $invoice = app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->assertSame(492500.0, round((float) $invoice->advance_total, 2), 'all of it was received');
        $this->assertSame(458250.0, round((float) $invoice->advance_applied, 2), 'but only this much was billed for');
        $this->assertSame(0.0, round((float) $invoice->balance_due, 2));

        // 2300 Customer Advances: 492,500 credited by the receipts, 458,250
        // debited back by the application. The remainder is the liability.
        $this->assertSame(-34250.0, $this->accountNet('2300'),
            'the credit stays visible in the liability account, where it is owed from');

        $position = $this->position->position($event->refresh());
        $this->assertSame(34250.0, $position['customer_credit']);
        $this->assertSame(34250.0, $position['refundable']);
    }

    /** The ordinary invoice is unchanged: it applies everything received. */
    public function test_an_ordinary_invoice_still_applies_the_whole_advance(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);

        $invoice = app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->assertSame(30000.0, round((float) $invoice->advance_applied, 2));
        $this->assertSame(70000.0, round((float) $invoice->balance_due, 2));
        $this->assertSame(0.0, $this->accountNet('2300'), 'nothing is left held on the customer\'s behalf');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // H. Cancellation.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancelling bills nothing, so everything held becomes the customer's. It
     * must survive the cancellation and stay reachable — a cancelled booking is
     * exactly when someone needs their money back.
     */
    public function test_credit_survives_cancellation_and_stays_settleable(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);

        $this->estimates->cancelEvent($event->refresh(), 'Customer postponed indefinitely');

        $position = $this->position->position($event->refresh());

        $this->assertSame(30000.0, $position['gross_received'], 'the receipt is untouched');
        $this->assertSame(0.0, $position['billed'], 'a cancelled booking will never be billed');
        $this->assertSame(30000.0, $position['refundable'], 'so all of it is owed back');
        $this->assertSame('Credit owed to customer', $this->position->headline($event->refresh())['label']);
    }

    /** Cancelling does not itself hand the money back — that is a separate act. */
    public function test_cancelling_does_not_refund_by_itself(): void
    {
        $event = $this->bookingQuotedAt(100000);
        $this->receive($event, 30000);
        $this->estimates->cancelEvent($event->refresh(), 'Customer postponed indefinitely');

        $this->assertSame(0, CateringRefund::where('catering_event_id', $event->id)->count());
        $this->assertSame(30000.0, $this->accountNet('1110'),
            'the money is still in the drawer until someone deliberately pays it out');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Closure.
    // ─────────────────────────────────────────────────────────────────────────

    /** A booking still holding the customer's money is not "settled". */
    public function test_a_booking_in_credit_cannot_be_closed(): void
    {
        $event = $this->bookingInCredit();
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owes the customer/i');

        app(CateringFinalInvoiceService::class)->close($event->refresh());
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
}
