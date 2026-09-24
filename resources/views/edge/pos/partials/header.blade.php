{{-- W1 (Team 1): the POS header in the Online layout (tenant/pos/index.blade.php:419-535 + :141-163).
     Online hides the app chrome on /pos and uses: a title row (sidebar toggle, "Restaurant POS", View Tables), the live
     table-session bar, the order-type TABS with the customer slot (Return / Quick Report / Add-Search Customer + chip), and
     the context row (branch · terminal, shift badge, no-terminal warning, Change). The Edge-only entry points (sync state,
     Status, Suppliers, Journal, Purchase Returns, Logout) live in the compact .edge-nav strip at the right of the title row
     — the #pos-sidebar-toggle collapses/expands it like Online's navigation toggle — so they never displace an Online control.
     Buttons whose feature is another team's (customer modal, completed orders, held orders, last print, shift, returns, quick report) are
     wired by js/boot ONLY when that team's function exists; otherwise they stay hidden. Gated buttons follow the Online
     permission (Return: tenant.sales-returns.* → $canSalesReturn; Quick Report: tenant.pos.quick-report-send → $canQuickReport;
     Change terminal: CHANGE_TERMINAL_PERMISSION → $canChangeTerminal); the server re-checks every one. --}}
    <header class="pos-header" id="pos-header">
        <div class="pos-title-row" id="pos-title-row">
            <button type="button" class="ghost" id="pos-sidebar-toggle" title="Hide navigation" aria-label="Hide navigation" aria-controls="edge-nav-links" aria-expanded="true">
                <i class="ti ti-layout-sidebar-left-collapse" aria-hidden="true"></i><span class="nav-glyph" aria-hidden="true">&#9776;</span>
            </button>
            <div class="pos-title"><span class="pos-title-pre">Restaurant</span><h1>POS</h1></div>
            <button type="button" id="view-tables-btn" class="sm" title="Table Board">
                <i class="ti ti-layout-grid" aria-hidden="true"></i>View Tables
            </button>
            <nav class="edge-nav" id="edge-nav" aria-label="Branch Server">
                <span class="chip" id="sync-chip" hidden></span>
                <div class="edge-nav-links" id="edge-nav-links">
                    <a class="navbtn sm" id="health-link" href="{{ url('/edge/local/pos/health') }}" title="Branch Server status"><i class="ti ti-heartbeat" aria-hidden="true"></i>Status</a>
                    @if($canSupplierFinance ?? false)
                        <a class="navbtn sm" id="suppliers-link" href="{{ url('/edge/local/pos/suppliers') }}"><i class="ti ti-truck" aria-hidden="true"></i>Suppliers</a>
                    @endif
                    @if($canManualJournal ?? false)
                        <a class="navbtn sm" id="journal-link" href="{{ url('/edge/local/pos/finance/journal') }}"><i class="ti ti-notebook" aria-hidden="true"></i>Journal</a>
                    @endif
                    @if($canPurchaseReturn ?? false)
                        <a class="navbtn sm" id="purchase-returns-link" href="{{ url('/edge/local/pos/purchase-returns') }}"><i class="ti ti-truck-return" aria-hidden="true"></i>Purchase Returns</a>
                    @endif
                    <span class="who"><i class="ti ti-user" aria-hidden="true"></i> <span id="ctx-user-name">{{ $userName }}</span></span>
                    <form method="POST" action="{{ url('/edge/local/logout') }}" id="logout-form">@csrf<button class="sm ghost" id="logout-btn" type="submit"><i class="ti ti-logout" aria-hidden="true"></i>Logout</button></form>
                </div>
            </nav>
        </div>

        {{-- Selected table-session bar (Online #pos-session-bar, O:446-472) — full width above the mode tabs, as Online. The shell
             owns the LAYOUT and the Online ids; W3 (js/tables renderSessionBar) owns the CONTENT + behaviour: it fills these ids from
             the page state, shows/hides the bar and wires the actions (Bill Preview / Request Bill / Move / Merge). #check-chip in the
             cart keeps working unchanged. A new W3 control id belongs in #pos-session-actions (ask Team 1 to add it here). --}}
        <div id="pos-session-bar" class="pos-card pos-session-bar" hidden style="display:none">
            <div class="session-context" id="pos-session-details">
                <strong>Table <span id="pos-session-table-no"></span></strong>
                <span class="text-muted" id="pos-session-no"></span>
                &middot; <span id="pos-session-waiter">No waiter</span>
                &middot; <span id="pos-session-guests"></span> guests
                &middot; Open check <strong id="pos-session-open-check"></strong>
                <span class="chip hot" id="pos-session-status" hidden>Bill requested</span>
            </div>
            <div class="pos-session-actions" id="pos-session-actions">
                <button type="button" class="sm outline-dark" id="pos-session-bill-preview">Bill Preview</button>
                <form id="pos-session-request-bill-form" style="margin:0;display:inline"><button type="submit" class="sm warn" title="Signal that the guest wants their bill. Marks this table as 'Bill Requested' so the cashier knows to prepare and close it — it does not charge anything.">Request Bill</button></form>
                <button type="button" class="sm ghost" id="pos-session-move-btn">Move</button>
                <button type="button" class="sm ghost" id="pos-session-merge-btn">Merge</button>
            </div>
        </div>

        {{-- Mode tabs + customer slot (Online O:481-538). The hidden #order-type select keeps the payload/JS contract
             (Online keeps a hidden #order_type select for the same reason, O:607-614). --}}
        <div class="pos-mode-row">
            <div class="mode-tabs" id="mode-tabs-wrapper" role="tablist" aria-label="POS Modes">
                @foreach(($orderTypeLabels ?? []) as $type => $label)
                    @if(in_array($type, $orderTypes ?? [], true))
                        <button type="button" role="tab" class="mode-tab {{ ($defaultOrderType ?? null) === $type ? 'active' : '' }}" aria-selected="{{ ($defaultOrderType ?? null) === $type ? 'true' : 'false' }}" data-mode-tab="{{ $type }}">{{ $label }}</button>
                    @endif
                @endforeach
            </div>
            <select id="order-type" hidden aria-hidden="true" tabindex="-1">
                @foreach(($orderTypeLabels ?? []) as $type => $label)
                    @if(in_array($type, $orderTypes ?? [], true))
                        <option value="{{ $type }}" @selected(($defaultOrderType ?? null) === $type)>{{ $label }}</option>
                    @endif
                @endforeach
            </select>
            <div class="pos-customer-slot" id="pos-customer-slot">
                <div id="pos-customer-chip" class="pos-customer-chip" hidden>
                    <i class="ti ti-user" aria-hidden="true"></i>
                    <strong id="chip-cust-name"></strong>
                    <span id="chip-cust-phone" class="text-muted"></span>
                    <span id="chip-cust-address" class="text-muted" hidden></span>
                    <button type="button" class="chip-clear" id="chip-cust-clear" aria-label="Remove customer" title="Remove customer">&times;</button>
                </div>
                <button type="button" class="sm outline-danger" id="pos-return-btn" title="Create a sales return (search a paid sale)" @unless($canSalesReturn ?? false) hidden data-denied="1" @endunless>
                    <i class="ti ti-arrow-back-up" aria-hidden="true"></i>Return
                </button>
                <button type="button" class="sm outline-primary" id="pos-quick-report-btn" title="Send or print a sales report" @unless($canQuickReport ?? false) hidden data-denied="1" @endunless>
                    <i class="ti ti-send" aria-hidden="true"></i>Quick Report
                </button>
                <button type="button" class="sm outline-dark" id="pos-customer-btn" hidden>
                    <i class="ti ti-user-search" aria-hidden="true"></i>Add / Search Customer
                </button>
            </div>
        </div>

        {{-- Context row (Online #order-controls-row, O:143-163). The appliance is BOUND to one branch (EnsureEdgeBranchBound):
             the branch is shown, never selectable. --}}
        <div class="pos-card order-controls-row" id="order-controls-row">
            <span class="ctx"><i class="ti ti-building-store" aria-hidden="true"></i> <strong id="ctx-branch-name">{{ $branchName }}</strong>
                <span class="text-muted">&middot;</span> <span id="ctx-terminal-name" class="text-muted">No terminal</span></span>
            @if($canChangeTerminal ?? false)
                <button type="button" class="sm ghost" id="pos-context-change-btn" title="Change terminal (the branch is fixed on this Branch Server)"><i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>Change</button>
            @endif
            <div id="pos-shift-status" hidden>
                <span class="badge bg-secondary" id="pos-shift-badge"></span>
                <span id="pos-shift-detail" class="text-muted"></span>
                <a href="#" id="pos-shift-open-link" hidden>Open shift</a>
            </div>
            {{-- Edge keeps the in-page Shift dialog (Online reaches /shifts/open|close on separate pages): always reachable. --}}
            <button type="button" class="sm ghost" id="shift-btn" title="Shift — open / close / blind count"><i class="ti ti-clock" aria-hidden="true"></i>Shift</button>
            <div id="no-terminal-warning" class="text-warning-emphasis" hidden>
                <i class="ti ti-alert-triangle" aria-hidden="true"></i> No terminal — selling and receipt/KOT printing are blocked until a terminal is selected
            </div>
            <div class="order-tools" id="pos-order-tools">
                <button type="button" class="sm ghost" id="held-orders-btn" title="Held Orders (Ctrl+L)" hidden><i class="ti ti-layout-list" aria-hidden="true"></i>Held Orders</button>
                <button type="button" class="sm ghost" id="completed-orders-btn" title="Recent completed orders — reprint receipt / KOT" hidden><i class="ti ti-receipt-2" aria-hidden="true"></i>Recent Orders</button>
                <button type="button" class="sm ghost" id="last-print-btn" title="Print history — the last sale's prints" hidden><i class="ti ti-printer" aria-hidden="true"></i>Prints</button>
                {{-- #edit-order-btn is NOT here: Online places it in the recalled-order bar (#recalled-order-bar, O:366-380), which W3
                     (js/tables renderRecalledBar) owns — a second copy would duplicate the id. --}}
            </div>
        </div>
    </header>
