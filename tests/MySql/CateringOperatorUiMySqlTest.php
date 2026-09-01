<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-OPERATOR-UI-1 — the booking workspace tells the truth while
 * the quotation is still a DRAFT.
 *
 * The owner's production walkthrough found Phase B's UI unreachable on the one
 * screen drafts actually use: the draft builder showed a single editable "Rate"
 * (which the server rightly ignored for block lines — a screen that lies), no
 * Cost Details, and the freeze action still wore its mechanism's name, "Mark
 * Sent / Lock". These tests pin the repaired presentation and the lifecycle
 * ordering around it.
 */
class CateringOperatorUiMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private int $branchId;

    private int $biryaniId;

    private int $chickenId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        // The screen renders behind @can; the render test is about MARKUP, not
        // authorization (authz has its own suites), so the gate says yes.
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true); // nullable => guests pass too

        $this->cleanTenant([
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId,
        ]);
        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $this->chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->unitId, 'rate' => 100,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 1,
        ]);
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Making',
            'block_type' => CateringProductCostBlock::TYPE_CHARGE,
            'charge_role' => CateringProductCostBlock::ROLE_MAKING,
            'rate' => 300, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'sort_order' => 2,
        ]);
    }

    private function booking(): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Walkthrough Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 50,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [
            [
                'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
                'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
            ],
            [
                'product_id' => null, 'item_name' => 'Mineral Water',
                'quantity' => 4, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 120,
            ],
        ]);

        return $event->refresh();
    }

    private function render(CateringEvent $event): string
    {
        $event->load([
            'currentEstimate.lines.costBlocks',
            'currentEstimate.lines.managedInstructions',
            'advances', 'refunds', 'productionReleases', 'finalInvoice',
        ]);
        $finance = app(CateringFinancialPositionService::class);

        return View::make('tenant.catering.events.show', [
            'event' => $event,
            'units' => \App\Models\Tenant\Unit::where('is_active', true)->get(['id', 'code', 'name']),
            'branches' => \App\Models\Tenant\Branch::query()->get(),
            'bookedDates' => [],
            'activeInstructions' => \App\Models\Tenant\CateringInstruction::active()->ordered()->get(),
            'profileMap' => collect(),
            'paymentMethods' => collect(),
            'costingReadiness' => null,
            'printers' => collect(),
            'position' => $finance->position($event),
            'headline' => $finance->headline($event),
            'ledger' => $finance->ledger($event),
        ])->render();
    }

    private function blockLine(CateringEvent $event): CateringEstimateLine
    {
        return $event->currentEstimate->lines()->where('product_id', $this->biryaniId)->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The draft screen shows the Phase B truth.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_draft_shows_calculated_and_quoted_apart_with_cost_details(): void
    {
        $event = $this->booking();
        $html = $this->render($event);

        $this->assertStringContainsString('System Rate', $html);
        $this->assertStringContainsString('Customer Rate', $html);
        $this->assertStringContainsString('Cost Details', $html);
        // chicken 0.5 x 100 + making 300 = 350/KG for the block line
        $this->assertStringContainsString('350.00', $html);
        // KASHIF-CLIENT-MENU-5: the row edits the quoted rate LIVE, through the
        // same override/use-calculated authorities — never a dead input.
        $this->assertStringContainsString('quoted-live', $html);
        $this->assertStringContainsString('data-act-quote=', $html);
        $this->assertStringContainsString('live-reason', $html,
            'a different rate still demands its reason, right on the row');

        // KASHIF-ORDER-PUNCH §B2: the guided punch bar on the draft builder —
        // item, Qty, the Party-ya-Own question, and the material stepper mount.
        $this->assertStringContainsString('punch-bar', $html);
        $this->assertStringContainsString('punch-item', $html);
        $this->assertStringContainsString('Supply split', $html);
        $this->assertStringContainsString('punch-live-rate', $html, 'price per selling unit is visible before row save');
        $this->assertStringContainsString('punch-live-amount', $html, 'line amount is visible before row save');
        $this->assertStringContainsString('customerRateTouched', $html,
            'fresh rows follow system rate until an operator deliberately overrides it');
        $this->assertStringContainsString("h('item_name_ur', punch.nameUr", $html,
            'punched rows preserve the product Urdu name for customer and kitchen documents');
        $this->assertStringContainsString("this.select();", $html,
            'customer rate focus selects the existing number for one-keystroke replacement');
        $this->assertStringContainsString('event-booking-details', $html,
            'customer and event detail is compact and expandable');
        $this->assertStringContainsString('Quotation Total', $html);
        $this->assertStringContainsString('punch-edit', $html, 'saved rows expose an explicit edit action');
        $this->assertStringContainsString('clearPunchInstructions', $html,
            'new/cancelled punches clear managed instructions and the free note');
        $this->assertStringContainsString('loadPunchInstructions', $html,
            'editing a saved row loads that row\'s own instructions');
        $this->assertStringContainsString("unitCode: p.unit_code || '—'", $html,
            'the sold item unit comes from the profile, never its first material');

        // KASHIF-LEGACY-ALIGN-2: the old software's one-glance strip sits on
        // every row's Cost Details — computed from the SAME snapshot the table
        // below shows, read-only by design.
        $this->assertStringContainsString('Order Rate', $html);
        $this->assertStringContainsString('Making Chrg', $html);
        $this->assertStringContainsString('Chicken Rate', $html,
            'the headline material box carries the material\'s own name, never a fixed "Meat Rate"');

        $line = $this->blockLine($event);
        $this->assertStringContainsString(
            'type="hidden" name="lines[0][rate]"', $html,
            'the block line submits its rate as a hidden field — never an editable box the server ignores'
        );
        $this->assertStringContainsString('cost-details-'.$line->id, $html,
            'the Cost Details panel is inline on the draft, not a separate screen');

        // The free-text legacy line keeps an editable rate and a calculated dash.
        $this->assertStringContainsString('class="form-control form-control-sm text-end line-rate"', $html);
        $this->assertStringContainsString('not priced from cost blocks', $html);
        $this->assertStringContainsString("h('rate_action', rateIntent)", $html,
            'Ctrl+Enter stages an explicit block-rate decision for Ctrl+S');
        $this->assertStringContainsString('applyPunchedQuotedRates',
            file_get_contents(app_path('Http/Controllers/Tenant/Catering/CateringEstimateController.php')),
            'Save Estimate applies the staged decision through the quoted-rate authority');
    }

    public function test_punch_customer_rate_survives_save_and_reload_for_a_block_line(): void
    {
        $event = $this->booking();
        $estimate = $event->currentEstimate->refresh();
        $block = $this->blockLine($event);
        $plain = $estimate->lines()->whereNull('product_id')->firstOrFail();

        $request = Request::create('/catering/estimates/'.$estimate->id, 'POST', [
            'lines' => [
                [
                    'line_uuid' => $block->line_uuid,
                    'product_id' => $block->product_id,
                    'item_name' => $block->item_name,
                    'quantity' => 10,
                    'unit_id' => $block->unit_id,
                    'rate' => 300,
                    'rate_action' => 'override',
                    'rate_override_reason' => 'Customer agreed rate entered in order punch',
                ],
                [
                    'line_uuid' => $plain->line_uuid,
                    'item_name' => $plain->item_name,
                    'quantity' => 4,
                    'unit_id' => $plain->unit_id,
                    'rate' => 120,
                ],
            ],
        ]);

        app(\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class)
            ->update($request, $estimate);

        $block->refresh();
        $this->assertSame(300.0, round((float) $block->rate, 2));
        $this->assertSame(3000.0, round((float) $block->amount, 2));
        $this->assertSame('Customer agreed rate entered in order punch', $block->rate_override_reason);

        $html = $this->render($event->refresh());
        $this->assertStringContainsString('value="300.00"', $html,
            'the agreed customer rate remains after the workspace reloads');
    }

    public function test_cost_details_offers_the_draft_actions_from_the_snapshot(): void
    {
        $event = $this->booking();
        $html = $this->render($event);

        // The actions post through [data-act] (built forms) — real nested <form>
        // tags are silently dropped by HTML inside the estimate form, which is
        // exactly why this panel could never appear on the draft screen before.
        $this->assertStringContainsString('data-act=', $html);
        $this->assertStringContainsString('/customer-supplied', $html);
        // KASHIF-COSTPANEL-SIMPLE-1: two plain questions per material — kitchen
        // needs how much, and of that the customer brings how much. The two
        // share boxes are LINKED; the panel carries NO duplicate rate control.
        $this->assertStringContainsString('Total kitchen', $html);
        $this->assertStringContainsString('Party dega', $html);
        $this->assertStringContainsString('Hum denge', $html);
        $this->assertStringContainsString('supply-split', $html);
        $this->assertStringContainsString('change rate', $html, 'the part\'s system rate is changeable right beside it');
        $this->assertStringContainsString('/rate', $html);
        $this->assertStringContainsString('Recipe says', $html);
        $this->assertStringNotContainsString('Quote a different rate', $html,
            'ONE rate control — the row\'s Quoted Rate box; the panel never duplicates it');
        // KASHIF-ORDER-PUNCH §A: Complimentary is an ITEM flag now — the row
        // carries NO trigger link; a flagged item lands at zero by itself.
        $this->assertStringNotContainsString('Complimentary?', $html);
        $this->assertStringContainsString('our_supplied_qty', $html);
        $this->assertStringNotContainsString('rate_basis</', $html,
            'schema words stay out of the operator screen');
    }

    public function test_a_complimentary_line_shows_its_badge_and_the_way_back(): void
    {
        $event = $this->booking();
        $line = $this->blockLine($event);

        // The legacy flag is nothing but a zero quote through the one override
        // authority — reason recorded, margins still counting the real cost.
        $this->lineBlocks->overrideQuotedRate($line, 0.0, 'Complimentary item');

        $html = $this->render($event->refresh());

        $this->assertStringContainsString('Complimentary · اعزازی', $html);
        $this->assertStringContainsString('Charge it instead', $html);
    }

    public function test_a_quoted_override_shows_its_reason_and_the_way_back(): void
    {
        $event = $this->booking();
        $line = $this->blockLine($event);

        $this->lineBlocks->overrideQuotedRate($line, 500.0, 'wedding package agreed rate');

        $html = $this->render($event->refresh());

        $this->assertStringContainsString('wedding package agreed rate', $html);
        $this->assertStringContainsString('agreed rate', $html);
        // The way back lives in the SAME row control: typing the calculated
        // figure puts the line back on the calculation. The panel says so.
        $this->assertStringContainsString('puts the line back on the calculation', $html);
        $this->assertStringContainsString('500.00', $html);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lifecycle wording and ordering.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_freeze_action_is_a_business_action_not_a_mechanism(): void
    {
        $event = $this->booking();
        $html = $this->render($event);

        $this->assertStringContainsString('Finalize Quotation', $html);
        $this->assertStringNotContainsString('Mark Sent / Lock', $html);
        $this->assertStringContainsString('Print Quotation', $html);
        $this->assertStringNotContainsString('Send quotation to printer', $html);
    }

    public function test_confirm_booking_waits_for_a_finalized_quotation(): void
    {
        $event = $this->booking();

        // The screen: no green Confirm while the quotation is an editable draft.
        $html = $this->render($event);
        $this->assertStringNotContainsString('>Confirm Booking</button>', $html);
        $this->assertStringContainsString('Finalize the quotation to confirm', $html);

        // The server refuses too — the button's absence must not be the guard.
        try {
            $this->estimates->confirmEvent($event);
            $this->fail('confirm must refuse a draft quotation');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still a draft', $e->getMessage());
        }
        $this->assertSame(CateringEvent::STATUS_DRAFT, $event->refresh()->status);

        // Finalize → accept → confirm is the sanctioned road, and it works.
        $estimate = $event->currentEstimate;
        $this->estimates->markSent($estimate);
        $this->estimates->markAccepted($estimate->refresh());

        $html = $this->render($event->refresh());
        $this->assertStringContainsString('>Confirm Booking</button>', $html);

        $this->estimates->confirmEvent($event->refresh());
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->refresh()->status);
    }

    public function test_a_booking_with_no_estimate_still_confirms_as_before(): void
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'No Estimate Yet',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(2)->toDateString(),
            'pax' => 20,
        ]);
        // Simulate the historical shape: an event whose estimate row is absent,
        // already moved past inquiry the way a real old booking would have been.
        $event->currentEstimate?->lines()->delete();
        $event->currentEstimate?->delete();
        $event->forceFill(['status' => CateringEvent::STATUS_DRAFT])->save();

        $this->estimates->confirmEvent($event->refresh());
        $this->assertSame(CateringEvent::STATUS_CONFIRMED, $event->refresh()->status);
    }

    public function test_the_sent_view_keeps_cost_details_and_stays_immutable(): void
    {
        $event = $this->booking();
        $this->estimates->markSent($event->currentEstimate);
        $event = $event->refresh();

        $html = $this->render($event);
        $this->assertStringContainsString('Cost Details', $html);
        $this->assertStringNotContainsString('Finalize Quotation', $html);
        $this->assertStringContainsString('Customer Accepted', $html);

        $line = $this->blockLine($event);
        try {
            $this->lineBlocks->overrideQuotedRate($line, 999.0, 'too late');
            $this->fail('a sent quotation must refuse a rate override');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    public function test_the_workspace_posts_in_place_instead_of_reloading(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString('id="event-workspace"', $html,
            'the swappable region every action re-renders into');
        $this->assertStringContainsString('cateringAjaxSubmit', $html,
            'the in-place submit pipeline is on the page');
        $this->assertStringContainsString('window.cateringAjaxSubmit(box.data(', $html,
            'Cost Details actions post through the pipeline, not by navigating');
        $this->assertStringContainsString('window.initEstimateBuilder', $html,
            'the builder re-initialises after each swap');
    }

    public function test_previewing_the_document_finalizes_nothing(): void
    {
        $event = $this->booking();
        $estimate = $event->currentEstimate->load('lines');

        $html = View::make('tenant.catering.documents.estimate', [
            'estimate' => $estimate,
            'event' => $event,
            'lang' => 'en',
            'position' => app(CateringFinancialPositionService::class)->position($event),
            'advanceTotal' => 0.0,
            'businessName' => 'Test Kitchen',
        ])->render();

        $this->assertStringContainsString('DRAFT — NOT YET ISSUED', $html,
            'a draft print is unmistakably a draft');
        $this->assertSame('draft', $estimate->refresh()->status,
            'looking at a document is not agreeing to it');
    }
}
