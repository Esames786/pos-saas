<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringMaterialRateController;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-RATE-PARITY-1 + RATE-HISTORY-1 — 24 September.
 *
 * Client: "Chkn rate kese update honge daily". Research in
 * docs/plans/kashif-rate-book-daily-update-2026-09-24.md found the Material Cost
 * Rates screen carrying every fault the Commercial screen had been deliberately
 * built to avoid. Measured on the live tenant, read-only:
 *
 *   • "Current" was max(id) — the highest row, whatever date it carried — while
 *     the costing has always resolved by date. A rate dated next week became the
 *     screen's current rate the instant it was saved, and every quotation went on
 *     costing at the old one. The screen was the one lying.
 *
 *   • Any product could take a "material" rate: the picker searched the whole
 *     catalogue and the rule was a bare exists:products,id. That is how the DISH
 *     "Chatni" (product_kind = sale_item) came to hold a material cost rate on
 *     production.
 *
 *   • The unit was optional, though a cost block can only follow a rate measured
 *     in the same unit.
 */
class CateringRateBookParityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $materialId;

    private int $dishId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_material_rates', 'products', 'categories', 'units', 'branches',
        ]);

        $this->makeBranch();
        $categoryId = $this->makeCategory(['name' => 'RAW']);

        $this->unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->materialId = $this->makeProduct($categoryId, [
            'name' => 'Chicken (Regular)', 'sku' => 'RM-CHICKEN',
            'product_kind' => Product::KIND_RAW_MATERIAL,
        ]);

        // The shape of the live defect: a DISH, not a material.
        $this->dishId = $this->makeProduct($categoryId, [
            'name' => 'Chatni', 'sku' => 'DISH-CHATNI',
            'product_kind' => Product::KIND_SALE_ITEM,
        ]);
    }

    // ── a dish is not a material ───────────────────────────────────────────

    /** The live defect, refused at the server — not merely hidden in the picker. */
    public function test_a_dish_cannot_be_given_a_material_rate(): void
    {
        try {
            $this->controller()->store($this->rateRequest(['product_id' => $this->dishId]));
            $this->fail('a dish must not be allowed to hold a material cost rate');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }

        $this->assertSame(0, CateringMaterialRate::count(), 'and nothing was written');
    }

    /** A real material still records, exactly as before. */
    public function test_a_material_records_normally(): void
    {
        $this->controller()->store($this->rateRequest());

        $this->assertSame(1, CateringMaterialRate::count());
        $this->assertSame($this->materialId, CateringMaterialRate::first()->product_id);
    }

    /** A rate of 450 means nothing until it says 450 per what. */
    public function test_the_unit_is_required(): void
    {
        try {
            $this->controller()->store($this->rateRequest(['unit_id' => null]));
            $this->fail('a rate with no unit must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());
        }
    }

    /** And it must be a unit the business still uses. */
    public function test_a_retired_unit_is_refused(): void
    {
        $retired = $this->tenant()->table('units')->insertGetId([
            'code' => 'OLD', 'name' => 'Retired', 'unit_type' => 'weight', 'is_active' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->controller()->store($this->rateRequest(['unit_id' => $retired]));
            $this->fail('a rate quoted per a retired unit cannot be matched against any block');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_id', $e->errors());
        }
    }

    // ── the screen must agree with the arithmetic ──────────────────────────

    /**
     * THE HEART OF IT. A rate dated in the future is recorded, is NOT current,
     * and the screen now says the same thing the costing does.
     *
     * The old screen picked max(id), so the future row — being newest — became
     * "current" the moment it was saved.
     */
    public function test_a_future_rate_is_not_todays_rate(): void
    {
        $this->rate(400, now()->subDays(3)->toDateString());
        $future = $this->rate(999, now()->addDays(7)->toDateString());

        // What the costing believes.
        $effective = CateringMaterialRate::effectiveFor($this->materialId);
        $this->assertSame('400.0000', (string) $effective->rate, 'costing still uses the arrived rate');

        // What the screen believes.
        $view = $this->controller()->index(Request::create('/catering/material-rates', 'GET'));
        $data = $view->getData();

        $currentIds = collect($data['latestRates']->items())->pluck('id')->all();
        $this->assertContains($effective->id, $currentIds, 'the arrived rate is the current one');
        $this->assertNotContains($future->id, $currentIds,
            'a rate dated next week is not what anything is costed at this morning');

        $this->assertTrue($data['scheduled']->contains('id', $future->id),
            'it is shown apart, as recorded but not yet in force');
    }

    /** Two rates on the SAME day: the later row wins, and the earlier is kept. */
    public function test_a_second_rate_on_the_same_day_takes_over_without_erasing_the_first(): void
    {
        $morning = $this->rate(400, now()->toDateString());
        $afternoon = $this->rate(455, now()->toDateString());

        $this->assertSame($afternoon->id, CateringMaterialRate::effectiveFor($this->materialId)->id);
        $this->assertNotNull($morning->fresh(), 'the morning decision survives the afternoon');

        $currentIds = collect($this->controller()
            ->index(Request::create('/catering/material-rates', 'GET'))
            ->getData()['latestRates']->items())->pluck('id')->all();
        $this->assertContains($afternoon->id, $currentIds);
        $this->assertNotContains($morning->id, $currentIds, 'one current row per material');
    }

    // ── the modal's history ────────────────────────────────────────────────

    /** Yesterday's rate is on the page, so the modal can offer it back. */
    public function test_the_modal_is_given_this_materials_recent_rates(): void
    {
        $this->rate(400, now()->subDays(2)->toDateString(), 'market fell');
        $this->rate(455, now()->toDateString(), 'market rose');

        $data = $this->controller()->index(Request::create('/catering/material-rates', 'GET'))->getData();

        $this->assertArrayHasKey('historyByMaterial', $data);
        $rows = $data['historyByMaterial'][$this->materialId] ?? null;
        $this->assertNotNull($rows, 'the material has a history to offer');
        $this->assertCount(2, $rows);

        // Newest first: the operator reads "what it is now" before "what it was".
        $this->assertSame(455.0, $rows[0]['rate']);
        $this->assertSame(400.0, $rows[1]['rate']);
        $this->assertSame('market fell', $rows[1]['note']);
        $this->assertSame('KG', $rows[0]['unit'], 'the unit travels with it — "Use this" restores both');
        $this->assertSame($this->unitId, $rows[0]['unit_id']);
    }

    /**
     * And ONLY materials are offered. A dish that somehow holds a rate — as one
     * does on production — must not be advertised in the picker or its history.
     */
    public function test_only_materials_are_offered_to_the_modal(): void
    {
        CateringMaterialRate::create([
            'product_id' => $this->dishId, 'rate' => 1, 'unit_id' => $this->unitId,
            'effective_from' => now()->toDateString(),
        ]);

        $data = $this->controller()->index(Request::create('/catering/material-rates', 'GET'))->getData();

        $this->assertArrayNotHasKey($this->dishId, $data['historyByMaterial'],
            'a dish is not a material, so it has no material-rate history to offer');
        $this->assertFalse($data['materials']->contains('id', $this->dishId),
            'and it is not in the picker');
        $this->assertTrue($data['materials']->contains('id', $this->materialId));
    }

    /**
     * The page must actually DRAW. Both blades changed here — the picker became
     * a plain list, a scheduled section appeared, and the modal gained a history
     * panel — and a controller that stops passing a variable a blade still reads
     * is a 500 for everybody, not a failing assertion. Close Branch went down
     * exactly that way.
     */
    public function test_the_screen_renders_with_everything_the_blade_reads(): void
    {
        $this->rate(400, now()->subDay()->toDateString(), 'market fell');
        $this->rate(999, now()->addDays(7)->toDateString());

        // The layout reads $errors, which ShareErrorsFromSession puts there on a
        // real request. Rendering a view directly skips the middleware stack, so
        // it is supplied here — a harness detail, not something the page needs.
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);

        $html = $this->controller()
            ->index(Request::create('/catering/material-rates', 'GET'))
            ->render();

        $this->assertStringContainsString('Chicken (Regular)', $html);
        $this->assertStringContainsString('rate-history-wrap', $html, 'the modal carries its history panel');
        $this->assertStringContainsString('data-rate-history', $html, 'and the data it renders from');
        $this->assertStringContainsString('Aage ki tareekh wali rates', $html,
            'the not-yet-in-force rate is shown apart');
        // The picker is a LIST now, not a catalogue search. Asserted on the
        // select itself rather than on the page: /ajax/products is a shared
        // endpoint other components on the layout use legitimately, and a
        // page-wide search for it fails for reasons that have nothing to do
        // with this screen — as it did on the first attempt at this assertion.
        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="rate-product"[^>]*>\s*<option value="">/',
            $html,
            'the material picker carries its options in the page'
        );
        $this->assertStringContainsString('RM-CHICKEN', $html, 'the material is one of them');
        $this->assertStringNotContainsString('DISH-CHATNI', $html,
            'and a dish is not offered as a material');
    }

    private function controller(): CateringMaterialRateController
    {
        return app(CateringMaterialRateController::class);
    }

    private function rate(float $amount, string $from, ?string $note = null): CateringMaterialRate
    {
        return CateringMaterialRate::create([
            'product_id' => $this->materialId,
            'rate' => $amount,
            'unit_id' => $this->unitId,
            'effective_from' => $from,
            'note' => $note,
        ]);
    }

    private function rateRequest(array $overrides = []): Request
    {
        return Request::create('/catering/material-rates', 'POST', array_merge([
            'product_id' => $this->materialId,
            'rate' => 450,
            'unit_id' => $this->unitId,
            'effective_from' => now()->toDateString(),
        ], $overrides));
    }
}
