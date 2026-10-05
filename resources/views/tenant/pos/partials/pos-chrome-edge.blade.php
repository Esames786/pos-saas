{{--
    W-B — the Edge CHROME SLOT of the shared POS layout (`layouts.pos` includes `$posRuntime->chromeView`).

    Owner decision A5 (modified): NO Edge-only layout delta. This partial renders NOTHING that takes space or reflows the page —
    only hidden / out-of-flow elements. Every visible Edge status (authority label, sub-label, pending sync, tone) is shown by the
    SHARED status slot from `POS_RUNTIME.authority`; this slot carries:
      - a hidden data island the shared overlay / status-slot JS may read (authority + the live refresh endpoint);
      - a hidden POST form for the local logout (the Edge session is ended by POST /edge/local/logout, CSRF-protected);
      - PHASE 3 STAGE B (owner §5.1 — Edge entry points): the Branch Server MENU, a Bootstrap offcanvas opened by the SAME
        `#pos-sidebar-toggle` button Online uses to open its sidebar. Closed it is `position:fixed; visibility:hidden` and
        translated off-screen — ZERO geometry in the page flow; opened it OVERLAYS the POS (backdrop) and never reflows it.
        Entries are RUNTIME-DRIVEN: Health / status (`routes.status`), the finance screens the operator is permitted to see
        (`routes.supplierFinancePage` / `financeJournalPage` / `purchaseReturnsPage` — EdgePosRuntimeFactory leaves a route null
        when the operator lacks the permission the Edge route enforces, so no entry renders) and Logout (submits the hidden
        CSRF form). The toggle button itself is untouched (same attributes on both runtimes — the skeleton gate compares them);
        the Cloud sidebar is never imported.
    Expects: $posRuntime (App\Support\Pos\PosRuntime, mode 'edge').
--}}
@php
    $edgeAuthority = $posRuntime->authority ?? [];
    $edgeMenu = array_values(array_filter([
        ['id' => 'pos-edge-menu-status', 'label' => 'Health / status', 'hint' => 'Appliance health, sync and authority state', 'icon' => 'ti ti-heartbeat', 'href' => $posRuntime->route('status')],
        ['id' => 'pos-edge-menu-supplier-finance', 'label' => 'Supplier finance', 'hint' => 'Suppliers, supplier ledger, record a payment, general journal', 'icon' => 'ti ti-truck-delivery', 'href' => $posRuntime->route('supplierFinancePage')],
        ['id' => 'pos-edge-menu-finance-journal', 'label' => 'General journal', 'hint' => 'Manual journal entries', 'icon' => 'ti ti-book', 'href' => $posRuntime->route('financeJournalPage')],
        ['id' => 'pos-edge-menu-purchase-returns', 'label' => 'Purchase returns', 'hint' => 'Return received goods to a supplier', 'icon' => 'ti ti-truck-return', 'href' => $posRuntime->route('purchaseReturnsPage')],
    ], fn (array $item) => $item['href'] !== null));
    $edgeChromeData = [
        'authority' => $edgeAuthority,
        'routes' => [
            'syncSummary' => $posRuntime->route('syncSummary'),
            'status' => $posRuntime->route('status'),
            'logout' => $posRuntime->route('logout'),
            'terminals' => $posRuntime->route('terminals'),
            'terminalSelect' => $posRuntime->route('terminalSelect'),
            'supplierFinancePage' => $posRuntime->route('supplierFinancePage'),
            'financeJournalPage' => $posRuntime->route('financeJournalPage'),
            'purchaseReturnsPage' => $posRuntime->route('purchaseReturnsPage'),
        ],
        'menu' => array_map(fn (array $item) => ['id' => $item['id'], 'href' => $item['href']], $edgeMenu),
        'login' => $posRuntime->transport['unauthenticated_redirect'] ?? null,
    ];
