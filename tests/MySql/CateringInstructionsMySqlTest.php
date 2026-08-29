<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringInstruction;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-INSTRUCTIONS-1 — a managed kitchen vocabulary instead of four
 * spellings of "mirch kam".
 *
 * The vocabulary is master data the Owner records (never seeded — the
 * authoritative list comes from the client). A booking line multi-selects from
 * it and may keep a free note beside the selections; a revision carries both;
 * the production release snapshots both as TEXT so the kitchen sheet stays
 * readable whatever later happens to the vocabulary. Historical lines that only
 * ever had free text keep meaning exactly what they said.
 */
class CateringInstructionsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $productId;

    private int $unitId;

    private CateringInstruction $mirchKam;

    private CateringInstruction $danaDana;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

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

        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'unit_id' => $this->unitId]);
        CateringProductProfile::updateOrCreate(
            ['product_id' => $this->productId],
            ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'recipe']
        );
        // Send-readiness fails closed without an effective Catering rate.
        \App\Models\Tenant\CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 900, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->mirchKam = CateringInstruction::create(['label' => 'Mirch Kam', 'label_ur' => 'مرچ کم', 'sort_order' => 1]);
        $this->danaDana = CateringInstruction::create(['label' => 'Chawal Dana Dana', 'label_ur' => 'چاول دانہ دانہ', 'sort_order' => 2]);
    }

    private function draftLine(array $lineOverrides = []): CateringEstimateLine
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Instruction Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(4)->toDateString(),
            'pax' => 40,
        ]);

        $this->estimates->saveDraftLines($event->currentEstimate, [array_merge([
            'product_id' => $this->productId, 'item_name' => 'Chicken Karahi',
            'quantity' => 8, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ], $lineOverrides)]);

        return $event->refresh()->currentEstimate->lines()->firstOrFail();
    }

    public function test_a_line_multi_selects_and_keeps_its_free_note(): void
    {
        $line = $this->draftLine([
            'instruction_ids' => [$this->mirchKam->id, $this->danaDana->id],
            'instructions' => 'oil thora sa',
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->mirchKam->id, $this->danaDana->id],
            $line->managedInstructions()->pluck('catering_instructions.id')->all()
        );
        $this->assertSame('oil thora sa', $line->instructions);
        $this->assertSame('Mirch Kam, Chawal Dana Dana — oil thora sa', $line->instructionSummary());
    }

    public function test_a_save_without_the_key_leaves_selections_alone(): void
    {
        $line = $this->draftLine(['instruction_ids' => [$this->mirchKam->id]]);

        // An ordinary re-save (venue changed, say) that never mentions
        // instruction_ids — an API caller that predates the feature.
        $this->estimates->saveDraftLines($line->estimate, [[
            'line_uuid' => $line->line_uuid,
            'product_id' => $this->productId, 'item_name' => 'Chicken Karahi',
            'quantity' => 9, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
        ]]);

        $this->assertSame([$this->mirchKam->id],
            $line->refresh()->managedInstructions()->pluck('catering_instructions.id')->all(),
            'a payload without the key must not wipe a selection someone made');

        // And an explicit empty selection DOES clear.
        $this->estimates->saveDraftLines($line->estimate, [[
            'line_uuid' => $line->line_uuid,
            'product_id' => $this->productId, 'item_name' => 'Chicken Karahi',
            'quantity' => 9, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
            'instruction_ids' => [],
        ]]);
        $this->assertSame([], $line->refresh()->managedInstructions()->pluck('catering_instructions.id')->all());
    }

    public function test_a_historical_free_text_line_reads_unchanged(): void
    {
        $line = $this->draftLine(['instructions' => 'gosht gala hua ho']);

        $this->assertSame('gosht gala hua ho', $line->instructionSummary(),
            'no selections → the note comes through verbatim, no separator noise');
    }

    public function test_a_revision_carries_the_selections(): void
    {
        $line = $this->draftLine([
            'instruction_ids' => [$this->danaDana->id],
            'instructions' => 'koyala zaroor',
        ]);
        $estimate = $line->estimate;
        $this->estimates->markSent($estimate);

        $revision = $this->estimates->revise($estimate->refresh());
        $copied = $revision->lines()->firstOrFail();

        $this->assertSame([$this->danaDana->id],
            $copied->managedInstructions()->pluck('catering_instructions.id')->all());
        $this->assertSame('koyala zaroor', $copied->instructions);
    }

    public function test_the_kitchen_sheet_snapshot_prints_selections_and_note_as_text(): void
    {
        $line = $this->draftLine([
            'instruction_ids' => [$this->mirchKam->id],
            'instructions' => 'serve garam',
        ]);
        $event = $line->estimate->event;
        $this->estimates->markSent($line->estimate);
        $this->estimates->markAccepted($line->estimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        $release = app(CateringProductionReleaseService::class)->release($event->refresh());
        $releaseLine = $release->lines()->firstOrFail();

        $this->assertStringContainsString('Mirch Kam', $releaseLine->instructions);
        $this->assertStringContainsString('serve garam', $releaseLine->instructions);

        // The snapshot is TEXT: renaming the vocabulary later must not rewrite
        // what the kitchen was told that night.
        $this->mirchKam->update(['label' => 'Kam Mirch']);
        $this->assertStringContainsString('Mirch Kam', $releaseLine->refresh()->instructions);
    }

    public function test_deactivation_hides_from_new_selection_but_deletes_nothing(): void
    {
        $line = $this->draftLine(['instruction_ids' => [$this->mirchKam->id]]);

        $this->mirchKam->update(['is_active' => false]);

        $this->assertSame([$this->danaDana->id],
            CateringInstruction::active()->ordered()->pluck('id')->all(),
            'inactive entries leave the selectable vocabulary');
        $this->assertSame([$this->mirchKam->id],
            $line->refresh()->managedInstructions()->pluck('catering_instructions.id')->all(),
            'lines already carrying the entry keep it');
    }
}
