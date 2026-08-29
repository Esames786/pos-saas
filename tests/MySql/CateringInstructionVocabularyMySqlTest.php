<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEstimateLine;
use App\Models\Tenant\CateringInstruction;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use Database\Seeders\Tenant\CateringInstructionVocabularySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-CATERING-INSTRUCTIONS-2 — the client's 55-label vocabulary, loaded
 * safely.
 *
 * The seed is idempotent by Roman label, additive-only, and NEVER a mouth for
 * the machine: an entry the Owner deactivated stays deactivated on rerun, a
 * label an operator already selected keeps its pivot rows, and the legacy
 * spellings ("Tommoto", "Bry Naam") are preserved verbatim because they are
 * what the client's staff recognize. No finance, no stock, no document
 * mutation — vocabulary only, proven below.
 */
class CateringInstructionVocabularyMySqlTest extends MySqlTenantTestCase
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

        $this->cleanTenant([
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
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
        CateringMaterialRate::create([
            'product_id' => $this->productId, 'rate' => 900, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
    }

    private function seedVocabulary(): void
    {
        (new CateringInstructionVocabularySeeder)->run();
    }

    public function test_a_fresh_seed_loads_exactly_the_55_legacy_labels_with_urdu(): void
    {
        $before = [
            DB::connection('tenant')->table('journal_lines')->count(),
            DB::connection('tenant')->table('stock_ledgers')->count(),
        ];

        $this->seedVocabulary();

        $this->assertSame(55, CateringInstruction::count());
        $this->assertSame(55, count(CateringInstructionVocabularySeeder::VOCABULARY));
        $this->assertSame(55, CateringInstruction::where('is_active', true)->count(), 'everything starts active');
        $this->assertSame(0, CateringInstruction::whereNull('label_ur')->orWhere('label_ur', '')->count(),
            'every entry carries its Urdu script');

        // The legacy spellings survive verbatim — they are recognition anchors.
        foreach (['Tommoto Kam', 'Lal Mirch Bry Naam', 'Gosht Gala Huwa Ho', 'Chawal Dana Dana', 'Koyala', 'Golden'] as $label) {
            $this->assertTrue(CateringInstruction::where('label', $label)->exists(), "missing legacy label: {$label}");
        }
        // "Return" on the legacy popup was a BUTTON, not an instruction.
        $this->assertFalse(CateringInstruction::where('label', 'Return')->exists());

        $this->assertSame($before, [
            DB::connection('tenant')->table('journal_lines')->count(),
            DB::connection('tenant')->table('stock_ledgers')->count(),
        ], 'vocabulary only — no finance, no stock');
    }

    public function test_rerunning_duplicates_nothing_and_never_overrules_the_owner(): void
    {
        $this->seedVocabulary();

        // The Owner deactivates one entry and renames nothing.
        CateringInstruction::where('label', 'Golden')->update(['is_active' => false]);

        $this->seedVocabulary();

        $this->assertSame(55, CateringInstruction::count(), 'rerun adds nothing');
        $this->assertSame(1, CateringInstruction::where('label', 'Golden')->count(), 'no duplicate row');
        $this->assertFalse((bool) CateringInstruction::where('label', 'Golden')->value('is_active'),
            'a seeder must never reactivate what a person switched off');
    }

    public function test_selections_survive_a_reseed_and_reach_the_kitchen_summary(): void
    {
        $this->seedVocabulary();

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Vocabulary Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 60,
        ]);

        $picked = CateringInstruction::whereIn('label', ['Chawal Dana Dana', 'Mirch Kam', 'Namak Kam', 'Oil Kam', 'Koyala'])
            ->pluck('id')->all();

        $this->estimates->saveDraftLines($event->currentEstimate, [[
            'product_id' => $this->productId, 'item_name' => 'Chicken Karahi',
            'quantity' => 8, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 900,
            'instruction_ids' => $picked,
            'instructions' => 'client demo note',
        ]]);

        $line = CateringEstimateLine::firstOrFail();
        $this->assertCount(5, $line->managedInstructions);

        $summary = $line->instructionSummary();
        foreach (['Chawal Dana Dana', 'Mirch Kam', 'Namak Kam', 'Oil Kam', 'Koyala', 'client demo note'] as $piece) {
            $this->assertStringContainsString($piece, $summary);
        }

        // A reseed (Urdu/ordering refresh) leaves the operator's selections alone.
        $this->seedVocabulary();
        $this->assertCount(5, $line->refresh()->managedInstructions);
    }

    public function test_the_production_command_is_allowlisted_to_kashif_only(): void
    {
        $src = file_get_contents(app_path('Console/Commands/CateringSeedInstructionsCommand.php'));

        $this->assertStringContainsString("private const ALLOWED_TENANTS = ['kashifkitchen'];", $src,
            'exactly one production tenant may receive this seed — and it is not Khatri');
        $this->assertStringContainsString('in_array($code, self::ALLOWED_TENANTS, true)', $src);
    }
}
