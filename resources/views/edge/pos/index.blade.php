{{--
  EDGE-CASHIER-UI-1 — Branch-Server browser cashier POS.

  The current Online Bingoo POS is the functional specification (locked product rule): this page presents
  the SAME operator surface — POS header + View Tables, terminal selector, order-type tabs, category/Deals
  pills, product tiles, cart, customer, totals, Hold/Draft/Review & Pay/Preview Bill, Table Board — but every
  mutation targets the Edge-local JSON APIs (edge.local.pos.*), never a Cloud posting/finance/inventory route.

  It is intentionally self-contained (inline CSS/JS, no Vite/build assets) so it renders on the appliance with
  NO Internet. The bootstrap view-model comes from EdgeLocalPosController@screen; all authority (stock, shift,
  terminal, sale) is re-validated server-side by EdgeLocalPosService. This is milestone 1 (serve the cashier
  experience + core cash sale); dine-in/reservation/report workflows layer onto the same page.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bingoo Edge — Cashier POS</title>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#172033; --line:#334155; --ink:#e2e8f0; --muted:#94a3b8; --accent:#4f46e5; --ok:#16a34a; --warn:#b45309; --danger:#b91c1c; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); height:100vh; display:flex; flex-direction:column; overflow:hidden; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        select, input, button { font:inherit; color:var(--ink); }
        select, input[type=text], input[type=search], input[type=number] { background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.45rem .6rem; }
        button { cursor:pointer; border:1px solid var(--line); background:var(--panel2); border-radius:8px; padding:.5rem .8rem; }
        button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
        button.ok { background:var(--ok); border-color:var(--ok); color:#fff; }
        button.ghost { background:transparent; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        .pill { border-radius:999px; padding:.35rem .8rem; }
        .pill.active { background:var(--accent); border-color:var(--accent); color:#fff; }
        main { flex:1; display:grid; grid-template-columns: 1fr 360px; min-height:0; }
        .grid-pane { display:flex; flex-direction:column; min-height:0; border-right:1px solid var(--line); }
        .tabs { display:flex; gap:.4rem; padding:.5rem .7rem; flex-wrap:wrap; border-bottom:1px solid var(--line); }
        .strip { display:flex; gap:.4rem; padding:.5rem .7rem; overflow-x:auto; border-bottom:1px solid var(--line); }
        .toolbar { display:flex; gap:.5rem; padding:.5rem .7rem; }
        .toolbar input { flex:1; }
        .tiles { flex:1; overflow-y:auto; padding:.7rem; display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.55rem; align-content:start; }
        .tile { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:.6rem; text-align:left; min-height:74px; display:flex; flex-direction:column; justify-content:space-between; }
        .tile .nm { font-size:.82rem; line-height:1.15rem; max-height:2.3rem; overflow:hidden; }
        .tile .pr { font-size:.82rem; color:var(--muted); margin-top:.35rem; }
        .tile.deal { border-color:var(--accent); }
        .cart-pane { display:flex; flex-direction:column; min-height:0; background:var(--panel2); }
        .cart-head { padding:.5rem .7rem; border-bottom:1px solid var(--line); display:flex; gap:.5rem; align-items:center; }
        .chip { font-size:.78rem; background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:.3rem .6rem; color:var(--muted); }
        .lines { flex:1; overflow-y:auto; padding:.4rem .5rem; }
        .line { display:grid; grid-template-columns:1fr auto; gap:.2rem .5rem; padding:.45rem .3rem; border-bottom:1px solid var(--line); }
        .line .ln-nm { font-size:.82rem; }
        .line .ln-ctl { display:flex; align-items:center; gap:.35rem; }
        .line .ln-ctl button { padding:.15rem .5rem; }
        .line .ln-amt { text-align:right; font-size:.82rem; }
        .totals { padding:.5rem .7rem; border-top:1px solid var(--line); font-size:.85rem; }
        .totals .row { display:flex; justify-content:space-between; padding:.15rem 0; }
        .totals .grand { font-size:1.15rem; font-weight:700; border-top:1px solid var(--line); margin-top:.3rem; padding-top:.4rem; }
        .actions { padding:.6rem .7rem; display:grid; grid-template-columns:1fr 1fr; gap:.45rem; }
        .actions .wide { grid-column:1 / -1; }
        .banner { padding:.35rem .9rem; font-size:.8rem; text-align:center; }
        .banner.warn { background:#3b2b0a; color:#fcd34d; }
        .banner.offline { background:#3a0d0d; color:#fca5a5; }
        .modal { position:fixed; inset:0; background:rgba(2,6,23,.72); display:none; align-items:center; justify-content:center; z-index:40; }
        .modal.open { display:flex; }
        .modal .box { background:var(--panel); border:1px solid var(--line); border-radius:12px; width:min(620px,94vw); max-height:88vh; overflow:auto; padding:1rem 1.1rem; }
        .modal h2 { margin:.1rem 0 .8rem; font-size:1rem; }
        .modal .close { position:absolute; }
        .board { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:.5rem; }
        .tbl { border:1px solid var(--line); border-radius:10px; padding:.6rem; text-align:center; }
        .tbl.occupied { border-color:var(--warn); }
        .tbl.reserved { border-color:var(--accent); }
        .muted { color:var(--muted); }
        .err { background:#450a0a; color:#fecaca; padding:.5rem .7rem; border-radius:8px; font-size:.82rem; margin:.5rem 0; }
        .toast { position:fixed; bottom:1rem; left:50%; transform:translateX(-50%); background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:.6rem 1rem; z-index:60; display:none; }
    </style>
</head>
<body>
    @php
        $vm = [
            'branchId' => $branchId, 'branchName' => $branchName, 'userName' => $userName,
            'terminals' => $terminals, 'defaultTerminalId' => $defaultTerminalId, 'canChangeTerminal' => $canChangeTerminal,
            'orderTypes' => $orderTypes, 'defaultOrderType' => $defaultOrderType, 'orderTypeLabels' => $orderTypeLabels,
            'categories' => $categories, 'products' => $products, 'combos' => $combos, 'waiters' => $waiters,
            'paymentMethods' => $paymentMethods, 'operationalStockReady' => $operationalStockReady,
        ];
    @endphp
    <script id="edge-pos-data" type="application/json">@json($vm)</script>

    <header>
        <h1>POS</h1>
        <button type="button" class="ghost" id="view-tables-btn">View Tables</button>
        <span class="who">{{ $branchName }} · {{ $userName }}</span>
        <span class="spacer"></span>
        <label class="who" for="order-type">Order</label>
        <select id="order-type"></select>
        <label class="who" for="terminal">Terminal</label>
        <select id="terminal" @unless($canChangeTerminal) title="Selling terminal is fixed for your account" @endunless></select>
        <button type="button" class="ghost" id="shift-btn">Shift</button>
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost">Logout</button></form>
    </header>

    @unless($operationalStockReady)
        <div class="banner warn">Operational stock baseline not accepted yet — selling is refused until a baseline is cut over.</div>
    @endunless
    <div class="banner offline" id="offline-banner" hidden>Network unavailable — sales complete locally and sync when the connection returns.</div>

    <main>
        <section class="grid-pane">
            <div class="tabs" id="category-tabs"></div>
            <div class="strip" id="child-strip" hidden></div>
            <div class="toolbar">
                <input type="search" id="search" placeholder="Search products or scan barcode…" autocomplete="off">
            </div>
            <div class="tiles" id="tiles"></div>
        </section>

        <section class="cart-pane">
            <div class="cart-head">
                <span class="chip" id="customer-chip">Walk-in</span>
                <input type="text" id="customer-name" placeholder="Customer (optional)" style="flex:1">
            </div>
            <div class="lines" id="cart-lines"><p class="muted" style="padding:.6rem">Cart is empty.</p></div>
            <div class="totals" id="totals">
                <div class="row"><span>Items</span><span id="t-items">0</span></div>
                <div class="row grand"><span>Total</span><span id="t-grand">0.00</span></div>
            </div>
            <div class="actions">
                <button type="button" id="hold-btn">Hold</button>
                <button type="button" id="draft-btn">Draft</button>
                <button type="button" class="wide" id="preview-btn">Preview Bill</button>
                <button type="button" class="primary wide" id="pay-btn">Review &amp; Pay</button>
                <button type="button" class="ghost" id="quick-report-btn">Quick Report</button>
                <button type="button" class="ghost" id="recent-prints-btn">Recent Prints</button>
            </div>
        </section>
    </main>

    <div class="modal" id="modal"><div class="box"><div id="modal-body"></div></div></div>
    <div class="toast" id="toast"></div>

    <script>
    (function () {
        'use strict';
        const DATA = JSON.parse(document.getElementById('edge-pos-data').textContent);
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const BASE = '{{ url('/edge/local/pos') }}';
        const money = n => (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);

        const state = { orderType: DATA.defaultOrderType, terminalId: null, category: null, cart: [] , clientUuid: null };

        // ---- API helper: all mutations go to the Edge-local POS endpoints only. ----
        async function api(method, path, body) {
            const opt = { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } };
            if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
            let res;
            try { res = await fetch(BASE + path, opt); }
            catch (e) { document.getElementById('offline-banner').hidden = false; throw new Error('offline'); }
            document.getElementById('offline-banner').hidden = true;
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || ('Request failed (' + res.status + ')'));
            return json;
        }

        function toast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 2600); }
        function uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }); }

        // ---- Terminal selector: default-terminal parity (auto-select assigned, never "first seen"). ----
        function renderTerminals() {
            const sel = document.getElementById('terminal');
            sel.innerHTML = '';
            DATA.terminals.forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.name; sel.appendChild(o); });
            let pick = DATA.defaultTerminalId && DATA.terminals.some(t => t.id === DATA.defaultTerminalId)
                ? DATA.defaultTerminalId : (DATA.terminals[0] ? DATA.terminals[0].id : null);
            if (pick) { sel.value = pick; selectTerminal(pick); }
            sel.disabled = !DATA.canChangeTerminal && DATA.terminals.length <= 1;
            sel.addEventListener('change', () => selectTerminal(Number(sel.value)));
        }
        async function selectTerminal(id) {
            state.terminalId = id;
            try { await api('POST', '/terminal/select', { terminal_id: id }); }
            catch (e) { toast(e.message); }
        }

        function renderOrderTypes() {
            const sel = document.getElementById('order-type');
            DATA.orderTypes.forEach(t => { const o = document.createElement('option'); o.value = t; o.textContent = DATA.orderTypeLabels[t] || t; sel.appendChild(o); });
            sel.value = state.orderType;
            sel.addEventListener('change', () => { state.orderType = sel.value; });
        }

        // ---- Category + Deals tabs (deal tabs are display-only). ----
        function renderTabs() {
            const tabs = document.getElementById('category-tabs');
            tabs.innerHTML = '';
            const all = tabBtn('All', null);
            tabs.appendChild(all);
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
            const q = document.getElementById('search').value.trim().toLowerCase();
            let items = [];
            if (state.category === 'deals') {
                items = DATA.combos.map(c => ({ deal: true, id: c.id, name: c.name, price: c.price }));
            } else {
                items = DATA.products
                    .filter(p => state.category == null || p.category_id === state.category)
                    .map(p => ({ deal: false, id: p.id, name: p.name, price: p.price }));
                // Deals also surface under their own category tab.
                DATA.combos.filter(c => state.category != null && c.category_id === state.category)
                    .forEach(c => items.unshift({ deal: true, id: c.id, name: c.name, price: c.price }));
            }
            if (q) items = items.filter(i => i.name.toLowerCase().includes(q));
            return items;
        }
        function renderTiles() {
            const wrap = document.getElementById('tiles'); wrap.innerHTML = '';
            const items = visibleItems();
            if (!items.length) { wrap.innerHTML = '<p class="muted">No products found.</p>'; return; }
            items.forEach(i => {
                const b = document.createElement('button'); b.className = 'tile' + (i.deal ? ' deal' : '');
                b.innerHTML = '<span class="nm">' + esc(i.name) + '</span><span class="pr">' + money(i.price) + (i.deal ? ' · Deal' : '') + '</span>';
                b.addEventListener('click', () => addToCart(i));
                wrap.appendChild(b);
            });
        }
        function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

        // ---- Cart (deals not yet sellable through the Edge sale API — kept explicit, not faked). ----
        function addToCart(item) {
            if (item.deal) { toast('Deal selling arrives in the deal-sale milestone.'); return; }
            const ex = state.cart.find(l => l.product_id === item.id && !l.deal);
            if (ex) ex.quantity += 1; else state.cart.push({ product_id: item.id, name: item.name, price: item.price, quantity: 1 });
            renderCart();
        }
        function changeQty(pid, d) {
            const l = state.cart.find(x => x.product_id === pid); if (!l) return;
            l.quantity += d; if (l.quantity <= 0) state.cart = state.cart.filter(x => x.product_id !== pid);
            renderCart();
        }
        function renderCart() {
            const wrap = document.getElementById('cart-lines');
            if (!state.cart.length) { wrap.innerHTML = '<p class="muted" style="padding:.6rem">Cart is empty.</p>'; }
            else {
                wrap.innerHTML = '';
                state.cart.forEach(l => {
                    const row = document.createElement('div'); row.className = 'line';
                    row.innerHTML = '<div class="ln-nm">' + esc(l.name) + '</div><div class="ln-amt">' + money(l.price * l.quantity) + '</div>' +
                        '<div class="ln-ctl"><button data-m="-1">−</button><span>' + l.quantity + '</span><button data-m="1">+</button></div>';
                    row.querySelector('[data-m="-1"]').addEventListener('click', () => changeQty(l.product_id, -1));
                    row.querySelector('[data-m="1"]').addEventListener('click', () => changeQty(l.product_id, 1));
                    wrap.appendChild(row);
                });
            }
            const items = state.cart.reduce((s, l) => s + l.quantity, 0);
            const grand = state.cart.reduce((s, l) => s + l.price * l.quantity, 0);
            document.getElementById('t-items').textContent = items;
            document.getElementById('t-grand').textContent = money(grand);
        }

        function cartLines() { return state.cart.map(l => ({ product_id: l.product_id, quantity: l.quantity })); }
        function requireCart() { if (!state.cart.length) { toast('Add at least one item.'); return false; } return true; }

        // ---- Preview Bill: authoritative running bill, ZERO mutation (edge preview endpoint). ----
        async function previewBill() {
            if (!requireCart()) return;
            try {
                const p = await api('POST', '/preview-bill', { order_type: state.orderType, lines: cartLines() });
                const t = p.totals || {};
                openModal('<h2>Preview Bill</h2>' +
                    '<p class="muted">Running bill — no payment, stock, KOT, or receipt is created.</p>' +
                    rowsHtml(t) +
                    '<div style="text-align:right;margin-top:1rem"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>');
            } catch (e) { toast(e.message); }
        }
        function rowsHtml(t) {
            const r = (k, v) => '<div class="row"><span>' + k + '</span><span>' + money(v) + '</span></div>';
            return '<div class="totals">' + r('Subtotal', t.subtotal ?? t.sub_total ?? 0) +
                (t.discount_amount ? r('Discount', -t.discount_amount) : '') +
                (t.tax_amount ? r('Tax', t.tax_amount) : '') +
                (t.service_charge ? r('Service charge', t.service_charge) : '') +
                '<div class="row grand"><span>Grand total</span><span>' + money(t.grand_total ?? 0) + '</span></div></div>';
        }

        // ---- Review & Pay: cash settlement through the Edge sale API. ----
        async function reviewAndPay() {
            if (!requireCart()) return;
            if (!state.terminalId) { toast('Select a terminal first.'); return; }
            let totals = {};
            try { const p = await api('POST', '/preview-bill', { order_type: state.orderType, lines: cartLines() }); totals = p.totals || {}; }
            catch (e) { toast(e.message); return; }
            const grand = Number(totals.grand_total || 0);
            const cash = DATA.paymentMethods[0];
            const needsQuickSale = state.orderType === 'quick_sale';
            openModal('<h2>Review &amp; Pay</h2>' + rowsHtml(totals) +
                (cash ? '' : '<div class="err">No cash payment method is configured.</div>') +
                (needsQuickSale ? '<label class="who">Vehicle #</label><input type="text" id="rp-vehicle" style="width:100%">' +
                    '<label class="who">Waiter</label><select id="rp-waiter" style="width:100%">' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select>' : '') +
                '<label class="who">Cash tendered</label><input type="number" id="rp-tendered" style="width:100%" value="' + money(grand) + '" min="' + money(grand) + '" step="0.01">' +
                '<div id="rp-err"></div>' +
                '<div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">' +
                '<button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button>' +
                '<button class="ok" id="rp-complete"' + (cash ? '' : ' disabled') + '>Complete Sale</button></div>');
            const btn = document.getElementById('rp-complete');
            if (btn) btn.addEventListener('click', () => completeSale(grand, cash));
        }
        async function completeSale(grand, cash) {
            const btn = document.getElementById('rp-complete'); btn.disabled = true;
            const tendered = Number(document.getElementById('rp-tendered').value || 0);
            if (tendered < grand) { showRpErr('Cash tendered is less than the total.'); btn.disabled = false; return; }
            const payload = {
                order_type: state.orderType, client_uuid: uuid(), lines: cartLines(),
                payments: [{ payment_method_id: cash.id, amount: grand, tendered_amount: tendered }],
            };
            if (state.orderType === 'quick_sale') {
                payload.vehicle_number = (document.getElementById('rp-vehicle').value || '').trim();
                payload.restaurant_waiter_id = Number(document.getElementById('rp-waiter').value || 0) || null;
            }
            const nm = (document.getElementById('customer-name').value || '').trim();
            if (nm) payload.customer_name = nm;
            try {
                const sale = await api('POST', '/sales', payload);
                closeModal();
                state.cart = []; renderCart();
                toast('Sale ' + (sale.sale_no || '#' + sale.sale_id) + ' completed · change ' + money(sale.change_amount || 0));
            } catch (e) { showRpErr(e.message); btn.disabled = false; }
        }
        function showRpErr(m) { const e = document.getElementById('rp-err'); if (e) e.innerHTML = '<div class="err">' + esc(m) + '</div>'; }

        // ---- Hold / Draft (Recall list endpoint lands with the dine-in milestone). ----
        async function holdSale(asDraft) {
            if (!requireCart()) return;
            try {
                await api('POST', '/held-sales', { order_type: state.orderType, save_as_draft: !!asDraft, lines: cartLines() });
                state.cart = []; renderCart();
                toast(asDraft ? 'Saved as draft.' : 'Order held.');
            } catch (e) { toast(e.message); }
        }

        // ---- View Tables (read-only board for milestone 1). ----
        async function viewTables() {
            try {
                const b = await api('GET', '/restaurant/board');
                let html = '<h2>Table Board</h2>';
                (b.floors || []).forEach(f => {
                    html += '<h3 class="muted" style="margin:.6rem 0 .3rem">' + esc(f.name) + '</h3><div class="board">';
                    (f.tables || []).forEach(t => {
                        const cls = t.status === 'occupied' || t.status === 'bill_requested' ? 'occupied' : (t.status === 'reserved' ? 'reserved' : '');
                        html += '<div class="tbl ' + cls + '"><strong>' + esc(t.table_no || t.name) + '</strong><div class="muted">' + esc(t.status) + '</div></div>';
                    });
                    html += '</div>';
                });
                if (!(b.floors || []).length) html += '<p class="muted">No floors configured.</p>';
                html += '<div style="text-align:right;margin-top:1rem"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
            } catch (e) { toast(e.message); }
        }

        async function shiftAction() {
            try {
                const s = await api('GET', '/shift');
                openModal('<h2>Shift</h2><p class="muted">Terminal shift state: ' + esc(s.status || 'unknown') + '</p>' +
                    '<div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem">' +
                    '<button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                    (s.status === 'open' ? '<button class="ghost" id="sh-close">Close shift</button>' : '<button class="ok" id="sh-open">Open shift</button>') + '</div>');
                const o = document.getElementById('sh-open'), c = document.getElementById('sh-close');
                if (o) o.addEventListener('click', async () => { try { await api('POST', '/shift/open', {}); toast('Shift opened.'); closeModal(); } catch (e) { toast(e.message); } });
                if (c) c.addEventListener('click', async () => { try { await api('POST', '/shift/close', {}); toast('Shift closed.'); closeModal(); } catch (e) { toast(e.message); } });
            } catch (e) { toast(e.message); }
        }

        function openModal(html) { document.getElementById('modal-body').innerHTML = html; document.getElementById('modal').classList.add('open'); }
        function closeModal() { document.getElementById('modal').classList.remove('open'); }
        document.getElementById('modal').addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });

        // Wire toolbar
        document.getElementById('search').addEventListener('input', renderTiles);
        document.getElementById('preview-btn').addEventListener('click', previewBill);
        document.getElementById('pay-btn').addEventListener('click', reviewAndPay);
        document.getElementById('hold-btn').addEventListener('click', () => holdSale(false));
        document.getElementById('draft-btn').addEventListener('click', () => holdSale(true));
        document.getElementById('view-tables-btn').addEventListener('click', viewTables);
        document.getElementById('shift-btn').addEventListener('click', shiftAction);
        document.getElementById('quick-report-btn').addEventListener('click', () => toast('Quick Report arrives in the reporting milestone (reuses the canonical report engine).'));
        document.getElementById('recent-prints-btn').addEventListener('click', () => toast('Recent Prints arrives with the print milestone.'));

        renderOrderTypes(); renderTerminals(); renderTabs(); renderTiles(); renderCart();
        window.EdgePOS = { closeModal, state };
    })();
    </script>
</body>
</html>
