<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringSettingController;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringSetting;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KITCHEN-SHEET-REQUIREMENTS-TOGGLE-1 — 26 September.
 *
 * Client: kitchen sheet ke neeche "Consolidated Raw Material Requirements
 * (planning)" har baar chhapti hai, aur bawarchi us se kuch nahi karta — wo
 * sirf jagah leti hai. Maang: ek switch, aur "by default off".
 *
 * Do baatein parkhi ja rahi hain, aur doosri pehli se zyada ahem hai:
 *
 *   1. Default BAND ho — kyunke "by default off" hi maanga gaya tha, aur ek
 *      aisa hissa jo koi nahi parhta band hi hona chahiye.
 *   2. Switch WAQAI asar kare — setting me sach likh dena aasan hai, us se
 *      kaghaz par table ka aana alag baat hai.
 */
class CateringKitchenRequirementsToggleMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            // Ye singleton hai; chhora to ek test ka switch agle test ke liye —
            // aur agle RUN ke liye bhi — chalta reh jata hai.
            'catering_settings',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Maang yehi thi: band. */
    public function test_it_is_off_on_a_brand_new_tenant(): void
    {
        $this->assertFalse((bool) CateringSetting::tenantDefault()->show_kitchen_requirements);
    }

    /** Aur band hone ka matlab: table kaghaz par hai hi nahi. */
    public function test_the_table_is_not_printed_by_default(): void
    {
        $html = $this->kitchenSheet();

        $this->assertStringNotContainsString('Consolidated Raw Material Requirements', $html);
        $this->assertStringNotContainsString('class="req"', $html);

        // Probe ko pehle khud ko zinda sabit karna chahiye: agar sheet hi khali
        // aa rahi ho to upar wale dono assert bemani hain.
        $this->assertStringContainsString('Dish 1', $html, 'sheet khud to bani hai');
    }

    /** Khol dein to aa jati hai — wohi table, usi jagah. */
    public function test_switching_it_on_prints_the_table(): void
    {
        CateringSetting::tenantDefault()->update(['show_kitchen_requirements' => true]);

        $html = $this->kitchenSheet();

        $this->assertStringContainsString('Consolidated Raw Material Requirements', $html);
        $this->assertStringContainsString('class="req"', $html);
    }

    /**
     * Settings screen se bhi — aur khaas kar WAPAS band ho sake.
     *
     * Bina tick wala checkbox KUCH post nahi karta, to agar controller sirf
     * $data me dekhta to switch ek baar khulne ke baad kabhi band na hota. Yehi
     * galti is se pehle send_customer_emails par ho chuki hai.
     */
    public function test_the_screen_can_switch_it_on_and_off_again(): void
    {
        $this->settingsUpdate(['show_kitchen_requirements' => '1']);
        $this->assertTrue((bool) CateringSetting::tenantDefault()->fresh()->show_kitchen_requirements,
            'tick lagane par khul jaye');

        // Tick hata kar bhejna = wo field bheji hi na jaye.
        $this->settingsUpdate([]);
        $this->assertFalse((bool) CateringSetting::tenantDefault()->fresh()->show_kitchen_requirements,
            'tick hatane par band ho jaye — bina tick ka checkbox kuch post nahi karta');
    }

    /** Aur is switch ne baqi settings ko nahi chhera. */
    public function test_it_does_not_disturb_the_other_settings(): void
    {
        CateringSetting::tenantDefault()->update(['send_customer_emails' => true]);

        $this->settingsUpdate(['show_kitchen_requirements' => '1', 'send_customer_emails' => '1']);

        $s = CateringSetting::tenantDefault()->fresh();
        $this->assertTrue((bool) $s->show_kitchen_requirements);
        $this->assertTrue((bool) $s->send_customer_emails, 'customer emails jahan thi wahin rahe');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function settingsUpdate(array $fields): void
    {
        $req = Request::create('/catering/settings', 'POST', array_merge([
            // required — inke baghair validation hi rok degi.
            'print_language_profile' => 'en',
        ], $fields));
        $req->setLaravelSession(app('session.store'));

        try {
            app(CateringSettingController::class)->update($req);
        } catch (\Throwable $e) {
            // back() ko referer chahiye, jo yahan nahi hai; likhna ho chuka hota
            // hai. Assertions database se parhti hain.
        }
    }

    private function kitchenSheet(): string
    {
        $categoryId = $this->makeCategory(['name' => 'RICE', 'sort_order' => 2]);
        $pid = $this->makeProduct($categoryId, ['name' => 'Dish 1', 'sku' => 'RQ1', 'unit_id' => $this->unitId]);
        CateringMaterialRate::create([
            'product_id' => $pid, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        // Requirements table MATERIALS se banti hai. Bina material block ke
        // release ke paas jama karne ko kuch hota hi nahi, aur "switch on"
        // wala test khali safhe par pass ya fail hota — donon bemani.
        $material = $this->makeProduct($categoryId, [
            'name' => 'Chicken (Regular)', 'sku' => 'RM-CHK-RQ',
            'product_kind' => \App\Models\Tenant\Product::KIND_RAW_MATERIAL,
            'unit_id' => $this->unitId,
        ]);
        CateringMaterialRate::create([
            'product_id' => $material, 'rate' => 775, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        \App\Models\Tenant\CateringProductProfile::create([
            'product_id' => $pid, 'catering_enabled' => true,
            'costing_mode' => \App\Models\Tenant\CateringProductProfile::COSTING_BLOCKS,
        ]);
        \App\Models\Tenant\CateringProductCostBlock::create([
            'product_id' => $pid, 'label' => 'Chicken (Regular)', 'block_type' => 'material',
            'charge_basis' => 'per_unit', 'rate_basis' => 'per_material_unit',
            'commercial_rate_source' => 'manual', 'rate' => 545,
            'material_product_id' => $material, 'quantity_per_unit' => 1.25,
            'unit_id' => $this->unitId, 'is_active' => true, 'sort_order' => 1,
        ]);

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'MR,SHEHZAD',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(2)->toDateString(), 'pax' => 120,
        ]);

        $estimate = $event->currentEstimate;
        $estimates->saveDraftLines($estimate, [[
            'product_id' => $pid, 'item_name' => 'Dish 1', 'quantity' => 12,
            'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);
        $estimates->markSent($estimate->refresh());
        $estimates->markAccepted($estimate->refresh());
        $estimates->confirmEvent($event->refresh());

        $release = app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release(CateringEvent::find($event->id))
            ->load(['lines', 'event']);

        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => $release, 'lang' => 'en', 'businessName' => 'Kashif Kitchen',
        ])->render();
    }
}
