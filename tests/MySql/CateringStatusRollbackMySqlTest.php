<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringEventStatusService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-STATUS-ROLLBACK-1 — walking a booking backwards, and the wall it
 * must never walk through.
 *
 * The rule under test is not "which status" but "what did that status already
 * DO". Before an invoice or a production release, a status is the whole of what
 * happened and can be taken back. After either, the ledger or the store carries
 * a mark that a status change cannot reach — and pretending otherwise would
 * leave the books saying one thing and the booking another.
 *
 * @see docs/plans/kashif-status-rollback-2026-09-09.md
 */
class CateringStatusRollbackMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringEventStatusService $status;

    private int $branchId;

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
        $this->status = app(CateringEventStatusService::class);
        $this->branchId = $this->makeBranch();

        // Same shape as CateringFinanceMySqlTest: a real cash/bank account
        // mapped to 1110, because a receipt with no account behind it is
        // refused before it is written.
        $cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Rollback Cash', 'account_type' => 'cash',
            'account_id' => \App\Models\Tenant\Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashAccountId]);
    }

    // ── the green zone ─────────────────────────────────────────────────────

    /** confirmed -> quoted: the confirmation is taken back, nothing else moves. */
    public function test_a_confirmed_booking_goes_back_to_quoted(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->refresh()->status);
        $this->assertNotNull($event->confirmed_at);

        $this->status->moveBack($event->refresh(), 'Customer wants to change the menu');

        $event->refresh();
        $this->assertSame(CateringEvent::STATUS_QUOTED, $event->status);
        $this->assertNull($event->confirmed_at,
            'the confirmation timestamp must not survive the confirmation being taken back');
    }

    /**
     * quoted -> draft: this one does more than change a status. The quotation
     * the customer was SENT becomes editable again, which is the whole cost of
     * the feature and the reason it says so out loud on the screen.
     */
    public function test_going_back_to_draft_reopens_the_sent_quotation(): void
    {
        $event = $this->quotedEvent();
        $current = $event->refresh()->currentEstimate;
        $this->assertSame(CateringEstimate::STATUS_SENT, $current->status);
        $this->assertNotNull($current->sent_at);

        $this->status->moveBack($event->refresh(), 'Wrong rate on one item');

        $event->refresh();
        $this->assertSame(CateringEvent::STATUS_DRAFT, $event->status);

        $current->refresh();
        $this->assertSame(CateringEstimate::STATUS_DRAFT, $current->status,
            'the quotation must be editable again, not merely the booking');
        $this->assertNull($current->sent_at,
            'and it is no longer a document that has been sent');

        // Crucially it is the SAME version — not a new Q2. Create Revision is
        // still there for when the customer should get new paper.
        $this->assertSame(1, CateringEstimate::where('catering_event_id', $event->id)->count(),
            'reopening corrects the paper in hand; it does not issue another');
    }

    /** draft -> inquiry: only the intention is left. */
    public function test_a_draft_goes_back_to_inquiry(): void
    {
        $event = $this->draftEvent();
        $this->status->moveBack($event->refresh(), 'Not a real booking yet');

        $this->assertSame(CateringEvent::STATUS_INQUIRY, $event->refresh()->status);
    }

    /** An inquiry is the beginning; there is nowhere behind it. */
    public function test_an_inquiry_has_nowhere_further_back(): void
    {
        $event = $this->draftEvent();
        $event->forceFill(['status' => CateringEvent::STATUS_INQUIRY])->save();

        $this->assertNull($this->status->backwardTarget($event->refresh()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be moved back/i');
        $this->status->moveBack($event->refresh(), 'nowhere to go');
    }

    // ── un-cancel ──────────────────────────────────────────────────────────

    /** A cancelled booking returns to exactly where it was cancelled from. */
    public function test_un_cancelling_restores_the_status_it_was_cancelled_from(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());
        $this->estimates->cancelEvent($event->refresh(), 'Customer postponed');

        $event->refresh();
        $this->assertSame(CateringEvent::STATUS_CANCELLED, $event->status);
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->status_before_cancel);
        $this->assertFalse($this->status->restoreTargetIsAssumed($event));

        $this->status->moveBack($event->refresh(), 'Customer is back on');

        $event->refresh();
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->status,
            'it goes back to where it was, not to a guess');
        $this->assertNull($event->status_before_cancel, 'and the pointer is spent');
        $this->assertNotNull($event->cancel_reason,
            'the cancellation itself stays legible — it happened');
        $this->assertNotNull($event->cancelled_at);
    }

    /**
     * A booking cancelled BEFORE this feature shipped has no record of where it
     * came from. It lands on draft, and the screen is told to say so rather than
     * pretend it knows.
     */
    public function test_a_booking_cancelled_before_this_feature_lands_on_draft(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());
        $this->estimates->cancelEvent($event->refresh(), 'Cancelled long ago');

        // Exactly the state an old row is in.
        $event->forceFill(['status_before_cancel' => null])->save();

        $this->assertTrue($this->status->restoreTargetIsAssumed($event->refresh()));
        $this->assertSame(CateringEvent::STATUS_DRAFT, $this->status->restoreTarget($event->refresh()));

        $this->status->moveBack($event->refresh(), 'Reviving an old booking');
        $this->assertSame(CateringEvent::STATUS_DRAFT, $event->refresh()->status);
    }

    // ── the wall ───────────────────────────────────────────────────────────

    /**
     * THE ONE THAT MUST BE ABLE TO GO RED.
     *
     * An invoiced booking has posted revenue to 4160 and a receivable to 1300.
     * Walking it back would make the document editable again while the money it
     * was billed on stands in the ledger — the books saying one thing and the
     * booking another.
     */
    public function test_an_invoiced_booking_can_never_be_moved_back(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $before = $this->tenant()->table('journal_entries')->count();
        $status = $event->refresh()->status;

        try {
            $this->status->moveBack($event->refresh(), 'Trying to reopen a billed booking');
            $this->fail('an invoiced booking must never be moved back');
        } catch (RuntimeException $e) {
            // Specifically the map's refusal: `completed` has nowhere to go.
            // The invoice check is proven separately, above, because this
            // path never reaches it.
            $this->assertStringContainsString('cannot be moved back', $e->getMessage());
        }

        $this->assertSame($status, $event->refresh()->status, 'and it must not have moved');
        $this->assertSame($before, $this->tenant()->table('journal_entries')->count(),
            'nor may a refused roll-back touch the ledger');
    }

    /**
     * The same wall, reached the other way — and this is the test that actually
     * proves the check exists.
     *
     * The one above refuses because `completed` has no backward step in the map,
     * so it never reaches assertNothingPosted(). Removing that method left it
     * green, which made it a guard that agreed with a defect. This one forces
     * the booking into a status the map DOES allow while a final invoice stands
     * on the books — the exact shape of the bug someone would introduce by
     * adding a backward step out of a posted status — and requires the refusal
     * to name the invoice.
     */
    public function test_an_invoice_blocks_a_roll_back_even_from_a_status_the_map_allows(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());
        app(CateringFinalInvoiceService::class)->issue($event->refresh());

        $this->assertTrue($event->refresh()->finalInvoice()->exists());

        // Back into a status the backward map is happy with, invoice and all.
        $event->forceFill(['status' => CateringEvent::STATUS_CONFIRMED])->save();
        $this->assertNotNull($this->status->backwardTarget($event->refresh()),
            'the map must allow this step, or the test proves nothing again');

        $before = $this->tenant()->table('journal_entries')->count();

        try {
            $this->status->moveBack($event->refresh(), 'Trying to reopen a billed booking');
            $this->fail('a booking with a final invoice must never be moved back');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('final invoice', $e->getMessage(),
                'and the refusal must name WHY — the ledger, not the status map');
        }

        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->refresh()->status);
        $this->assertSame($before, $this->tenant()->table('journal_entries')->count());
    }

    /** The kitchen has been told to cook; that is answered by a document. */
    public function test_a_released_booking_cannot_be_moved_back(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());
        app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release($event->refresh(), null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/production release|cannot be moved back/i');
        $this->status->moveBack($event->refresh(), 'Trying to unrelease');
    }

    // ── money ──────────────────────────────────────────────────────────────

    /**
     * The property the whole feature rests on: a roll-back re-interprets money,
     * it never moves any.
     */
    public function test_moving_back_leaves_every_payment_exactly_as_it_was(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());

        app(CateringAdvanceService::class)->record($event->refresh(), [
            'amount' => 25000,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
        ]);

        $rows = $this->tenant()->table('catering_advances')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $entries = $this->tenant()->table('journal_entries')->count();
        $lines = $this->tenant()->table('journal_lines')->count();
        $received = app(CateringFinancialPositionService::class)->position($event->refresh())['gross_received'];

        $this->status->moveBack($event->refresh(), 'Customer changed the menu after paying a deposit');

        $this->assertSame(CateringEvent::STATUS_QUOTED, $event->refresh()->status);
        $this->assertEquals($rows, $this->tenant()->table('catering_advances')
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'a roll-back must not edit a receipt that already happened');
        $this->assertSame($entries, $this->tenant()->table('journal_entries')->count(),
            'nor post, reverse or delete a journal entry');
        $this->assertSame($lines, $this->tenant()->table('journal_lines')->count());
        $this->assertSame(0, $this->tenant()->table('catering_refunds')->count(),
            'and money does not walk out because a booking stepped backwards');

        $this->assertEqualsWithDelta($received,
            app(CateringFinancialPositionService::class)->position($event->refresh())['gross_received'], 0.01,
            'the same money is still there');
    }

    /** Every roll-back says why. A blank reason is not a reason. */
    public function test_a_roll_back_needs_a_reason(): void
    {
        $event = $this->quotedEvent();
        $this->estimates->confirmEvent($event->refresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reason/i');
        $this->status->moveBack($event->refresh(), '   ');
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function draftEvent(): CateringEvent
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 400]);

        // Without an effective material rate the costing is incomplete and both
        // markSent() and confirmEvent() refuse — assertCostingReady() is doing
        // its job, not getting in the way.
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Rollback Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(20)->toDateString(),
            'pax' => 100,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $productId, 'item_name' => 'Test Dish', 'quantity' => 100, 'rate' => 1000],
        ]);

        return $event->refresh();
    }

    private function quotedEvent(): CateringEvent
    {
        $event = $this->draftEvent();
        $this->estimates->markSent($event->currentEstimate->refresh());

        return $event->refresh();
    }
}