@endphp
<div id="pos-edge-chrome" hidden aria-hidden="true" style="display:none"
     data-mode="{{ $posRuntime->mode }}"
     data-authority-state="{{ $edgeAuthority['state'] ?? '' }}"
     data-can-mutate="{{ ! empty($edgeAuthority['can_mutate']) ? '1' : '0' }}"
     data-sync-url="{{ $posRuntime->route('syncSummary') }}">
    <script type="application/json" id="pos-edge-chrome-data">@json($edgeChromeData)</script>
    <form id="pos-edge-logout-form" method="POST" action="{{ $posRuntime->route('logout') }}">@csrf</form>
    {{-- W-G3 (X1): the shared theme script (public/assets/js/theme-script.js setThemeAndSidebarTheme) looks for `.sidebar`
         and, finding none, logs "Sidebar element not found" and SKIPS the data-theme/data-sidebar/data-color/data-layout
         attributes it writes on <html> for the Online page. This zero-geometry hook (inside the display:none chrome, and
         `body.pos-workspace.nosidebar .sidebar` is display:none too) lets Edge run the SAME code path as Online. It is not
         the Cloud sidebar (<div class="sidebar"> never renders on Edge). --}}
    <nav id="pos-edge-theme-hook" class="sidebar" hidden aria-hidden="true" style="display:none"></nav>
</div>
{{-- Stage B — the Branch Server menu (see the header note). Out of flow: Bootstrap 5 `.offcanvas` is position:fixed and hidden
     until `.show`; it lives OUTSIDE #main-content (the skeleton / id-set / component-set gates compare the page content only). --}}
<div class="offcanvas offcanvas-start" tabindex="-1" id="pos-edge-menu" aria-labelledby="pos-edge-menu-title" data-bs-backdrop="true" data-bs-scroll="false" style="width:300px">
    <div class="offcanvas-header border-bottom">
        <div>
            <h5 class="offcanvas-title mb-0" id="pos-edge-menu-title">Branch Server</h5>
            <div class="small text-muted" id="pos-edge-menu-branch">{{ $posRuntime->identity['branch_name'] ?? '' }}</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column gap-3">
        <div class="small text-muted" id="pos-edge-menu-authority">{{ $edgeAuthority['label'] ?? '' }}@if(! empty($edgeAuthority['sub_label'])) · {{ $edgeAuthority['sub_label'] }}@endif</div>
        <nav class="list-group list-group-flush" id="pos-edge-menu-links" aria-label="Branch Server screens">
            @foreach($edgeMenu as $item)
                <a class="list-group-item list-group-item-action d-flex align-items-center gap-2 px-2" id="{{ $item['id'] }}" href="{{ $item['href'] }}" title="{{ $item['hint'] }}">
                    <i class="{{ $item['icon'] }}" aria-hidden="true"></i><span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
        <button type="submit" class="btn btn-outline-danger mt-auto" id="pos-edge-menu-logout" form="pos-edge-logout-form"><i class="ti ti-logout me-1" aria-hidden="true"></i>Log out</button>
    </div>
</div>
<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('pos-sidebar-toggle');
        var menu = document.getElementById('pos-edge-menu');
        if (!toggle || !menu) { return; }
        toggle.addEventListener('click', function () {
            // The shared page handler (registered first) toggles body.nosidebar for the Cloud sidebar. There is no sidebar on a
            // Branch Server, so restore the body state and the button exactly as rendered — the page never drifts from Online.
            document.body.classList.add('nosidebar');
            toggle.title = 'Show navigation';
            toggle.setAttribute('aria-label', 'Show navigation');
            var icon = toggle.querySelector('i');
            if (icon) { icon.className = 'ti ti-layout-sidebar-left-expand'; }
            var api = window.bootstrap && window.bootstrap.Offcanvas;
            if (api) { api.getOrCreateInstance(menu).toggle(); } else { menu.classList.toggle('show'); }
        });
    });
})();
</script>
