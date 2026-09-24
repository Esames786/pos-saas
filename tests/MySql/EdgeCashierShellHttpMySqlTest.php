<?php

namespace Tests\MySql;

use App\Http\Controllers\Edge\EdgeLocalAssetController;
use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W1 (Team 1) — the cashier SHELL in the Online layout, over the real branch_server route → middleware → controller →
 * view-model → Blade (GET /edge/local/pos), plus the JSON endpoints the shell itself drives (GET /shift for the shift
 * badge, the 401 that sends the operator back to the Edge login).
 *
 * Audit records: A1/E-01 header + navigation, A2 order-type tabs, A33/E-06 Branch & Terminal context, A35 touch
 * sizes, A36/E-08 states (toast/confirm/spinner/401-419), A41/R1.1 shift badge, A32 calculator, A34 shortcuts,
 * A43 deep links, E-09 responsive rules, E-10 offline assets + favicon. What a server-render test can prove is proved
 * here (markup, ids, gating, the stylesheet's rules, the script contract); the executed behaviour is proven in the
 * browser (docs/status/edge-w1-team1-report.md BROWSER_ACCEPTANCE_STEP per record).
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

    private function page(): string
    {
        return $this->get('/edge/local/pos')->assertOk()->getContent();
    }

    // ── A1 / E-01: Online POS header structure; Edge entry points in the compact strip ──
    public function test_header_follows_the_online_pos_layout_with_edge_links_in_a_secondary_strip(): void
    {
        $html = $this->page();
        foreach (['pos-header', 'pos-title-row', 'pos-sidebar-toggle', 'view-tables-btn', 'pos-session-bar', 'pos-session-details', 'pos-session-table-no',
            'pos-session-no', 'pos-session-waiter', 'pos-session-guests', 'pos-session-open-check', 'pos-session-actions', 'mode-tabs-wrapper',
            'pos-customer-slot', 'pos-customer-chip', 'chip-cust-name', 'chip-cust-phone', 'chip-cust-address', 'chip-cust-clear', 'pos-customer-btn',
            'order-controls-row', 'ctx-branch-name', 'ctx-terminal-name', 'pos-shift-status', 'pos-shift-badge', 'pos-shift-detail', 'pos-shift-open-link',
            'no-terminal-warning', 'shift-btn', 'held-orders-btn', 'completed-orders-btn', 'last-print-btn', 'edge-nav', 'edge-nav-links', 'sync-chip',
            'health-link', 'logout-btn'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "header control #{$id}");
        }
        $this->assertStringContainsString('<h1>POS</h1>', $html);
        $this->assertStringContainsString('<span class="pos-title-pre">Restaurant</span>', $html, 'Online title reads "Restaurant POS"');
        $this->assertStringContainsString('<strong id="ctx-branch-name">Shell Branch</strong>', $html);

        // The Edge-only links sit INSIDE the secondary strip (after the Online controls of the title row), never between them.
        $nav = strpos($html, 'id="edge-nav"');
        foreach (['id="health-link"', 'id="logout-btn"', 'id="sync-chip"'] as $needle) {
            $this->assertGreaterThan($nav, strpos($html, $needle), "{$needle} lives in the Edge strip");
        }
        $this->assertLessThan($nav, strpos($html, 'id="view-tables-btn"'));
        $this->assertLessThan(strpos($html, 'id="mode-tabs-wrapper"'), $nav, 'the strip is on the title row, above the mode tabs');
        // Finance links only with their permission (the census user of the render test holds none here).
        $this->assertStringNotContainsString('id="suppliers-link"', $html);
        $this->assertStringNotContainsString('id="journal-link"', $html);
    }

    // ── A2: order-type TABS of the user's allowed set (hidden select keeps the payload contract) ──
    public function test_order_type_is_a_tab_row_of_the_users_allowed_types(): void
    {
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['allowed_order_types' => json_encode(['takeaway', 'delivery']), 'default_order_type' => 'delivery']);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $html = $this->page();

        $this->assertMatchesRegularExpression('/class="mode-tab active" aria-selected="true" data-mode-tab="delivery">Delivery</', $html);
        $this->assertMatchesRegularExpression('/class="mode-tab " aria-selected="false" data-mode-tab="takeaway">Takeaway</', $html);
        $this->assertStringNotContainsString('data-mode-tab="dine_in"', $html, 'a type the user may not run is not offered');
        $this->assertStringNotContainsString('data-mode-tab="quick_sale"', $html);
        $this->assertMatchesRegularExpression('/<select id="order-type" hidden/', $html);
        // Switching with items asks first and clears the order (Online applyModeTab) — the script contract:
        $this->assertStringContainsString("'Start a fresh ' + label + ' order?'", $html);
        $this->assertStringContainsString('function resetOrderForModeSwitch()', $html);
        $this->assertStringContainsString("u.searchParams.set('mode', mode)", $html);
        $this->assertStringContainsString("classList.add('pos-controls-locked')", $html, 'a recalled check locks the tabs');
    }

    // ── A33 / E-06: Branch & Terminal context — bound branch (no selector), terminal in the Online dialog, Change gated ──
    public function test_branch_and_terminal_context_dialog_and_change_gate(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('id="posContextModal"', $html);
        $this->assertStringContainsString('<select id="terminal"', $html);
        $this->assertStringContainsString('the branch cannot be changed here', $html);
        $this->assertStringNotContainsString('id="branch_id"', $html, 'the appliance is bound to one branch — no branch selector');
        $this->assertStringNotContainsString('id="pos-context-change-btn"', $html, 'Change needs the change-terminal permission (Online @can)');

        $this->grantEdgePermission($this->userId, UserDataScope::CHANGE_TERMINAL_PERMISSION);
        $html = $this->page();
        $this->assertStringContainsString('id="pos-context-change-btn"', $html);
        $this->assertStringContainsString('"canChangeTerminal":true', $html);
    }

    // ── A44/R3.1/R5.1 gating of the header entry points by the Online permission ──
    public function test_return_and_quick_report_buttons_follow_the_online_permission(): void
    {
        $html = $this->page();
        $this->assertMatchesRegularExpression('/id="pos-return-btn"[^>]*hidden data-denied="1"/', $html, 'no return permission → hidden, never wired');
        $this->assertMatchesRegularExpression('/id="pos-quick-report-btn"[^>]*hidden data-denied="1"/', $html);

        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->grantEdgePermission($this->userId, 'tenant.pos.quick-report-send');
        $html = $this->page();
        $this->assertDoesNotMatchRegularExpression('/id="pos-return-btn"[^>]*data-denied/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pos-quick-report-btn"[^>]*data-denied/', $html);
        $this->assertStringContainsString('"canSalesReturn":true', $html);
        $this->assertStringContainsString("w1Wire('pos-return-btn'", $html);
    }

    // ── A41 / R1.1: the shift badge polls GET /shift (terminal-scoped; amounts already stripped by W0b) ──
    public function test_shift_badge_endpoint_and_page_contract(): void
    {
        $html = $this->page();
        $this->assertStringContainsString("api('GET', '/shift', undefined, { quiet: true })", $html);
        $this->assertStringContainsString("'No open shift'", $html);
        $this->assertStringContainsString("'Shift open'", $html);
        $this->assertStringContainsString('setInterval(refreshShiftStatus, 300000)', $html, 'Online 5-minute resync');

        $this->getJson('/edge/local/pos/shift')->assertStatus(422);                   // no terminal yet → badge stays hidden
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->getJson('/edge/local/pos/shift')->assertOk()->assertJsonPath('shift', null);   // → "No open shift" + Open shift link
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertSuccessful();
        $s = $this->getJson('/edge/local/pos/shift')->assertOk();
        $this->assertNotNull($s->json('shift.business_date'), '→ "Shift open · Business date …"');
        $this->assertNotNull($s->json('shift.opened_at'));
    }

    // ── A36 / E-08: states — severity toast, confirm, busy buttons, loading bar, 401/419 → Edge login ──
    public function test_states_and_session_expiry_contract(): void
    {
        $html = $this->page();
        foreach (['id="toast"', 'id="edge-loading"', 'id="edge-confirm"', 'function toast(msg, level)', 'function toastError(msg)', 'function confirmDialog(o)',
            'function showSpinner()', 'function hideSpinner()', 'function setButtonBusy(button, busy, label)', 'function showInlineError(target, msg)',
            'function showInlineToast(target, msg, level)', 'res.status === 401 || res.status === 419', "const LOGIN_URL = '" . url('/edge/local/login') . "'"] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // The endpoint the page hits after the session is gone answers 401 JSON (not a login HTML page) → the script redirects.
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
        $this->assertStringContainsString("b.id = 'toggle-calc-btn'", $html);
        $this->assertSame(17, preg_match_all('/<button type="button" data-key="/', $html), '16 keys + "=" (Online keypad)');
        foreach (["k === 'f'", "k === 'h'", "k === 'l'", "k === 'p'", "k === 'Enter'", "k === 'm'", "e.key === 'Escape'"] as $key) {
            $this->assertStringContainsString($key, $html, "shortcut {$key}");
        }
        foreach (["q.get('mode')", "q.get('held_sale_id')", "q.get('table_session_id')", "q.get('customer_id')", 'await loadHeld(heldId)', 'startCheckOnSession(table)'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }

    // ── A35 / E-09 / page-wide look: Online sizes + breakpoints; E-10: no external asset, inline icon ──
    public function test_online_sizes_breakpoints_and_offline_assets(): void
    {
        $html = $this->page();
        foreach (['grid-template-columns:minmax(0,1fr) 500px', 'repeat(auto-fill,minmax(170px,1fr))', 'min-height:148px', 'min-height:44px', '.actions button { min-height:42px',
            '@media (min-width: 1200px) and (min-height: 720px)', '@media (max-width: 1199px)', '@media (max-width: 991.98px)', '@media (max-width: 800px)', '--bg:#F7F7F7', '--primary:#CAA23F'] as $rule) {
            $this->assertStringContainsString($rule, $html, "stylesheet carries {$rule}");
        }
        $this->assertStringContainsString('<link rel="icon" href="data:image/svg+xml,', $html, 'inline icon → no /favicon.ico 404');
        // Every src/href is same-origin (url() renders the appliance's own host) or a data: URI — never a CDN / other host.
        preg_match_all('/(?:src|href)\s*=\s*["\']\s*((?:https?:)?\/\/[^"\']*)/i', $html, $m);
        $foreign = array_values(array_filter($m[1], fn ($u) => ! str_starts_with($u, url('/') . '/') && $u !== url('/')));
        $this->assertSame([], $foreign, 'no external/CDN asset or link');
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('fonts.googleapis', $html);
    }

    // ── E-10: the packaged Bootstrap / SweetAlert2 / Tabler load through the local asset route once it is allowlisted ──
    public function test_local_assets_are_linked_only_when_the_asset_route_is_allowed(): void
    {
        $allowed = EdgeLocalAssetController::available();
        $html = $this->page();
        $this->assertSame($allowed, str_contains($html, '/edge/local/assets/css/bootstrap.min.css'), 'no link that would 404');

        config(['edge.route_allowlist' => array_values(array_unique(array_merge((array) config('edge.route_allowlist'), [EdgeLocalAssetController::ROUTE_NAME])))]);
        $this->assertTrue(EdgeLocalAssetController::available());
        $html = $this->page();
        foreach (['css/bootstrap.min.css', 'plugins/tabler-icons/tabler-icons.min.css', 'js/bootstrap.bundle.min.js', 'plugins/sweetalert/sweetalert2.all.min.js'] as $asset) {
            $this->assertStringContainsString(url('/edge/local/assets/' . $asset), $html, $asset);
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
