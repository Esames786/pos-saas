<?php

namespace Tests\MySql;

use App\Http\Controllers\Edge\EdgeLocalAssetController;
use App\Models\Tenant\User;
use App\Services\Edge\EdgePosRuntimeFactory;
use App\Services\Security\UserDataScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W1 (Team 1) — the cashier SHELL over the real branch_server route → middleware → controller → view-model → Blade
 * (GET /edge/local/pos), plus the JSON endpoints the shell itself drives (GET /shift for the shift badge, the 401 that
 * sends the operator back to the Edge login).
 *
 * PHASE 3 STAGE A (4 Oct 2026): GET /edge/local/pos renders THE shared Online cashier view (`tenant.pos.index` through
 * layouts.pos + EdgePosRuntimeFactory). Every assertion below targets the shared view's own markup and script: the Online
 * ids, the shared runtime-status slot (`#pos-runtime-slot`, owner A5), the hidden Edge chrome (`#pos-edge-chrome`), the
 * `POS_RUNTIME` island and `POS.route(...)` keys. What the OLD Edge page proved with its own ids/CSS/JS (the Edge nav
 * strip, the hand-written stylesheet, the W1 script contract) is retired here and listed in
 * docs/status/edge-phase3-stage-a-route-swap.md; geometry equality with Online is the regression gate's job
 * (EdgeSharedPosRegressionGateMySqlTest).
 *
 * Audit records: A1/E-01 header + navigation, A2 order-type tabs, A33/E-06 Branch & Terminal context, A35 touch
 * sizes, A36/E-08 states (401/419), A41/R1.1 shift badge, A32 calculator, A34 shortcuts, A43 deep links, E-10 offline assets.
 */
class EdgeCashierShellHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $terminal2Id;
    private int $userId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();

        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');

        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'combo_components', 'combos', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users']);
        $this->branchId = $this->makeBranch(['name' => 'Shell Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter One']);
        $this->terminal2Id = $this->makeTerminal($this->branchId, ['name' => 'Counter Two']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'default_terminal_id' => $this->terminalId, 'employee_code' => 'SHEL' . Str::random(4)]);
        $categoryId = $this->makeCategory(['name' => 'Grills']);
        $productId = $this->makeProduct($categoryId, ['name' => 'Chicken Tikka', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 250]);
        $this->makePaymentMethod(['method_type' => 'cash', 'name' => 'Cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $productId, 'product_variant_id' => null, 'quantity' => 10]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function page(string $query = ''): string
    {
        return $this->get('/edge/local/pos' . $query)->assertOk()->assertViewIs('tenant.pos.index')->getContent();
    }

    /** The opening tag of the element carrying this id (attributes may span lines). */
    private function tag(string $html, string $id, string $element = '[a-z]+'): string
    {
        $this->assertSame(1, preg_match('/<' . $element . '\b[^>]*\sid="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m), "#{$id} is on the page");

        return $m[0];
    }

    // ── A1 / E-01: the Online POS title row, the SHARED runtime-status slot in it, and the zero-geometry Edge chrome ──
    public function test_header_is_the_online_pos_title_row_with_the_shared_runtime_slot_and_the_hidden_edge_chrome(): void
    {
        $html = $this->page();
        foreach (['pos-sidebar-toggle', 'view-tables-btn', 'pos-session-bar', 'pos-session-details', 'pos-session-table-no',
            'pos-session-no', 'pos-session-waiter', 'pos-session-guests', 'pos-session-open-check', 'pos-session-actions', 'mode-tabs-wrapper',
            'pos-customer-slot', 'pos-customer-chip', 'chip-cust-name', 'chip-cust-phone', 'chip-cust-address', 'chip-cust-clear', 'pos-customer-btn',
            'order-controls-row', 'ctx-branch-name', 'ctx-terminal-name', 'pos-shift-status', 'pos-shift-badge', 'pos-shift-detail', 'pos-shift-open-link',
            'no-terminal-warning', 'held-orders-btn', 'completed-orders-btn', 'last-print-btn',
            // the shared runtime-status slot (owner A5: same box in both runtimes) + the hidden Edge chrome slot (W-B)
            'pos-runtime-slot', 'pos-runtime-state', 'pos-runtime-sub', 'pos-runtime-pending',
            'pos-edge-chrome', 'pos-edge-chrome-data', 'pos-edge-logout-form', 'main-content'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "control #{$id}");
        }
        $this->assertStringContainsString('<h1 class="h3 mb-0">Restaurant POS</h1>', $html, 'the Online title');
        $this->assertStringContainsString('window.POS_RUNTIME = {"mode":"edge"', $html, 'the runtime island precedes every page script');
        $this->assertStringContainsString('"identity":{"branch_id":' . $this->branchId . ',"branch_name":"Shell Branch","branch_selectable":false', $html);

        // The bound branch is the selected (and only) option of the Online branch select — disabled with the capability hint,
        // and a hidden carrier keeps branch_id in the payload (a disabled select does not post).
        $select = $this->tag($html, 'branch_id', 'select');
        $this->assertStringContainsString(' disabled', $select);
        $this->assertStringContainsString('title="' . EdgePosRuntimeFactory::LABELS['branchSelect'] . '"', $select);
        $this->assertMatchesRegularExpression('/<option value="' . $this->branchId . '"[^>]*\sselected>\s*Shell Branch\s*<\/option>/', $html);
        $this->assertStringContainsString('<input type="hidden" name="branch_id" value="' . $this->branchId . '">', $html);

        // The status slot sits on the title row (before the mode tabs), carries the runtime mode and a business label from
        // POS_RUNTIME.authority; the Edge chrome is OUTSIDE #main-content and renders nothing visible.
        $slot = strpos($html, 'id="pos-runtime-slot"');
        $this->assertLessThan(strpos($html, 'id="mode-tabs-wrapper"'), $slot, 'the slot is on the title row, above the mode tabs');
        $this->assertStringContainsString('data-runtime-mode="edge"', $html);
        $this->assertMatchesRegularExpression('/<span class="badge [^"]*" id="pos-runtime-state">[A-Z][A-Z ]+<\/span>/', $html, 'a business authority label, never empty');
        $this->assertLessThan(strpos($html, 'id="main-content"'), strpos($html, 'id="pos-edge-chrome"'));
        $this->assertMatchesRegularExpression('/<div id="pos-edge-chrome" hidden aria-hidden="true" style="display:none"/', $html);
        $this->assertStringContainsString('<form id="pos-edge-logout-form" method="POST" action="/edge/local/logout"', $html);
    }

    // ── A2: order-type TABS of the user's allowed set (the hidden Online select keeps the payload contract) ──
    public function test_order_type_is_a_tab_row_of_the_users_allowed_types(): void
    {
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['allowed_order_types' => json_encode(['takeaway', 'delivery']), 'default_order_type' => 'delivery']);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $html = $this->page();

        $this->assertMatchesRegularExpression('/class="mode-tab active" data-mode-tab="delivery">Delivery</', $html);
        $this->assertMatchesRegularExpression('/class="mode-tab " data-mode-tab="takeaway">Takeaway</', $html);
        $this->assertStringNotContainsString('data-mode-tab="dine_in"', $html, 'a type the user may not run is not offered');
        $this->assertStringNotContainsString('data-mode-tab="quick_sale"', $html);
        $this->assertMatchesRegularExpression('/<div class="d-none">\s*<select id="order_type" name="order_type">/', $html, 'the Online select is kept hidden for the payload');
        // Switching with items asks first and clears the order (Online applyModeTab); a recalled check locks the tabs.
        $this->assertStringContainsString("title: 'Start a fresh ' + button.textContent.trim() + ' order?'", $html);
        $this->assertStringContainsString('function applyModeTab(button, confirmed)', $html);
        $this->assertStringContainsString("POS.route('posIndex') + '?branch_id=' + newBranch + '&mode=' + newType", $html, 'mode deep link through the runtime route');
        $this->assertStringContainsString("classList.add('pos-controls-locked')", $html, 'a recalled check locks the tabs');
    }

    // ── A33 / E-06: Branch & Terminal — bound branch (disabled select + hint), terminal in the Online dialog, list scoped by UserDataScope ──
    public function test_branch_and_terminal_context_dialog_and_change_gate(): void
    {
        // W-E: the seeded cashier holds the full catalogue template (incl. change-terminal) — model the pinned operator explicitly.
        $this->revokeEdgePermission($this->userId, UserDataScope::CHANGE_TERMINAL_PERMISSION);
        $html = $this->page();
        $this->assertStringContainsString('id="posContextModal"', $html);
        $this->assertStringContainsString('<select id="terminal_id" name="terminal_id"', $html);
        $this->assertStringContainsString(EdgePosRuntimeFactory::LABELS['branchSelect'], $html, 'the appliance is bound to one branch — the select is disabled with the hint');
        $this->assertStringContainsString(' disabled', $this->tag($html, 'branch_id', 'select'));
        // X2 (Online UserDataScope::terminalsForPos): a pinned operator is offered ONLY his assigned terminal.
        $this->assertStringContainsString('>Counter One &mdash; Shell Branch</option>', $html);
        $this->assertStringNotContainsString('Counter Two', $html, 'Change needs the change-terminal permission — the other counter is not offered');
        $this->assertStringContainsString('"terminal_selection":"session"', $html);

        $this->grantEdgePermission($this->userId, UserDataScope::CHANGE_TERMINAL_PERMISSION);
        $html = $this->page();
        $this->assertStringContainsString('>Counter One &mdash; Shell Branch</option>', $html);
        $this->assertStringContainsString('>Counter Two &mdash; Shell Branch</option>', $html, 'change-terminal → every counter of the bound branch');
        $this->assertStringContainsString('data-bs-target="#posContextModal" title="Change branch or terminal"', $html);
    }

    // ── A44/R3.1/R5.1 gating of the header entry points by the Online permission (@can) ──
    public function test_return_and_quick_report_buttons_follow_the_online_permission(): void
    {
        // W-E: the seeded cashier holds the full catalogue template — model the operator without Return / Quick Report explicitly
        // (the Online button is @can('tenant.sales-returns.create'); the posting permission is revoked with it).
        $this->revokeEdgePermission($this->userId, 'tenant.sales-returns.create');
        $this->revokeEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->revokeEdgePermission($this->userId, 'tenant.pos.quick-report-send');
        $html = $this->page();
        $this->assertStringNotContainsString('id="pos-return-btn"', $html, 'no return permission → not rendered (Online @can), never wired');
        $this->assertStringNotContainsString('id="pos-quick-report-btn"', $html);
        $this->assertStringNotContainsString('id="posReturnModal"', $html);

        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.create');
        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->grantEdgePermission($this->userId, 'tenant.pos.quick-report-send');
        $html = $this->page();
        $return = $this->tag($html, 'pos-return-btn', 'button');
        $this->assertStringNotContainsString('disabled', $return, 'salesReturn capability is ON on the Branch Server');
        $this->assertStringContainsString('data-return-url="/edge/local/pos/sales-returns/create?embed=1"', $return, 'the Return window opens the Edge return page (same tenant view)');
        $this->assertStringNotContainsString('disabled', $this->tag($html, 'pos-quick-report-btn', 'button'));
        $this->assertStringContainsString('"salesReturn":true', $html);
        $this->assertStringContainsString('"quickReport":true', $html);
    }

    // ── A41 / R1.1: the shift badge polls GET /shift (terminal-scoped; amounts already stripped by W0b) ──
    public function test_shift_badge_endpoint_and_page_contract(): void
    {
        $html = $this->page();
        $this->assertStringContainsString("fetch(POS.route('shiftStatus') + '?terminal_id=' + encodeURIComponent(tid)", $html);
        $this->assertStringContainsString('"shiftStatus":"\/edge\/local\/pos\/shift"', $html, 'the runtime route the badge polls');
        $this->assertStringContainsString("'No open shift'", $html);
        $this->assertStringContainsString("'Shift open'", $html);
        $this->assertStringContainsString('setInterval(function () { refreshShiftStatus(); }, 300000)', $html, 'Online 5-minute resync');
        $this->assertStringContainsString('<a href="/edge/local/pos/shifts/open" id="pos-shift-open-link"', $html, 'Open shift → the SAME tenant shift page on the Edge route');

        $this->getJson('/edge/local/pos/shift')->assertStatus(422);                   // no terminal yet → badge stays hidden
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->getJson('/edge/local/pos/shift')->assertOk()->assertJsonPath('shift', null);   // → "No open shift" + Open shift link
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertSuccessful();
        $s = $this->getJson('/edge/local/pos/shift')->assertOk();
        $this->assertNotNull($s->json('shift.business_date'), '→ "Shift open · Business date …"');
        $this->assertNotNull($s->json('shift.opened_at'));
    }

    // ── A36 / E-08: states — the shared transport sends 401/419 to the Edge login; the shared overlay / loader are on the page ──
    public function test_states_and_session_expiry_contract(): void
    {
        $html = $this->page();
        foreach (['id="global-loader"', 'id="pos-runtime-overlay"', 'id="pos-runtime-overlay-close"', 'sweetalert2.all.min.js',
            'res.status === 401 || res.status === 419', 'window.location.href = transport.unauthenticated_redirect',
            '"transport":{"csrf_header":"X-CSRF-TOKEN","body":"json","unauthenticated_redirect":"\/edge\/local\/login"}'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // The endpoint the page hits after the session is gone answers 401 JSON (not a login HTML page) → the transport redirects.
        Auth::guard('tenant')->logout();
        $this->getJson('/edge/local/pos/shift')->assertStatus(401);
        $this->get('/edge/local/pos')->assertRedirect('/edge/local/login');
    }

    // ── A32 calculator, A34 shortcuts, A43 deep links ──
    public function test_calculator_shortcuts_and_deep_links_are_on_the_page(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('id="calculator-panel"', $html);
        $this->assertStringContainsString('id="calculator_heading"', $html);
        $this->assertStringContainsString('id="calc-display"', $html);
        $this->assertStringContainsString('id="toggle-calc-btn"', $html);
        $this->assertSame(17, preg_match_all('/<button type="button" data-key="/', $html), '16 keys + "=" (Online keypad)');
        foreach (["event.key === 'f'", "event.key === 'h'", "event.key === 'l'", "event.key === 'p'", "event.key === 'Enter'", "event.key === 'm'"] as $key) {
            $this->assertStringContainsString($key, $html, "shortcut {$key}");
        }
        // Deep links are resolved server-side (the same query keys as Online) and carried in the form / the recall island.
        $this->assertStringContainsString('<input type="hidden" name="held_sale_id"', $html);
        $this->assertStringContainsString('id="restaurant_table_session_id"', $html);
        $this->assertStringContainsString('preload held sale (page-load recall via ?held_sale_id=)', $html);
        $this->assertStringContainsString('const heldSale   = ', $html);
        $this->assertMatchesRegularExpression('/class="mode-tab active" data-mode-tab="takeaway">Takeaway</', $this->page('?mode=takeaway'));
    }

    // ── E-10: the Online stylesheet set, served locally; no external asset; the Online favicon through the asset route ──
    public function test_the_page_links_the_online_stylesheet_set_locally_and_no_external_asset(): void
    {
        config(['edge.route_allowlist' => array_values(array_unique(array_merge((array) config('edge.route_allowlist'), [EdgeLocalAssetController::ROUTE_NAME])))]);
        $html = $this->page();
        foreach (['css/bootstrap.min.css', 'css/style.css', 'css/fonts-local.css', 'css/a11y-custom.css', 'plugins/tabler-icons/tabler-icons.min.css'] as $css) {
            $this->assertStringContainsString('<link rel="stylesheet" href="' . EdgePosRuntimeFactory::ASSET_BASE . '/' . $css . '">', $html, $css);
        }
        $this->assertStringContainsString('<link rel="shortcut icon" type="image/x-icon" href="' . EdgePosRuntimeFactory::ASSET_BASE . '/img/favicon.png">', $html);
        $this->get(EdgePosRuntimeFactory::ASSET_BASE . '/img/favicon.png')->assertOk();
        // Every src/href is same-origin or relative — never a CDN / other host.
        preg_match_all('/(?:src|href)\s*=\s*["\']\s*((?:https?:)?\/\/[^"\']*)/i', $html, $m);
        $foreign = array_values(array_filter($m[1], fn ($u) => ! str_starts_with($u, url('/') . '/') && $u !== url('/')));
        $this->assertSame([], $foreign, 'no external/CDN asset or link');
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('fonts.googleapis', $html);
    }

    // ── W-C (owner decision A3) — the no-external-URL rule extends INTO every stylesheet the page links: each one is fetched
    //    through the app (the appliance's own asset route), and none may @import or url() another host. The self-hosted
    //    Nunito (fonts-local.css → fonts/nunito/*.woff2) resolves through the same route. ──
    public function test_every_linked_stylesheet_is_served_by_the_app_and_carries_no_import_or_remote_url(): void
    {
        config(['edge.route_allowlist' => array_values(array_unique(array_merge((array) config('edge.route_allowlist'), [EdgeLocalAssetController::ROUTE_NAME])))]);
        $html = $this->page();

        preg_match_all('/<link\b[^>]*\brel\s*=\s*["\']stylesheet["\'][^>]*>/i', $html, $tags);
        $hrefs = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) {
                $hrefs[] = html_entity_decode($h[1], ENT_QUOTES);
            }
        }
        $this->assertNotEmpty($hrefs, 'the page links its stylesheets');
        $origin = rtrim(url('/'), '/');

        foreach (array_unique($hrefs) as $href) {
            $this->assertTrue(str_starts_with($href, $origin . '/') || (str_starts_with($href, '/') && ! str_starts_with($href, '//')), "{$href} must be same-origin");
            $path = str_starts_with($href, $origin) ? substr($href, strlen($origin)) : $href;
            $res = $this->get($path);
            $this->assertSame(200, $res->getStatusCode(), "{$path} must be served by the appliance itself");
            $this->assertStringStartsWith('text/css', (string) $res->headers->get('Content-Type'), $path);
            $css = $res->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
                ? (string) file_get_contents($res->baseResponse->getFile()->getPathname())
                : (string) $res->getContent();
            $this->assertStringNotContainsString('@import', $css, "{$path} must not @import anything");
            $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?(?:https?:)?\/\//i', $css, "{$path} must not url() another host");
            $this->assertStringNotContainsString('fonts.googleapis', $css, $path);

            if (str_ends_with(parse_url($path, PHP_URL_PATH) ?: '', '/fonts-local.css')) {
                preg_match_all('/url\("([^"]+\.woff2)"\)/', $css, $fonts);
                $this->assertNotEmpty($fonts[1]);
                foreach (array_unique($fonts[1]) as $rel) {
                    $fontPath = preg_replace('#/css/[^/]+$#', '/', parse_url($path, PHP_URL_PATH)) . preg_replace('#^\.\./#', '', $rel);
                    $font = $this->get($fontPath);
                    $this->assertSame(200, $font->getStatusCode(), "{$fontPath} (Nunito) must be served locally");
                    $this->assertStringStartsWith('font/woff2', (string) $font->headers->get('Content-Type'));
                }
            }
        }
    }

    // ── E-10: the packaged Bootstrap / SweetAlert2 / Tabler load through the local asset route (allowlisted on the appliance) ──
    public function test_local_assets_are_linked_through_the_allowlisted_asset_route(): void
    {
        config(['edge.route_allowlist' => array_values(array_unique(array_merge((array) config('edge.route_allowlist'), [EdgeLocalAssetController::ROUTE_NAME])))]);
        $this->assertTrue(EdgeLocalAssetController::available());
        $html = $this->page();
        foreach (['css/bootstrap.min.css', 'plugins/tabler-icons/tabler-icons.min.css', 'js/bootstrap.bundle.min.js', 'plugins/sweetalert/sweetalert2.all.min.js'] as $asset) {
            $this->assertStringContainsString(EdgePosRuntimeFactory::ASSET_BASE . '/' . $asset, $html, $asset);
            $this->get('/edge/local/assets/' . $asset)->assertOk();
        }
        // The login page too (unauthenticated).
        Auth::guard('tenant')->logout();
        $login = $this->get('/edge/local/login')->assertOk()->getContent();
        $this->assertStringContainsString(url('/edge/local/assets/css/bootstrap.min.css'), $login);
        $this->assertStringContainsString('<link rel="icon" href="data:image/svg+xml,', $login);
        $this->assertStringContainsString('Branch Server', $login);
    }
}
