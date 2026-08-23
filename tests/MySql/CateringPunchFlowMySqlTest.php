<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Catering\CateringEstimateController;
use App\Http\Controllers\Tenant\Catering\CateringLineCostController;
use App\Models\Tenant\CateringEstimateLineCostBlock;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Models\Tenant\CateringProductCostBlock;
use App\Models\Tenant\CateringProductProfile;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Catering\CateringProductionReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * KASHIF-ORDER-PUNCH — the WHOLE punch, walked end to end by machine, exactly
 * as the browser drives it. One punch: ghost-line save (the same payload the
 * bar's final Enter submits) → the same three block adjustments the bar posts
 * afterwards (kitchen KG, booking-only rate, گاہک share) → a second punch lands
 * BELOW the first → finalize → the customer's print carries the materials box
 * and the shares → confirm → the kitchen release carries the split and the
 * station. If any step of the operator's round trip breaks, this breaks.
 */
class CateringPunchFlowMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private int $branchId;

    private int $biryaniId;

    private int $raitaId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        Gate::before(fn (?\App\Models\Tenant\User $user = null) => true);

        $this->cleanTenant([
            'catering_event_revisions',
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_line_instruction', 'catering_instructions',
            'catering_estimate_line_cost_blocks', 'catering_estimate_lines', 'catering_estimates',
            'catering_refunds', 'catering_advances', 'catering_final_invoices',
            'catering_events', 'catering_settings',
            'catering_product_cost_blocks', 'catering_product_profiles', 'catering_material_rates',
            'journal_lines', 'journal_entries', 'stock_ledgers',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->estimates = app(CateringEstimateService::class);
        $this->branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $this->unitId = DB::connection('tenant')->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->biryaniId = $this->makeProduct($categoryId, ['name' => 'Chicken Biryani', 'sku' => 'CAT-BIR', 'unit_id' => $this->unitId]);
        $this->raitaId = $this->makeProduct($categoryId, ['name' => 'Raita', 'sku' => 'CAT-RAITA', 'unit_id' => $this->unitId]);
        $chickenId = $this->makeProduct($categoryId, [
            'name' => 'Chicken', 'sku' => 'RM-CHK', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);
        $yogurtId = $this->makeProduct($categoryId, [
            'name' => 'Yogurt', 'sku' => 'RM-YOG', 'unit_id' => $this->unitId,
            'product_kind' => 'raw_material', 'is_stock_tracked' => true,
        ]);

        foreach ([[$chickenId, 80], [$yogurtId, 60]] as [$id, $rate]) {
            CateringMaterialRate::create([
                'product_id' => $id, 'rate' => $rate, 'unit_id' => $this->unitId,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
        }

        foreach ([
            [$this->biryaniId, 'Chicken', $chickenId, 0.5, 100, 300, 'Main Kitchen'],
            [$this->raitaId, 'Yogurt', $yogurtId, 0.8, 60, 70, 'Cold Section'],
        ] as [$productId, $matLabel, $matId, $ratio, $matRate, $makingRate, $station]) {
            CateringProductProfile::updateOrCreate(
                ['product_id' => $productId],
                ['catering_enabled' => true, 'pricing_mode' => 'fixed', 'costing_mode' => 'blocks',
                    'allow_party_supply' => true, 'production_station' => $station,
                    'default_quote_unit_id' => $this->unitId],
            );
            CateringProductCostBlock::create([
                'product_id' => $productId, 'label' => $matLabel,
                'block_type' => CateringProductCostBlock::TYPE_MATERIAL,
                'material_product_id' => $matId, 'quantity_per_unit' => $ratio,
                'unit_id' => $this->unitId, 'rate' => $matRate,
                'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
                'sort_order' => 1,
            ]);
            CateringProductCostBlock::create([
                'product_id' => $productId, 'label' => 'Making',
                'block_type' => CateringProductCostBlock::TYPE_CHARGE,
                'charge_role' => CateringProductCostBlock::ROLE_MAKING,
                'rate' => $makingRate, 'charge_basis' => CateringProductCostBlock::BASIS_PER_UNIT,
                'sort_order' => 2,
            ]);
        }
    }

    private function req(array $data): Request
    {
        $request = Request::create('/x', 'POST', $data);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    /** The EXACT payload the punch bar's final Enter submits (ghost row). */
    private function punchSave(CateringEvent $event, array $ghostLines): void
    {
        app(CateringEstimateController::class)->update(
            $this->req(['lines' => $ghostLines]),
            $event->refresh()->currentEstimate,
        );
    }

    private function block(CateringEvent $event, string $itemName, string $label): CateringEstimateLineCostBlock
    {
        $line = $event->refresh()->currentEstimate->lines()->where('item_name', $itemName)->firstOrFail();

        return CateringEstimateLineCostBlock::where('catering_estimate_line_id', $line->id)
            ->where('label', $label)->firstOrFail();
    }

    public function test_the_full_punch_round_trip_from_bar_to_kitchen_to_print(): void
    {
        $ledgers = fn () => [
            DB::connection('tenant')->table('journal_lines')->count(),
            DB::connection('tenant')->table('stock_ledgers')->count(),
        ];
        $before = $ledgers();

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId, 'customer_name' => 'Punch Customer',
            'booking_date' => now()->toDateString(), 'event_date' => now()->addDays(3)->toDateString(),
            'pax' => 100,
        ]);

        // ── PUNCH 1: 361-style — Biryani 10 KG, then the bar's three PUTs. ──
        $this->punchSave($event, [[
            'product_id' => $this->biryaniId, 'item_name' => 'Chicken Biryani',
            'quantity' => 10, 'unit_id' => $this->unitId, 'rate' => 0,
            'instructions' => 'Zafran on top',
        ]]);

        $line = $event->refresh()->currentEstimate->lines()->firstOrFail();
        $this->assertSame('Chicken Biryani', $line->item_name);
        $this->assertSame(350.0, (float) $line->calculated_rate, 'chicken 0.5×100 + making 300');
        $this->assertSame(3500.0, (float) $line->amount);
        $this->assertSame('Zafran on top', $line->instructions);

        $chicken = $this->block($event, 'Chicken Biryani', 'Chicken');
        $lineCost = app(CateringLineCostController::class);
        // kitchen KG 5 → 6 (the pm-kg box)
        $lineCost->updateMaterial($this->req(['event_material_qty' => 6]), $chicken);
        // booking-only rate 100 → 120 (the pm-rate box)
        $lineCost->chargedRate($this->req(['rate' => 120]), $chicken->refresh());
        // گاہک 2 KG (the pm-cust box)
        $lineCost->customerSupplied($this->req(['is_customer_supplied' => 0, 'customer_supplied_qty' => 2]), $chicken->refresh());

        $chicken->refresh();
        $line->refresh();
        $this->assertSame(6.0, (float) $chicken->event_material_qty, 'kitchen needs the punched 6');
        $this->assertSame(2.0, $chicken->suppliedQty());
        $this->assertSame(4.0, $chicken->ourStockRequirement());
        $this->assertSame(480.0, (float) $chicken->amount, 'ہم 4 KG × 120');
        $this->assertSame(348.0, (float) $line->calculated_rate, '(480 + 3000 making) / 10');

        // ── PUNCH 2 lands BELOW, first row untouched (the round trip). ──
        $this->punchSave($event, [
            [
                'line_uuid' => $line->line_uuid, 'product_id' => $this->biryaniId,
                'item_name' => 'Chicken Biryani', 'quantity' => 10, 'unit_id' => $this->unitId, 'rate' => 0,
                'instructions' => 'Zafran on top',
            ],
            [
                'product_id' => $this->raitaId, 'item_name' => 'Raita',
                'quantity' => 5, 'unit_id' => $this->unitId, 'rate' => 0,
            ],
        ]);

        $lines = $event->refresh()->currentEstimate->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['Chicken Biryani', 'Raita'], $lines->pluck('item_name')->all());
        $chicken->refresh();
        $this->assertSame(2.0, $chicken->suppliedQty(), 'the second punch never disturbs the first row\'s split');
        $this->assertSame(120.0, (float) $chicken->rate, 'nor its booking rate');
        $this->assertSame(118.0, (float) $lines[1]->calculated_rate, 'raita 0.8×60 + making 70');

        // ── Customer print: materials box, shares, totals. ──
        $estimate = $event->currentEstimate->refresh();
        $html = View::make('tenant.catering.documents.estimate', [
            'estimate' => $estimate->load(['event.customer', 'lines']),
            'event' => $event,
            'lang' => 'en',
            'position' => app(CateringFinancialPositionService::class)->position($event),
            'advanceTotal' => 0.0,
            'businessName' => 'Kashif Kitchen',
        ])->render();

        $this->assertStringContainsString('Chicken Biryani', $html);
        $this->assertStringContainsString('سامان', $html, 'the materials box prints');
        $this->assertStringContainsString('6 KG', $html, 'the punched kitchen quantity, not the recipe default');
        $this->assertStringContainsString('گاہک 2', $html, 'the customer share, spelled out');
        $this->assertStringContainsString('Zafran on top', $html, 'the punched instruction reaches the paper');

        // ── Kitchen: finalize → accept → confirm → release. ──
        $this->estimates->markSent($estimate);
        $this->estimates->markAccepted($estimate->refresh());
        $this->estimates->confirmEvent($event->refresh());
        $release = app(CateringProductionReleaseService::class)->release($event->refresh());

        $releaseLines = $release->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $releaseLines);
        $this->assertSame('Main Kitchen', $releaseLines[0]->production_station, 'the item\'s own kitchen');
        $this->assertSame('Cold Section', $releaseLines[1]->production_station);
        $this->assertStringContainsString('CUSTOMER SUPPLIES: Chicken 2 KG (of 6 KG)',
            (string) $releaseLines[0]->instructions, 'the kitchen sheet knows the split');
        $this->assertStringContainsString('Zafran on top', (string) $releaseLines[0]->instructions);

        // ── And the whole trip moved no money and no stock. ──
        $this->assertSame($before, $ledgers());
    }
}
