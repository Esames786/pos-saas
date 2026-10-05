<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use App\Services\Edge\EdgePosRuntimeFactory;
use App\Support\Pos\PosRuntime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * PHASE 3 — THE SHARED-VIEW REGRESSION GATE (replaces the W0 control census, owner directive 4 Oct 2026).
 *
 * The W0 census (EdgeCashierControlCensusHttpMySqlTest + tests/Fixtures/edge/online-pos-control-census.json, both DELETED in
 * Stage B — their 276 ids are frozen in tests/Fixtures/edge/shared-pos-required-ids.json) compared the OLD separately-styled
 * Edge page against the Online view, id by id. With ONE shared cashier Blade (`tenant.pos.index` through `layouts.pos`) that
 * comparison is replaced — not retired — by rendering BOTH runtimes in the SAME test run, from the SAME tenant database, and proving:
 *
 *   (a) SAME BLADE   — both responses are `tenant.pos.index` through `layouts.pos` (view identity + POS_RUNTIME island),
 *                      and the normalised DOM skeleton of the page content (tags, ids, classes, element order, kept
 *                      attributes) is IDENTICAL except for an explicit allowlist of runtime-dependent attributes;
 *   (b) CRITICAL CONTROLS — every Online control id the old census registered (all 276 rows, every state) exists in BOTH
 *                      renders; the 17 `online_required` rows are present on Edge too (same place, disabled where gated);
 *   (c) CAPABILITY STATE — for every PosRuntime::CAPABILITY_KEYS the control(s) the view gates on it are enabled on the
 *                      Cloud render and disabled-with-hint on the Edge render exactly when EdgePosRuntimeFactory says off;
 *   (d) NO EDGE-ONLY COPIED MARKUP — skeleton equality (Edge has no element Cloud lacks) + none of the OLD Edge page's own
 *                      ids reappears in either render + `/edge/local/pos` strictness (see $this->strictCutover());
 *   (e) NO CLOUD-ONLY ENDPOINT LEAKS — every url-bearing attribute and every POS_RUNTIME route of the Edge render resolves to
 *                      an ALLOWLISTED edge.local.* route or a local asset; no Cloud path, no Internet asset;
 *   (f) SAME MODALS / COMPONENTS — identical sets of `.modal[id]`, `[data-bs-toggle]`, `[data-bs-target]`, form ids,
 *                      button ids and named controls in both renders.
 *
 * The gate pins NO hash of the view (Team M edits index.blade.php concurrently): everything is derived from the two live
 * renders. Static scans that need no database live in tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.
 * Category → replacement mapping: docs/status/edge-phase3-census-replacement.md.
 *
 * Runtime boot: setUp boots as a Branch Server (like every Edge MySQL test); the Cloud render re-boots the application as
 * Cloud (refreshApplication, APP_ROLE unset) and renders GET /pos over the REAL tenant HTTP stack (IdentifyTenant by host,
 * subscription access, route permission) against the SAME tenant test database — so the two renders see identical data.
 */
class EdgeSharedPosRegressionGateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    /**
     * STAGE B (6 Oct 2026): the W0 census fixture + EdgeCashierControlCensusHttpMySqlTest are deleted. The 276 control ids it
     * registered (by state: present / equivalent / online_required) live on as the FROZEN required-id set of the shared view
     * — a plain id list, deliberately NOT a sha1 pin of any view (test_a's skeleton equality guards drift).
     */
    private const CENSUS_FIXTURE = 'tests/Fixtures/edge/shared-pos-required-ids.json';

    /** Attributes whose VALUE may legitimately differ between the two runtimes (never geometry). */
    public const RUNTIME_ATTRIBUTES = [
        'disabled',                 // capability-off controls (owner A5: same control, same place, disabled)
        'title',                    // capability hints
        'href', 'action', 'src',    // endpoint / asset URLs come from the runtime map
        'value',                    // csrf token, ids, branch id carriers
        'content',                  // <meta csrf-token>
        'data-runtime-mode',        // the status slot's mode marker
        'data-report-url', 'data-return-url', 'data-management-url', 'data-board-url', 'data-sync-url', // data-* urls
    ];

    /** Elements whose CLASS list is runtime state (status-slot tone / pending badge). Classes are compared everywhere else. */
    private const RUNTIME_CLASS_IDS = ['pos-runtime-state', 'pos-runtime-pending'];

    /** Hidden carrier inputs the Edge render may add (zero geometry): branchSelect off → a fixed branch_id carrier. */
    private const EDGE_ONLY_HIDDEN_INPUTS = ['branch_id'];

    /**
     * Blade capability gates of the shared view (`@disabled(! $posRuntime->can('…'))` sites) → the control(s) gated.
     * `hint` = the control also carries the capability hint as its title when off. Derived from the view (read it);
     * the static half asserts this map stays complete against the view source.
     */
    public const CAPABILITY_CONTROLS = [
        'reports' => ['selectors' => ['//*[@id="pos-report-btn"]'], 'hint' => true],
        'salesReturn' => ['selectors' => ['//*[@id="pos-return-btn"]'], 'hint' => true],
        'quickReport' => ['selectors' => ['//*[@id="pos-quick-report-btn"]'], 'hint' => true],
        'branchSelect' => ['selectors' => ['//select[@id="branch_id"]'], 'hint' => true],
        'nonCashTender' => ['selectors' => ['//select[@id="payment_method_id"]/option[@data-type!="cash"]'], 'hint' => false],
        'tipOnPaidSale' => ['selectors' => ['//button[contains(concat(" ", normalize-space(@class), " "), " tip-btn ")]'], 'hint' => false],
        'manageFloorsTables' => ['selectors' => ['//button[@data-management-url]'], 'hint' => true],
        'quickReportNetwork' => ['selectors' => ['//*[@id="qr-network"]'], 'hint' => true],
        'quickReportEmail' => ['selectors' => ['//*[@id="qr-email"]'], 'hint' => true],
        'customerCreate' => ['selectors' => ['//*[@id="qa-save"]'], 'hint' => true],
        'customerAddressCreate' => ['selectors' => ['//*[@id="new-addr-save"]'], 'hint' => true],
    ];

    /** Capabilities the page JS gates (POS.can) — no Blade @disabled site; the runtime flag + null route are asserted. */
    public const JS_GATED_CAPABILITIES = ['changeRider' => 'salesOrderShow'];

    /**
     * The OLD Edge page's own control ids (resources/views/edge/pos/** minus the shared views) — frozen BEFORE the folder was
     * deleted (Stage B, 6 Oct 2026); this list is now the only reference to the old page. Neither render nor the shared
     * sources may ever carry one of them.
     */
    public const OLD_EDGE_ONLY_IDS = [
        'edge-pos-data', 'cart-lines', 't-grand', 't-subtotal', 't-items', 't-quote-note',
        'customer-chip', 'customer-name', 'order-type', 'terminal', 'search',
        'edge-nav', 'edge-nav-links', 'edge-confirm', 'edge-loading', 'modal-body', 'toast', 'sync-chip', 'check-chip',
        'shift-btn', 'logout-btn', 'ctx-bound-branch', 'ctx-user-name', 'pos-header', 'pos-title-row', 'pos-order-tools',
        'actions', 'totals', 'calc-keypad', 'w2-quick-sale-row', 'offline-banner',
    ];

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $tableId;
    private int $karahi;
    private int $naan;
    private int $cashMethodId;
    private int $cardMethodId;
    private string $cloudHost;
    private ?int $masterTenantId = null;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        $this->pointEdgeLocalAtTenant();
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations',
            'sales_return_lines', 'sales_returns', 'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'printers', 'terminal_printer_settings', 'manager_approvals', 'model_has_permissions', 'model_has_roles', 'permissions',
            'cash_count_lines', 'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'customer_addresses', 'customers', 'delivery_riders', 'delivery_channels', 'sale_payments', 'sales_order_lines',
            'sales_orders', 'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals',
            'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['name' => 'Gate Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'auto_approve', 'status' => 'active']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter 1']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'GATE' . Str::random(4)]);
        $floorId = (int) DB::connection('tenant')->table('restaurant_floors')->insertGetId(['branch_id' => $this->branchId, 'name' => 'Ground', 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available', 'restaurant_floor_id' => $floorId]);
        $this->makeTable($this->branchId, ['table_no' => 'T2', 'status' => 'available', 'restaurant_floor_id' => $floorId]);
        $this->makeWaiter($this->branchId, ['name' => 'Waiter Ali']);
        $categoryId = $this->makeCategory(['name' => 'Karahi']);
        $this->karahi = $this->makeProduct($categoryId, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->naan = $this->makeProduct($categoryId, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        // Stage B (gap A of the same-dataset comparison): a combo FILED to a product-less category — that category's pill exists
        // only because of the combo's category_id, so a runtime that lost the column would render a different pill set and fail
        // the skeleton equality below (the pills are server-rendered from $pillCategoryIds on both runtimes).
        $dealsCat = $this->makeCategory(['name' => 'Deals', 'is_active' => 1, 'sort_order' => 9]);
        $comboId = (int) DB::connection('tenant')->table('combos')->insertGetId(['branch_id' => $this->branchId, 'category_id' => $dealsCat, 'code' => 'FAM', 'name' => 'Family Deal', 'price' => 220, 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('combo_components')->insert([
            ['combo_id' => $comboId, 'product_id' => $this->karahi, 'quantity' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['combo_id' => $comboId, 'product_id' => $this->naan, 'quantity' => 2, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $this->cardMethodId = $this->makePaymentMethod(['method_type' => 'card', 'name' => 'Card']);
        DB::connection('tenant')->table('delivery_channels')->insert(['name' => 'Own Riders', 'type' => 'own', 'is_active' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->karahi, 'product_variant_id' => null, 'quantity' => 50],
            ['product_id' => $this->naan, 'product_variant_id' => null, 'quantity' => 50],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        // The FULL cashier surface: every permission the shared view gates a control on (@can sites), read from the view.
        foreach ($this->viewPermissionGates() as $perm) {
            $this->grantEdgePermission($this->userId, $perm);
        }
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();

        $this->cloudHost = 'shvgate.' . config('tenancy.tenant_base_domain');
    }

    protected function tearDown(): void
    {
        $this->cleanMasterTenant();
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    // ───────────────────────────── the gate ─────────────────────────────

    /**
     * (a) + (d-skeleton) + (f): BOTH runtimes render the same Blade; the normalised page skeleton is identical modulo the
     * runtime-attribute allowlist; the modal / component sets are identical; no old-Edge id is on either page.
     */
    public function test_a_same_blade_identical_skeleton_and_f_same_modals_and_components_in_both_runtimes(): void
    {
        $dead = $this->makeDeadSessionDeepLink();
        $pages = $this->renderBoth(['', $dead]);
        [$edge, $cloud] = $pages[''];

        // (a) view identity — the same Blade through the same layout, each with its own runtime.
        $edgeRt = $this->runtimeFrom($edge);
        $cloudRt = $this->runtimeFrom($cloud);
        $this->assertSame(PosRuntime::MODE_EDGE, $edgeRt['mode']);
        $this->assertSame(PosRuntime::MODE_CLOUD, $cloudRt['mode']);
        $this->assertEqualsCanonicalizing(PosRuntime::ROUTE_KEYS, array_values(array_intersect(array_keys($edgeRt['routes']), PosRuntime::ROUTE_KEYS)));
        $this->assertEqualsCanonicalizing(PosRuntime::ROUTE_KEYS, array_values(array_intersect(array_keys($cloudRt['routes']), PosRuntime::ROUTE_KEYS)));
        foreach ([$edge, $cloud] as $html) {
            $this->assertStringContainsString('<body class="pos-workspace nosidebar">', $html, 'layouts.pos body');
            $this->assertStringContainsString('body.pos-workspace .page-wrapper { margin: 0; padding-top: 0; }', $html, 'layouts.pos chrome rules');
            $this->assertStringContainsString('id="pos-runtime-overlay"', $html, 'layouts.pos shared overlay');
            $this->assertStringContainsString('id="pos-runtime-slot"', $html, 'the shared status slot (A5)');
            $this->assertStringContainsString('window.POS = {', $html, 'tenant.pos.js.pos-runtime island');
            $this->assertStringContainsString('id="pos-sale-form"', $html, 'tenant.pos.index content');
        }
        // The SAME theme files in the SAME order (only the asset base differs).
        $this->assertSame($this->assetList($cloud), $this->assetList($edge), 'head/foot asset list must be identical modulo the asset base');

        // (a) skeleton — identical except the allowlisted runtime attributes.
        $edgeSkel = $this->skeleton($edge);
        $cloudSkel = $this->skeleton($cloud);
        $this->assertGreaterThan(600, count($cloudSkel), 'the skeleton collapsed — the extractor or the view is broken');
        $onlyCloud = array_values(array_diff($cloudSkel, $edgeSkel));
        $onlyEdge = array_values(array_diff($edgeSkel, $cloudSkel));
        $this->assertSame([], $onlyEdge, "Elements on the EDGE render that the CLOUD render lacks (Edge-only markup?):\n" . implode("\n", array_slice($onlyEdge, 0, 60)));
        $this->assertSame([], $onlyCloud, "Elements on the CLOUD render that the EDGE render lacks:\n" . implode("\n", array_slice($onlyCloud, 0, 60)));
        $this->assertSame($cloudSkel, $edgeSkel, 'same elements but a different ORDER / nesting — the skeleton must be identical line by line');
        // The dead-session page state (recall of a held check whose table session died): same skeleton too, and the
        // recovery modal (deadSessionModal — rendered only in this state) is on BOTH pages.
        [$edgeDead, $cloudDead] = $pages[$dead];
        $this->assertSame($this->skeleton($cloudDead), $this->skeleton($edgeDead), 'dead-session page state: the skeleton must be identical');
        foreach (['deadSessionModal', 'dead-table-pick', 'dead-move', 'dead-reopen'] as $id) {
            $this->assertContains($id, $this->elementIds($edgeDead), "dead-session state: #{$id} on Edge");
            $this->assertContains($id, $this->elementIds($cloudDead), "dead-session state: #{$id} on Cloud");
        }
        $this->assertGreaterThan(count($cloudSkel), count($this->skeleton($cloudDead)), 'the dead-session state renders more (the recovery modal)');

        // Hidden carriers: Edge may add only the fixed branch_id carrier (branchSelect off); nothing else may differ.
        $edgeHidden = $this->hiddenInputNames($edge);
        $cloudHidden = $this->hiddenInputNames($cloud);
        $this->assertSame([], array_values(array_diff($cloudHidden, $edgeHidden)), 'hidden inputs Cloud has and Edge lacks');
        $this->assertSame([], array_values(array_diff(array_diff($edgeHidden, $cloudHidden), self::EDGE_ONLY_HIDDEN_INPUTS)), 'hidden inputs Edge adds beyond the allowlist');

        // (f) modal ids / toggles / targets / form ids / button ids / named controls — identical sets.
        foreach ($this->componentSets($cloud) as $kind => $cloudSet) {
            $edgeSet = $this->componentSets($edge)[$kind];
            $this->assertNotEmpty($cloudSet, "{$kind}: the Cloud render has none — extractor broken");
            $this->assertSame([], array_values(array_diff($cloudSet, $edgeSet)), "{$kind} present on Cloud, missing on Edge");
            $this->assertSame([], array_values(array_diff($edgeSet, $cloudSet)), "{$kind} present on Edge, missing on Cloud");
        }
        $this->assertGreaterThanOrEqual(15, count($this->componentSets($cloud)['modal ids']), 'the page should carry ~18 modals on the main state (22 in the source; some are state-gated)');

        // (d) no OLD Edge page id reappears on either render (the shared view is Online's markup, never the old copy's).
        foreach (['edge' => $edge, 'cloud' => $cloud] as $runtime => $html) {
            $found = array_values(array_filter(self::OLD_EDGE_ONLY_IDS, fn ($id) => str_contains($html, 'id="' . $id . '"')));
            $this->assertSame([], $found, "{$runtime} render carries OLD Edge-page ids: " . implode(', ', $found));
        }
    }

    /**
     * (b) every control id the W0 census registered (all 276 rows) exists in BOTH renders; the `online_required` rows are
     * on Edge too — in place, disabled where the view gates them (category (c) proves the state).
     */
    public function test_b_every_census_control_exists_in_both_renders_including_the_online_required_rows(): void
    {
        // Two page states per runtime: the main page and the dead-session recall (the recovery modal exists only there).
        $dead = $this->makeDeadSessionDeepLink();
        $pages = $this->renderBoth(['', $dead]);
        [$edge, $cloud] = $pages[''];
        $edgeAll = $edge . $pages[$dead][0];
        $cloudAll = $cloud . $pages[$dead][1];
        $rows = $this->censusRows();
        $this->assertGreaterThan(250, count($rows), 'the census fixture was blanked');

        $edgeIds = $this->elementIds($edge);
        $cloudIds = $this->elementIds($cloud);
        $missing = ['cloud' => [], 'edge' => []];
        $counts = [];
        // The W0 census rule: the id is on the page as an element id, or as a quoted id in the page script (a control the
        // SHARED JS creates at runtime — #open-table-error / #bill-preview-frame — or the state-only recovery modal the
        // JS drives; test (a) renders that state on both runtimes and finds the modal in both DOMs).
        $inDomOrScript = fn (string $html, array $ids, string $id) => in_array($id, $ids, true)
            || str_contains($html, "'{$id}'") || str_contains($html, 'id="' . $id . '"');
        $domOnly = [];
        foreach ($rows as $id => $state) {
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            if (! $inDomOrScript($cloudAll, $cloudIds, $id)) {
                $missing['cloud'][] = "{$id} ({$state})";
            }
            if (! $inDomOrScript($edgeAll, $edgeIds, $id)) {
                $missing['edge'][] = "{$id} ({$state})";
            }
            if (in_array($id, $cloudIds, true) && in_array($id, $edgeIds, true)) {
                $domOnly[] = $id;
            }
        }
        $this->assertGreaterThan(240, count($domOnly), 'most census controls are server-rendered elements on BOTH pages');
        $this->assertSame([], $missing['cloud'], "census controls missing on the CLOUD render:\n - " . implode("\n - ", $missing['cloud']));
        $this->assertSame([], $missing['edge'], "census controls missing on the EDGE render:\n - " . implode("\n - ", $missing['edge']));
        $this->assertArrayHasKey('online_required', $counts);
        $this->assertGreaterThanOrEqual(17, $counts['online_required'], 'the 17 Cloud-only rows must still be registered (they render in place on Edge)');

        // The two id sets are the same set (no runtime adds or drops a control).
        $this->assertSame([], array_values(array_diff($cloudIds, $edgeIds)), 'ids on Cloud only');
        $this->assertSame([], array_values(array_diff($edgeIds, $cloudIds)), 'ids on Edge only');
        $this->assertGreaterThan(280, count($cloudIds));

        // Counts with denominators for the report (never a percentage).
        @mkdir(storage_path('framework/testing'), 0777, true);
        file_put_contents(storage_path('framework/testing/edge-shared-pos-gate-summary.json'), json_encode([
            'census_rows' => count($rows), 'by_state' => $counts, 'census_rows_as_dom_elements_on_both' => count($domOnly),
            'ids_on_cloud' => count($cloudIds), 'ids_on_edge' => count($edgeIds),
            'skeleton_lines' => count($this->skeleton($cloud)), 'taken_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * (c) for EVERY PosRuntime::CAPABILITY_KEYS: the runtime flag per runtime (Cloud all on; Edge = EdgePosRuntimeFactory)
     * and the state of the control(s) the view gates on it — enabled on Cloud, disabled (+ hint) on Edge when off.
     */
    public function test_c_capability_gated_controls_have_the_expected_state_per_runtime(): void
    {
        [$edge, $cloud] = $this->renderBoth()[''];
        $edgeRt = $this->runtimeFrom($edge);
        $cloudRt = $this->runtimeFrom($cloud);
        $edgeDom = $this->xpath($edge);
        $cloudDom = $this->xpath($cloud);

        $covered = array_merge(array_keys(self::CAPABILITY_CONTROLS), array_keys(self::JS_GATED_CAPABILITIES));
        foreach (PosRuntime::CAPABILITY_KEYS as $key) {
            $this->assertTrue($cloudRt['capabilities'][$key], "Cloud: capability {$key} is on");
            $this->assertArrayHasKey($key, EdgePosRuntimeFactory::CAPABILITIES, "EdgePosRuntimeFactory::CAPABILITIES must define {$key}");
            $expectedEdge = EdgePosRuntimeFactory::CAPABILITIES[$key];
            $this->assertSame($expectedEdge, $edgeRt['capabilities'][$key], "Edge: capability {$key} must follow the factory matrix");
            if (! in_array($key, $covered, true)) {
                // No gate in the view: the capability must be ON for the Branch Server (otherwise an off capability would
                // render an enabled control — a gap the matrix must never open silently).
                $this->assertTrue($expectedEdge, "capability {$key} is OFF on Edge but the shared view gates no control on it");
                continue;
            }
            if (isset(self::JS_GATED_CAPABILITIES[$key])) {
                $route = self::JS_GATED_CAPABILITIES[$key];
                $this->assertNotNull($cloudRt['routes'][$route], "Cloud: route {$route} behind {$key}");
                if (! $expectedEdge) {
                    $this->assertNull($edgeRt['routes'][$route], "Edge: route {$route} must be null while {$key} is off");
                }
                $this->assertStringContainsString("POS.can('{$key}')", $edge, "the page JS gates {$key}");
                continue;
            }
            foreach (self::CAPABILITY_CONTROLS[$key]['selectors'] as $xp) {
                $cloudNodes = $cloudDom->query($xp);
                $edgeNodes = $edgeDom->query($xp);
                $this->assertGreaterThan(0, $cloudNodes->length, "{$key}: control {$xp} missing on Cloud");
                $this->assertSame($cloudNodes->length, $edgeNodes->length, "{$key}: control {$xp} count differs between runtimes");
                foreach ($cloudNodes as $n) {
                    $this->assertFalse($n->hasAttribute('disabled'), "Cloud: {$xp} must be enabled");
                }
                foreach ($edgeNodes as $n) {
                    if ($expectedEdge) {
                        $this->assertFalse($n->hasAttribute('disabled'), "Edge: {$xp} must be enabled ({$key} on)");
                    } else {
                        $this->assertTrue($n->hasAttribute('disabled'), "Edge: {$xp} must be disabled ({$key} off)");
                        if (self::CAPABILITY_CONTROLS[$key]['hint']) {
                            $this->assertSame($this->expectedHint($key), $n->getAttribute('title'), "Edge: {$xp} carries the {$key} hint");
                        }
                    }
                }
            }
        }
        // The cash tender stays enabled on Edge (nonCashTender gates only the non-cash options).
        $this->assertFalse($edgeDom->query('//select[@id="payment_method_id"]/option[@data-type="cash"]')->item(0)->hasAttribute('disabled'));
        // Off ⇒ route null (§7), on BOTH the factory and the rendered island.
        foreach (['reportsCenter' => 'reports', 'quickReportEmail' => 'quickReportEmail', 'manageFloors' => 'manageFloorsTables',
            'manageTables' => 'manageFloorsTables', 'customerQuickStore' => 'customerCreate', 'customerAddressStore' => 'customerAddressCreate'] as $route => $cap) {
            $this->assertFalse($edgeRt['capabilities'][$cap]);
            $this->assertNull($edgeRt['routes'][$route], "{$route} must be null while {$cap} is off");
            $this->assertNotNull($cloudRt['routes'][$route], "Cloud route {$route}");
        }
    }

    /**
     * (e) no Cloud-only endpoint leaks into the Edge runtime: every url-bearing attribute, every iframe/script/link URL and
     * every POS_RUNTIME route of the Edge render is an ALLOWLISTED edge.local.* route or a local asset.
     */
    public function test_e_every_url_of_the_edge_render_resolves_to_an_allowlisted_edge_route_or_a_local_asset(): void
    {
        $html = $this->renderEdge();
        $rt = $this->runtimeFrom($html);

        $urls = [];
        foreach ($rt['routes'] as $key => $template) {
            if ($template !== null) {
                $urls["POS_RUNTIME.routes.{$key}"] = $template;
            }
        }
        $xp = $this->xpath($html);
        foreach ($xp->query('//*[@href or @action or @src or @data-report-url or @data-return-url or @data-management-url or @data-board-url or @data-sync-url]') as $el) {
            foreach (['href', 'action', 'src', 'data-report-url', 'data-return-url', 'data-management-url', 'data-board-url', 'data-sync-url'] as $attr) {
                if ($el->hasAttribute($attr)) {
                    $urls[$el->nodeName . ($el->getAttribute('id') ? '#' . $el->getAttribute('id') : '') . '[' . $attr . ']'] = $el->getAttribute($attr);
                }
            }
        }
        $this->assertGreaterThan(60, count($urls), 'the URL inventory collapsed');

        $bad = [];
        foreach ($urls as $where => $url) {
            $path = (string) parse_url(str_replace('\/', '/', $url), PHP_URL_PATH);
            if ($url === '' || $url === '#' || $url === 'about:blank' || str_starts_with($url, '#') || str_starts_with($url, 'javascript:') || str_starts_with($url, '?')) {
                continue; // in-page anchors, lazy iframes (about:blank), query-only (capability-off route rendered empty)
            }
            if (preg_match('#^https?://#i', $url)) {
                $bad[] = "{$where}: absolute URL {$url}";
                continue;
            }
            if (str_starts_with($path, '/edge/local/assets/') || str_starts_with($path, '/edge/local/storage/')) {
                continue;
            }
            if (! str_starts_with($path, '/edge/local/')) {
                $bad[] = "{$where}: not an Edge-local path: {$url}";
                continue;
            }
            if ($this->allowlistedRouteFor($url) === null) {
                $bad[] = "{$where}: no ALLOWLISTED edge.local.* route for {$url}";
            }
        }
        $this->assertSame([], $bad, "Edge render URLs that are not allowlisted Edge routes / local assets:\n - " . implode("\n - ", $bad));

        // Belt and braces (EdgeSharedPosViewMySqlTest's leak regexes): no Cloud path in any quoted/attribute/url( context.
        $this->assertSame([], $this->cloudPathsIn($html), 'the Edge page must carry no Cloud path — only /edge/local/…');
        $this->assertStringNotContainsString('fonts.googleapis', $html);
        $this->assertSame(0, preg_match_all('#(?:src|href|action)\s*=\s*["\']https?://#i', $html));
        $this->assertSame(0, preg_match_all('#url\(\s*["\']?https?://#i', $html));
        $this->assertDoesNotMatchRegularExpression('#<div class="header[\s"]#', $html, 'the Cloud header must not render on Edge');
        $this->assertDoesNotMatchRegularExpression('#<div class="sidebar[\s"]#', $html, 'the Cloud sidebar must not render on Edge');
    }

    /**
     * (d) the OLD Edge page — STAGE B: STRICT is the ONLY mode. The old folder is deleted, `/edge/local/pos` renders
     * `tenant.pos.index`, NO edge.local.* route renders a Blade under resources/views/edge/pos/**, and the Phase 2 alias
     * `/edge/local/pos/shared` is a 301 to the canonical page (query string preserved). EDGE_POS_CUTOVER_STRICT is a no-op.
     */
    public function test_d_no_old_edge_page_renders_anywhere_and_the_alias_redirects_to_the_canonical_page(): void
    {
        $screen = Route::getRoutes()->getByName('edge.local.pos.screen');
        $this->assertNotNull($screen, 'edge.local.pos.screen (GET /edge/local/pos) must exist');
        $this->assertTrue($this->strictCutover(), 'strict is the only mode since Stage B');
        $this->assertDirectoryDoesNotExist(resource_path('views/edge/pos'), 'Stage B deleted resources/views/edge/pos/**');

        $renders = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'edge.local.')) {
                continue;
            }
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            $src = $this->methodSource($class, $method);
            if ($src !== null && preg_match_all("/view\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]/", $src, $m)) {
                $renders[$name] = array_values(array_unique($m[1]));
            }
        }

        $this->assertSame([], $renders, 'STRICT cutover: no edge.local.* route may render a Blade under resources/views/edge/pos/**: ' . json_encode($renders));
        $this->assertStringEndsWith('@sharedScreen', $screen->getActionName());
        $this->get('/edge/local/pos')->assertOk()->assertViewIs('tenant.pos.index');
        // The Phase 2 alias is a permanent redirect to THE page; deep-link queries survive it.
        $this->get('/edge/local/pos/shared')->assertStatus(301)->assertRedirect('/edge/local/pos');
        $this->get('/edge/local/pos/shared?mode=takeaway&held_sale_id=7')->assertStatus(301)->assertRedirect('/edge/local/pos?mode=takeaway&held_sale_id=7');
    }

    // ───────────────────────────── renders ─────────────────────────────

    /**
     * Render the SAME page states on both runtimes within ONE test run: every Edge render first (the appliance boot), then
     * ONE re-boot as Cloud and the same states over the tenant HTTP stack. Keys are the query strings ("" = main page).
     *
     * @param  string[]  $queries
     * @return array<string, array{0:string,1:string}> query → [edgeHtml, cloudHtml]
     */
    private function renderBoth(array $queries = ['']): array
    {
        $out = [];
        foreach ($queries as $q) {
            $out[$q] = [$this->renderEdge($q), null];
        }
        $this->bootCloud();
        foreach ($queries as $q) {
            $out[$q][1] = $this->renderCloud($q);
        }
        // Evidence / debugging: EDGE_GATE_DUMP_DIR=<dir> writes both renders and both skeletons (never required to pass).
        if ($dir = getenv('EDGE_GATE_DUMP_DIR')) {
            $dir = rtrim($dir, '/\\');
            @mkdir($dir, 0777, true);
            foreach ($out as $q => [$edge, $cloud]) {
                $tag = $q === '' ? 'main' : preg_replace('/[^a-z0-9]+/i', '-', ltrim($q, '?'));
                file_put_contents("{$dir}/edge-render-{$tag}.html", $edge);
                file_put_contents("{$dir}/cloud-render-{$tag}.html", $cloud);
                file_put_contents("{$dir}/edge-skeleton-{$tag}.txt", implode("\n", $this->skeleton($edge)));
                file_put_contents("{$dir}/cloud-skeleton-{$tag}.txt", implode("\n", $this->skeleton($cloud)));
            }
        }

        return $out;
    }

    /** A held dine-in check whose table session was closed → the dead-session page state (?held_sale_id=) on BOTH runtimes. */
    private function makeDeadSessionDeepLink(): string
    {
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $sessionId = (int) $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->assertStatus(201)->json('session_id');
        $heldId = (int) $this->postJson('/edge/local/pos/held-sales', ['order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->karahi, 'quantity' => 1]]])->assertStatus(201)->json('sale_id');
        DB::connection('tenant')->table('restaurant_table_sessions')->where('id', $sessionId)->update(['status' => 'closed', 'closed_at' => now()]);

        return '?held_sale_id=' . $heldId;
    }

    private function renderEdge(string $query = ''): string
    {
        $r = $this->get('/edge/local/pos' . $query);
        $this->assertSame(200, $r->getStatusCode(), "Edge render {$query}: " . Str::limit(strip_tags((string) $r->getContent()), 300));
        $r->assertViewIs('tenant.pos.index');

        return (string) $r->getContent();
    }

    /** Re-boot the application as Cloud (APP_ROLE unset) against the SAME tenant test database; seed the master tenant rows. */
    private function bootCloud(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        $this->refreshApplication();
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        $this->seedMasterTenant();
        DB::setDefaultConnection('tenant');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Auth::shouldUse('tenant');
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
    }

    /** GET /pos over the real tenant HTTP stack (IdentifyTenant by host → subscription access → route permission → POSController). */
    private function renderCloud(string $query = ''): string
    {
        $r = $this->get('http://' . $this->cloudHost . '/pos' . $query);
        $this->assertSame(200, $r->getStatusCode(), "Cloud render {$query}: " . Str::limit(strip_tags((string) $r->getContent()), 300));
        $r->assertViewIs('tenant.pos.index');

        return (string) $r->getContent();
    }

    private function pointEdgeLocalAtTenant(): void
    {
        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
    }

    /** The master rows IdentifyTenant + the subscription gate need (as BillPreviewPrintTargetMySqlTest seeds them). */
    private function seedMasterTenant(): void
    {
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->cloudHost)->delete();
        foreach ($m->table('tenants')->where('tenant_code', 'shvgate')->pluck('id') as $old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
        }
        $m->table('tenants')->where('tenant_code', 'shvgate')->delete();
        $this->masterTenantId = (int) $m->table('tenants')->insertGetId([
            'tenant_code' => 'shvgate', 'business_name' => 'Shared View Gate', 'owner_name' => 'Owner', 'owner_email' => 'owner@shvgate.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->masterTenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert(['tenant_id' => $this->masterTenantId, 'domain' => $this->cloudHost, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $planId = $m->table('plans')->where('code', 'shvgate-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'shvgate-plan', 'name' => 'Shared View Gate', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        // Only an EXISTING module is attached (never created — the master DB is shared with every other test).
        $key = $m->table('route_catalogs')->where('route_name', 'tenant.pos.index')->value('module_key');
        if ($key && ($module = Module::forRouteModuleKey($key)->first())) {
            $m->table('plan_modules')->updateOrInsert(['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->masterTenantId, 'plan_id' => $planId, 'status' => 'active', 'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cleanMasterTenant(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->cloudHost)->delete();
            if ($this->masterTenantId) {
                $m->table('subscriptions')->where('tenant_id', $this->masterTenantId)->delete();
                $m->table('tenant_databases')->where('tenant_id', $this->masterTenantId)->delete();
                $m->table('tenants')->where('id', $this->masterTenantId)->delete();
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    // ───────────────────────────── extraction helpers ─────────────────────────────

    /** Every `@can('…')` permission the shared view and its partials gate a control on (+ the change-terminal constant). */
    private function viewPermissionGates(): array
    {
        $src = '';
        foreach (array_merge([resource_path('views/tenant/pos/index.blade.php')], glob(resource_path('views/tenant/pos/partials/*.blade.php')) ?: []) as $f) {
            $src .= file_get_contents($f);
        }
        preg_match_all("/@can\(['\"]([a-z0-9_.-]+)['\"]\)/i", $src, $m);
        $perms = array_values(array_unique($m[1]));
        $perms[] = \App\Services\Security\UserDataScope::CHANGE_TERMINAL_PERMISSION;
        $perms[] = 'tenant.pos.index';
        $perms[] = 'tenant.pos.store';

        return array_values(array_unique($perms));
    }

    /** Decode window.POS_RUNTIME from a render. */
    private function runtimeFrom(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.POS_RUNTIME = (\{.*?\});<\/script>/s', $html, $m), 'the page must inject window.POS_RUNTIME');
        $rt = json_decode($m[1], true);
        $this->assertIsArray($rt);

        return $rt;
    }

    private function dom(string $html): \DOMDocument
    {
        // Inline scripts are not part of the skeleton and libxml (non-recovery) would end a <script> at the first "</" of a JS
        // template string — strip them before parsing (the raw HTML keeps serving the regex-based checks).
        $html = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $dom;
    }

    private function xpath(string $html): \DOMXPath
    {
        return new \DOMXPath($this->dom($html));
    }

    /**
     * The normalised DOM skeleton of the page content (#main-content subtree): one line per element — depth, tag, id,
     * classes (except the status-slot tone elements), every attribute except the runtime allowlist. Text nodes, comments,
     * <script>/<style>/<template>/<noscript> and hidden inputs are not part of the skeleton (hidden inputs are compared
     * separately; the JS parity is proven by the shared-view tests).
     *
     * @return string[]
     */
    private function skeleton(string $html): array
    {
        $xp = $this->xpath($html);
        $root = $xp->query('//*[@id="main-content"]')->item(0);
        $this->assertNotNull($root, 'layouts.pos content container #main-content');
        $lines = [];
        $this->walk($root, 0, $lines);

        return $lines;
    }

    private function walk(\DOMElement $el, int $depth, array &$lines): void
    {
        foreach ($el->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
                continue;
            }
            if ($tag === 'input' && strtolower($child->getAttribute('type')) === 'hidden') {
                continue;
            }
            $id = $child->getAttribute('id');
            $attrs = [];
            foreach ($child->attributes as $attr) {
                $name = strtolower($attr->nodeName);
                if ($name === 'id' || in_array($name, self::RUNTIME_ATTRIBUTES, true)) {
                    continue;
                }
                if ($name === 'class') {
                    if (in_array($id, self::RUNTIME_CLASS_IDS, true)) {
                        continue;
                    }
                    $attrs[$name] = implode(' ', preg_split('/\s+/', trim($attr->nodeValue), -1, PREG_SPLIT_NO_EMPTY));
                    continue;
                }
                if (str_starts_with($name, 'data-') && str_contains($name, 'url')) {
                    continue;
                }
                $attrs[$name] = preg_replace('/\s+/', ' ', trim($attr->nodeValue));
            }
            ksort($attrs);
            $lines[] = str_repeat(' ', $depth) . $tag . ($id !== '' ? '#' . $id : '')
                . ($attrs ? ' ' . implode(' ', array_map(fn ($k, $v) => $v === '' ? $k : $k . '=' . $v, array_keys($attrs), $attrs)) : '');
            $this->walk($child, $depth + 1, $lines);
        }
    }

    /** @return string[] names of hidden inputs (sorted, unique) */
    private function hiddenInputNames(string $html): array
    {
        $names = [];
        foreach ($this->xpath($html)->query('//input[@type="hidden"]') as $i) {
            $names[] = $i->getAttribute('name');
        }
        $names = array_values(array_unique(array_filter($names)));
        sort($names);

        return $names;
    }

    /** @return array<string, string[]> kind → sorted unique identifiers */
    private function componentSets(string $html): array
    {
        $xp = $this->xpath($html);
        // Scoped to the page content (#main-content): the layout's chrome slot differs BY DESIGN (Cloud: the hidden Online
        // header + sidebar; Edge: the hidden data island) and renders nothing visible in either runtime (W-A / W-B).
        $root = $xp->query('//*[@id="main-content"]')->item(0);
        $this->assertNotNull($root);
        $pluck = function (string $query, callable $fn) use ($xp, $root): array {
            $out = [];
            foreach ($xp->query('.' . $query, $root) as $n) {
                $out[] = $fn($n);
            }
            $out = array_values(array_unique(array_filter($out)));
            sort($out);

            return $out;
        };

        return [
            'modal ids' => $pluck('//*[contains(concat(" ", normalize-space(@class), " "), " modal ") and @id]', fn ($n) => $n->getAttribute('id')),
            'data-bs-toggle' => $pluck('//*[@data-bs-toggle]', fn ($n) => $n->getAttribute('data-bs-toggle') . '→' . $n->getAttribute('data-bs-target') . ($n->getAttribute('id') ? '#' . $n->getAttribute('id') : '')),
            'data-bs-target' => $pluck('//*[@data-bs-target]', fn ($n) => $n->getAttribute('data-bs-target')),
            'form ids' => $pluck('//form[@id]', fn ($n) => $n->getAttribute('id')),
            'button ids' => $pluck('//button[@id]', fn ($n) => $n->getAttribute('id')),
            'named controls' => $pluck('//*[self::input or self::select or self::textarea][@name and not(@type="hidden")]', fn ($n) => $n->nodeName . '[' . $n->getAttribute('name') . ']'),
            'modal structure' => $pluck('//*[contains(concat(" ", normalize-space(@class), " "), " modal ") and @id]', function ($n) use ($xp) {
                $parts = [];
                foreach (['modal-dialog', 'modal-header', 'modal-body', 'modal-footer'] as $c) {
                    $parts[] = $c . ':' . $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")]', $n)->length;
                }

                return $n->getAttribute('id') . ' ' . implode(' ', $parts);
            }),
        ];
    }

    /** @return string[] every element id of the render (unique, sorted) */
    private function elementIds(string $html): array
    {
        // Page content only (#main-content): the chrome slot is hidden and differs by design (Cloud header/sidebar ids).
        $xp = $this->xpath($html);
        $ids = [];
        foreach ($xp->query('//*[@id="main-content"]//*[@id]') as $el) {
            $ids[] = $el->getAttribute('id');
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        return $ids;
    }

    /** head/foot <link>/<script> asset URLs, normalised to a path under /assets/ (sorted by document order). */
    private function assetList(string $html): array
    {
        preg_match_all('#<(?:link|script)[^>]+(?:href|src)="([^"]+)"#i', $html, $m);

        return array_map(function (string $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);

            return preg_replace('#^/edge/local/assets/#', '/assets/', $path);
        }, $m[1]);
    }

    /** id → state for every `ids` row of the W0 census fixture (group state, per-id override). */
    private function censusRows(): array
    {
        $fixture = json_decode(file_get_contents(base_path(self::CENSUS_FIXTURE)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('online_view_sha1', $fixture, 'the required-id fixture must never pin a view hash');
        $rows = [];
        foreach ($fixture['ids'] as $state => $ids) {
            foreach ($ids as $id) {
                $rows[$id] = $state;
            }
        }

        return $rows;
    }

    private function expectedHint(string $capability): string
    {
        return EdgePosRuntimeFactory::LABELS[$capability] ?? 'Not available on the Branch Server — use the Online POS.';
    }

    /** Cloud paths that must never appear on an Edge page (quoted / attribute / url( context, JSON-unescaped). */
    private function cloudPathsIn(string $html): array
    {
        $text = str_replace('\/', '/', $html);
        preg_match_all('#(?<=["\'=(])/(pos|api/pos|api|printing|restaurant|held-sales|shifts|shifts-close-branch|sales-returns|sales-orders|ajax|reports)(?=[/?"\'\s)]|$)[^"\'\s<>)]*#', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /** The registered, allowlisted edge.local.* route (any verb) whose URI template matches $template (path only). */
    private function allowlistedRouteFor(string $template): ?string
    {
        $path = ltrim((string) parse_url(preg_replace('/\{[^}]+\}/', 'X', $template), PHP_URL_PATH), '/');
        $want = preg_replace('#/X(?=/|$)#', '/{}', $path);
        $allow = (array) config('edge.route_allowlist');
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            $name = (string) $route->getName();
            if ($uri === $want && str_starts_with($name, 'edge.local.') && in_array($name, $allow, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Strict "no old Edge page" mode — since Stage B the ONLY mode (the Phase 2 fallback route + old view are gone); the former
     * forcing variable EDGE_POS_CUTOVER_STRICT=1 is accepted and ignored. Same rule as the static half.
     */
    private function strictCutover(): bool
    {
        return true;
    }

    /** Source text of one controller method (for the static "which route renders which Blade" scan). */
    private function methodSource(string $class, string $method): ?string
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());

        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
