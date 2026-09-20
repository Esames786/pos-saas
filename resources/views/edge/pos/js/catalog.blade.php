{{-- W0 js/catalog (Team 2 owns from W2): terminal + order-type selectors, category/Deals pills, search, product tiles. --}}
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

        // ---- Category + Deals tabs (deal tabs are display-only). ----
        function renderTabs() {
            const tabs = $('category-tabs'); tabs.innerHTML = '';
            const all = tabBtn('All', null); tabs.appendChild(all);
            DATA.categories.forEach(c => tabs.appendChild(tabBtn(c.name, c.id)));
            if (DATA.combos.length) tabs.appendChild(tabBtn('Deals', 'deals'));
            all.classList.add('active');
        }
        function tabBtn(label, id) {
            const b = document.createElement('button'); b.className = 'pill'; b.textContent = label;
            b.addEventListener('click', () => { state.category = id; document.querySelectorAll('#category-tabs .pill').forEach(x => x.classList.remove('active')); b.classList.add('active'); renderTiles(); });
            return b;
        }
        function visibleItems() {
            const q = $('search').value.trim().toLowerCase();
            let items = [];
            if (state.category === 'deals') {
                items = DATA.combos.map(c => ({ deal: true, id: c.id, name: c.name, price: c.price }));
            } else {
                // a `hidden` product (kept only because it sits on an open bill) is never offered on the grid.
                items = DATA.products.filter(p => !p.hidden && (state.category == null || p.category_id === state.category))
                    .map(p => ({ deal: false, id: p.id, name: p.name, price: p.price }));
                DATA.combos.filter(c => state.category != null && c.category_id === state.category)
                    .forEach(c => items.unshift({ deal: true, id: c.id, name: c.name, price: c.price }));
            }
            if (q) items = items.filter(i => i.name.toLowerCase().includes(q));
            return items;
        }
        function renderTiles() {
            const wrap = $('tiles'); wrap.innerHTML = '';
            const items = visibleItems();
            if (!items.length) { wrap.innerHTML = '<p class="muted">No products found.</p>'; return; }
            items.forEach(i => {
                const b = document.createElement('button'); b.className = 'tile' + (i.deal ? ' deal' : '');
                b.innerHTML = '<span class="nm">' + esc(i.name) + '</span><span class="pr">' + money(i.price) + (i.deal ? ' · Deal' : '') + '</span>';
                b.addEventListener('click', () => addToCart(i));
                wrap.appendChild(b);
            });
        }
