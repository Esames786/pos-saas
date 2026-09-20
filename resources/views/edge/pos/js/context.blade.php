{{-- W0d js/context (Team 1 owns): the operating context controls — terminal selector + order-type selector (tabs on Online). --}}
        // ---- Terminal selector: default-terminal parity (auto-select assigned, never "first seen"). ----
        function renderTerminals() {
            const sel = $('terminal'); sel.innerHTML = '';
            DATA.terminals.forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.name; sel.appendChild(o); });
            const pick = DATA.defaultTerminalId && DATA.terminals.some(t => t.id === DATA.defaultTerminalId)
                ? DATA.defaultTerminalId : (DATA.terminals[0] ? DATA.terminals[0].id : null);
            if (pick) { sel.value = pick; selectTerminal(pick); }
            sel.disabled = !DATA.canChangeTerminal && DATA.terminals.length <= 1;
            sel.addEventListener('change', () => selectTerminal(Number(sel.value)));
        }
        async function selectTerminal(id) {
            state.terminalId = id;
            try { await api('POST', '/terminal/select', { terminal_id: id }); } catch (e) { toast(e.message); }
        }
        function renderOrderTypes() {
            const sel = $('order-type');
            DATA.orderTypes.forEach(t => { const o = document.createElement('option'); o.value = t; o.textContent = DATA.orderTypeLabels[t] || t; sel.appendChild(o); });
            sel.value = state.orderType;
            sel.addEventListener('change', () => { state.orderType = sel.value; });
        }
        function lockOrderType(type) { state.orderType = type; const sel = $('order-type'); sel.value = type; sel.disabled = true; }
        function unlockOrderType() { const sel = $('order-type'); sel.disabled = false; }
