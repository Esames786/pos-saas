<?php

namespace Tests\Feature\Edge;

use App\Support\Pos\CloudPosRuntimeFactory;
use App\Support\Pos\PosRuntime;
use Tests\TestCase;

/**
 * W-G3 (X1) — the Edge CHROME slot of the shared POS layout and the split-bill page's script, after the browser proof:
 *
 *   - public/assets/js/theme-script.js (loaded by layouts.pos on BOTH runtimes) looks for `.sidebar` and, finding none,
 *     logs "Sidebar element not found" on every Edge page and SKIPS the data-theme/data-sidebar/data-color/data-layout
 *     attributes it writes on <html> for the Online page (whose Cloud chrome renders the sidebar). The Edge chrome
 *     partial carries a hidden, zero-geometry `.sidebar` hook so Edge runs the SAME code path — it is NOT the Cloud
 *     sidebar (`<div class="sidebar">` never renders on Edge: EdgeSharedPosViewMySqlTest).
 *   - the split-bill page's script referenced a #tendered_amount that does not exist on the page (either layout) and
 *     raised "Cannot read properties of null" (3 pageerrors inside the Edge POS modal); it is guarded now.
 */
class EdgeChromePartialTest extends TestCase
{
    private function edgeRuntime(): PosRuntime
    {
        $c = app(CloudPosRuntimeFactory::class)->make(1, 'Main');

        return new PosRuntime(
            mode: PosRuntime::MODE_EDGE,
            routes: array_merge($c->routes, ['syncSummary' => '/edge/local/pos/sync/summary', 'status' => '/edge/local/health', 'logout' => '/edge/local/logout', 'terminals' => '/edge/local/pos/terminals', 'terminalSelect' => '/edge/local/pos/terminal/select']),
            capabilities: $c->capabilities,
            identity: $c->identity,
            authority: ['state' => 'local_active', 'label' => 'LOCAL MODE', 'sub_label' => 'MANUAL SWITCH', 'can_mutate' => true, 'pending_sync' => 0, 'tone' => 'ok', 'connection_label' => 'ONLINE'],
            assets: $c->assets,
            transport: ['csrf_header' => 'X-CSRF-TOKEN', 'body' => 'multipart', 'unauthenticated_redirect' => '/edge/local/login'],
            managerCredential: PosRuntime::CREDENTIAL_EMPLOYEE,
            labels: [],
            chromeView: 'tenant.pos.partials.pos-chrome-edge',
        );
    }

    public function test_the_edge_chrome_carries_a_hidden_zero_geometry_sidebar_hook_for_the_shared_theme_script(): void
    {
        $html = view('tenant.pos.partials.pos-chrome-edge', ['posRuntime' => $this->edgeRuntime()])->render();

        // The hook: inside the display:none chrome wrapper, itself hidden, class `.sidebar` (the selector theme-script.js uses).
        $this->assertStringContainsString('id="pos-edge-chrome" hidden aria-hidden="true" style="display:none"', $html);
        $this->assertMatchesRegularExpression('/<nav id="pos-edge-theme-hook" class="sidebar" hidden aria-hidden="true" style="display:none"><\/nav>/', $html);
        // Never the Cloud sidebar markup, and nothing visible: the partial renders only hidden / out-of-flow elements.
        $this->assertDoesNotMatchRegularExpression('/<div class="sidebar[\s"]/', $html);
        $this->assertDoesNotMatchRegularExpression('/sidebar-inner|sidebar-menu|<ul/', $html, 'an empty hook, no menu');
        $this->assertSame(1, preg_match_all('/class="sidebar"/', $html));

        // The selector the theme script actually uses is `.sidebar` (guard against a drift in the vendored script).
        $theme = file_get_contents(public_path('assets/js/theme-script.js'));
        $this->assertStringContainsString("document.querySelector('.sidebar')", $theme);
        $this->assertStringContainsString("console.error('Sidebar element not found')", $theme);
    }

