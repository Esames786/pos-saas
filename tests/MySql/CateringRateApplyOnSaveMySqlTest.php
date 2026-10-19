<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringCommercialRateController;
use App\Models\Tenant\CateringCommercialRateApplication;
use App\Models\Tenant\CateringMaterialCommercialRate;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Services\Catering\CateringCommercialRateImpactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * RATE-APPLY-ON-SAVE-1 — 28 September.
 *
 * Malik: "rate change karte he do checkbox dedo ya all dedo, k click karne pe
 * sub pe apply hogae."
 *
 * Pehle rate LIKHNA aur rate LAGANA do alag safhe thay, aur doosra safha itna
 * lamba tha (230 qatarein, koi pagination nahi) ke us ka asal button neeche dab
 * jata tha. Malik ka natija: "commercial rate badalne se kuch hota hi nahi."
 * Hota tha — bas wo button nazar nahi aata tha.
 *
 * DO TICK, EK NAHI. "Jo pehle se house rate par hain" aur "jo haath se likhi
 * gayi hain" alag rehte hain, kyunke doosra ek aisa faisla palat deta hai jo
 * kisi ne soch kar liya tha. Prod par is ka wazan naapa gaya: Chicken Regular
 * ki 98 me se 98 dishes ka rate GIRTA hai, ek bhi nahi barhti. Aisi cheez
 * default par nahi rakhi jati.
 */
class CateringRateApplyOnSaveMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringCommercialRateImpactService $impact;

    private int $unitId;

    private int $otherUnitId;

    private int $chickenId;

    private int $followingDishId;

    private int $manualDishId;

    private int $perDishDishId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_commercial_rate_applications',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'catering_material_rates', 'catering_material_commercial_rates',
            'units', 'products', 'categories', 'branches',
        ]);

        $this->impact = app(CateringCommercialRateImpactService::class);
        $this->makeBranch();
        $categoryId = $this->makeCategory();

        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->otherUnitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'PH', 'name' => 'Piece', 'unit_type' => 'quantity',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        CateringMaterialRate::create([
            'product_id' => $this->chickenId, 'rate' => 80, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        CateringMaterialCommercialRate::create([
            'product_id' => $this->chickenId, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->followingDishId = $this->makeProduct($categoryId, ['name' => 'Follows Dish', 'sku' => 'D-FOL', 'unit_id' => $this->unitId]);
        $this->manualDishId = $this->makeProduct($categoryId, ['name' => 'Manual Dish', 'sku' => 'D-MAN', 'unit_id' => $this->unitId]);
        $this->perDishDishId = $this->makeProduct($categoryId, ['name' => 'Per Dish Unit', 'sku' => 'D-PDU', 'unit_id' => $this->unitId]);

        $this->block($this->followingDishId, [
            'rate' => 100, 'commercial_rate_source' => CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK,
        ]);
        $this->block($this->manualDishId, [
            'rate' => 250, 'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL,
        ]);
        // Dish ke unit par qeemat — ye house rate ko follow kar hi nahi sakti,
        // kyunke house rate MATERIAL ke unit ki baat karta hai.
        $this->block($this->perDishDishId, [
            'rate' => 900, 'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL,
            'rate_basis' => CateringProductCostBlock::RATE_PER_DISH_UNIT,
        ]);
    }

    // ── Kuch tick na ho to kuch na ho ───────────────────────────────────────

    /**
     * Purana bartaao zinda rahe.
     *
     * "Rate likhna qeemat badalna nahi" is poore nizam ki buniyad hai. Naya
     * chunav us buniyad ke ooper hai, us ki jagah nahi — aur agar ye test kal
     * gir gaya to matlab rate likhna khud-ba-khud qeematein hilane laga.
     */
    public function test_recording_a_rate_with_nothing_ticked_still_reprices_nothing(): void
    {
        $this->recordRate(150);

        $this->assertSame(150.0, $this->bookRate(), 'rate to darj hona chahiye');
        $this->assertSame(100.0, $this->blockRate($this->followingDishId), 'magar dish ka rate waise ka waisa');
        $this->assertSame(250.0, $this->blockRate($this->manualDishId));
    }

    // ── Pehla tick: jo pehle se follow karti hain ───────────────────────────

    public function test_ticking_the_first_box_moves_only_the_dishes_that_already_follow(): void
    {
        $this->recordRate(150, ['apply_following' => 1]);

        $this->assertSame(150.0, $this->blockRate($this->followingDishId), 'follow karne wali dish naye rate par');
        $this->assertSame(250.0, $this->blockRate($this->manualDishId), 'haath wali ko chhua tak nahi');
        $this->assertSame(
            CateringProductCostBlock::SOURCE_MANUAL,
            $this->blockOf($this->manualDishId)->commercial_rate_source,
            'aur wo haath par hi rahi'
        );
    }

    // ── Doosra tick: haath wali bhi ────────────────────────────────────────

    /**
     * JORNA AUR RATE LAGANA EK HI AMAL HAI, do nahi.
     *
     * Sab se aasan ghalti ye hoti ke conversion sirf `commercial_rate_source`
     * badal deta aur rate ko haath na lagata. Tab screen par likha hota "ye
     * dish house rate par hai" aur us ka rate PURANA hota — do jagah do jawab,
     * aur koi test us par na bolta. Is liye yahan dono cheezein ek saath
     * jaanchi jati hain: source bhi, aur rate bhi.
     */
    public function test_ticking_the_second_box_links_the_hand_set_dishes_and_puts_them_on_the_new_rate(): void
    {
        $this->recordRate(150, ['apply_manual' => 1]);

        $block = $this->blockOf($this->manualDishId);
        $this->assertSame(CateringProductCostBlock::SOURCE_COMMERCIAL_BOOK, $block->commercial_rate_source,
            'haath wali dish ab house rate par');
        $this->assertSame(150.0, (float) $block->rate,
            'aur NAYE rate par — jorna kaafi nahi, rate bhi pohnchna chahiye');
    }

    /** Jo follow kar hi nahi sakti, usay zabardasti na jora jaye. */
    public function test_a_dish_priced_per_dish_unit_is_left_alone_even_when_everything_is_ticked(): void
    {
        $this->recordRate(150, ['apply_following' => 1, 'apply_manual' => 1]);

        $block = $this->blockOf($this->perDishDishId);
        $this->assertSame(CateringProductCostBlock::SOURCE_MANUAL, $block->commercial_rate_source,
            'dish ke unit par qeemat house rate ko follow kar hi nahi sakti');
        $this->assertSame(900.0, (float) $block->rate,
            'aur us ka rate chhua nahi jata — 900 per dish ko 150 per KG kehna ek ghalat adad likhna hai');
    }

    // ── Jo pehra narm NAHI hua ─────────────────────────────────────────────

    /**
     * `applyToProducts` ab bhi manual block ko nahi chhoota.
     *
     * Naya raasta banate waqt sab se aasan ghalti ye hoti ke purane method ka
     * pehra narm kar diya jata — phir form me ek id likh dene se koi bhi
     * haath se tay kiya hua rate mit sakta tha. Is liye conversion ka apna
     * alag, sarih naam wala method hai, aur ye test us faisle ka pehra hai.
     */
    public function test_the_old_apply_still_refuses_a_hand_set_block_handed_to_it_directly(): void
    {
        $manualBlockId = $this->blockOf($this->manualDishId)->id;

        $applied = $this->impact->applyToProducts($this->chickenId, [$manualBlockId], null);

        $this->assertSame(0, $applied, 'id form me likh dene se manual rate nahi mit sakta');
        $this->assertSame(250.0, $this->blockRate($this->manualDishId));
    }

    /** Aur jis material ka house rate hi na ho, us par jorna mana hai. */
    public function test_a_material_with_no_house_rate_cannot_link_anything(): void
    {
        $categoryId = $this->makeCategory(['name' => 'Beef Cat']);
        $beefId = $this->makeProduct($categoryId, [
            'name' => 'Beef', 'sku' => 'RM-BEEF', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $dishId = $this->makeProduct($categoryId, ['name' => 'Beef Dish', 'sku' => 'D-BEEF', 'unit_id' => $this->unitId]);
        $this->block($dishId, [
            'rate' => 500, 'material_product_id' => $beefId,
            'commercial_rate_source' => CateringProductCostBlock::SOURCE_MANUAL,
        ]);

        // Prod par yehi haalat hai: Beef aur Mutton par 114 dishes hain aur
        // dono ka house rate mojood hi nahi. Khamoshi se kuch na karne se
        // behtar hai saaf mana kar dena.
        $this->expectException(RuntimeException::class);
        $this->impact->linkManualBlocks($beefId, null);
    }

    // ── Hisaab-kitaab ──────────────────────────────────────────────────────

    /** Har conversion log me darj ho — warna kal koi nahi bata sakega kis ne kya kiya. */
    public function test_every_conversion_is_written_down_with_the_rate_it_replaced(): void
    {
        $this->recordRate(150, ['apply_manual' => 1]);

        $row = CateringCommercialRateApplication::query()
            ->where('action', CateringCommercialRateApplication::ACTION_BLOCK_LINKED)
            ->latest('id')->first();

        $this->assertNotNull($row, 'jorne ka amal log me hona chahiye');
        $this->assertSame(250.0, (float) $row->old_commercial_rate, 'purana rate');
        $this->assertSame(150.0, (float) $row->new_commercial_rate, 'naya rate');
    }

    /** Ginti wohi ho jo checkbox ke saath likhi jati hai. */
    public function test_the_counts_behind_the_checkboxes_are_the_real_ones(): void
    {
        $counts = $this->impact->blockSourceCounts();

        $this->assertSame(1, $counts[$this->chickenId]['book'], 'ek dish pehle se follow karti hai');
        $this->assertSame(2, $counts[$this->chickenId]['manual'], 'do haath se likhi hain');

        // Aur ginti jorne ke baad hilni chahiye, warna safha jhoot bolta rahega.
        $this->recordRate(150, ['apply_manual' => 1]);
        $after = $this->impact->blockSourceCounts();

        $this->assertSame(2, $after[$this->chickenId]['book'], 'ek aur book par aa gayi');
        $this->assertSame(1, $after[$this->chickenId]['manual'], 'aur per-dish wali haath par hi rahi');
    }

    /**
     * "Sab par" ka nishana safhe par mojood ho.
     *
     * Ye tick sirf JS me zinda hai, is liye yahan wohi jaanch hai jo maine
     * apni pehli ghalti ke baad rakhi thi: har id ka zikr do jagah hona
     * chahiye — markup me aur us ke handler me. Ek hi zikr ka matlab hai ke
     * ya to khana safhe par nahi, ya usay chalane wala koi nahi.
     */
    public function test_the_master_tick_has_something_to_tick(): void
    {
        $blade = file_get_contents(
            resource_path('views/tenant/catering/commercial-rates/index.blade.php')
        );

        foreach (['apply-all', 'apply-following', 'apply-manual', 'rate-apply-choices'] as $id) {
            $this->assertGreaterThan(1, substr_count($blade, $id),
                "'{$id}' ka zikr markup aur handler dono me hona chahiye");
        }

        // Aur wo dono asli khane hi utha-gira sake — naam se, kisi andaze se nahi.
        $this->assertStringContainsString("querySelectorAll('input[name^=apply_]')", $blade,
            'master tick dono checkbox ko unke naam se dhoondta hai');
        $this->assertStringContainsString('name="apply_following"', $blade);
        $this->assertStringContainsString('name="apply_manual"', $blade);
    }

    /**
     * DEFAULT ON HAI, IS LIYE SAVE PAR CONFIRM LAZMI HAI.
     *
     * Malik ne do dafa kaha: "all already check ho." Wo un ka faisla hai. Magar
     * ek tick jo pehle se laga ho wo dekha nahi jata — aur yahan us ka matlab
     * ek click me 98 qeematein hai, jin me se prod par 98 ki 98 NEECHE jati
     * hain. Is liye confirm ka hona utna hi lazmi hai jitna tick ka.
     *
     * Ye test us JORE ka pehra hai: agar kal koi confirm hata de aur tick
     * default on chhor de, to ye bolega.
     */
    public function test_the_boxes_are_pre_ticked_and_saving_asks_first(): void
    {
        $blade = file_get_contents(
            resource_path('views/tenant/catering/commercial-rates/index.blade.php')
        );

        foreach (['id="apply-all" checked', 'id="apply-manual" checked', 'id="apply-following" checked'] as $on) {
            $this->assertStringContainsString($on, $blade, "'{$on}' — tick pehle se laga hona chahiye");
        }

        $this->assertStringContainsString("form.addEventListener('submit'", $blade,
            'save par pehle poochha jana chahiye');
        $this->assertStringContainsString('e.preventDefault()', $blade,
            'aur "nahi" kehne par ruk bhi jaye');
        $this->assertStringContainsString('modal-xl', $blade, 'modal chaura ho');
    }

    /**
     * Server ka qanoon UI ke default se ALAG hai, aur alag hi rehna chahiye.
     *
     * Checkbox ka pehle se laga hona sirf safhe ki baat hai. Agar jhanda na
     * aaye to kuch nahi hota — warna koi aur raasta (API, purana form, test)
     * chupke se qeematein hila deta. Ye test upar wale
     * `..._with_nothing_ticked_...` ka jora hai: wo bartaao jaanchta hai, ye
     * ye baat ke wo bartaao UI ke default ke bawajood zinda hai.
     */
    public function test_the_ui_default_does_not_become_the_server_default(): void
    {
        $this->recordRate(150);

        $this->assertSame(100.0, $this->blockRate($this->followingDishId),
            'jhanda na ho to safhe ka tick kuch nahi karta');
        $this->assertSame(250.0, $this->blockRate($this->manualDishId));
    }

    /**
     * BAND HAI, MAGAR KHALI NAHI.
     *
     * Malik ne chunav wale hisse par laal cross laga kar likha "COLLAPSED". Wo
     * band ho gaya — magar KUL GINTI collapse ke BAHAR rakhi gayi hai, taake
     * band haalat me bhi safha ye bata sake ke save kitni dishes ko chhuega.
     *
     * Ginti bhi andar daal dena ek satar kam likhna tha aur ghalat hota: phir
     * ye chhupana nahi, mitana hota — aur ek pehle se laga hua tick jiska koi
     * adad nazar na aaye, wo chunav nahi, andaza hai.
     */
    public function test_collapsing_the_choices_does_not_hide_how_many_dishes_move(): void
    {
        $blade = file_get_contents(
            resource_path('views/tenant/catering/commercial-rates/index.blade.php')
        );

        $this->assertStringContainsString('id="apply-which"', $blade, 'chunav collapse me hon');

        // Ginti aur "Sab par" dono us collapse se PEHLE aayen — yani us ke bahar.
        $collapse = mb_strpos($blade, 'id="apply-which"');
        foreach (['data-count="total"' => 'kul ginti', 'id="apply-all"' => '"Sab par" ka tick'] as $needle => $what) {
            $this->assertLessThan($collapse, mb_strpos($blade, $needle),
                "{$what} collapse ke BAHAR hona chahiye — warna band haalat me safha khamosh hai");
        }
    }

    /**
     * Material chunte hi tick WAPAS lag jaye.
     *
     * Ye mera apna keeda tha: ginti sifar hone par tick hata diya jata tha aur
     * material chunne par dobara nahi lagta tha — yani operator material
     * chunta aur sab khane khali mil te, jab ke markup me "checked" likha hua
     * tha. Markup sach bolta tha, chalta hua safha nahi.
     */
    public function test_choosing_a_material_puts_the_ticks_back(): void
    {
        $blade = file_get_contents(
            resource_path('views/tenant/catering/commercial-rates/index.blade.php')
        );

        $this->assertStringContainsString('input.checked = n > 0;', $blade,
            'ginti aane par tick wapas lagna chahiye, sirf hatna nahi');
        $this->assertStringNotContainsString('if (n === 0) input.checked = false;', $blade,
            'purana ek-tarfa qaida wapas na aaye');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function block(int $dishId, array $attrs): CateringProductCostBlock
    {
        return CateringProductCostBlock::create(array_merge([
            'product_id' => $dishId,
            'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
            'material_product_id' => $this->chickenId,
            'label' => 'Chicken',
            'unit_id' => $this->unitId,
            'quantity_per_unit' => 1,
            'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
            'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
            'sort_order' => 1,
        ], $attrs));
    }

    /** Asal controller, asal request — wohi raasta jo browser chalata hai. */
    private function recordRate(float $rate, array $extra = []): void
    {
        app(CateringCommercialRateController::class)->store(Request::create(
            '/catering/commercial-rates', 'POST', array_merge([
                'product_id' => $this->chickenId,
                'rate' => $rate,
                'unit_id' => $this->unitId,
                'effective_from' => now()->toDateString(),
            ], $extra)
        ));
    }

    private function bookRate(): float
    {
        return (float) CateringMaterialCommercialRate::where('product_id', $this->chickenId)
            ->orderByDesc('effective_from')->orderByDesc('id')->value('rate');
    }

    private function blockOf(int $dishId): CateringProductCostBlock
    {
        return CateringProductCostBlock::where('product_id', $dishId)->firstOrFail();
    }

    private function blockRate(int $dishId): float
    {
        return (float) $this->blockOf($dishId)->rate;
    }
}
