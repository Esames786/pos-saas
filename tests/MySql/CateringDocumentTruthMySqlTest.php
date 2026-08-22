<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * CAT-DOC-001 / CAT-PRICE-001 / CAT-RATE-UX-001 — what the customer is handed,
 * and what the operator is told.
 *
 * Three findings from the product audit, all of the same kind: the screen said
 * one thing and the system did another.
 *
 *   a DRAFT quotation printed identically to an issued one, so it could be
 *   signed while its numbers were still moving underneath it;
 *
 *   the printed balance was computed from GROSS advances, so a partly refunded
 *   booking printed as though the business still held all the money — and
 *   disagreed with the screen beside it;
 *
 *   "Per PAX" and "Fixed" were offered, stored and displayed, and no calculation
 *   anywhere read them.
 */
class CateringDocumentTruthMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $productId;

    private int $cashAccountId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        // Outside an HTTP request nothing shares the session error bag, but every
        // page uses $errors. Share it so a render failure means a REAL defect.
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->cleanTenant([
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'catering_settings',
            'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'stock_ledgers',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();
        $this->productId = $this->makeProduct($this->makeCategory(), ['name' => 'Chicken Biryani']);

        $this->cashAccountId = DB::connection('tenant')->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => \App\Models\Tenant\Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 500000, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashAccountId]);

        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );
    }

    private function quotation(float $total = 100000): CateringEstimate
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Document Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 200,
        ]);

        return $this->estimates->saveDraftLines($event->currentEstimate, [[
            'item_name' => 'Chicken Biryani', 'quantity' => 100, 'rate' => $total / 100,
        ]]);
    }

    private function render(CateringEstimate $estimate): string
    {
        $estimate->load(['event.customer', 'lines']);

        return View::make('tenant.catering.documents.estimate', [
            'estimate' => $estimate,
            'event' => $estimate->event,
            'lang' => 'en',
            'position' => app(CateringFinancialPositionService::class)->position($estimate->event),
            'advanceTotal' => 0.0,
            'businessName' => 'Test Caterer',
        ])->render();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // N · a draft must say it is a draft
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_draft_quotation_says_so_on_the_customers_copy(): void
    {
        $estimate = $this->quotation();
        $this->assertTrue($estimate->isDraft());

        $html = $this->render($estimate);

        $this->assertStringContainsString('DRAFT ESTIMATE', $html,
            'the title itself must say it — an operator reading only the heading must not be misled');
        $this->assertStringContainsString('DRAFT — NOT YET ISSUED', $html);
        $this->assertStringContainsString('may change', $html,
            'and it must say WHY it matters, not merely stamp a word on the page');
    }

    public function test_a_sent_quotation_looks_like_the_issued_document(): void
    {
        $estimate = $this->quotation();
        $this->estimates->markSent($estimate->refresh());

        $html = $this->render($estimate->refresh());

        $this->assertStringNotContainsString('DRAFT', $html,
            'once it is issued it is the quotation, and a draft warning would undermine it');
        $this->assertStringContainsString('ESTIMATE', $html);
    }

    public function test_a_superseded_version_is_marked_as_superseded(): void
    {
        $estimate = $this->quotation();
        $this->estimates->markSent($estimate->refresh());
        $this->estimates->revise($estimate->refresh());

        $html = $this->render($estimate->refresh());

        $this->assertStringContainsString('SUPERSEDED', $html,
            'a reprinted old version must not read as the current quotation');
    }

    /** No internal costing may ever reach a customer document. */
    public function test_the_customer_document_carries_no_internal_cost(): void
    {
        $estimate = $this->quotation();

        $html = $this->render($estimate);

        foreach (['Costs us', 'Material Cost', 'estimated_material_cost', 'Margin'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
    }

    /**
     * KASHIF-LEGACY-ALIGN-1 — "Is p gosht nh arha". The customer's copy names
     * each material, the line's TOTAL kitchen quantity, and WHO supplies it —
     * always in both languages. Internal cost still never leaks.
     */
    public function test_the_customer_copy_names_each_material_its_quantity_and_who_provides_it(): void
    {
        $estimate = $this->quotation();
        $line = $estimate->lines->first();

        \App\Models\Tenant\CateringEstimateLineCostBlock::create([
            'catering_estimate_line_id' => $line->id,
            'label' => 'Beef', 'material_name' => 'Beef', 'unit_code' => 'KG',
            'block_type' => \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL,
            'charge_basis' => \App\Models\Tenant\CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => \App\Models\Tenant\CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'rate' => 120, 'quantity_per_unit' => 1.2,
            'default_material_qty' => 120, 'event_material_qty' => 120,
            'is_customer_supplied' => true, 'amount' => 0, 'sort_order' => 1,
        ]);
        \App\Models\Tenant\CateringEstimateLineCostBlock::create([
            'catering_estimate_line_id' => $line->id,
            'label' => 'Basmati Rice', 'material_name' => 'Basmati Rice', 'unit_code' => 'KG',
            'block_type' => \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL,
            'charge_basis' => \App\Models\Tenant\CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => \App\Models\Tenant\CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'rate' => 55, 'quantity_per_unit' => 0.8,
            'default_material_qty' => 80, 'event_material_qty' => 80,
            'is_customer_supplied' => false, 'amount' => 4400, 'sort_order' => 2,
        ]);

        $html = $this->render($estimate->refresh());

        // The box header, both languages.
        $this->assertStringContainsString('Materials', $html);
        $this->assertStringContainsString('سامان', $html);

        // Each material with the line's TOTAL kitchen draw — the same number
        // the kitchen release sheet works from.
        $this->assertStringContainsString('Beef', $html);
        $this->assertStringContainsString('120 KG', $html);
        $this->assertStringContainsString('Basmati Rice', $html);
        $this->assertStringContainsString('80 KG', $html);

        // Who provides what — both languages, on every material.
        $this->assertStringContainsString('Customer provides', $html);
        $this->assertStringContainsString('گاہک دے گا', $html);
        $this->assertStringContainsString('We provide', $html);
        $this->assertStringContainsString('ہم دیں گے', $html);

        // And the margin's ingredients still never reach the customer.
        foreach (['Costs us', 'material_rate_at_quote', 'material_cost'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
    }

    /**
     * KASHIF-LEGACY-ALIGN-5 — the legacy Complimentry flag on the customer's
     * copy: a line quoted at ZERO against a real calculated rate prints its
     * flag in both languages, and bills nothing.
     */
    public function test_a_complimentary_line_prints_its_flag_in_both_languages(): void
    {
        $estimate = $this->quotation();
        $line = $estimate->lines->first();
        $line->calculated_rate = 1000;
        $line->rate = 0;
        $line->amount = 0;
        $line->rate_override_reason = 'Complimentary item';
        $line->save();

        $html = $this->render($estimate->refresh());

        $this->assertStringContainsString('Complimentary', $html);
        $this->assertStringContainsString('اعزازی', $html);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // O · the printed balance must know about refunds
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A booking that shrank, was refunded, then grew again.
     *
     * The audit's illustration — refund part of an advance while a bill still
     * stands — turns out not to be reachable: CateringRefund refuses to hand back
     * money that is covering an outstanding balance. A refund only ever comes out
     * of CREDIT. So the reachable shape is this one, and it exercises the same
     * defect harder:
     *
     *      quoted 30,000, received 30,000        balance 0
     *      booking shrinks to 10,000             credit 20,000
     *      refund 20,000                         net received 10,000
     *      booking grows to 50,000               balance 40,000
     *
     * Gross advances total 30,000 and the business holds 10,000. The document
     * used to print 20,000 due. The right answer is 40,000, and the difference is
     * exactly the refund the customer's copy never knew about.
     */
    public function test_a_refund_changes_what_the_printed_document_says_is_due(): void
    {
        $estimate = $this->quotation(30000);
        $event = $estimate->event;

        app(CateringAdvanceService::class)->record($event, [
            'amount' => 30000, 'received_date' => now()->toDateString(),
        ]);

        $this->estimates->saveDraftLines($estimate->refresh(), [[
            'item_name' => 'Chicken Biryani', 'quantity' => 100, 'rate' => 100,
        ]]);

        app(CateringRefundService::class)->record($event->refresh(), [
            'amount' => 20000, 'refund_date' => now()->toDateString(),
            'reason' => 'Booking reduced', 'payment_method_id' => $this->paymentMethodId,
        ]);

        $this->estimates->saveDraftLines($estimate->refresh(), [[
            'item_name' => 'Chicken Biryani', 'quantity' => 100, 'rate' => 500,
        ]]);

        $html = $this->render($estimate->refresh());

        $this->assertStringContainsString('40,000.00', $html,
            'the business holds 10,000 against a 50,000 bill — printing 20,000 ignores the refund entirely');
        $this->assertStringContainsString('Refunded', $html,
            'and the refund is shown, so the person holding the paper can reconcile the two figures');
        $this->assertStringContainsString('20,000.00', $html);
        $this->assertStringContainsString('Net Received', $html);
        $this->assertStringContainsString('30,000.00', $html, 'gross received is still stated honestly');
    }

    /** The document and the screen must never disagree. */
    public function test_the_document_and_the_booking_screen_use_one_authority(): void
    {
        $estimate = $this->quotation(30000);
        $event = $estimate->event;

        app(CateringAdvanceService::class)->record($event, [
            'amount' => 30000, 'received_date' => now()->toDateString(),
        ]);
        $this->estimates->saveDraftLines($estimate->refresh(), [[
            'item_name' => 'Chicken Biryani', 'quantity' => 100, 'rate' => 100,
        ]]);
        app(CateringRefundService::class)->record($event->refresh(), [
            'amount' => 20000, 'refund_date' => now()->toDateString(),
            'reason' => 'Booking reduced', 'payment_method_id' => $this->paymentMethodId,
        ]);

        $position = app(CateringFinancialPositionService::class)->position($event->refresh());

        $this->assertEqualsWithDelta(30000.0, $position['gross_received'], 0.01);
        $this->assertEqualsWithDelta(20000.0, $position['refunded'], 0.01);
        $this->assertEqualsWithDelta(10000.0, $position['net_received'], 0.01);
        $this->assertEqualsWithDelta(0.0, $position['balance_due'], 0.01);

        $this->assertStringContainsString(
            number_format($position['balance_due'], 2),
            $this->render($estimate->refresh()),
            'the paper prints the same number the screen shows'
        );
    }

    /** Money owed BACK to the customer is stated as such, never as a negative balance. */
    public function test_an_over_refunded_position_prints_as_money_owed_to_the_customer(): void
    {
        // An advance can never exceed the outstanding balance — the model refuses
        // it — so the honest route to a credit is the real one: the customer paid
        // in full and then the booking got smaller.
        $estimate = $this->quotation(30000);
        $event = $estimate->event;

        app(CateringAdvanceService::class)->record($event, [
            'amount' => 30000, 'received_date' => now()->toDateString(),
        ]);

        $this->estimates->saveDraftLines($estimate->refresh(), [[
            'item_name' => 'Chicken Biryani', 'quantity' => 100, 'rate' => 100,
        ]]);

        $html = $this->render($estimate->refresh());

        $this->assertStringContainsString('Refundable to Customer', $html,
            'holding more than the bill is a debt to the customer, not a negative balance');
        $this->assertStringContainsString('20,000.00', $html);
    }

    /** Books stay balanced through all of it. */
    public function test_the_trial_balance_stays_at_zero(): void
    {
        $estimate = $this->quotation(30000);
        $event = $estimate->event;

        app(CateringAdvanceService::class)->record($event, [
            'amount' => 30000, 'received_date' => now()->toDateString(),
        ]);
        $this->estimates->saveDraftLines($estimate->refresh(), [[
            'item_name' => 'Chicken Biryani', 'quantity' => 100, 'rate' => 100,
        ]]);
        app(CateringRefundService::class)->record($event->refresh(), [
            'amount' => 20000, 'refund_date' => now()->toDateString(),
            'reason' => 'Booking reduced', 'payment_method_id' => $this->paymentMethodId,
        ]);

        $sums = DB::connection('tenant')->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c')->first();

        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // P · a pricing method that does nothing must not be offered
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_product_screen_no_longer_offers_a_pricing_method_that_does_nothing(): void
    {
        $html = View::make('tenant.catering.profiles.index', [
            'profiles' => CateringProductProfile::with('product')->paginate(15),
            'units' => \App\Models\Tenant\Unit::all(),
            'search' => '',
        ])->render();

        $this->assertStringNotContainsString('>Per PAX<', $html,
            'a mode no calculation reads must not be presented as a choice');
        $this->assertStringContainsString('How it is priced', $html);
        $this->assertStringContainsString('Quantity', $html,
            'and the screen must say what actually decides the amount');
        $this->assertStringContainsString('lump sum', $html,
            'including the real answer for a charge that does not scale');
    }

    /** The stored column is untouched, so no existing profile changes meaning. */
    public function test_existing_profiles_keep_whatever_pricing_mode_they_were_saved_with(): void
    {
        $profile = CateringProductProfile::where('product_id', $this->productId)->firstOrFail();
        $profile->forceFill(['pricing_mode' => 'per_pax'])->save();

        $this->assertSame('per_pax', $profile->refresh()->pricing_mode,
            'removing the control from the screen must not rewrite anybody\'s data');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CAT-RATE-UX-001 · the two books say what they are
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_cost_rate_screen_no_longer_calls_itself_the_commercial_book(): void
    {
        $html = View::make('tenant.catering.material-rates.index', [
            'latestRates' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15),
            'history' => collect(),
            'units' => collect(),
            'search' => '',
        ])->render();

        $this->assertStringContainsString('Material Cost Rates', $html);
        $this->assertStringContainsString('costs our business', $html);
        $this->assertStringNotContainsString('Commercial quote rates', $html,
            'this screen used to announce itself with the OTHER book\'s name');
        $this->assertStringContainsString('/catering/commercial-rates', $html,
            'and it must point at the book it is not');
    }

    public function test_the_commercial_screen_names_itself_as_the_customer_charge(): void
    {
        $html = View::make('tenant.catering.commercial-rates.index', [
            'rates' => collect(), 'scheduled' => collect(), 'history' => collect(),
            'materials' => collect(), 'units' => collect(),
        ])->render();

        $this->assertStringContainsString('Commercial Charge Rates', $html);
        $this->assertStringContainsString('customer charge', $html);
        $this->assertStringContainsString('never reprices an existing quotation', $html,
            'the one thing an owner most needs to know before touching it');
        $this->assertStringContainsString('/catering/material-rates', $html);
    }
}
