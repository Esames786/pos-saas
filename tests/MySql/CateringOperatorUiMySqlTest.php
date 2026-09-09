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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
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
        $this->assertStringContainsString('this.select();', $html,
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

    /**
     * PUNCH-NO-FREE-TEXT-1 — a line may only be a dish that exists.
     *
     * This assertion is the REVERSE of the one it replaces, and deliberately so.
     * PUNCH-SEARCH-MATCH-1 demoted the typed term below the real matches, which
     * stopped it hijacking a search, and that test then pinned free text as a
     * feature to be kept. It left the door open: typing an id that does not
     * exist, "5834", still built a line with no product, no unit and rate 0.00.
     * A quotation line with nothing behind it is worse than a refusal, because
     * it looks priced and it reaches the customer. The owner's rule replaced the
     * old one, so the guard replaces it too.
     *
     * BOTH doors are checked. The punch bar and the row picker each built the
     * same unbacked line, and closing one would only move the problem a screen
     * across.
     *
     * It pins markup, which is the honest limit here: existing free-text lines
     * must still save, so the server cannot refuse a line without a product
     * without rewriting history.
     */
    /**
     * PUNCH-TAB-ORDER-1 — Qty, then the rate, then the note, then the breakdown.
     *
     * The Customer rate used to sit almost last in the walk, after every
     * cost-block material row, so an operator agreeing a price tabbed through
     * the entire breakdown to reach the one figure they were changing.
     *
     * Two orders have to agree or a keyboard screen stops being one: what plain
     * Tab does (the DOM) and what Enter does (punchSeq). This checks both.
     */
    public function test_the_rate_is_reached_straight_after_the_quantity(): void
    {
        $html = $this->render($this->booking());

        // The Enter walk.
        $seq = $this->between($html, 'function punchSeq()', 'return seq.filter');
        $qty = strpos($seq, "'punch-qty'");
        $rate = strpos($seq, "'punch-customer-rate'");
        $note = strpos($seq, "'punch-instr'");
        $own = strpos($seq, "'punch-own'");

        $this->assertNotFalse($qty);
        $this->assertNotFalse($rate);
        $this->assertLessThan($rate, $qty, 'Qty comes first');
        $this->assertLessThan($note, $rate, 'the rate is reached BEFORE the note');
        $this->assertLessThan($own, $note, 'and the breakdown comes after both');

        // What plain Tab does — the markup itself, in the same order.
        $bar = $this->between($html, 'id="punch-bar"', 'id="punch-mats"');
        $this->assertLessThan(
            strpos($bar, 'id="punch-customer-rate"'),
            strpos($bar, 'id="punch-qty"'),
            'Tab follows the DOM, so the DOM must agree with the Enter walk'
        );
        $this->assertLessThan(
            strpos($bar, 'id="punch-instr"'),
            strpos($bar, 'id="punch-customer-rate"'),
            'the rate box sits before the note on screen, not after the breakdown'
        );
    }

    /** The slice of $haystack between two markers — for order assertions. */
    private function between(string $haystack, string $from, string $to): string
    {
        $a = strpos($haystack, $from);
        $b = strpos($haystack, $to, $a === false ? 0 : $a);
        $this->assertNotFalse($a, "marker not found: {$from}");
        $this->assertNotFalse($b, "marker not found: {$to}");

        return substr($haystack, $a, $b - $a);
    }

    /**
     * PUNCH-EDIT-SWAP-1 — editing a row and changing its dish REPLACES that row.
     *
     * It used to add a second one and leave the first alone: three identical
     * Chicken Karahi Shanwari lines reached a live quotation that way. Two
     * separate faults, and fixing only the first would have been worse than the
     * duplicate — the row would have kept the old NAME while wearing the new
     * dish's costing.
     *
     *   punchPick        rebuilt the punch from nothing, losing editRow, so
     *                    punchCommit took the "new row" path.
     *   punchCommitEdit  staged quantity, materials and instructions on a saved
     *                    row but never the dish itself.
     */
    public function test_changing_the_dish_while_editing_replaces_the_row(): void
    {
        $html = $this->render($this->booking());

        // The edit survives the pick.
        $this->assertStringContainsString('const editing = punch && punch.editRow ? punch : null', $html,
            'picking a product mid-edit must not forget which row is being edited');
        $this->assertStringContainsString('editRow: editing ? editing.editRow : null', $html,
            'the row identity is carried onto the new punch');

        // And the row is told what it now is.
        $this->assertStringContainsString('if (punch.productChanged) {', $html);
        $this->assertMatchesRegularExpression('/\[product_id\]"\]\'\)\.val\(punch\.productId/', $html,
            'a swapped row must write the NEW product id, or the name and the costing disagree');
        $this->assertStringContainsString('.val(punch.name)', $html,
            'and the new name');
    }

    /**
     * LINE-ORDER-1 — the operator arranges the quotation and the paper follows.
     *
     * This is not a display nicety. `saveDraftLines` writes
     * `sort_order = $index` from the order the lines are POSTED in, and every
     * document reads them back with `orderBy('sort_order')`. So the order of
     * these rows already decided the order on the customer's quotation and the
     * kitchen sheet — there was simply no way to change it.
     *
     * Two things have to be true for that to work, and both are pinned here:
     * a block-costed line's Cost Details row must travel WITH it, or the
     * breakdown ends up under somebody else's dish; and the `lines[i]` indices
     * must be rewritten after a move, because an order that depends on how the
     * browser serialises a form is a promise nobody wrote down.
     */
    public function test_a_row_can_be_moved_and_its_breakdown_moves_with_it(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString('line-up', $html, 'every row can be moved up');
        $this->assertStringContainsString('line-down', $html, 'and down');

        // The first version of this guard NAMED the kinds of row that travel
        // with a line, and passed while an unsaved row left its own breakdown
        // behind — the exact fault it was written for. So the code stopped
        // naming them, and this stopped naming them too: everything up to the
        // next line row belongs to this line.
        $this->assertStringContainsString('$row.nextUntil(\'tr[data-row]\')', $html,
            "a line's breakdown must move with the line — whatever kind it is");
        $this->assertStringContainsString('function renumberLines()', $html,
            'the posted indices are rewritten, so the order is stated rather than inferred');
        $this->assertStringContainsString("'lines[' + position", $html,
            'renumbering rewrites the index each row posts under');
    }

    /**
     * RECALC-ASKS-TO-SAVE-1 — Recalculate must not swallow unsaved work.
     *
     * It recomputes from the SAVED quotation and the workspace is then redrawn
     * from the server's answer, so anything punched but not yet saved simply
     * vanished — no warning, no trace, minutes of typing gone.
     *
     * The listener is registered in the CAPTURE phase on purpose: the ajax
     * pipeline also listens for submit on the document, and this has to be able
     * to stop it before it posts. A bubbling listener would run too late.
     */
    public function test_recalculate_warns_before_it_discards_unsaved_rows(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString('data-reprice', $html,
            'the reprice form is marked so the guard can find it');
        $this->assertStringContainsString('tr.punch-row', $html);
        $this->assertStringContainsString('.punch-staged', $html);
        $this->assertMatchesRegularExpression('/addEventListener\(\s*.submit.,[\s\S]{0,2000}?\}, true\);/', $html,
            'capture phase, or the ajax pipeline posts before the warning can stop it');
    }

    /**
     * STACKED-MATERIAL-ROW-1 (step 1) — the new builder exists and posts the
     * SAME thing as the old one.
     *
     * The whole safety of this rebuild rests on one claim: it changes how a line
     * is ENTERED and nothing else. The server, the block authorities, the
     * costing and the documents must not be able to tell which builder drew the
     * row. So what is pinned here is the PAYLOAD, not the appearance.
     *
     * Step 1 deliberately does not switch anything over. punchRowHtml is still
     * the builder in use, so this can ship without changing what anyone sees.
     */
    public function test_the_stacked_builder_posts_exactly_what_the_old_one_posts(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString('function punchStackedRowHtml(', $html);

        // The material payload, field for field. THREE places build it — the
        // old row builder, this new one, and punchCommitEdit which stages the
        // same fields onto a saved row. That duplication is itself worth pinning:
        // a fourth copy appearing unnoticed is exactly how the shape drifts.
        foreach (['[label]', '[kg]', '[rate]', '[cust]'] as $field) {
            $this->assertSame(
                3,
                substr_count($html, "+ p + '".$field.'"'),
                "all three builders must post materials{$field} — the server cannot be able to tell them apart"
            );
        }

        // A material the dish may not take from the customer still posts its
        // zero: a disabled input is never submitted, and a missing one leaves
        // whatever the server held before.
        $this->assertStringContainsString('a disabled input is not submitted', $html,
            'the reason the zero is posted is written down where it will be read');

        // Step 1 changes nothing the operator sees.
        $this->assertStringContainsString('punchRowHtml(idx, qty, punchLineCalc(qty))', $html,
            'the old builder is still the one in use until step 4');
    }

    /**
     * STACKED-MATERIAL-ROW-1 (step 2) — the OWN/PARTY switch is gone.
     *
     * It asked again for something the item already states. Worse, it had to
     * ZERO the customer shares whenever the operator switched back to OWN — a
     * fix for a real bug (a hidden number that still billed nothing), but a fix
     * that only existed because the switch created the hidden state in the first
     * place.
     *
     * Now each material carries its own Party box, open whenever the ITEM allows
     * party supply. There is no mode, so there is nothing to unwind.
     */
    public function test_party_is_decided_by_the_item_not_by_a_switch(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringNotContainsString("punch.mode !== 'PARTY' ? 'disabled'", $html,
            'the Party box must not be gated by a mode switch');
        $this->assertStringContainsString("if (punch.party) seq.push(r.find('.pm-cust')", $html,
            'and Tab must reach the box whenever it is on screen');
        $this->assertStringContainsString("\$('#punch-seg-wrap').addClass('d-none');", $html,
            'the switch is never shown');
    }

    /**
     * STACKED-MATERIAL-ROW-1 (step 3) — the owner's columns, and the classes kept.
     *
     * Material · Rate · Required Qty · Own · Party. Required is the recipe's
     * answer at this quantity and is NOT a field: typing over it would only hide
     * the difference between what the dish needs and what we actually send.
     *
     * The input classes are unchanged, and that is the load-bearing part. Every
     * handler, the Enter walk and the totals already speak to pm-rate, pm-own
     * and pm-cust, so keeping them is what makes this a rearrangement of what is
     * seen rather than a rewrite of what happens.
     */
    public function test_the_material_columns_match_the_owners_layout(): void
    {
        $html = $this->render($this->booking());

        foreach (['>Material<', '>Rate<', '>Required Qty<', '>Own<'] as $heading) {
            $this->assertStringContainsString($heading, $html, "the breakdown is headed {$heading}");
        }

        foreach (['pm-rate', 'pm-own', 'pm-cust'] as $class) {
            $this->assertStringContainsString($class, $html,
                "{$class} must survive the rearrangement — every handler speaks to it");
        }

        $this->assertStringContainsString('pm-req', $html);
        $this->assertStringContainsString('this.textContent = punchFmt(qty * punch.mats[+this.dataset.i].ratio)', $html,
            'required moves when the quantity moves');
    }

    /**
     * PUNCH-WALK-VISIBLE-1 — Enter walks what the operator can see.
     *
     * The punch is a keyboard flow, and the walk is a LIST of elements built by
     * punchSeq(). A list is a memory of what was put on screen, and memories go
     * stale: hiding the OWN/PARTY switch while leaving #punch-own in the list
     * trapped the caret on Instructions. focus() on a hidden element does
     * nothing at all — activeElement never moved, the next Enter recomputed the
     * same index and tried the same hidden button, and the material rows became
     * unreachable by keyboard. A disabled Party box stalls it the same way.
     *
     * So the walk stopped trusting the list and started asking each element
     * whether it is on screen and usable. That rule cannot go stale.
     */
    public function test_the_enter_walk_only_visits_fields_the_operator_can_use(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString(
            'seq.filter(el => el && ! el.disabled && el.offsetParent !== null)', $html,
            'the walk must skip anything hidden or disabled, or one Enter can trap the operator');
        $this->assertStringNotContainsString('return seq.filter(Boolean);', $html,
            'filtering only nulls is what let a HIDDEN button stay in the walk');

        // Every way into the punch bar — picking an item, editing a saved row,
        // editing an unsaved one — lands on Qty with the number selected.
        $this->assertSame(3, substr_count($html, ".trigger('focus').trigger('select')"),
            'each way into the punch bar must land on Qty, selected');
    }

    /**
     * PUNCH-REQUIRED-QTY-1 — Required Qty is the answer for THIS quantity.
     *
     * punchRenderMats() reads #punch-qty to work out each material's Required
     * figure, and both edit paths set the quantity AFTER rendering — so the live
     * screen showed "Required 15 KG" beside an Own of 42 on a 28 KG dish: the
     * previous quantity's answer, sitting under the current one's numbers.
     */
    public function test_required_quantity_is_rendered_after_the_quantity_is_known(): void
    {
        $html = $this->render($this->booking());

        $this->assertSame(2, substr_count($html, "\$('#punch-qty').val(qty);\n        punchRenderMats();"),
            'both edit paths must set the quantity before the materials render');
        $this->assertSame(0, substr_count($html, "punchRenderMats();\n        \$('#punch-customer-rate').val(punch.currentQuotedRate);\n        \$('#punch-qty').val(qty).trigger"),
            'nothing may render the materials and then set the quantity');
    }

    /**
     * ROW-DRAG-1/2/3 — a row can be picked up, and it moves the way the arrows
     * move it.
     *
     * WHAT THIS GUARD CANNOT DO, said plainly: dragging happens in a browser,
     * and no assertion here can drag anything. What it CAN prove is the thing
     * that matters — that drag is a second way to perform the SAME move, not a
     * second implementation of it. Both paths move `lineGroup()` and then call
     * `renumberLines()`.
     *
     * That is not a technicality. The printed quotation reads this order back —
     * `sort_order` follows the POSTED sequence — so an order computed in two
     * places is a customer's paper that can disagree with the screen.
     *
     * Two live faults are pinned here because both shipped:
     *   ROW-DRAG-2  dragstart refused whenever a punch was open, and a cancelled
     *               dragstart becomes a text selection — the row never moved.
     *   ROW-DRAG-3  the drop indicator was painted onto a line's COLLAPSED Cost
     *               Details row, whose bounding rect is all zeros.
     */
    public function test_a_row_can_be_dragged_and_it_moves_exactly_as_the_arrows_do(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString("+ '<span class=\"line-drag", $html,
            'the row the punch bar builds carries the handle');
        $this->assertSame(
            substr_count($html, 'data-row="s') + 1,
            substr_count($html, 'class="line-drag'),
            'one handle per saved row, plus the one the punch builder writes'
        );
        $this->assertStringContainsString('draggable="true"', $html);

        // Drop never fires unless dragover cancels the default.
        $this->assertStringContainsString("\$(document).on('dragover', '#lines-body > tr'", $html);
        $this->assertMatchesRegularExpression(
            "/on\('dragover'[\s\S]{0,400}?e\.preventDefault\(\)/", $html,
            'without preventDefault on dragover the browser never fires a drop at all');

        // ROW-DRAG-2 — never refuse the drag.
        $start = $this->between($html, "on('dragstart', '.line-drag'", "on('dragover'");
        $this->assertStringNotContainsString('if (punch) { e.preventDefault(); return; }', $start,
            'a drag must never be refused — a cancelled dragstart becomes a text selection');

        // ROW-DRAG-3 — the indicator follows only what can be seen.
        $over = $this->between($html, "on('dragover', '#lines-body > tr'", "on('drop'");
        $this->assertStringContainsString('filter(function () { return this.offsetParent !== null; })', $over,
            'only rows the operator can see may define where a line begins and ends');
        $this->assertStringNotContainsString('group.last()[0].getBoundingClientRect()', $over,
            "measuring the group's hidden last row is what broke the indicator");

        // The same two authorities the arrows use, and no third one.
        $drop = $this->between($html, "on('drop', '#lines-body > tr'", "on('dragend'");
        $this->assertStringContainsString('lineGroup(moving)', $drop,
            'the whole line moves — its breakdown with it');
        $this->assertStringContainsString('renumberLines();', $drop,
            'and the posted indices are rewritten, exactly as after an arrow');
        $this->assertStringNotContainsString('sort_order', $drop,
            'the browser must not invent an order of its own');

        // The index an edit is holding is repaired after EVERY move — which
        // covers the arrows, where the hazard was never guarded.
        $renumber = $this->between($html, 'function renumberLines()', "\$(document).on('click', '.line-up, .line-down'");
        $this->assertStringContainsString('if (punch && punch.editRow)', $renumber,
            'an edit in flight must have its row index repaired after any move');
        $this->assertStringContainsString('punch.editIdx = at[1]', $renumber);

        // A handle the browser can treat as text starts a selection, not a drag.
        $this->assertStringContainsString('user-select: none', $html,
            'the handle must not be selectable as text');

        // The arrows are the addition's companion, never its casualty: they are
        // the only way that works on a touch screen or from a keyboard.
        $this->assertStringContainsString('line-up', $html);
        $this->assertStringContainsString('line-down', $html);
    }

    public function test_an_item_that_does_not_exist_cannot_be_entered(): void
    {
        $html = $this->render($this->booking());

        // Exactly ONE tagging select may survive on this page: the CUSTOMER
        // picker, where typing a name nobody has yet is the entire point. Both
        // PRODUCT selects must be free of it. Counting is the precise test —
        // a blanket "not contains" would also forbid the customer picker.
        $this->assertSame(1, substr_count($html, 'tags: true'),
            'only the customer picker may create by typing; a product must exist');
        $this->assertStringNotContainsString('createTag:', $html,
            'the punch bar must not manufacture an item from what was typed');
        $this->assertStringNotContainsString('type a custom item', $html,
            'and the placeholder must not invite what the bar now refuses');
    }

    /**
     * SAVE-REJECTION-VISIBLE-1 — a refused save must look refused.
     *
     * The workspace posts by fetch and used to treat every non-ok response the
     * same way: reload the booking. For 419 and 500 that is right. For 422 it
     * threw away the only thing that mattered — the reason — and re-rendered
     * the booking from the database, so a REJECTED Save Estimate looked exactly
     * like a successful one that had saved nothing. A client lost a whole
     * quotation to it on 8 September (POST /catering/estimates/1 → 422, 11:43).
     *
     * Two halves, and the JS branch is worthless without the second: the page
     * must handle 422 separately, and the server must actually answer an XHR
     * save with a JSON 422 carrying `errors`.
     */
    public function test_a_rejected_save_is_shown_to_the_operator_not_reloaded_away(): void
    {
        $html = $this->render($this->booking());

        $this->assertStringContainsString('r.status === 422', $html,
            'a validation refusal must be handled apart from 419/500');
        $this->assertStringContainsString('body.errors', $html,
            "and the server's reason is what the operator is told");
    }

    public function test_the_server_answers_an_ajax_save_with_a_json_422_and_reasons(): void
    {
        $event = $this->booking();

        // A line the punch bar can produce when the operator never completes it:
        // a name, and nothing else.
        $request = \Illuminate\Http\Request::create(
            '/catering/estimates/'.$event->currentEstimate->id,
            'PUT',
            ['lines' => [['item_name' => 'Chicken Karahi']]]
        );
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        // What a browser fetch() actually sends. Request::create() injects a
        // NAVIGATION Accept header (text/html,…) which no fetch ever sends, and
        // under that header Laravel redirects instead of answering JSON — so the
        // page could never read the reason. The production 422 proves the real
        // request looks like this one.
        $request->headers->set('Accept', '*/*');

        // This is the condition Laravel uses to answer with JSON 422 instead of
        // redirecting back — the whole 422 branch in the page depends on it.
        $this->assertTrue($request->expectsJson(),
            'an XHR save must be answered in JSON, or the page can never read the reason');

        try {
            app(\App\Http\Controllers\Tenant\Catering\CateringEstimateController::class)
                ->update($request, $event->currentEstimate);
            $this->fail('an incomplete line must not be accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(422, $e->status);
            $errors = $e->errors();
            $this->assertArrayHasKey('lines.0.quantity', $errors,
                'the operator is told WHICH field, not just that something failed');
            $this->assertArrayHasKey('lines.0.rate', $errors);
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
