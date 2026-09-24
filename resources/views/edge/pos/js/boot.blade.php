{{-- W0 js/boot (Team 1 owns): event wiring, first render, the public EdgePOS handle used by inline onclick handlers. Always the LAST fragment.
     W1: header buttons are wired to the owning team's function ONLY when it exists (typeof … === 'function'), otherwise hidden;
     Online keyboard shortcuts (O:6608-6616), calculator (O:6582-6606), page-load deep links (?mode / ?held_sale_id /
     ?table_session_id / ?customer_id, POSController:37-116 + O:6621-6690), the navigation toggle, shift/sync polling. --}}
        // ---- Online headings the census/browser target, added to the menu + cart panes when their owners have not (never duplicated). ----
        (function productsHeading() { const grid = $('product-grid') || $('tiles'); if ($('products_heading') || !grid) return; const h = document.createElement('h2'); h.id = 'products_heading'; h.textContent = 'Products'; grid.parentNode.insertBefore(h, grid); })();
        (function dockCartHeading() {
            const pane = document.querySelector('.cart-pane'); if (!pane) return;
            if (!$('cart_heading')) {
                const row = document.createElement('div'); row.className = 'cart-heading-row'; row.id = 'cart-heading-row';
                row.innerHTML = '<h2 id="cart_heading">Cart</h2><div class="tools" id="cart-heading-tools"></div>';
                pane.insertBefore(row, pane.firstChild);
            }
            const tools = $('cart-heading-tools') || pane;
            if (!$('toggle-calc-btn')) {
                const b = document.createElement('button'); b.type = 'button'; b.className = 'sm ghost'; b.id = 'toggle-calc-btn'; b.title = 'Calculator (Ctrl+M)';
                b.innerHTML = '<i class="ti ti-calculator" aria-hidden="true"></i><span>Calc</span>'; tools.appendChild(b);
            }
            const calc = $('calculator-panel'), actions = $('actions');
            if (calc && actions && actions.parentNode === pane) pane.insertBefore(calc, actions);
        })();
        function updateCartHeading() { const h = $('cart_heading'); if (h) h.textContent = (state.session || (state.held && state.held.table_no)) ? 'Table Cart' : 'Cart'; }

        // ---- calculator (Online #calculator-panel: keypad, C clears, = evaluates; arithmetic only) ----
        function toggleCalculator() {
            const p = $('calculator-panel'); p.hidden = !p.hidden;
            if (!p.hidden) $('calc-display').focus();
        }
        document.querySelectorAll('#calc-keypad [data-key]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.key, d = $('calc-display');
            if (k === 'C') { d.value = ''; return; }
            if (k === '=') {
                const expr = d.value.trim();
                if (!expr) return;
                if (!/^[0-9+\-*/.()\s]+$/.test(expr)) { d.value = 'Error'; return; }
                try { const v = Function('"use strict"; return (' + expr + ')')(); d.value = Number.isFinite(v) ? String(Math.round(v * 1e6) / 1e6) : 'Error'; } catch (e) { d.value = 'Error'; }
                return;
            }
            if (d.value === 'Error') d.value = '';
            d.value += k;
        }));

        // ---- header wiring: the owning team's function when it exists, else hidden (onclick, so a team that also assigns
        //      onclick to the same Online id replaces — never doubles — the handler) ----
        const w1Fn = f => (typeof f === 'function' ? f : null);
        function w1Wire(id, fn, visibleWhen) {
            const el = $(id); if (!el) return;
            if (!fn || el.dataset.denied === '1' || visibleWhen === false) { el.hidden = true; return; }
            el.hidden = false; el.dataset.wired = '1';
            el.onclick = e => { e.preventDefault(); fn(); };
        }
        // Search box: W2's live #pos_search (js/catalog wireSearch already binds input → barcode scan + renderTiles, so it is not
        // bound twice here). The legacy hidden #search is only bound when #pos_search is absent (null-guarded, may be removed).
        const w1SearchBox = () => $('pos_search') || $('search');
        if (!$('pos_search') && $('search')) $('search').addEventListener('input', renderTiles);
        if ($('customer-name')) $('customer-name').addEventListener('input', renderChips);
        $('view-tables-btn').hidden = !DATA.orderTypes.includes('dine_in');   // Online: View Tables only for a dine-in operator (O:427)
        $('view-tables-btn').addEventListener('click', () => viewTables());
        const w1Shift = w1Fn(typeof openShiftDialog === 'function' ? openShiftDialog : null) || w1Fn(typeof shiftAction === 'function' ? shiftAction : null);
        w1Wire('shift-btn', w1Shift);
        w1Wire('pos-shift-open-link', w1Shift);
        w1Wire('pos-return-btn', w1Fn(typeof openReturns === 'function' ? openReturns : null) || w1Fn(typeof returnsFlow === 'function' ? returnsFlow : null), !!DATA.canSalesReturn);
        w1Wire('pos-quick-report-btn', w1Fn(typeof openQuickReport === 'function' ? openQuickReport : null) || w1Fn(typeof quickReport === 'function' ? quickReport : null), !!DATA.canQuickReport);
        w1Wire('pos-customer-btn', w1Fn(typeof openCustomerModal === 'function' ? openCustomerModal : null));
        w1Wire('held-orders-btn', w1Fn(typeof openHeldOrders === 'function' ? openHeldOrders : null) || w1Fn(typeof recallList === 'function' ? recallList : null));
        w1Wire('completed-orders-btn', w1Fn(typeof openCompletedOrders === 'function' ? openCompletedOrders : null));
        // Online #last-print-btn → the last sale's prints (lastPrintModal); W5 keeps the last sale in `lastPrintSale`.
        w1Wire('last-print-btn', typeof openLastPrint === 'function'
            ? () => (typeof lastPrintSale !== 'undefined' && lastPrintSale && lastPrintSale.id ? openLastPrint(lastPrintSale.id, lastPrintSale.no) : openLastPrint(null))
            : w1Fn(typeof recentPrints === 'function' ? recentPrints : null));
        if ($('pos-context-change-btn')) $('pos-context-change-btn').addEventListener('click', () => openDialog('posContextModal'));
        $('chip-cust-clear').addEventListener('click', clearChipCustomer);
        $('toggle-calc-btn').addEventListener('click', toggleCalculator);

        // ---- navigation toggle (Online #pos-sidebar-toggle): collapses / expands the Edge navigation strip; remembered per browser. ----
        (function navToggle() {
            const btn = $('pos-sidebar-toggle');
            let collapsed = window.innerWidth < 1200;
            try { const v = localStorage.getItem('edge_pos_nav_collapsed'); if (v !== null) collapsed = v === '1'; } catch (e) { /* storage blocked */ }
            const apply = () => {
                document.body.classList.toggle('nav-collapsed', collapsed);
                btn.title = collapsed ? 'Show navigation' : 'Hide navigation'; btn.setAttribute('aria-label', btn.title); btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                const i = btn.querySelector('.ti'); if (i) i.className = 'ti ' + (collapsed ? 'ti-layout-sidebar-left-expand' : 'ti-layout-sidebar-left-collapse');
            };
            btn.addEventListener('click', () => { collapsed = !collapsed; try { localStorage.setItem('edge_pos_nav_collapsed', collapsed ? '1' : '0'); } catch (e) { /* storage blocked */ } apply(); });
            apply();
        })();

        // ---- keyboard shortcuts (Online O:6608-6616) + Esc closes the top dialog (Bootstrap modal default) ----
        function w1ClickIfUsable(id) { const b = $(id); if (b && !b.hidden && !b.disabled && b.offsetParent !== null) { b.click(); return true; } return false; }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                if ($('edge-confirm').classList.contains('open')) { _settleConfirm(false); return; }
                if ($('posContextModal').classList.contains('open')) { closeDialog('posContextModal'); return; }
                if ($('modal').classList.contains('open')) { closeModal(); return; }
                return;
            }
            if (!e.ctrlKey || e.altKey || e.metaKey) return;
            const k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
            if (k === 'f') { e.preventDefault(); const s = w1SearchBox(); if (s) { s.focus(); if (s.select) s.select(); } }
            else if (k === 'h') { e.preventDefault(); w1ClickIfUsable('hold-sale-btn') || w1ClickIfUsable('save-round-btn'); }                // Hold Sale / Save Order
            else if (k === 'l') { e.preventDefault(); const f = typeof openHeldOrders === 'function' ? openHeldOrders : recallList; f(); }     // Held Orders
            else if (k === 'p') { e.preventDefault(); const t = $('tendered_amount') || $('rp-tendered'); if ($('modal').classList.contains('open') && t) { t.focus(); if (t.select) t.select(); } else w1ClickIfUsable('review-pay-btn'); } // Pay (Online: focus the tender)
            else if (k === 'Enter') { e.preventDefault(); w1ClickIfUsable('complete-sale-btn') || w1ClickIfUsable('rp-complete') || w1ClickIfUsable('review-pay-btn'); } // Complete sale
            else if (k === 'm') { e.preventDefault(); toggleCalculator(); }                                                                     // Calculator
        });

        // ---- first render ----
        renderOrderTypes(); renderTerminals(); renderTabs(); renderTiles(); renderCart();
        observeCartChips(); renderCustomerChip();
        new MutationObserver(updateCartHeading).observe($('check-chip'), { attributes: true, childList: true, characterData: true, subtree: true });
        refreshSync(); setInterval(refreshSync, 60000);
        setInterval(refreshShiftStatus, 300000);   // Online resyncs the shift status every 5 minutes (O:2451)

        // ---- page-load deep links (Online POSController@index + preload): ?mode=, ?held_sale_id=, ?table_session_id=, ?customer_id= ----
        (async function deepLinks() {
            let q;
            try { q = new URLSearchParams(window.location.search); } catch (e) { return; }
            const mode = q.get('mode');
            if (mode && DATA.orderTypes.includes(mode) && mode !== state.orderType) { state.orderType = mode; highlightModeTab(); renderCart(); }
            const heldId = Number(q.get('held_sale_id') || 0), sessionId = Number(q.get('table_session_id') || 0), customerId = Number(q.get('customer_id') || 0);
            try {
                if (heldId > 0) {
                    await loadHeld(heldId);                                   // Online: recall the held sale on load
                } else if (sessionId > 0) {
                    const b = await api('GET', '/restaurant/board');         // Online: attach the open table session
                    let table = null;
                    (b.floors || []).forEach(f => (f.tables || []).forEach(t => { if (t.session && Number(t.session.id) === sessionId) table = t; }));
                    if (!table) toast('That table session is not open on this branch any more.', 'warning');
                    else if (state.orderType !== 'dine_in' && !DATA.orderTypes.includes('dine_in')) toast('Dine-in is not enabled for your account.', 'warning');
                    else startCheckOnSession(table);
                }
                if (customerId > 0 && !state.held) {
                    // Online preselects the customer (CustomerController → /pos?customer_id=). The synced book is searched by id.
                    const r = await api('GET', '/customers?id=' + customerId, undefined, { quiet: true });
                    const c = (r.customers || []).find(x => Number(x.id) === customerId);
                    if (c) { state.customer = c; renderChips(); renderCustomerChip(); }
                }
            } catch (e) { toast(e.message); }
        })();

        window.EdgePOS = { closeModal, state, loadHeld, viewTables, toast, confirmDialog, openDialog, closeDialog };
