<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEventRevision;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringEventHistoryService;
use App\Services\Catering\CateringLineCostBlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-EVENT-HISTORY-1/2 — the booking's memory, and the way back.
 *
 * The properties worth protecting: history is APPEND-ONLY (a revert writes a
 * new row, never edits one); a restored quotation version is byte-equal to
 * the original while the original stays untouched; a checkpoint revert brings
 * back header, lines, quantities, supply splits and agreed rates through the
 * NORMAL pipelines; and no revert of any kind moves money.
 */
class CateringEventHistoryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringLineCostBlockService $lineBlocks;

    private CateringEventHistoryService $history;

    private int $branchId;

    private int $biryaniId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_event_revisions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->lineBlocks = app(CateringLineCostBlockService::class);
        $this->history = app(CateringEventHistoryService::class);

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId]);
        $chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        CateringMaterialRate::create([
            'product_id' => $chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->biryaniId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks']
        );
        CateringProductCostBlock::create([
            'product_id' => $this->biryaniId, 'label' => 'Chicken',
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $chickenId, 'quantity_per_unit' => 0.5,
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

    private function booking(float $qty = 10)
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'History Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(7)->toDateString(),
            'pax' => 100,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => $qty, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);

        return $event->refresh();
    }

    private function ledgers(): array
    {
        $db = DB::connection('tenant');

        return [$db->table('journal_lines')->count(), $db->table('stock_ledgers')->count()];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Remembering
    // ─────────────────────────────────────────────────────────────────────────

    public function test_saves_become_checkpoints_with_a_human_summary(): void
    {
        $event = $this->booking();

        $first = $this->history->record($event, 'created');
        $this->assertNotNull($first);
        $this->assertSame('Booking created', $first->change_summary);

        // Nothing changed → no noise row.
        $this->assertNull($this->history->record($event, 'lines_saved'));

        $event->fill(['pax' => 250])->save();
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => 15, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);

        $second = $this->history->record($event->refresh(), 'lines_saved');
        $this->assertNotNull($second);
        $this->assertStringContainsString('PAX 100→250', $second->change_summary);
        $this->assertStringContainsString('Chicken Biryani 10→15 KG', $second->change_summary);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Restoring a quotation version (Phase B)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_restoring_a_superseded_version_copies_it_forward_and_touches_nothing_behind(): void
    {
        $before = $this->ledgers();

        $event = $this->booking(10);
        $q1 = $event->currentEstimate;
        $this->estimates->markSent($q1->refresh());

        $q2 = $this->estimates->revise($q1->refresh());
        $this->estimates->saveDraftLines($q2, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => 25, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
        $this->estimates->markSent($q2->refresh());

        $q3 = $this->estimates->restoreVersion($q1->refresh());

        $this->assertSame(3, (int) $q3->version_no);
        $this->assertTrue($q3->isDraft());
        $this->assertStringContainsString('Restored from Q1', (string) $q3->notes);

        // The restored draft IS Q1's content: same line, same quantity, same
        // block snapshots, same money.
        $q1->refresh();
        $line3 = $q3->lines()->first();
        $line1 = $q1->lines()->first();
        $this->assertSame((float) $line1->quantity, (float) $line3->quantity);
        $this->assertSame((float) $line1->rate, (float) $line3->rate);
        $this->assertSame(
            $line1->costBlocks()->count(),
            $line3->costBlocks()->count(),
        );
        $this->assertSame((float) $q1->grand_total, (float) $q3->grand_total);

        // Q2 stepped aside; Q1 is untouched history.
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q2->refresh()->status);
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $q1->status);
        $this->assertSame(25.0, (float) $q2->lines()->first()->quantity, 'the superseded version keeps its own story');

        // A current (non-superseded) version refuses to be "restored".
        $this->expectException(\RuntimeException::class);
        $this->estimates->restoreVersion($q3->refresh());

        $this->assertSame($before, $this->ledgers());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reverting to a checkpoint (Phase C)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_checkpoint_revert_round_trips_header_lines_splits_and_agreed_rates(): void
    {
        $before = $this->ledgers();

        $event = $this->booking(10);
        $line = $event->currentEstimate->lines->first();

        // The state worth coming back to: partial supply + an agreed rate.
        $chicken = $this->lineBlocks->snapshotsFor($line)->firstWhere('label', 'Chicken');
        $this->lineBlocks->setCustomerSupplied($chicken, false, 2.0);
        $this->lineBlocks->overrideQuotedRate($line->refresh(), 320.0, 'package deal');

        $checkpoint = $this->history->record($event->refresh(), 'lines_saved');
        $this->assertNotNull($checkpoint);

        // Everything changes afterwards.
        $event->fill(['pax' => 500, 'venue' => 'Somewhere Else'])->save();
        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => 40, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 0,
        ]]);
        $afterChange = $this->history->record($event->refresh(), 'lines_saved');
        $this->assertNotNull($afterChange);

        $rowsBefore = CateringEventRevision::count();
        $this->history->revertTo($checkpoint);

        $event->refresh();
        $this->assertSame(100, (int) $event->pax, 'header came back');
        $this->assertNull($event->venue);

        $estimate = CateringEstimate::where('catering_event_id', $event->id)->orderByDesc('version_no')->first();
        $line = $estimate->lines()->first();
        $this->assertSame(10.0, (float) $line->quantity);
        $this->assertSame(320.0, (float) $line->rate, 'the agreed rate came back');
        $this->assertSame('package deal', $line->rate_override_reason);

        $chicken = $this->lineBlocks->snapshotsFor($line)->firstWhere('label', 'Chicken');
        $this->assertEqualsWithDelta(2.0, $chicken->suppliedQty(), 0.001, 'the supply split came back');
        $this->assertEqualsWithDelta(3.0, $chicken->ourStockRequirement(), 0.001);

        // Append-only: the revert ADDED a row; the checkpoint row is untouched.
        $this->assertSame($rowsBefore + 1, CateringEventRevision::count());
        $this->assertSame('reverted', CateringEventRevision::orderByDesc('id')->first()->action);
        // assertEquals, not assertSame: MySQL's JSON type re-orders object keys,
        // so the claim is CONTENT equality — the state itself never changed.
        $this->assertEquals(
            $checkpoint->snapshot,
            $checkpoint->refresh()->snapshot,
            'history is never rewritten'
        );

        // And no revert of any kind moves money or stock.
        $this->assertSame($before, $this->ledgers());
    }

    public function test_reverting_past_a_sent_quotation_creates_a_new_version_never_an_edit(): void
    {
        $event = $this->booking(10);
        $checkpoint = $this->history->record($event, 'created');

        $this->estimates->markSent($event->currentEstimate->refresh());
        $sentId = $event->currentEstimate->id;

        $this->history->revertTo($checkpoint);

        $sent = CateringEstimate::find($sentId);
        $this->assertSame(CateringEstimate::STATUS_SUPERSEDED, $sent->status,
            'the sent version stepped aside — it was not edited');
        $this->assertSame(10.0, (float) $sent->lines()->first()->quantity);

        $current = CateringEstimate::where('catering_event_id', $event->id)->orderByDesc('version_no')->first();
        $this->assertTrue($current->isDraft());
        $this->assertGreaterThan(1, (int) $current->version_no);
    }
}