    /**
     * Phase 3 Stage B (owner §5.1) — the Branch Server menu: a Bootstrap offcanvas (position:fixed, hidden until opened → zero
     * geometry in the flow) in the chrome slot, opened by the SHARED #pos-sidebar-toggle through a script (the button's own
     * attributes are never touched — the skeleton gate compares them). Entries are runtime-driven: a null route renders no entry.
     */
    public function test_the_edge_chrome_carries_a_runtime_driven_offcanvas_menu_behind_the_shared_sidebar_toggle(): void
    {
        $c = $this->edgeRuntime();
        $with = fn (array $routes) => new PosRuntime(mode: $c->mode, routes: array_merge($c->routes, $routes), capabilities: $c->capabilities, identity: array_merge($c->identity, ['branch_name' => 'Chrome Branch']),
            authority: $c->authority, assets: $c->assets, transport: $c->transport, managerCredential: $c->managerCredential, labels: $c->labels, chromeView: $c->chromeView);

        // A cashier without finance permissions: Health + Logout only.
        $html = view('tenant.pos.partials.pos-chrome-edge', ['posRuntime' => $with(['supplierFinancePage' => null, 'financeJournalPage' => null, 'purchaseReturnsPage' => null])])->render();
        $this->assertStringContainsString('<div class="offcanvas offcanvas-start" tabindex="-1" id="pos-edge-menu"', $html);
        $this->assertStringContainsString('id="pos-edge-menu-status" href="/edge/local/health"', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-outline-danger mt-auto" id="pos-edge-menu-logout" form="pos-edge-logout-form">', $html);
        $this->assertStringContainsString('Chrome Branch', $html);
        $this->assertStringContainsString('LOCAL MODE · MANUAL SWITCH', $html);
        foreach (['pos-edge-menu-supplier-finance', 'pos-edge-menu-finance-journal', 'pos-edge-menu-purchase-returns'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "{$absent} must not render for a null route");
        }
        // The menu is outside the display:none island (it must be able to open) but carries no `.sidebar`, no `<ul`, no Cloud chrome.
        $this->assertLessThan(strpos($html, 'id="pos-edge-menu"'), strpos($html, '</div>'), 'the offcanvas follows the hidden island, not inside it');
        $this->assertSame(1, preg_match_all('/class="sidebar"/', $html));
        $this->assertDoesNotMatchRegularExpression('/sidebar-inner|sidebar-menu|<ul/', $html);
        // Opened by the shared toggle through a listener; the toggle's attributes are not rewritten server-side.
        $this->assertStringContainsString("document.getElementById('pos-sidebar-toggle')", $html);
        $this->assertStringContainsString('window.bootstrap && window.bootstrap.Offcanvas', $html);
        $this->assertStringNotContainsString('data-bs-toggle="offcanvas"', $html, 'no data-bs-toggle on any control — the toggle button stays Online\'s');

        // An operator with every finance permission: the three entries, in the runtime's paths, and the data island carries them.
        $html = view('tenant.pos.partials.pos-chrome-edge', ['posRuntime' => $with(['supplierFinancePage' => '/edge/local/pos/suppliers', 'financeJournalPage' => '/edge/local/pos/finance/journal', 'purchaseReturnsPage' => '/edge/local/pos/purchase-returns'])])->render();
        $this->assertStringContainsString('id="pos-edge-menu-supplier-finance" href="/edge/local/pos/suppliers"', $html);
        $this->assertStringContainsString('id="pos-edge-menu-finance-journal" href="/edge/local/pos/finance/journal"', $html);
        $this->assertStringContainsString('id="pos-edge-menu-purchase-returns" href="/edge/local/pos/purchase-returns"', $html);
        preg_match('/<script type="application\/json" id="pos-edge-chrome-data">(.*?)<\/script>/s', $html, $m);
        $island = json_decode($m[1], true);
        $this->assertSame('/edge/local/pos/suppliers', $island['routes']['supplierFinancePage']);
        $this->assertSame(['pos-edge-menu-status', 'pos-edge-menu-supplier-finance', 'pos-edge-menu-finance-journal', 'pos-edge-menu-purchase-returns'], array_column($island['menu'], 'id'));
    }

    public function test_the_split_bill_script_guards_the_tendered_field_it_does_not_render(): void
    {
        $src = file_get_contents(resource_path('views/tenant/sales-orders/split-bill.blade.php'));
        $this->assertStringNotContainsString('id="tendered_amount"', $src, 'the page renders no tendered field — the split is not paid here');
        $this->assertStringContainsString("const tendered = document.getElementById('tendered_amount');", $src);
        $this->assertStringContainsString('if (tendered && !tendered.dataset.manual) {', $src);
        $this->assertMatchesRegularExpression('/if \(tendered\) \{\s*tendered\.addEventListener\(/s', $src);
        // every reference to the element is guarded: the only `tendered.addEventListener` is the one inside `if (tendered) {`
        $this->assertSame(1, preg_match_all('/tendered\.addEventListener/', $src));
        $this->assertSame(1, preg_match_all('/if \(tendered\) \{\s*tendered\.addEventListener/s', $src), 'no unguarded listener on the missing element');
    }
}
