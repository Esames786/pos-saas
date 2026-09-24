{{-- W0d js/context (Team 1 owns): the operating context — terminal (Online #posContextModal + autoSelectTerminal), order-type
     TABS (Online #mode-tabs-wrapper + applyModeTab, O:6359-6428), the context summary (updateContextSummary, O:7027-7040),
     the shift badge (refreshShiftStatus, O:2409-2447 — polls GET /edge/local/pos/shift, which already strips amounts), the
     no-terminal warning, the table-session bar and the customer chip mirrors. lockOrderType()/unlockOrderType() keep their
     names and meaning for js/held + js/tables + js/payment. --}}
        // ---- Terminal selector: default-terminal parity (assigned → remembered on this browser → first; never "first seen"). ----
        function renderTerminals() {
            const sel = $('terminal'); sel.innerHTML = '';
            DATA.terminals.forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.name; sel.appendChild(o); });
            let saved = null;
            try { saved = Number(localStorage.getItem('pos_terminal_' + DATA.branchId)) || null; } catch (e) { /* storage blocked */ }
            const has = id => id && DATA.terminals.some(t => t.id === id);
            const pick = has(DATA.defaultTerminalId) ? DATA.defaultTerminalId : (has(saved) ? saved : (DATA.terminals[0] ? DATA.terminals[0].id : null));
            sel.disabled = !DATA.canChangeTerminal && DATA.terminals.length <= 1;
            sel.addEventListener('change', () => selectTerminal(Number(sel.value)));
            if (pick) { sel.value = pick; selectTerminal(pick); } else { updateContextSummary(); }
        }
        async function selectTerminal(id) {
            state.terminalId = id;
            updateContextSummary();
            try {
                await api('POST', '/terminal/select', { terminal_id: id }, { quiet: true });
                try { localStorage.setItem('pos_terminal_' + DATA.branchId, String(id)); } catch (e) { /* storage blocked */ }
            } catch (e) { toast(e.message, 'error'); }
            refreshShiftStatus();
        }
        function updateContextSummary() {
            const t = DATA.terminals.find(x => x.id === state.terminalId);
            $('ctx-terminal-name').textContent = t ? t.name : 'No terminal';
            $('no-terminal-warning').hidden = !!t;
        }

        // ---- Shift badge (Online #pos-shift-status): open / no open shift on the selected terminal. Advisory — never blocks. ----
        let _shiftSeq = 0, _shiftOpen = null;
        async function refreshShiftStatus() {
            const wrap = $('pos-shift-status');
            if (!state.terminalId) { wrap.hidden = true; return; }
            const seq = ++_shiftSeq;
            try {
                const d = await api('GET', '/shift', undefined, { quiet: true });
                if (seq !== _shiftSeq) return; // a newer poll won
                wrap.hidden = false;
                const badge = $('pos-shift-badge'), detail = $('pos-shift-detail'), link = $('pos-shift-open-link');
                _shiftOpen = !!d.shift;
                if (d.shift) {
                    badge.className = 'badge bg-success'; badge.textContent = 'Shift open';
                    const opened = d.shift.opened_at ? new Date(d.shift.opened_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                    detail.textContent = 'Business date ' + (d.shift.business_date || '') + (opened ? ' · opened ' + opened : '');
                    link.hidden = true;
                } else {
                    badge.className = 'badge bg-danger'; badge.textContent = 'No open shift';
                    detail.textContent = 'POS operations are blocked on this terminal.';
                    link.hidden = false;
                }
            } catch (e) { /* status is advisory; never block the POS on a failed poll (a 401 already redirected) */ }
        }
        let _modalClosedTimer = null;
        function onModalClosed() { clearTimeout(_modalClosedTimer); _modalClosedTimer = setTimeout(refreshShiftStatus, 400); } // a dialog may have opened/closed the shift

        // ---- Order-type TABS (Online applyModeTab): same allowed set; switching with an order in progress asks first, then
        //      starts a fresh order (cart, customer, discount/promo, delivery details cleared) and keeps ?mode= in the URL. ----
        function renderOrderTypes() {
            const sel = $('order-type');
            if (!sel.options.length) {
                DATA.orderTypes.forEach(t => { const o = document.createElement('option'); o.value = t; o.textContent = DATA.orderTypeLabels[t] || t; sel.appendChild(o); });
            }
            sel.value = state.orderType;
            sel.addEventListener('change', () => applyModeTab(sel.value, true)); // programmatic / proof-tool path
            document.querySelectorAll('[data-mode-tab]').forEach(b => b.addEventListener('click', () => applyModeTab(b.dataset.modeTab, false)));
            highlightModeTab();
        }
        function highlightModeTab() {
            document.querySelectorAll('[data-mode-tab]').forEach(b => { const on = b.dataset.modeTab === state.orderType; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
            $('order-type').value = state.orderType;
        }
        async function applyModeTab(mode, confirmed) {
            if (!DATA.orderTypes.includes(mode) || mode === state.orderType) { highlightModeTab(); return; }
            if ($('mode-tabs-wrapper').classList.contains('pos-controls-locked')) { highlightModeTab(); return; } // a recalled check / table owns the type
            if (state.cart.length && !confirmed) {
                const label = DATA.orderTypeLabels[mode] || mode;
                const ok = await confirmDialog({ title: 'Start a fresh ' + label + ' order?', text: 'The current cart, customer and payment details will be cleared.', confirmText: 'Start Fresh', icon: 'warning' });
                if (!ok) { highlightModeTab(); return; }
            }
            resetOrderForModeSwitch(); // Online clears the order state on every switch (O:6384-6399)
            state.orderType = mode;
            highlightModeTab();
            if (typeof renderCart === 'function') renderCart();
            // W2 panels (vehicle/waiter, delivery) + the totals quote follow the new type; other fragments may listen to the event.
            if (typeof onOrderTypeChanged === 'function') onOrderTypeChanged();
            if (typeof scheduleQuote === 'function') scheduleQuote();
            document.dispatchEvent(new CustomEvent('edge:order-type-changed', { detail: { orderType: mode } }));
            renderCustomerChip();
            try { const u = new URL(window.location.href); u.searchParams.set('mode', mode); window.history.replaceState({}, '', u.toString()); } catch (e) { /* no history API */ }
        }
        // The Online mode switch clears the order in progress (O:6384-6399); nothing is sent to the server.
        function resetOrderForModeSwitch() {
            state.cart = []; state.dirty = false; state.customer = null; state.pendingClientUuid = null;
            state.commercial = { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null,
                                 delivery_channel_id: null, delivery_rider_id: null, delivery_address: '', delivery_charge_amount: DATA.defaultDeliveryCharge };
            ['customer-name', 'delivery_address', 'vehicle_number', 'qs-waiter-select', 'delivery_channel_id', 'delivery_rider_id', 'delivery_charge_amount']
                .forEach(id => { const el = $(id); if (el) el.value = ''; });
        }
        // A recalled check / an open table owns the order type: the tabs are CSS-locked like Online (.pos-controls-locked, O:4220-4230).
        function lockOrderType(type) {
            state.orderType = type; highlightModeTab();
            $('mode-tabs-wrapper').classList.add('pos-controls-locked'); $('mode-tabs-wrapper').setAttribute('aria-disabled', 'true');
            $('order-type').disabled = true;
        }
        function unlockOrderType() {
            $('mode-tabs-wrapper').classList.remove('pos-controls-locked'); $('mode-tabs-wrapper').removeAttribute('aria-disabled');
            $('order-type').disabled = false;
            highlightModeTab();
        }
        // The table-session bar (#pos-session-bar) sits in the header (partials/header) with the Online ids; its content is
        // rendered by W3 (js/tables renderSessionBar) on every cart render.

        // ---- Customer chip (Online #pos-customer-chip, CUSTOMER-UX-1): the attached book customer, else the typed name. ----
        function renderCustomerChip() {
            const chip = $('pos-customer-chip'); if (!chip) return;
            const cust = state.customer, typed = ($('customer-name') ? String($('customer-name').value || '').trim() : '');
            const name = (cust && cust.name) || (state.held && state.held.customer_name) || typed;
            if (!name) { chip.hidden = true; return; }
            chip.hidden = false;
            $('chip-cust-name').textContent = name;
            $('chip-cust-phone').textContent = (cust && cust.phone) || (state.held && state.held.customer_phone) || '';
            const addrEl = $('delivery_address');
            const addr = state.orderType === 'delivery' ? ((addrEl && addrEl.value) || state.commercial.delivery_address || '') : '';
            $('chip-cust-address').textContent = addr ? '· ' + addr : ''; $('chip-cust-address').hidden = !addr;
            $('chip-cust-clear').hidden = !!state.held; // a recalled check keeps its customer (Online locks the order controls)
        }
        function clearChipCustomer() {
            if (state.held) return;
            state.customer = null;
            ['customer-name', 'delivery_address'].forEach(id => { const el = $(id); if (el) el.value = ''; });
            if (state.commercial) state.commercial.delivery_address = '';
            if (typeof renderChips === 'function') renderChips();
            renderCustomerChip();
        }

        // js/held renders #customer-chip on every cart render — observing it keeps the Online header chip in step without
        // touching another team's fragment.
        function observeCartChips() {
            const obs = new MutationObserver(() => { renderCustomerChip(); highlightModeTab(); }); // tabs follow state.orderType set by any fragment
            ['check-chip', 'customer-chip'].forEach(id => { const el = $(id); if (el) obs.observe(el, { attributes: true, childList: true, characterData: true, subtree: true }); });
            ['customer-name', 'delivery_address'].forEach(id => { const el = $(id); if (el) { el.addEventListener('input', renderCustomerChip); el.addEventListener('change', renderCustomerChip); } });
        }
