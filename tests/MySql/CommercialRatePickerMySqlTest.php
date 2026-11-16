<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringCommercialRateController;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringProductCostBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * COMMERCIAL-RATE-PICKER-1 (owner, 26 Oct): "Set a commercial rate" — "sirf wo material dikhao jo kahin use ho rahe hon,
 * aage bracket me product count, aur search lagao". Since the purchase items came in (14 Oct) the list held 1,000+
 * materials (A/C FILTER, ACCOUNTS REGISTER…), a handful of them in any dish.
 *
 * The page, rendered through its controller: every material is still in the box, each marked in use or not, with its
 * dish count — the same count the "kin dishes par lag jaye" box shows; the list opens on those in use (the script, walked
 * in the browser); "Show all materials" brings back the rest.
 */
class CommercialRatePickerMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private int $unitId;

    private int $chickenId;

    private int $muttonId;

    private int $filterId;

    private int $dishAId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);
        $this->cleanTenant([
            'catering_commercial_rate_applications', 'catering_estimate_line_cost_blocks', 'catering_estimate_lines',
            'catering_estimates', 'catering_events', 'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates', 'units', 'products', 'categories', 'branches',
        ]);
        $this->makeBranch();
        $category = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $material = fn (string $name, string $sku) => $this->makeProduct($category, [
            'name' => $name, 'sku' => $sku, 'unit_id' => $this->unitId, 'product_kind' => 'raw_material', 'is_sellable' => 0, 'is_pos_visible' => 0,
        ]);
        $this->chickenId = $material('Chicken Regular', 'RM-CHK');      // in two dishes
        $this->muttonId = $material('Mutton', 'RM-MUT');                // a house rate, no dish yet
        $this->filterId = $material('AC Filter', 'OLD-899');            // an imported purchase item, used nowhere
        $this->dishAId = $this->makeProduct($category, ['name' => 'Chicken Karahi', 'sku' => 'D-KAR', 'unit_id' => $this->unitId]);
        $dishB = $this->makeProduct($category, ['name' => 'Chicken Biryani', 'sku' => 'D-BIR', 'unit_id' => $this->unitId]);

        foreach ([[$this->dishAId, CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK], [$dishB, CateringProductCostBlock::SOURCE_MANUAL]] as [$dish, $source]) {
            CateringProductCostBlock::create([
                'product_id' => $dish, 'block_type' => CateringProductCostBlock::TYPE_MATERIAL, 'material_product_id' => $this->chickenId,
                'label' => 'Chicken', 'unit_id' => $this->unitId, 'quantity_per_unit' => 1, 'rate' => 100, 'commercial_rate_source' => $source,
                'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT, 'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
                'sort_order' => 1,
            ]);
        }
        CateringMaterialCommercialRate::create(['product_id' => $this->muttonId, 'rate' => 2400, 'unit_id' => $this->unitId,
            'effective_from' => now()->subWeek()->toDateString()]);
        view()->share('errors', new ViewErrorBag);
    }

    public function test_every_material_is_marked_in_use_or_not_with_its_dish_count(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('#<option value="' . $this->chickenId . '" data-in-use="1" data-dishes="2">Chicken Regular \(RM-CHK\) — 2 dishes</option>#', $html,
            'used by two dishes');
        $this->assertMatchesRegularExpression('#<option value="' . $this->muttonId . '" data-in-use="1" data-dishes="0">Mutton \(RM-MUT\) — no dish yet</option>#', $html,
            'it already has a house rate, so it stays in the list to be changed');
        $this->assertMatchesRegularExpression('#<option value="' . $this->filterId . '" data-in-use="0" data-dishes="0">AC Filter \(OLD-899\)</option>#', $html,
            'used nowhere: still in the box (Show all), marked not in use');
        $this->assertDoesNotMatchRegularExpression('#<option value="' . $this->dishAId . '"#', $html, 'a dish is never a material here');
    }

    public function test_the_count_is_the_one_the_apply_box_shows(): void
    {
        $html = $this->page();
        preg_match("#data-block-counts='([^']*)'#", $html, $m);
        $counts = json_decode(html_entity_decode($m[1] ?? '{}'), true);

        $this->assertSame(2, ($counts[$this->chickenId]['book'] ?? 0) + ($counts[$this->chickenId]['manual'] ?? 0),
            'the box below counts the same two dishes, so the two numbers never disagree');
    }

    public function test_the_picker_searches_opens_on_materials_in_use_and_can_show_all(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('#<input class="form-check-input" type="checkbox" id="rate-show-all">#', $html, 'Show all: there, not ticked');
        $this->assertStringContainsString('Show all materials (also ones no dish uses yet)', $html);
        $this->assertStringContainsString('<select name="product_id" id="rate-material" class="form-select" required>', $html);
        $this->assertStringContainsString("\$('#rate-material')", $html, 'the searchable list');
        $this->assertStringContainsString("getAttribute('data-in-use') === '1'", $html, 'it opens on the materials in use');
        $this->assertStringContainsString("sel.dispatchEvent(new Event('change'))", $html, 'a choice reaches the dish counts and their ticks');
        $this->assertInlineScriptsParse($html, 'commercial rates', 'rate-material');
    }

    private function page(): string
    {
        return app(CateringCommercialRateController::class)->index(Request::create('/catering/commercial-rates', 'GET'))->render();
    }
}
