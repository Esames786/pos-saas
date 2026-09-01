<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEventController;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-EVENT-ACTIONS-1 + KASHIF-URDU-CARRY-1.
 *
 * Two client-reported gaps, guarded together because they are the same
 * complaint from opposite ends: work the operator could not REACH from the
 * events list, and an Urdu name the kitchen sheet could not SHOW.
 *
 * The list's Actions menu never writes a status itself — it posts to the same
 * lifecycle authority the event screen uses — so what this test pins is which
 * steps are OFFERED, not what they do.
 */
class CateringEventActionsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $productId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_event_revisions', 'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->productId = $this->makeProduct($categoryId, [
            'name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId,
        ]);

        // The quotation gate refuses to send without a costed basis, so the
        // dish carries a real rate — this test is about reach and Urdu, not
        // about defeating the costing rule.
        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        // The Urdu name lives where the product book keeps it — nowhere else.
        DB::connection('tenant')->table('product_translations')->insert([
            'product_id' => $this->productId, 'language_code' => 'ur',
            'name' => 'چکن بریانی', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function booking(): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Actions Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 50,
        ]);

        // NOTE the payload carries NO item_name_ur — exactly the punch screen's
        // shape, which is how the blank Urdu reached production in the first place.
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);

        return $event->refresh();
    }

    /**
     * A booking whose dish needs 5 KG of chicken, 2 of which the customer is
     * bringing — the exact shape the client's split-supply lines take.
     */
    private function splitSupplyBooking(): CateringEvent
    {
        $chickenId = $this->makeProduct(
            DB::connection('tenant')->table('categories')->value('id'),
            ['name' => 'Chicken', 'sku' => 'RM-CHK-'.uniqid(), 'unit_id' => $this->unitId,
                'product_kind' => 'raw_material', 'is_stock_tracked' => true]
        );
        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $chickenId, 'rate' => 400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        \App\Models\Tenant\CateringProductProfile::updateOrCreate(
            ['product_id' => $this->productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
        \App\Models\Tenant\CateringProductCostBlock::create([
            'product_id' => $this->productId, 'label' => 'Chicken',
            'block_type' => \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $chickenId, 'quantity_per_unit' => 0.5,
            'unit_id' => $this->unitId, 'rate' => 400,
            'charge_basis' => \App\Models\Tenant\CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => \App\Models\Tenant\CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 1,
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Split Supply Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 50,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'quantity' => 10, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);

        $line = $event->refresh()->currentEstimate->lines->first();
        $block = $line->costBlocks->filter->isMaterial()->first();
        $block->forceFill(['customer_supplied_qty' => 2])->save();

        return $event->refresh();
    }

    private function listHtml(): string
    {
        $response = app(CateringEventController::class)->index(Request::create('/catering/events', 'GET'));

        return $response->render();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The list can reach the work.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_quoted_booking_offers_its_quotation_and_the_next_lawful_step(): void
    {
        $event = $this->booking();
        $this->estimates->markSent($event->currentEstimate);
        $event->refresh();

        $html = $this->listHtml();
        $estimateId = $event->currentEstimate->id;

        $this->assertStringContainsString("/catering/documents/estimate/{$estimateId}", $html,
            'the quotation an operator opens the booking to print');
        $this->assertStringContainsString("/catering/events/{$event->id}/confirm", $html,
            'confirming is the next step a quoted booking allows');
        $this->assertStringContainsString("/catering/events/{$event->id}/production-releases", $html,
            'a sent quotation is releasable to the kitchen');
        $this->assertStringContainsString("/catering/events/{$event->id}/cancel", $html);

        // Closure is NOT offered: the finance authority would refuse it here, and
        // a button whose only outcome is an error is worse than no button.
        $this->assertStringNotContainsString("/catering/events/{$event->id}/close", $html);
        $this->assertStringNotContainsString("/catering/events/{$event->id}/final-invoice", $html);
    }

    public function test_a_draft_booking_offers_no_release_because_the_quotation_is_not_sent(): void
    {
        $event = $this->booking();

        $html = $this->listHtml();

        $this->assertStringNotContainsString("/catering/events/{$event->id}/production-releases", $html,
            'releasing production off a draft quotation is exactly what the service refuses');
    }

    public function test_a_released_booking_links_its_production_release_and_kitchen_sheet(): void
    {
        $event = $this->booking();
        $this->estimates->markSent($event->currentEstimate);
        $release = app(CateringProductionReleaseService::class)->release($event->refresh(), null);

        $html = $this->listHtml();

        $this->assertStringContainsString("/catering/production-releases/{$release->id}", $html,
            'the release page the client could not reach from the list');
        $this->assertStringContainsString("/catering/documents/kitchen-sheet/{$release->id}", $html);
        $this->assertStringContainsString($release->release_no, $html);
    }

    public function test_a_cancelled_booking_offers_no_lifecycle_action(): void
    {
        $event = $this->booking();
        $event->forceFill(['status' => CateringEvent::STATUS_CANCELLED])->save();

        $html = $this->listHtml();

        $this->assertStringNotContainsString("/catering/events/{$event->id}/confirm", $html);
        $this->assertStringNotContainsString("/catering/events/{$event->id}/cancel", $html);
        $this->assertStringNotContainsString("/catering/events/{$event->id}/production-releases", $html);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The Urdu name reaches the kitchen.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_estimate_line_takes_its_urdu_name_from_the_product_book(): void
    {
        $event = $this->booking();

        $this->assertSame('چکن بریانی', $event->currentEstimate->lines->first()->item_name_ur,
            'the punch payload carries no Urdu, so the product book must supply it');
    }

    public function test_the_production_release_line_carries_the_urdu_name(): void
    {
        $event = $this->booking();
        $this->estimates->markSent($event->currentEstimate);
        $release = app(CateringProductionReleaseService::class)->release($event->refresh(), null);

        $this->assertSame('چکن بریانی', $release->lines->first()->item_name_ur,
            'the kitchen sheet prints Urdu only if the snapshot HAS Urdu');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // KASHIF-KITCHEN-MATERIALS-1 — the kitchen sheet says what each dish takes.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_release_snapshots_what_each_dish_takes_and_who_brings_it(): void
    {
        $event = $this->splitSupplyBooking();
        $this->estimates->markSent($event->currentEstimate);
        $release = app(CateringProductionReleaseService::class)->release($event->refresh(), null);

        $mats = $release->lines->first()->materials_snapshot;

        $this->assertIsArray($mats);
        $this->assertCount(1, $mats);
        $this->assertSame('Chicken', $mats[0]['name']);
        // The kitchen cooks the WHOLE requirement whoever carried it in.
        $this->assertEqualsWithDelta(5.0, $mats[0]['qty'], 0.001);
        $this->assertEqualsWithDelta(3.0, $mats[0]['ours'], 0.001);
        $this->assertEqualsWithDelta(2.0, $mats[0]['customer'], 0.001);
        $this->assertSame('split', $mats[0]['supply']);
    }

    public function test_the_kitchen_sheet_prints_the_same_material_sentence_as_the_quotation(): void
    {
        $event = $this->splitSupplyBooking();
        $this->estimates->markSent($event->currentEstimate);
        $release = app(CateringProductionReleaseService::class)->release($event->refresh(), null);

        $sheet = View::make('tenant.catering.documents.kitchen-sheet', [
            'release' => $release->load(['lines', 'event']),
            'lang' => 'en',
            'businessName' => 'Kashif Kitchen',
        ])->render();

        $quotation = View::make('tenant.catering.documents.estimate', [
            'estimate' => $event->refresh()->currentEstimate->load('lines.costBlocks'),
            'event' => $event,
            'lang' => 'en',
            'businessName' => 'Kashif Kitchen',
            'position' => app(\App\Services\Catering\CateringFinancialPositionService::class)->position($event),
        ])->render();

        $sentence = 'Chicken 5 KG (us 3, customer 2)';

        $this->assertStringContainsString($sentence, $quotation,
            "the customer's copy already said this");
        $this->assertStringContainsString($sentence, $sheet,
            'and the kitchen must read the SAME sentence — one arithmetic, or the two papers disagree');
    }

    public function test_a_release_frozen_before_the_snapshot_existed_still_prints(): void
    {
        $event = $this->splitSupplyBooking();
        $this->estimates->markSent($event->currentEstimate);
        $release = app(CateringProductionReleaseService::class)->release($event->refresh(), null);

        // Exactly what the 8 lines already on production look like.
        DB::connection('tenant')->table('catering_production_release_lines')
            ->where('catering_production_release_id', $release->id)
            ->update(['materials_snapshot' => null]);

        $sheet = View::make('tenant.catering.documents.kitchen-sheet', [
            'release' => $release->fresh()->load(['lines', 'event']),
            'lang' => 'en',
            'businessName' => 'Kashif Kitchen',
        ])->render();

        $this->assertStringContainsString('Chicken Biryani', $sheet, 'the sheet still renders');
        $this->assertStringNotContainsString('(us', $sheet, 'it just has nothing to say about materials');
    }

    public function test_bulk_kitchen_sheets_explains_itself_instead_of_throwing(): void
    {
        // The bulk pages open in a NEW TAB, where a framework error page is the
        // only thing the operator sees — for a situation where nothing is wrong.
        $event = $this->booking();

        $response = app(\App\Http\Controllers\Tenant\Catering\CateringBulkDocumentController::class)
            ->kitchenSheets(Request::create('/catering/documents/bulk/kitchen-sheets', 'GET', ['ids' => [$event->id]]));

        $this->assertSame(422, $response->getStatusCode());
        $html = $response->getContent();
        $this->assertStringContainsString('No kitchen sheet yet', $html);
        $this->assertStringContainsString($event->event_no, $html, 'the booking is named, not silently dropped');
        $this->assertStringContainsString('Release production', $html, 'and the way forward is stated');
    }

    public function test_an_explicit_urdu_name_is_never_overwritten_by_the_product_book(): void
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Explicit Urdu',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 20,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Biryani',
            'item_name_ur' => 'خاص بریانی',
            'quantity' => 5, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);

        $this->assertSame('خاص بریانی', $event->refresh()->currentEstimate->lines->first()->item_name_ur,
            'what the operator typed outranks the catalogue');
    }
}
