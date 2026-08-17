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
            'catering_refunds', 'catering_final_invoices', 'catering_advances', 'catering_cost_snapshots',
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
            'tenant.catering.events.form' => [
                'event' => $event, 'branches' => \App\Models\Tenant\Branch::all(),
                'bookedDates' => [$event->event_date->toDateString() => [
                    ['event_no' => $event->event_no, 'customer' => 'Render Test Customer', 'pax' => 250],
                ]],
            ],
            // The guide must render for a tenant with NO data at all — it is the
            // screen a brand-new operator opens first.
            'tenant.catering.guide' => ['lang' => 'en'],
            'tenant.catering.events.show' => [
                'event' => $event, 'units' => $units,
                'profileMap' => collect([]), 'paymentMethods' => collect([]),
                'costingReadiness' => app(\App\Services\Catering\CateringRecipeCostingService::class)->readiness($estimate),
            ] + $this->financePayload($event),
            'tenant.catering.profiles.index' => ['profiles' => $profiles, 'units' => $units, 'search' => ''],
            // The block editor must render for a dish with NO blocks configured —
            // that is the state every dish is in the first time it is opened.
            'tenant.catering.cost-blocks.edit' => [
                'profile' => $profiles->first(),
                'blocks' => app(\App\Services\Catering\CateringCostBlockService::class)
                    ->blocksFor($profiles->first()->product_id),
                'readiness' => app(\App\Services\Catering\CateringCostBlockService::class)
                    ->readiness($profiles->first()->product_id),
                'rate' => app(\App\Services\Catering\CateringCostBlockService::class)
                    ->rateFor($profiles->first()->product_id),
                'units' => $units,
                'materials' => \App\Models\Tenant\Product::limit(5)->get(['id', 'name', 'sku', 'unit_id']),
                'materialRates' => collect(),
            ],
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
            // Catering materials reuse the shared product list under its own base
            // path — the regression guard for the contextBase indirection.
            'tenant.products.index' => [
                'products' => \App\Models\Tenant\Product::paginate(15),
                'categories' => \App\Models\Tenant\Category::all(),
                'context' => 'manufacturing',
                'contextBase' => '/catering/materials',
            ],
        ];

        // Screens that must tell the operator what a click costs before it is
        // clicked. Documents and the guide are excluded: they are output, not
        // action surfaces.
        $mustExplainImpact = [
            'tenant.catering.events.index',
            'tenant.catering.events.form',
            'tenant.catering.events.show',
            'tenant.catering.profiles.index',
            'tenant.catering.cost-blocks.edit',
            'tenant.catering.material-rates.index',
            'tenant.catering.rate-impact.index',
            'tenant.catering.printer-mappings.index',
            'tenant.catering.settings.index',
        ];

        foreach ($screens as $view => $data) {
            try {
                $html = View::make($view, $data)->render();
            } catch (\Throwable $e) {
                $this->fail("View [{$view}] failed to render: ".$e->getMessage());
            }
            $this->assertNotEmpty($html, "View [{$view}] rendered empty");

            if (in_array($view, $mustExplainImpact, true)) {
                // The finance and stock chips always render one way or the
                // other, so their absence means the header is missing entirely.
                $this->assertTrue(
                    str_contains($html, 'Affects finance') || str_contains($html, 'No finance effect'),
                    "[{$view}] must state whether it affects finance"
                );
                $this->assertTrue(
                    str_contains($html, 'Moves stock') || str_contains($html, 'No stock movement'),
                    "[{$view}] must state whether it moves stock"
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * KASHIF-CATERING-PRODUCT-UX-1 (item 6) — the impact text must be TRUE.
     *
     * This module already shipped one screen that claimed no accounting entries
     * were posted while CateringAdvanceService was posting a journal entry and
     * moving a cash/bank balance. An operator trusting that label would have
     * misread their own books, so the retired claim is asserted dead here and a
     * regression restores a lie rather than merely a wording change.
     */
    public function test_impact_wording_does_not_overclaim(): void
    {
        $event = $this->event->fresh(['customer', 'branch', 'estimates.lines', 'currentEstimate.lines', 'advances', 'productionReleases', 'finalInvoice']);

        $html = View::make('tenant.catering.events.show', [
            'event' => $event,
            'units' => \App\Models\Tenant\Unit::all(['id', 'code', 'name']),
            'profileMap' => collect([]),
            'paymentMethods' => collect([]),
            'costingReadiness' => app(\App\Services\Catering\CateringRecipeCostingService::class)
                ->readiness($this->estimate->fresh(['lines', 'event'])),
        ] + $this->financePayload($event))->render();

        foreach ([
            'no accounting entries are posted',
            'V1 posts no accounting entries',
            'Operational records only in V1',
            // The advance modal carried this one until KASHIF-CATERING-CUSTOMER-CREDIT-1,
            // directly above a button that posts a journal entry and moves the drawer.
            'no GL/cash-bank posting',
        ] as $retiredLie) {
            $this->assertStringNotContainsString(
                $retiredLie, $html,
                "the event screen must never again claim \"{$retiredLie}\" — advances and final invoices DO post to the general ledger"
            );
        }

        $this->assertStringContainsString('posts to the general ledger', $html,
            'the screen must say plainly that recording an advance posts to the ledger');

        // The case a binary reversible yes/no would have to lie about.
        $this->assertStringContainsString('does NOT refund it', $html,
            'cancelling after an advance must be described honestly, not as simply reversible');
        $this->assertStringContainsString('Only partly reversible', $html,
            'the event screen is neither safe-to-repeat nor wholly irreversible');

        // Email delivery is NOT part of this release: production runs
        // MAIL_MAILER=log, so a message is recorded and reaches nobody.
        // Advertising an "Emails customer" impact would promise delivery the
        // environment cannot make.
        $this->assertStringNotContainsString('when a quotation is sent and when an invoice is issued', $html,
            'the event screen must not advertise customer email delivery in a release that cannot deliver');

        // "Safe to repeat" asserts idempotency. Creating a booking twice
        // creates two bookings, so no catering screen may claim it.
        $this->assertStringNotContainsString('Safe to repeat', $html,
            'no screen may claim idempotency it does not have');
    }

    /**
     * KASHIF-CATERING-PRODUCT-UX-1 (item 6) — the Materials LIST is a catering
     * action screen too, and it lives in a view shared with the generic catalog
     * and manufacturing. The explanation must reach the catering path and only
     * the catering path.
     */
    public function test_materials_list_explains_itself_without_leaking_into_other_contexts(): void
    {
        $payload = [
            'products' => \App\Models\Tenant\Product::paginate(15),
            'categories' => \App\Models\Tenant\Category::all(),
        ];

        $materials = View::make('tenant.products.index', $payload + [
            'context' => 'manufacturing', 'contextBase' => '/catering/materials',
        ])->render();

        $this->assertStringContainsString('never sold to a customer directly', $materials);
        $this->assertStringContainsString('issues no stock', $materials,
            'the materials list must state that editing a material moves no stock');
        $this->assertStringContainsString('Material Rate Book', $materials,
            'it must point at where the QUOTED rate lives');
        $this->assertStringContainsString('No finance effect', $materials);
        $this->assertStringContainsString('No stock movement', $materials);

        // The same view, other contexts — must NOT inherit any of it.
        foreach ([
            'catalog' => ['context' => 'catalog', 'contextBase' => '/products'],
            'manufacturing' => ['context' => 'manufacturing', 'contextBase' => '/manufacturing/products'],
        ] as $label => $ctx) {
            $other = View::make('tenant.products.index', $payload + $ctx)->render();

            foreach (['never sold to a customer directly', 'issues no stock', 'No finance effect'] as $cateringOnly) {
                $this->assertStringNotContainsString(
                    $cateringOnly, $other,
                    "the {$label} product list must not inherit catering wording"
                );
            }
        }
    }

    /**
     * PLATFORM-ENTITLEMENT-BOUNDARY-2 — the navbar POS button follows the plan.
     *
     * It was gated on @can('tenant.pos.index'), which every Owner holds
     * regardless of plan, so a catering-only tenant saw a POS button for a
     * module it does not have. Asserted at source because the header needs a
     * full request context to render, and the gate is what matters.
     */
    public function test_the_navbar_pos_button_is_gated_on_the_plan_not_the_permission(): void
    {
        $header = file_get_contents(resource_path('views/partials/header.blade.php'));

        $this->assertStringContainsString("hasEnabledModuleKey('pos')", $header,
            'the POS button must check the plan — every Owner holds every permission');

        // The permission check may remain as a second gate, but never alone.
        $posBlock = substr($header, (int) strpos($header, 'pos-nav'), 900);
        $this->assertStringContainsString('$posEntitled', $posBlock,
            'the entitlement check must wrap the button, not sit somewhere unrelated');
    }

    /** No catering screen may assert idempotency, on any surface. */
    public function test_no_catering_screen_claims_to_be_safe_to_repeat(): void
    {
        $partial = file_get_contents(resource_path('views/tenant/catering/partials/screen-impact.blade.php'));

        $this->assertStringNotContainsString('Safe to repeat', $partial,
            'the impact partial must not assert idempotency for actions that are not idempotent');
        $this->assertStringContainsString('Operational / reversible', $partial);
        $this->assertStringContainsString('Contains irreversible action', $partial,
            'a screen carrying both a safe reprint and an irreversible issue must say CONTAINS, not that everything is final');
    }

    /**
     * A screen that moves real stock must say so, and one that does not must
     * not imply it does. The production release is the only catering surface
     * that draws inventory.
     */
    public function test_only_the_material_issue_screen_claims_stock_movement(): void
    {
        $safe = View::make('tenant.catering.material-rates.index', [
            'latestRates' => \App\Models\Tenant\CateringMaterialRate::with(['product.unit', 'unit', 'product.translations'])->paginate(25),
            'history' => null,
            'units' => \App\Models\Tenant\Unit::all(['id', 'code', 'name']),
            'search' => '',
        ])->render();

        $this->assertStringContainsString('No stock movement', $safe,
            'the rate book prices quotations and must not imply it touches inventory');
        $this->assertStringContainsString('No finance effect', $safe,
            'changing a quoting rate posts nothing');
    }

    /**
     * KASHIF-CATERING-PRODUCT-UX-1 — a caterer buys mutton; they do not author a
     * BOM, run WIP, or book a finished-goods receipt. The materials screen shares
     * its data shape with manufacturing but must share none of its vocabulary.
     *
     * The second half is the point: the same views rendered for a manufacturing
     * tenant must still carry that wording, so this cannot be "fixed" by simply
     * deleting the words.
     */
    public function test_manufacturing_vocabulary_never_reaches_a_catering_screen(): void
    {
        // Whole visible strings, not bare words. Banning "BOM" alone would also
        // trip on the sidebar and on inert JS the catering path never renders,
        // which makes the test fail for reasons that are not the defect.
        $banned = [
            'Manufacturing Raw Material',
            'Manufacturing Finished Good',
            'Back to Manufacturing Products',
            'Can be BOM Component',
            'Can be BOM Output',
            'Manufactured Finished Good',
            'WIP/FG receipts',
            'Recipe / BOM',
            'items consumed in BOMs',
        ];

        $cateringPayload = [
            'product' => null,
            'title' => 'Create Material',
            'categories' => \App\Models\Tenant\Category::all(),
            'units' => \App\Models\Tenant\Unit::all(),
            'context' => 'manufacturing',
            'contextBase' => '/catering/materials',
        ];

        $catering = View::make('tenant.products.form', $cateringPayload)->render();

        foreach ($banned as $word) {
            $this->assertStringNotContainsStringIgnoringCase(
                $word, $catering,
                "the catering materials form must not say \"{$word}\" — a kitchen has no such concept"
            );
        }

        // It must still say what it IS, or the words were merely deleted.
        $this->assertStringContainsString('Ingredient', $catering);
        $this->assertStringContainsString('Packaging Material', $catering);
        $this->assertStringContainsString('Back to Materials', $catering);
        $this->assertStringContainsString('Material Rate Book', $catering,
            'the form must point at where the QUOTED rate lives, not just the purchase cost');

        // Non-regression: a manufacturing tenant keeps its own vocabulary.
        $manufacturing = View::make('tenant.products.form', array_merge($cateringPayload, [
            'title' => 'Create Manufacturing Product',
            'contextBase' => '/manufacturing/products',
        ]))->render();

        $this->assertStringContainsString('Back to Manufacturing Products', $manufacturing,
            'manufacturing wording must survive untouched for manufacturing tenants');
        $this->assertStringContainsString('Can be BOM Component', $manufacturing,
            'the BOM role checkboxes must remain for manufacturing tenants');
        $this->assertStringContainsString('Recipe / BOM', $manufacturing,
            'the consumption-method wording must be unchanged outside catering');
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

        // KASHIF-UAT-2: a catering-only tenant owns raw materials but has no
        // Manufacturing module, so its materials screen lives under catering and
        // must NOT drag the manufacturing module along with it.
        $this->assertTrue($svc->check($catering, 'tenant.catering.materials.index')['allowed'],
            'a Catering tenant must be able to manage its own raw materials');
        $this->assertTrue($svc->check($catering, 'tenant.catering.guide.index')['allowed'],
            'the Catering guide must open on a catering plan');
        $this->assertFalse($svc->check($pos, 'tenant.catering.guide.index')['allowed'],
            'the Catering guide must stay denied without the catering module');
        $this->assertFalse($svc->check($catering, 'tenant.manufacturing.products.index')['allowed'],
            'the catering materials screen must not open Manufacturing');
        $this->assertFalse($svc->check($pos, 'tenant.catering.materials.index')['allowed'],
            'catering materials must stay denied without the catering module');

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

    /**
     * KASHIF-CATERING-STORE-2 — the Cost Blocks screen must explain itself.
     *
     * "Charged per KG" and "Material per KG" are both accurate and both read as
     * versions of the same number to someone meeting them for the first time.
     * Getting that wrong means quoting a dish at what its chicken costs, or
     * asking the store for what the customer was billed.
     */
    public function test_the_cost_block_screen_explains_itself_in_operator_language(): void
    {
        $profile = \App\Models\Tenant\CateringProductProfile::with('product.unit')->first();

        $html = View::make('tenant.catering.cost-blocks.edit', [
            'profile' => $profile,
            'blocks' => app(\App\Services\Catering\CateringCostBlockService::class)->blocksFor($profile->product_id),
            'readiness' => app(\App\Services\Catering\CateringCostBlockService::class)->readiness($profile->product_id),
            'rate' => 0.0,
            'units' => \App\Models\Tenant\Unit::all(),
            'materials' => \App\Models\Tenant\Product::limit(5)->get(['id', 'name', 'sku', 'unit_id']),
            'materialRates' => collect(),
        ])->render();

        // The two kinds of part, told apart in words rather than by field name.
        $this->assertStringContainsString('Something real the kitchen uses', $html,
            'a material must be described as a physical thing');
        $this->assertStringContainsString('Money for work, not for goods', $html,
            'a charge must be described as work, not stock');
        $this->assertStringContainsString('Nothing leaves the store for a charge', $html);

        // Per-unit versus lump sum, in an example rather than a definition.
        $this->assertStringContainsString('multiplies by the order size', $html);
        $this->assertStringContainsString('charged once', $html);

        // The three numbers, named as three things.
        foreach (['Customer pays', 'Kitchen uses', 'Costs us'] as $column) {
            $this->assertStringContainsString($column, $html,
                "the preview must name '{$column}' as its own number");
        }
        $this->assertStringContainsString('They are meant to differ', $html);

        // The rate book is named as the source of real cost, and as the ONLY
        // place a rate is edited — two writable sources would be worse than one.
        $this->assertStringContainsString('Material Rate Book', $html);
        $this->assertStringContainsString('only place they are edited', $html);
    }

    /**
     * The commercial contribution must never be presented as what the material
     * costs. That conflation is the one this whole screen exists to prevent.
     */
    public function test_the_cost_block_screen_never_calls_the_charge_a_material_cost(): void
    {
        $profile = \App\Models\Tenant\CateringProductProfile::with('product.unit')->first();

        $html = View::make('tenant.catering.cost-blocks.edit', [
            'profile' => $profile,
            'blocks' => app(\App\Services\Catering\CateringCostBlockService::class)->blocksFor($profile->product_id),
            'readiness' => app(\App\Services\Catering\CateringCostBlockService::class)->readiness($profile->product_id),
            'rate' => 0.0,
            'units' => \App\Models\Tenant\Unit::all(),
            'materials' => \App\Models\Tenant\Product::limit(5)->get(['id', 'name', 'sku', 'unit_id']),
            'materialRates' => collect(),
        ])->render();

        foreach ([
            'Material cost per',
            'Cost per unit',
            'material cost contribution',
        ] as $conflation) {
            $this->assertStringNotContainsString($conflation, $html,
                "'{$conflation}' would present what the customer is charged as what the material costs");
        }

        // And developer vocabulary stays out of an operator screen.
        foreach (['pricing authority', 'snapshot authority', 'commercial component'] as $jargon) {
            $this->assertStringNotContainsString($jargon, $html,
                "'{$jargon}' is code vocabulary, not something a storeman should have to parse");
        }
    }

    /**
     * The financial position the controller hands the event screen. Taken from
     * the real service rather than hand-built, so a change to its shape breaks
     * the render here instead of on the customer's screen.
     *
     * @return array<string, mixed>
     */
    private function financePayload(\App\Models\Tenant\CateringEvent $event): array
    {
        $finance = app(\App\Services\Catering\CateringFinancialPositionService::class);

        return [
            'position' => $finance->position($event),
            'headline' => $finance->headline($event),
            'ledger' => $finance->ledger($event),
        ];
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
