{{--
    W-B — the Edge CHROME SLOT of the shared POS layout (`layouts.pos` includes `$posRuntime->chromeView`).

    Owner decision A5 (modified): NO Edge-only layout delta. This partial renders NOTHING that takes space or reflows the page —
    only hidden / out-of-flow elements. Every visible Edge status (authority label, sub-label, pending sync, tone) is shown by the
    SHARED status slot from `POS_RUNTIME.authority`; this slot only carries:
      - a hidden data island the shared overlay / status-slot JS may read (authority + the live refresh endpoint);
      - a hidden POST form for the local logout (the Edge session is ended by POST /edge/local/logout, CSRF-protected).
    Expects: $posRuntime (App\Support\Pos\PosRuntime, mode 'edge').
--}}
@php
    $edgeAuthority = $posRuntime->authority ?? [];
    $edgeChromeData = [
        'authority' => $edgeAuthority,
        'routes' => [
            'syncSummary' => $posRuntime->route('syncSummary'),
            'status' => $posRuntime->route('status'),
            'logout' => $posRuntime->route('logout'),
            'terminals' => $posRuntime->route('terminals'),
            'terminalSelect' => $posRuntime->route('terminalSelect'),
        ],
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
