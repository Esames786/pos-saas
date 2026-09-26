<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * KITCHEN-SHEET-LANG-1 — zabaan ka chunaav events list par bhi.
 *
 * Client (26 Sep): Production Release screen par Kitchen Sheet ke teen button
 * hain — EN / اردو / Both — magar events list par sirf ek link tha, jo tenant
 * ki default zabaan kholta tha. Bawarchi Urdu parhta hai aur daftar English, to
 * ek hi list se dono nikalne parte hain.
 *
 * Route pehle se `?lang=` maanta tha (CateringDocumentController::language aur
 * CateringBulkDocumentController::language) — sirf screen par chunaav nahi tha.
 * Is liye ye test do baatein poochta hai:
 *
 *   1. teenon pate safhe par mojood hon, poore `?lang=` ke saath
 *   2. aur wo pate waqai kaam karein — har ek se document us zabaan me nikle
 *
 * Doosri baat pehli se zyada ahem hai: `?lang=ur` likh dena aasan hai, us se
 * Urdu ka safha banna alag baat hai.
 */
class CateringKitchenSheetLanguageMySqlTest extends MySqlTenantTestCase
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
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Events list ke Actions menu me teenon zabaanein. */
    public function test_the_events_list_offers_all_three_languages(): void
    {
        $release = $this->release();
        $html = $this->eventsListHtml();

        foreach (['en', 'ur', 'both'] as $lang) {
            $this->assertStringContainsString(
                "/catering/documents/kitchen-sheet/{$release->id}?lang={$lang}",
                $html,
                "{$lang} ka pata Actions menu me hona chahiye"
            );
        }
    }

    /** Aur bulk button par bhi — wahan se kai bookings ek saath chhapti hain. */
    public function test_the_bulk_button_offers_all_three_languages(): void
    {
        $this->release();
        $html = $this->eventsListHtml();

        foreach (['en', 'ur', 'both'] as $lang) {
            $this->assertStringContainsString(
                "/catering/documents/bulk/kitchen-sheets?lang={$lang}",
                $html,
                "bulk {$lang} ka pata hona chahiye"
            );
        }

        // Bare button, bina lang ke — tenant ki default zabaan, jaisa pehle tha.
        $this->assertStringContainsString('/catering/documents/bulk/kitchen-sheets"', $html,
            'purana bartaao bhi qayam rahe');
    }

    /**
     * ASAL SAWAL: pate sirf likhe hue nahi, chalte bhi hon. Har zabaan se
     * document render karke dekha ja raha hai.
     */
    public function test_each_language_actually_renders_that_sheet(): void
    {
        $release = $this->release();

        $en = $this->sheet($release->id, 'en');
        $ur = $this->sheet($release->id, 'ur');
        $both = $this->sheet($release->id, 'both');

        // Urdu safha RTL hota hai; English nahi. Ye document ka apna faisla hai,
        // sirf ek lafz ki mojoodgi nahi.
        $this->assertStringContainsString('dir="rtl"', $ur, 'Urdu sheet RTL honi chahiye');
        $this->assertStringContainsString('dir="ltr"', $en, 'English sheet LTR');

        // Urdu naam sirf Urdu aur Both par.
        $this->assertStringContainsString('کھانا 1', $ur);
        $this->assertStringContainsString('کھانا 1', $both);

        // Aur English par khane ka English naam.
        $this->assertStringContainsString('Dish 1', $en);
    }

    /**
     * Ek probe jo "nahi mila" keh sake, use pehle "mila" kehne ke qabil hona
     * chahiye — is liye ye jaanch ke ghalat lang chup chaap qubool na ho jaye.
     */
    public function test_a_nonsense_language_falls_back_instead_of_breaking(): void
    {
        $release = $this->release();

        $html = $this->sheet($release->id, 'klingon');

        $this->assertNotSame('', trim($html), 'safha phir bhi banna chahiye');
        $this->assertStringContainsString('Kitchen Sheet', $html);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function sheet(int $releaseId, string $lang): string
    {
        $req = Request::create("/catering/documents/kitchen-sheet/{$releaseId}", 'GET', ['lang' => $lang]);

        return app(\App\Http\Controllers\Tenant\Catering\CateringDocumentController::class)
            ->kitchenSheet($req, \App\Models\Tenant\CateringProductionRelease::findOrFail($releaseId))
            ->render();
    }

    private function eventsListHtml(): string
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $user = \App\Models\Tenant\User::on('tenant')->find(
            $this->makeUser(['employee_code' => 'KL'.Str::random(4)])
        );
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['tenant.catering.documents.kitchen-sheet', 'tenant.catering.documents.bulk-kitchen-sheets'] as $p) {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::on('tenant')
                ->firstOrCreate(['name' => $p, 'guard_name' => 'tenant']));
        }

        return app(\App\Http\Controllers\Tenant\Catering\CateringEventController::class)
            ->index(Request::create('/catering/events', 'GET'))
            ->render();
    }

    private function release(): \App\Models\Tenant\CateringProductionRelease
    {
        $categoryId = $this->makeCategory(['name' => 'RICE', 'sort_order' => 2]);
        $pid = $this->makeProduct($categoryId, ['name' => 'Dish 1', 'sku' => 'KL1', 'unit_id' => $this->unitId]);
        CateringMaterialRate::create([
            'product_id' => $pid, 'rate' => 100, 'unit_id' => $this->unitId,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR,ABDUL NAEEM',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(2)->toDateString(),
            'pax' => 551,
        ]);

        $estimate = $event->currentEstimate;
        $estimates->saveDraftLines($estimate, [[
            'product_id' => $pid, 'item_name' => 'Dish 1', 'item_name_ur' => 'کھانا 1',
            'quantity' => 27, 'unit_id' => $this->unitId, 'unit_code' => 'KG', 'rate' => 500,
        ]]);
        $estimates->markSent($estimate->refresh());
        $estimates->markAccepted($estimate->refresh());
        $estimates->confirmEvent($event->refresh());

        return app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release(CateringEvent::find($event->id))
            ->load(['lines', 'event']);
    }
}
