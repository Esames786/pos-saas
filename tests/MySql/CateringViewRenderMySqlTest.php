<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Tenant\CateringEstimate;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringEstimateService;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\MySql\Support\TenantFixtures;

/**
 * PLATFORM-ENTITLEMENT-BOUNDARY-1 / CATERING-UAT — the coverage that was missing.
 *
 * Two defect classes reached the client because nothing here existed:
 *   1. Catering Blade views were never RENDERED by any test (services only), so
 *      two views that compiled to invalid PHP shipped and 500'd.
 *   2. Sidebar/route visibility was never asserted against a RESTRICTED plan, so
 *      Sales/Reports leaked into a Catering-only tenant and the dashboard was
 *      blocked by the reports module.
 */
class CateringViewRenderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEvent $event;

    private CateringEstimate $estimate;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        // Outside an HTTP request nothing shares the session error bag, but every
        // page uses $errors. Share it so a render failure means a REAL defect.
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines',
            'catering_material_issues', 'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
            'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_material_rates', 'catering_printer_mappings', 'catering_product_profiles',
            'catering_settings', 'product_translations', 'recipe_ingredients', 'recipes',
            'units', 'printers', 'products', 'categories', 'customers', 'branches',
        ]);

        $branchId = $this->makeBranch();
        $categoryId = $this->makeCategory();
        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = $this->makeProduct($categoryId, ['unit_id' => $unitId, 'default_purchase_price' => 400]);
        $this->tenant()->table('product_translations')->insert([
            'product_id' => $productId, 'language_code' => 'ur', 'name' => 'چکن بریانی',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tenant()->table('catering_product_profiles')->insert([
            'product_id' => $productId, 'catering_enabled' => 1, 'pricing_mode' => 'fixed',
            'default_catering_rate' => 500, 'production_station' => 'Rice',
            'production_label' => 'Deg Biryani', 'production_label_ur' => 'دیگ بریانی',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400, 'unit_id' => $unitId,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $printerId = $this->makePrinter();
        $this->tenant()->table('catering_printer_mappings')->insert([
            'branch_id' => $branchId, 'category_id' => null, 'production_station' => 'Rice',
            'printer_id' => $printerId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $estimates = app(CateringEstimateService::class);
        $this->event = $estimates->createEvent([
            'branch_id' => $branchId, 'customer_name' => 'Render Test Customer',
            'customer_name_ur' => 'رینڈر ٹیسٹ', 'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(4)->toDateString(), 'venue' => 'Test Hall', 'pax' => 250,
        ]);
        $this->estimate = $estimates->saveDraftLines($this->event->currentEstimate, [[
            'product_id' => $productId, 'item_name' => 'Chicken Biryani', 'item_name_ur' => 'چکن بریانی',
            'quantity' => 40, 'unit_id' => $unitId, 'unit_code' => 'KG', 'rate' => 500,
            'instructions' => 'Medium spice',
        ]], ['service_charge_amount' => 1500]);
    }

    /**
     * Renders the real Blade views with real data. A view that compiles to
     * invalid PHP, or references an undefined variable, throws here.
     */
    public function test_every_catering_screen_renders_without_error(): void
    {
        $event = $this->event->fresh(['customer', 'branch', 'estimates.lines', 'currentEstimate.lines', 'advances', 'productionReleases', 'finalInvoice']);
        $estimate = $this->estimate->fresh(['lines', 'event']);
        $units = \App\Models\Tenant\Unit::all(['id', 'code', 'name']);
        $profiles = \App\Models\Tenant\CateringProductProfile::with(['product.translations', 'defaultQuoteUnit'])->paginate(25);

        $screens = [
            'tenant.catering.events.index' => [
                'events' => CateringEvent::with('currentEstimate')->paginate(25),
                'buckets' => ['today' => 0, 'tomorrow' => 0, 'week' => 1, 'unconfirmed' => 1],
                'filter' => null, 'status' => null,
            ],
            'tenant.catering.events.form' => ['event' => $event, 'branches' => \App\Models\Tenant\Branch::all()],
            'tenant.catering.events.show' => [
                'event' => $event, 'units' => $units,
                'profileMap' => collect([]), 'paymentMethods' => collect([]),
                'costingReadiness' => app(\App\Services\Catering\CateringRecipeCostingService::class)->readiness($estimate),
            ],
            'tenant.catering.profiles.index' => ['profiles' => $profiles, 'units' => $units, 'search' => ''],
            'tenant.catering.material-rates.index' => [
                'latestRates' => \App\Models\Tenant\CateringMaterialRate::with(['product.unit', 'unit', 'product.translations'])->paginate(25),
                'history' => null, 'units' => $units, 'search' => '',
            ],
            'tenant.catering.rate-impact.index' => [
                'product' => null, 'currentRate' => null, 'rows' => collect(), 'agreedRows' => collect(),
            ],
            'tenant.catering.printer-mappings.index' => [
                'mappings' => \App\Models\Tenant\CateringPrinterMapping::with(['branch', 'category', 'printer'])->get(),
                'branches' => \App\Models\Tenant\Branch::all(),
                'categories' => \App\Models\Tenant\Category::all(),
                'printers' => \App\Models\Tenant\Printer::all(),
            ],
            'tenant.catering.settings.index' => ['settings' => \App\Models\Tenant\CateringSetting::tenantDefault()],
            'tenant.catering.documents.estimate' => [
                'estimate' => $estimate, 'event' => $event, 'lang' => 'both',
                'advanceTotal' => 0.0, 'businessName' => 'Render Test',
            ],
        ];

        foreach ($screens as $view => $data) {
            try {
                $html = View::make($view, $data)->render();
            } catch (\Throwable $e) {
                $this->fail("View [{$view}] failed to render: ".$e->getMessage());
            }
            $this->assertNotEmpty($html, "View [{$view}] rendered empty");
        }

        $this->addToAssertionCount(1);
    }

    /** The estimate document must carry both languages when lang=both. */
    public function test_estimate_document_renders_english_and_urdu(): void
    {
        $html = View::make('tenant.catering.documents.estimate', [
            'estimate' => $this->estimate->fresh(['lines', 'event']),
            'event' => $this->event->fresh(),
            'lang' => 'both', 'advanceTotal' => 0.0, 'businessName' => 'Render Test',
        ])->render();

        $this->assertStringContainsString('Chicken Biryani', $html);
        $this->assertStringContainsString('چکن بریانی', $html, 'Urdu line name must appear in bilingual mode');

        $urdu = View::make('tenant.catering.documents.estimate', [
            'estimate' => $this->estimate->fresh(['lines', 'event']),
            'event' => $this->event->fresh(),
            'lang' => 'ur', 'advanceTotal' => 0.0, 'businessName' => 'Render Test',
        ])->render();
        $this->assertStringContainsString('dir="rtl"', $urdu, 'Urdu document must be RTL');
    }

    /**
     * PLATFORM-ENTITLEMENT-BOUNDARY-1 — the exact allow/deny matrix the owner
     * specified, asserted against the real entitlement authority.
     */
    public function test_restricted_catering_plan_route_entitlement_matrix(): void
    {
        DB::setDefaultConnection('master');

        // The entitlement authority resolves a route's module via route_catalogs.
        // Without the catalog every route reads as "unmapped => allowed", which
        // would make this matrix silently vacuous — seed the real registry.
        (new \Database\Seeders\MasterSeeder)->run();
        \Illuminate\Support\Facades\Artisan::call('system:routes-sync');
        $this->assertGreaterThan(0, DB::connection('master')->table('route_catalogs')
            ->where('route_name', 'tenant.pos.index')->count(), 'route catalog must be populated');

        $svc = app(TenantSubscriptionAccessService::class);

        $cateringOnly = $this->planWith('render-catering-only', [
            'catering', 'catalog', 'inventory', 'kitchen_inventory', 'finance', 'printing', 'users_roles', 'multi_branch',
        ]);
        $posTenant = $this->planWith('render-pos-plan', ['pos', 'catalog', 'printing', 'reports']);

        $catering = $this->tenantOn('rendercat', $cateringOnly);
        $pos = $this->tenantOn('renderpos', $posTenant);

        // Landing page must never be an entitlement decision.
        $this->assertTrue($svc->check($catering, 'tenant.dashboard')['allowed'],
            'dashboard must open without the reports module');

        // Shared resources: reachable from EITHER pos or catering.
        foreach (['tenant.customers.index', 'tenant.payment-methods.index'] as $route) {
            $this->assertTrue($svc->check($catering, $route)['allowed'], "{$route} must be allowed for a Catering tenant");
            $this->assertTrue($svc->check($pos, $route)['allowed'], "{$route} must be allowed for a POS tenant");
        }

        // Enabling Catering must NOT open the rest of the Sales module.
        foreach ([
            'tenant.pos.index', 'tenant.sales-orders.index', 'tenant.sales-returns.index',
            'tenant.sales-ledger.index', 'tenant.delivery-channels.index', 'tenant.delivery-riders.index',
            'tenant.reports.center.index', 'tenant.manufacturing.bom.index',
            'tenant.printing.category-mappings.index',
        ] as $route) {
            $result = $svc->check($catering, $route);
            $this->assertFalse($result['allowed'], "{$route} must stay DENIED for a Catering-only tenant");
        }

        // Catering routes denied for a POS-only tenant.
        $this->assertFalse($svc->check($pos, 'tenant.catering.events.index')['allowed'],
            'catering must stay denied without the catering module');

        // POS tenant keeps its own surfaces, INCLUDING POS KOT routing/layouts
        // (this is the regression guard for the printing split).
        foreach ([
            'tenant.pos.index', 'tenant.sales-orders.index', 'tenant.reports.center.index',
            'tenant.printing.category-mappings.index', 'tenant.printing.layouts.index',
        ] as $route) {
            $this->assertTrue($svc->check($pos, $route)['allowed'], "{$route} must remain allowed for a POS tenant");
        }

        // The shared physical print transport stays available to Catering.
        foreach (['tenant.printing.printers.index', 'tenant.printing.jobs.index', 'tenant.print-agents.index'] as $route) {
            $this->assertTrue($svc->check($catering, $route)['allowed'],
                "{$route} is the shared print transport and must stay allowed for Catering");
        }

        DB::setDefaultConnection('tenant');
    }

    private function planWith(string $code, array $moduleKeys): Plan
    {
        $plan = Plan::updateOrCreate(['code' => $code], ['name' => $code, 'price' => 0, 'is_active' => true]);
        PlanModule::where('plan_id', $plan->id)->delete();
        foreach ($moduleKeys as $key) {
            if ($module = Module::where('key', $key)->first()) {
                PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
            }
        }

        return $plan->fresh();
    }

    private function tenantOn(string $code, Plan $plan): Tenant
    {
        $tenant = Tenant::updateOrCreate(['tenant_code' => $code], [
            'business_name' => $code, 'status' => 'active',
        ]);
        Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        return $tenant->fresh();
    }
}
