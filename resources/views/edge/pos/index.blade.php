{{--
  EDGE-CASHIER-UI — Branch-Server browser cashier POS.

  The current Online Bingoo POS is the functional specification (locked product rule): this page presents
  the SAME operator surface — POS header + View Tables, terminal selector, order-type tabs, category/Deals
  pills, product tiles, cart, customer, totals, Hold/Draft/Recall, Add Round/KOT, Review & Pay, Preview Bill,
  Table Board with open/recall/close/reserve actions — but every mutation targets the Edge-local JSON APIs
  (edge.local.pos.*), never a Cloud posting/finance/inventory route.

  Self-contained (inline CSS/JS, no Vite/build assets) so it renders on the appliance with NO Internet. The
  bootstrap view-model comes from EdgeLocalPosController@screen; all authority (stock, shift, terminal, sale,
  KOT sent-pool, table locks) is re-validated server-side by EdgeLocalPosService.

  Milestones: 1 = serve the cashier experience + core cash sale · 2 = Dine-In / Recall / Add Round / KOT /
  table actions (this revision).
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
        body { margin:0; font-family:system-ui,Segoe UI,sans-serif; background:var(--bg); color:var(--ink); height:100vh; display:flex; flex-direction:column; overflow:hidden; font-size:14px; }
        header { background:var(--panel); border-bottom:1px solid var(--line); padding:.5rem .9rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
        header h1 { font-size:1.05rem; margin:0; }
        header .who { color:var(--muted); font-size:.8rem; }
        header .spacer { flex:1; }
        select, input, button { font:inherit; color:var(--ink); }
        select, input[type=text], input[type=search], input[type=number], input[type=datetime-local], textarea { background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:.45rem .6rem; }
        button { cursor:pointer; border:1px solid var(--line); background:var(--panel2); border-radius:8px; padding:.5rem .8rem; }
        button.primary { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
        button.ok { background:var(--ok); border-color:var(--ok); color:#fff; }
        button.warn { background:var(--warn); border-color:var(--warn); color:#fff; }
        button.danger { background:var(--danger); border-color:var(--danger); color:#fff; }
        button.ghost { background:transparent; }
        button.sm { padding:.3rem .6rem; font-size:.8rem; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        .pill { border-radius:999px; padding:.35rem .8rem; }
        .pill.active { background:var(--accent); border-color:var(--accent); color:#fff; }
        main { flex:1; display:grid; grid-template-columns: 1fr 380px; min-height:0; }
        .grid-pane { display:flex; flex-direction:column; min-height:0; border-right:1px solid var(--line); }
        .tabs { display:flex; gap:.4rem; padding:.5rem .7rem; flex-wrap:wrap; border-bottom:1px solid var(--line); }
        .toolbar { display:flex; gap:.5rem; padding:.5rem .7rem; }
        .toolbar input { flex:1; }
        .tiles { flex:1; overflow-y:auto; padding:.7rem; display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.55rem; align-content:start; }
        .tile { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:.6rem; text-align:left; min-height:74px; display:flex; flex-direction:column; justify-content:space-between; }
        .tile .nm { font-size:.82rem; line-height:1.15rem; max-height:2.3rem; overflow:hidden; }
        .tile .pr { font-size:.82rem; color:var(--muted); margin-top:.35rem; }
        .tile.deal { border-color:var(--accent); }
        .cart-pane { display:flex; flex-direction:column; min-height:0; background:var(--panel2); }
        .cart-head { padding:.5rem .7rem; border-bottom:1px solid var(--line); display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
        .chip { font-size:.78rem; background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:.3rem .6rem; color:var(--muted); }
        .chip.hot { border-color:var(--accent); color:var(--ink); }
        .chip.draft { border-color:var(--warn); color:#fcd34d; }
        .lines { flex:1; overflow-y:auto; padding:.4rem .5rem; }
        .line { display:grid; grid-template-columns:1fr auto; gap:.2rem .5rem; padding:.45rem .3rem; border-bottom:1px solid var(--line); }
        .line .ln-nm { font-size:.82rem; }
        .line .ln-sub { font-size:.72rem; color:var(--muted); }
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
        .modal .box { background:var(--panel); border:1px solid var(--line); border-radius:12px; width:min(720px,94vw); max-height:88vh; overflow:auto; padding:1rem 1.1rem; }
        .modal h2 { margin:.1rem 0 .8rem; font-size:1rem; }
        .modal h3 { margin:.6rem 0 .3rem; font-size:.85rem; color:var(--muted); }
        .board { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:.5rem; }
        .tbl { border:1px solid var(--line); border-radius:10px; padding:.6rem; text-align:center; background:var(--panel2); cursor:pointer; }
        .tbl.occupied, .tbl.bill_requested { border-color:var(--warn); }
        .tbl.reserved { border-color:var(--accent); }
        .tbl.selected { outline:2px solid var(--accent); }
        .muted { color:var(--muted); }
        .err { background:#450a0a; color:#fecaca; padding:.5rem .7rem; border-radius:8px; font-size:.82rem; margin:.5rem 0; }
        .toast { position:fixed; bottom:1rem; left:50%; transform:translateX(-50%); background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:.6rem 1rem; z-index:60; display:none; max-width:90vw; }
        .list-row { display:flex; justify-content:space-between; align-items:center; gap:.5rem; padding:.5rem .3rem; border-bottom:1px solid var(--line); cursor:pointer; }
        .list-row:hover { background:var(--panel2); }
        .field { display:flex; flex-direction:column; gap:.2rem; margin:.4rem 0; }
        .field label { font-size:.78rem; color:var(--muted); }
        .btn-row { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; flex-wrap:wrap; }
        .btn-row.left { justify-content:flex-start; }
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
            'canCompleteSale' => $canCompleteSale,
            'deliveryChannels' => $deliveryChannels, 'deliveryRiders' => $deliveryRiders,
            'deliveryChargeLocked' => $deliveryChargeLocked, 'defaultDeliveryCharge' => $defaultDeliveryCharge,
            'manualDiscountNeedsManager' => $manualDiscountNeedsManager,
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
        <span class="chip" id="sync-chip" hidden></span>
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost">Logout</button></form>
    </header>

    @unless($operationalStockReady)
        <div class="banner warn">Operational stock baseline not accepted yet — selling is refused until a baseline is cut over.</div>
    @endunless
    <div class="banner offline" id="offline-banner" hidden>Network unavailable — sales complete locally and sync when the connection returns.</div>

    <main>
        <section class="grid-pane">
            <div class="tabs" id="category-tabs"></div>
            <div class="toolbar">
                <input type="search" id="search" placeholder="Search products or scan barcode…" autocomplete="off">
            </div>
            <div class="tiles" id="tiles"></div>
        </section>

        <section class="cart-pane">
            <div class="cart-head">
                <span class="chip" id="customer-chip">Walk-in</span>
                <span class="chip" id="check-chip" hidden></span>
                <input type="text" id="customer-name" placeholder="Customer (optional)" style="flex:1;min-width:120px">
            </div>
            <div class="lines" id="cart-lines"><p class="muted" style="padding:.6rem">Cart is empty.</p></div>
            <div class="totals" id="totals">
                <div class="row"><span>Items</span><span id="t-items">0</span></div>
                <div class="row grand"><span>Total</span><span id="t-grand">0.00</span></div>
            </div>
            <div class="actions" id="actions"></div>
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
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        // ---- state: a plain cart, OR a table session with no check yet, OR a loaded (recalled) open check. ----
        const state = { orderType: DATA.defaultOrderType, terminalId: null, category: null, cart: [], dirty: false,
                        session: null,   // {id, table_id, table_no, waiter_name}
                        held: null,      // {id, sale_no, sale_uuid, is_draft, order_type, session_id, table_no, waiter_name, terminal_id, totals...}
                        customer: null,  // {id, name, phone, addresses[]} picked from the synced book
                        pendingClientUuid: null,
                        // Commercial intent — the same fields the Online Review & Pay carries (discount, promo, delivery).
                        commercial: { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null,
                                      delivery_channel_id: null, delivery_rider_id: null, delivery_address: '', delivery_charge_amount: DATA.defaultDeliveryCharge } };

        // ---- API helper: all mutations go to the Edge-local POS endpoints only. ----
        async function api(method, path, body) {
            const opt = { method, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } };
            if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
            let res;
            try { res = await fetch(BASE + path, opt); }
            catch (e) { document.getElementById('offline-banner').hidden = false; throw new Error('The branch server did not answer — check the LAN connection.'); }
            document.getElementById('offline-banner').hidden = true;
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || (json.errors ? Object.values(json.errors).flat().join(' ') : 'Request failed (' + res.status + ')'));
            return json;
        }
        function toast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3200); }
        function uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }); }
        function openModal(html) { document.getElementById('modal-body').innerHTML = html; document.getElementById('modal').classList.add('open'); }
        function closeModal() { document.getElementById('modal').classList.remove('open'); }
        document.getElementById('modal').addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });
        const $ = id => document.getElementById(id);

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

        // ---- Cart. A carried line (from an open check) keeps its line id + captured price. ----
        function addToCart(item) {
            if (item.deal) {
                // DEAL parity: the client names the deal + quantity only; the server expands the synced combo book.
                const ex = state.cart.find(l => l.combo_id === item.id && !l.line_id);
                if (ex) ex.quantity += 1; else state.cart.push({ key: 'd' + item.id, combo_id: item.id, product_id: null, name: item.name, price: item.price, quantity: 1, line_id: null, kot_sent_quantity: 0, deal: true, components: [], component_lines: [] });
            } else {
                const ex = state.cart.find(l => l.product_id === item.id && !l.combo_id);
                if (ex) ex.quantity += 1; else state.cart.push({ key: 'p' + item.id, product_id: item.id, name: item.name, price: item.price, quantity: 1, line_id: null, kot_sent_quantity: 0 });
            }
            state.dirty = true; renderCart();
        }
        function changeQty(key, d) {
            const l = state.cart.find(x => x.key === key); if (!l) return;
            const next = l.quantity + d;
            if (next < l.kot_sent_quantity) { toast('Already sent to the kitchen — reducing needs a void with a reason.'); return; }
            l.quantity = next; if (l.quantity <= 0) state.cart = state.cart.filter(x => x.key !== key);
            state.dirty = true; renderCart();
        }
        function renderCart() {
            const wrap = $('cart-lines');
            if (!state.cart.length) { wrap.innerHTML = '<p class="muted" style="padding:.6rem">Cart is empty.</p>'; }
            else {
                wrap.innerHTML = '';
                state.cart.forEach(l => {
                    const row = document.createElement('div'); row.className = 'line';
                    const sub = (l.kot_sent_quantity > 0 ? '<div class="ln-sub">kitchen has ' + l.kot_sent_quantity + '</div>' : '') +
                        (l.components && l.components.length ? '<div class="ln-sub">' + esc(l.components.join(' · ')) + '</div>' : '');
                    row.innerHTML = '<div><div class="ln-nm">' + esc(l.name) + (l.deal ? ' <span class="chip hot" style="padding:.05rem .4rem">Deal</span>' : '') + '</div>' + sub + '</div><div class="ln-amt">' + money(l.price * l.quantity) + '</div>' +
                        '<div class="ln-ctl"><button data-m="-1">−</button><span>' + l.quantity + '</span><button data-m="1">+</button><span class="muted" style="font-size:.72rem">@ ' + money(l.price) + '</span></div>';
                    row.querySelector('[data-m="-1"]').addEventListener('click', () => changeQty(l.key, -1));
                    row.querySelector('[data-m="1"]').addEventListener('click', () => changeQty(l.key, 1));
                    wrap.appendChild(row);
                });
            }
            $('t-items').textContent = state.cart.reduce((s, l) => s + l.quantity, 0);
            $('t-grand').textContent = money(state.held && !state.dirty ? state.held.grand_total : state.cart.reduce((s, l) => s + l.price * l.quantity, 0));
            renderChips(); renderActions();
        }
        function renderChips() {
            const c = $('check-chip');
            if (state.held) {
                c.hidden = false; c.className = 'chip ' + (state.held.is_draft ? 'draft' : 'hot');
                c.textContent = (state.held.is_draft ? 'DRAFT ' : 'Held ') + (state.held.table_no ? 'Table ' + state.held.table_no + ' · ' : '') + (state.held.sale_no || '').slice(-8) + (state.held.waiter_name ? ' · ' + state.held.waiter_name : '');
            } else if (state.session) {
                c.hidden = false; c.className = 'chip hot';
                c.textContent = 'Table ' + state.session.table_no + (state.session.waiter_name ? ' · ' + state.session.waiter_name : '') + ' · new check';
            } else { c.hidden = true; }
            const cust = state.customer?.name || state.held?.customer_name || $('customer-name').value.trim();
            $('customer-chip').textContent = cust || 'Walk-in';
        }
        function cartLines() { return state.cart.map(l => Object.assign(l.combo_id ? { combo_id: l.combo_id, quantity: l.quantity } : { product_id: l.product_id, quantity: l.quantity }, l.line_id ? { sales_order_line_id: l.line_id } : {})); }
        // The commercial intent that travels with hold, preview and payment — the same fields as Online's Review & Pay.
        function commercial() {
            const c = state.commercial, out = {};
            if (c.discount_type && c.discount_type !== 'none') { out.discount_type = c.discount_type; out.discount_value = Number(c.discount_value || 0); }
            if (c.promo_code) out.promo_code = c.promo_code;
            if (c.manager_approval_id) out.manager_approval_id = c.manager_approval_id;
            if (state.customer) { out.customer_id = state.customer.id; out.customer_name = state.customer.name; out.customer_phone = state.customer.phone || null; }
            else { const nm = $('customer-name').value.trim(); if (nm) out.customer_name = nm; }
            if (state.orderType === 'delivery' && !state.session) {
                if (c.delivery_channel_id) out.delivery_channel_id = Number(c.delivery_channel_id);
                if (c.delivery_rider_id) out.delivery_rider_id = Number(c.delivery_rider_id);
                if (c.delivery_address) out.delivery_address = c.delivery_address;
                if (!DATA.deliveryChargeLocked) out.delivery_charge_amount = Number(c.delivery_charge_amount || 0);
            }
            return out;
        }
        function requireCart() { if (!state.cart.length) { toast('Add at least one item.'); return false; } return true; }

        // ---- Contextual action buttons (the Online layout: Hold/Draft/Recall, then KOT/Add Round for a check). ----
        function renderActions() {
            const a = $('actions'); a.innerHTML = '';
            const btn = (label, cls, fn, wide) => { const b = document.createElement('button'); b.textContent = label; b.className = cls + (wide ? ' wide' : ''); b.addEventListener('click', fn); a.appendChild(b); return b; };
            if (state.held) {
                btn(state.dirty ? 'Save round' : 'Saved', state.dirty ? 'primary' : '', () => saveRound(false));
                btn('KOT', 'warn', sendKot);
                btn('Preview Bill', '', previewBill);
                btn('Split Bill', '', splitBill);
                btn('Review & Pay', 'ok', reviewAndPay, true);
                btn('Cancel order', 'danger', cancelOrder);
                btn('Leave check', 'ghost', leaveCheck);
            } else if (state.session) {
                btn('Hold (send later)', 'primary', () => holdSale(false));
                btn('Draft', '', () => holdSale(true));
                btn('Preview Bill', '', previewBill, true);
                btn('Leave table', 'ghost', leaveCheck, true);
            } else {
                btn('Hold', '', () => holdSale(false));
                btn('Draft', '', () => holdSale(true));
                btn('Recall', '', recallList);
                btn('Preview Bill', '', previewBill);
                btn('Review & Pay', 'primary', reviewAndPay, true);
            }
            btn('Quick Report', 'ghost', quickReport);
            btn('Recent Prints', 'ghost', recentPrints);
        }

        // ---- Preview Bill: authoritative running bill, ZERO mutation. ----
        async function previewBill() {
            if (!requireCart()) return;
            try {
                let t;
                if (state.held && !state.dirty) { t = state.held; }
                else { const p = await api('POST', '/preview-bill', Object.assign({ order_type: state.orderType, lines: cartLines() }, commercial())); t = p.totals || {}; }
                openModal('<h2>Preview Bill</h2><p class="muted">Running bill — no payment, stock, KOT, or receipt is created.</p>' + rowsHtml(t) +
                    '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>');
            } catch (e) { toast(e.message); }
        }
        function rowsHtml(t) {
            const r = (k, v) => '<div class="row"><span>' + k + '</span><span>' + money(v) + '</span></div>';
            return '<div class="totals">' + r('Subtotal', t.subtotal ?? t.sub_total ?? 0) +
                (Number(t.discount_amount) ? r('Discount' + (t.promo_code ? ' (' + esc(t.promo_code) + ')' : ''), -t.discount_amount) : '') +
                (Number(t.tax_amount) ? r('Tax', t.tax_amount) : '') +
                (Number(t.service_charge_amount ?? t.service_charge) ? r('Service charge', t.service_charge_amount ?? t.service_charge) : '') +
                (Number(t.delivery_charge_amount) ? r('Delivery charge', t.delivery_charge_amount) : '') +
                '<div class="row grand"><span>Grand total</span><span>' + money(t.grand_total ?? 0) + '</span></div></div>';
        }

        // ---- Commercial panel (Online Review & Pay parity): promo code, manual discount, delivery details, customer. ----
        function commercialPanelHtml() {
            const c = state.commercial, isDelivery = state.orderType === 'delivery' && !state.session && !state.held;
            let html = '<details ' + ((c.discount_type !== 'none' || c.promo_code || isDelivery) ? 'open' : '') + '><summary class="muted">Discount · Promo · Customer' + (isDelivery ? ' · Delivery' : '') + '</summary>' +
                '<div class="field"><label>Promo code</label><div style="display:flex;gap:.4rem"><input type="text" id="cm-promo" value="' + esc(c.promo_code || '') + '" placeholder="Promo code" style="flex:1;text-transform:uppercase"></div></div>' +
                '<div class="field"><label>Manual discount' + (DATA.manualDiscountNeedsManager ? ' (manager approval required)' : '') + '</label><div style="display:flex;gap:.4rem">' +
                '<select id="cm-disc-type"><option value="none"' + (c.discount_type === 'none' ? ' selected' : '') + '>None</option><option value="fixed"' + (c.discount_type === 'fixed' ? ' selected' : '') + '>Rs</option><option value="percent"' + (c.discount_type === 'percent' ? ' selected' : '') + '>%</option></select>' +
                '<input type="number" id="cm-disc-value" min="0" step="0.01" value="' + esc(c.discount_value || 0) + '" style="flex:1"></div></div>' +
                '<div class="field"><label>Customer</label><div style="display:flex;gap:.4rem"><input type="search" id="cm-cust-q" placeholder="Search name / phone…" style="flex:1"><button type="button" class="sm ghost" id="cm-cust-clear">Walk-in</button></div>' +
                '<div id="cm-cust-results"></div><div class="muted" id="cm-cust-picked">' + (state.customer ? esc(state.customer.name) + (state.customer.phone ? ' · ' + esc(state.customer.phone) : '') : 'Walk-in') + '</div></div>';
            if (isDelivery) {
                html += '<div class="field"><label>Delivery channel</label><select id="cm-channel"><option value="">—</option>' + DATA.deliveryChannels.map(ch => '<option value="' + ch.id + '"' + (Number(c.delivery_channel_id) === ch.id ? ' selected' : '') + '>' + esc(ch.name) + (ch.type === 'aggregator' ? ' (aggregator)' : '') + '</option>').join('') + '</select></div>' +
                    '<div class="field"><label>Rider</label><select id="cm-rider"><option value="">—</option>' + DATA.deliveryRiders.map(r => '<option value="' + r.id + '"' + (Number(c.delivery_rider_id) === r.id ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('') + '</select></div>' +
                    '<div class="field"><label>Address</label>' + (state.customer && state.customer.addresses && state.customer.addresses.length ? '<select id="cm-addr-pick"><option value="">Saved addresses…</option>' + state.customer.addresses.map(a => '<option value="' + esc(a.address) + '">' + esc((a.label ? a.label + ': ' : '') + a.address) + '</option>').join('') + '</select>' : '') +
                    '<input type="text" id="cm-address" value="' + esc(c.delivery_address || '') + '" placeholder="Delivery address"></div>' +
                    '<div class="field"><label>Delivery charge' + (DATA.deliveryChargeLocked ? ' (fixed by the branch)' : '') + '</label><input type="number" id="cm-charge" min="0" step="1" value="' + esc(DATA.deliveryChargeLocked ? DATA.defaultDeliveryCharge : (c.delivery_charge_amount ?? 0)) + '"' + (DATA.deliveryChargeLocked ? ' disabled' : '') + '></div>';
            }
            html += '<div class="btn-row left"><button type="button" class="sm" id="cm-apply">Apply &amp; recalculate</button></div></details>';
            return html;
        }
        function readCommercialPanel() {
            const c = state.commercial;
            c.promo_code = ($('cm-promo')?.value || '').trim().toUpperCase();
            c.discount_type = $('cm-disc-type')?.value || 'none';
            c.discount_value = Number($('cm-disc-value')?.value || 0);
            if ($('cm-channel')) c.delivery_channel_id = $('cm-channel').value || null;
            if ($('cm-rider')) c.delivery_rider_id = $('cm-rider').value || null;
            if ($('cm-address')) c.delivery_address = $('cm-address').value.trim();
            if ($('cm-charge') && !DATA.deliveryChargeLocked) c.delivery_charge_amount = Number($('cm-charge').value || 0);
            c.manager_approval_id = null; // a changed discount needs a fresh approval
        }
        function wireCommercialPanel(onChange) {
            $('cm-apply')?.addEventListener('click', () => { readCommercialPanel(); state.dirty = true; onChange(); });
            $('cm-addr-pick')?.addEventListener('change', e => { if (e.target.value) $('cm-address').value = e.target.value; });
            $('cm-cust-clear')?.addEventListener('click', () => { state.customer = null; readCommercialPanel(); state.dirty = true; onChange(); });
            let timer = null;
            $('cm-cust-q')?.addEventListener('input', e => {
                clearTimeout(timer); const q = e.target.value.trim(); if (q.length < 2) { $('cm-cust-results').innerHTML = ''; return; }
                timer = setTimeout(async () => {
                    try {
                        const r = await api('GET', '/customers?q=' + encodeURIComponent(q));
                        $('cm-cust-results').innerHTML = r.customers.map(cu => '<div class="list-row" data-cid="' + cu.id + '"><span>' + esc(cu.name) + '</span><span class="muted">' + esc(cu.phone || '') + '</span></div>').join('') || '<div class="muted">No customer found in the book. Adding a new customer needs the Online POS.</div>';
                        document.querySelectorAll('#cm-cust-results [data-cid]').forEach(row => row.addEventListener('click', () => {
                            state.customer = r.customers.find(cu => cu.id === Number(row.dataset.cid)); readCommercialPanel(); state.dirty = true; renderChips(); onChange();
                        }));
                    } catch (err) { toast(err.message); }
                }, 250);
            });
        }
        // Manager approval for a manual discount — the manager authenticates with THEIR OWN Edge credential.
        function askManagerApproval(payload) {
            return new Promise(resolve => {
                openModal('<h2>Manager approval — manual discount</h2><p class="muted">Discount ' + esc(payload.discount_type) + ' ' + esc(payload.discount_value) + ' (' + money(payload.discount_amount) + ') needs a manager.</p>' +
                    '<div class="field"><label>Manager employee code</label><input type="text" id="ma-code" autocomplete="off"></div><div class="field"><label>Manager Edge credential</label><input type="password" id="ma-cred" autocomplete="off"></div><div id="ma-err"></div>' +
                    '<div class="btn-row"><button class="ghost" id="ma-cancel">Cancel</button><button class="ok" id="ma-ok">Approve</button></div>');
                $('ma-cancel').onclick = () => { closeModal(); resolve(null); };
                $('ma-ok').onclick = async () => {
                    try {
                        const r = await api('POST', '/manager-approvals/verify', { manager_employee_code: $('ma-code').value.trim(), manager_credential: $('ma-cred').value, action_type: 'manual_discount', payload });
                        closeModal(); resolve(r.approval_id);
                    } catch (e) { $('ma-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
            });
        }

        // ---- Hold / Draft (new check) — on a table session this is Round 1 of a dine-in check. ----
        async function holdSale(asDraft) {
            if (!requireCart()) return;
            const payload = Object.assign({ order_type: state.session ? 'dine_in' : state.orderType, save_as_draft: !!asDraft, lines: cartLines() }, commercial());
            if (state.session) payload.restaurant_table_session_id = state.session.id;
            if (payload.order_type === 'quick_sale') { const q = await askQuickSaleAttribution(); if (!q) return; Object.assign(payload, q); }
            try {
                const s = await api('POST', '/held-sales', payload);
                await loadHeld(s.sale_id);
                toast(asDraft ? 'Saved as draft ' + s.sale_no : 'Order held ' + s.sale_no + (state.session ? ' — send the KOT when ready.' : ''));
            } catch (e) { toast(e.message); }
        }
        function askQuickSaleAttribution() {
            return new Promise(resolve => {
                openModal('<h2>Quick Sale</h2><div class="field"><label>Vehicle #</label><input type="text" id="qs-vehicle"></div>' +
                    '<div class="field"><label>Waiter</label><select id="qs-waiter">' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' +
                    '<div class="btn-row"><button class="ghost" id="qs-cancel">Cancel</button><button class="primary" id="qs-ok">Continue</button></div>');
                $('qs-cancel').onclick = () => { closeModal(); resolve(null); };
                $('qs-ok').onclick = () => { const v = $('qs-vehicle').value.trim(), w = Number($('qs-waiter').value || 0) || null; closeModal(); resolve({ vehicle_number: v, restaurant_waiter_id: w }); };
            });
        }

        // ---- Recall: list the open checks, load one into the cart. Never touches the terminal selection. ----
        async function recallList() {
            try {
                const r = await api('GET', '/held-sales');
                let html = '<h2>Recall</h2>';
                if (!r.held_sales.length) html += '<p class="muted">No open checks.</p>';
                r.held_sales.forEach(h => {
                    html += '<div class="list-row" data-id="' + h.id + '"><div><strong>' + esc(h.sale_no) + '</strong> ' + (h.is_draft ? '<span class="chip draft">DRAFT</span>' : '') +
                        '<div class="muted">' + esc(DATA.orderTypeLabels[h.order_type] || h.order_type) + (h.table_no ? ' · Table ' + esc(h.table_no) : '') + (h.waiter_name ? ' · ' + esc(h.waiter_name) : '') + (h.customer_name ? ' · ' + esc(h.customer_name) : '') + '</div></div>' +
                        '<div>' + money(h.grand_total) + '<div class="muted" style="font-size:.72rem">' + h.item_count + ' items</div></div></div>';
                });
                html += '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                document.querySelectorAll('#modal .list-row').forEach(row => row.addEventListener('click', () => { closeModal(); loadHeld(Number(row.dataset.id)); }));
            } catch (e) { toast(e.message); }
        }
        async function loadHeld(id) {
            const d = await api('GET', '/held-sales/' + id);
            const h = d.held_sale;
            state.held = h; state.session = h.restaurant_table_session_id ? { id: h.restaurant_table_session_id, table_no: h.table_no, waiter_name: h.waiter_name } : null;
            // A deal is ONE cart row (its header); its components ride underneath for display + split.
            state.cart = h.lines.filter(l => l.line_kind !== 'component').map(l => l.line_kind === 'combo_header'
                ? { key: 'l' + l.id, combo_id: l.combo_id, product_id: null, name: l.product_name, price: l.unit_price, quantity: l.quantity, line_id: l.id, kot_sent_quantity: 0, deal: true,
                    components: h.lines.filter(c => c.parent_line_id === l.id).map(c => c.quantity + ' × ' + c.product_name),
                    component_lines: h.lines.filter(c => c.parent_line_id === l.id).map(c => ({ id: c.id, per_unit: l.quantity > 0 ? c.quantity / l.quantity : c.quantity })) }
                : { key: 'l' + l.id, product_id: l.product_id, name: l.product_name, price: l.unit_price, quantity: l.quantity, line_id: l.id, kot_sent_quantity: l.kot_sent_quantity });
            state.commercial = Object.assign({}, state.commercial, { discount_type: h.discount_type || 'none', discount_value: h.discount_value || 0, promo_code: h.promo_code || '', manager_approval_id: null,
                delivery_channel_id: h.delivery_channel_id, delivery_rider_id: h.delivery_rider_id, delivery_address: h.delivery_address || '', delivery_charge_amount: h.delivery_charge_amount ?? DATA.defaultDeliveryCharge });
            state.customer = h.customer_id ? { id: h.customer_id, name: h.customer_name, phone: h.customer_phone, addresses: [] } : null;
            state.dirty = false;
            lockOrderType(h.order_type);
            if (h.customer_name) $('customer-name').value = h.customer_name;
            renderCart();
        }
        function leaveCheck() {
            if (state.dirty && !confirm('Unsaved changes will be discarded. Leave?')) return;
            state.held = null; state.session = null; state.cart = []; state.dirty = false; $('customer-name').value = ''; unlockOrderType(); renderCart();
        }

        // ---- Add Round: re-submit carried lines by id + new lines; the server keeps captured prices + KOT-sent state. ----
        async function saveRound(asDraft) {
            if (!state.held) return; if (!requireCart()) return;
            try {
                const payload = Object.assign({ held_sale_id: state.held.id, order_type: state.held.order_type, save_as_draft: !!asDraft, lines: cartLines() }, commercial());
                if (state.held.restaurant_table_session_id) payload.restaurant_table_session_id = state.held.restaurant_table_session_id;
                if (state.held.order_type === 'quick_sale') { payload.vehicle_number = state.held.vehicle_number || ''; payload.restaurant_waiter_id = state.held.waiter_id; }
                const s = await api('POST', '/held-sales', payload);
                await loadHeld(s.sale_id);
                toast('Round saved on ' + s.sale_no);
                return true;
            } catch (e) { toast(e.message); return false; }
        }
        // ---- KOT: the unsent delta only (server sent-pool). Saves first when the cart changed. ----
        async function sendKot() {
            if (!state.held) return;
            if (state.dirty && !(await saveRound(state.held.is_draft))) return;
            try {
                const k = await api('POST', '/held-sales/' + state.held.id + '/kot');
                if (!k.batch) { toast(k.message || 'Nothing new for the kitchen.'); }
                else { toast('KOT #' + k.batch.sequence_no + ' sent · ' + k.batch.lines.length + ' line(s)'); await loadHeld(state.held.id); }
            } catch (e) { toast(e.message); }
        }

        // ---- Review & Pay: cash settlement — a new sale, or a held check (its OWN shift takes the cash). ----
        async function reviewAndPay() {
            if (!requireCart()) return;
            if (!state.terminalId) { toast('Select a terminal first.'); return; }
            if (!state.pendingClientUuid) state.pendingClientUuid = uuid(); // one identity per payment attempt (approval binding + idempotent retry)
            let totals = {};
            try {
                if (state.held) { if (state.dirty && !(await saveRound(state.held.is_draft))) return; totals = state.held; }
                else { const p = await api('POST', '/preview-bill', Object.assign({ order_type: state.orderType, lines: cartLines() }, commercial())); totals = p.totals || {}; }
            } catch (e) { toast(e.message); return; }
            state.lastTotals = totals;
            const grand = Number(totals.grand_total || 0);
            const cash = DATA.paymentMethods[0];
            const needsQuickSale = !state.held && state.orderType === 'quick_sale';
            openModal('<h2>Review &amp; Pay' + (state.held ? ' — ' + esc(state.held.sale_no) : '') + '</h2>' + rowsHtml(totals) + commercialPanelHtml() +
                (cash ? '' : '<div class="err">No cash payment method is configured.</div>') +
                (needsQuickSale ? '<div class="field"><label>Vehicle #</label><input type="text" id="rp-vehicle"></div><div class="field"><label>Waiter</label><select id="rp-waiter">' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' : '') +
                (DATA.canCompleteSale ? '<div class="field"><label>Cash tendered</label><input type="number" id="rp-tendered" value="' + money(grand) + '" min="' + money(grand) + '" step="0.01"></div>' : '') +
                '<div id="rp-err"></div>' +
                // COMPLETE SALE PERMISSION parity (Online f12f1fc): Review & Pay opens for everyone (preview, discount later);
                // taking the payment is gated on tenant.pos.store — the button is absent and the Online hint shows instead.
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button>' +
                (DATA.canCompleteSale ? '<button class="ok" id="rp-complete"' + (cash ? '' : ' disabled') + '>Complete Sale</button>'
                    : '<span class="muted" style="align-self:center">Apply the discount, then <strong>Hold</strong> — a counter will close the bill.</span>') + '</div>');
            const btn = $('rp-complete'); if (btn) btn.addEventListener('click', () => completeSale(grand, cash));
            wireCommercialPanel(reviewAndPay); // Apply & recalculate re-opens the modal on the server's new totals
        }
        async function completeSale(grand, cash) {
            const btn = $('rp-complete'); btn.disabled = true;
            const tendered = Number($('rp-tendered').value || 0);
            if (tendered < grand) { showRpErr('Cash tendered is less than the total.'); btn.disabled = false; return; }
            const payments = [{ payment_method_id: cash.id, amount: grand, tendered_amount: tendered }];
            try {
                let sale;
                if (state.held) {
                    sale = await api('POST', '/held-sales/' + state.held.id + '/settle', { client_uuid: state.pendingClientUuid, payments });
                } else {
                    const payload = Object.assign({ order_type: state.orderType, client_uuid: state.pendingClientUuid, lines: cartLines(), payments }, commercial());
                    if (state.orderType === 'quick_sale') { payload.vehicle_number = ($('rp-vehicle').value || '').trim(); payload.restaurant_waiter_id = Number($('rp-waiter').value || 0) || null; }
                    sale = await api('POST', '/sales', payload);
                }
                closeModal();
                const syncNote = sale.edge_sync_state && sale.edge_sync_state !== 'acknowledged' ? ' · Pending sync' : '';
                state.held = null; state.session = null; state.cart = []; state.dirty = false; state.customer = null; state.pendingClientUuid = null;
                state.commercial = Object.assign({}, state.commercial, { discount_type: 'none', discount_value: 0, promo_code: '', manager_approval_id: null, delivery_address: '', delivery_rider_id: null, delivery_channel_id: null });
                $('customer-name').value = ''; unlockOrderType(); renderCart();
                toast('Sale ' + (sale.sale_no || '#' + sale.sale_id) + ' completed · change ' + money(sale.change_amount || 0) + syncNote);
                autoReceipt(sale.sale_id);
                refreshSync();
            } catch (e) {
                // DISCOUNT parity: the server demands a manager for this discount → the manager approves with their own credential.
                if (/manager approval/i.test(e.message) && !state.commercial.manager_approval_id) {
                    const t = state.lastTotals || {};
                    const approvalId = await askManagerApproval({ sales_order_id: state.held ? state.held.id : 0, branch_id: DATA.branchId, client_uuid: state.held ? '' : state.pendingClientUuid,
                        discount_type: state.commercial.discount_type, discount_value: Number(state.commercial.discount_value || 0), discount_amount: Number(t.manual_discount_amount ?? t.discount_amount ?? 0) });
                    if (approvalId) { state.commercial.manager_approval_id = approvalId; if (state.held) state.dirty = true; reviewAndPay(); return; }
                    reviewAndPay(); return;
                }
                showRpErr(e.message); btn.disabled = false;
            }
        }
        function showRpErr(m) { const e = $('rp-err'); if (e) e.innerHTML = '<div class="err">' + esc(m) + '</div>'; }

        // ---- Printing: receipt after payment (ensure-once), Recent Prints, Reprint, Print Here fallback, Retry. ----
        async function autoReceipt(saleId) {
            try {
                const job = await api('POST', '/sales/' + saleId + '/receipt', {});
                if (job.fallback) { printHere(job); }
                else { toast('Receipt → ' + job.printer_name); }
            } catch (e) { toast('Receipt not queued: ' + e.message); }
        }
        function printHere(job) {
            // Print Here / local fallback: the canonical document opens in a print window; the operator confirms it printed.
            const w = window.open(job.preview_url, '_blank', 'width=420,height=640');
            if (!w) { toast('Allow pop-ups to print here — or open Recent Prints.'); return; }
            toast((job.document_type === 'kot' ? 'KOT' : 'Receipt') + ' opened for printing here.');
        }
        async function recentPrints() {
            try {
                const r = await api('GET', '/print-jobs');
                let html = '<h2>Recent Prints</h2>';
                if (!r.jobs.length) html += '<p class="muted">Nothing printed yet.</p>';
                r.jobs.forEach(j => {
                    const kind = (j.document_type || '').toUpperCase() + (j.event_type && j.event_type !== 'normal' ? ' · ' + j.event_type : '');
                    html += '<div class="list-row" style="cursor:default"><div><strong>' + esc(kind) + '</strong> ' + esc(j.reference_no || '') +
                        '<div class="muted">' + esc(j.printer_name) + ' · ' + esc(j.print_status) + ' · ' + esc(new Date(j.created_at).toLocaleTimeString()) + '</div></div>' +
                        '<div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">' +
                        '<button class="sm" data-open="' + j.id + '">Print here</button>' +
                        (j.fallback && j.print_status !== 'printed' ? '<button class="sm ok" data-printed="' + j.id + '">Printed</button>' : '') +
                        (j.reference_id && (j.document_type === 'receipt' || j.document_type === 'kot') ? '<button class="sm" data-reprint="' + j.reference_id + '" data-kind="' + j.document_type + '">Reprint</button>' : '') +
                        (j.print_status === 'failed' ? '<button class="sm warn" data-retry="' + j.id + '">Retry</button>' : '') +
                        '</div></div>';
                });
                html += '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                const byJob = id => r.jobs.find(x => x.id === Number(id));
                document.querySelectorAll('#modal [data-open]').forEach(b => b.onclick = () => printHere(byJob(b.dataset.open)));
                document.querySelectorAll('#modal [data-printed]').forEach(b => b.onclick = async () => { try { await api('POST', '/print-jobs/' + b.dataset.printed + '/printed', {}); toast('Marked printed.'); recentPrints(); } catch (e) { toast(e.message); } });
                document.querySelectorAll('#modal [data-retry]').forEach(b => b.onclick = async () => { try { await api('POST', '/print-jobs/' + b.dataset.retry + '/retry', {}); toast('Queued for retry.'); recentPrints(); } catch (e) { toast(e.message); } });
                document.querySelectorAll('#modal [data-reprint]').forEach(b => b.onclick = async () => {
                    try {
                        if (b.dataset.kind === 'kot') { const k = await api('POST', '/sales/' + b.dataset.reprint + '/kot-reprint', {}); toast('KOT reprint → ' + (k.jobs[0]?.printer_name || 'queued')); if (k.jobs[0]?.fallback) printHere(k.jobs[0]); }
                        else { const j = await api('POST', '/sales/' + b.dataset.reprint + '/receipt', { reprint: true }); toast('Receipt reprint → ' + j.printer_name); if (j.fallback) printHere(j); }
                        recentPrints();
                    } catch (e) { toast(e.message); }
                });
            } catch (e) { toast(e.message); }
        }

        // ---- Split Bill (Online parity): move quantities onto a new held check on the same table; each pays on its own. ----
        async function splitBill() {
            if (!state.held) return;
            if (state.dirty && !(await saveRound(state.held.is_draft))) return;
            const rows = state.cart.filter(l => l.line_id);
            openModal('<h2>Split Bill — ' + esc(state.held.sale_no) + '</h2><p class="muted">Choose how much of each item moves to the new check. The kitchen is not told again.</p>' +
                rows.map(l => '<div class="line"><div><div class="ln-nm">' + esc(l.name) + (l.deal ? ' <span class="chip hot" style="padding:.05rem .4rem">Deal</span>' : '') + '</div><div class="ln-sub">' + l.quantity + ' on this check @ ' + money(l.price) + '</div></div>' +
                    '<div class="ln-ctl"><input type="number" class="sb-qty" data-key="' + esc(l.key) + '" min="0" max="' + l.quantity + '" step="1" value="0" style="width:5rem"></div></div>').join('') +
                '<div id="sb-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Cancel</button><button class="primary" id="sb-ok">Split</button></div>');
            $('sb-ok').onclick = async () => {
                const lines = [];
                document.querySelectorAll('.sb-qty').forEach(inp => {
                    const q = Number(inp.value || 0); if (q <= 0) return;
                    const l = state.cart.find(x => x.key === inp.dataset.key); if (!l) return;
                    lines.push({ sales_order_line_id: l.line_id, quantity: q });
                    // a deal moves with its components (per-unit quantities × deals moved)
                    (l.component_lines || []).forEach(c => lines.push({ sales_order_line_id: c.id, quantity: +(c.per_unit * q).toFixed(3) }));
                });
                if (!lines.length) { $('sb-err').innerHTML = '<div class="err">Choose at least one quantity to split.</div>'; return; }
                try {
                    const r = await api('POST', '/held-sales/' + state.held.id + '/split', { lines });
                    closeModal();
                    toast('Split into ' + r.child.sale_no + ' — pay each check from Recall or the Table Board.');
                    await loadHeld(r.parent.status === 'held' ? r.parent.id : r.child.id);
                } catch (e) { $('sb-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
        }

        // ---- Cancel the whole open check (reason required; the server applies the branch approval mode). ----
        async function cancelOrder() {
            if (!state.held) return;
            try {
                const r = await api('GET', '/void-reasons');
                if (!r.reasons.length) { toast('No cancellation reasons are configured.'); return; }
                openModal('<h2>Cancel order ' + esc(state.held.sale_no) + '</h2><div class="field"><label>Reason</label><select id="cx-reason">' + r.reasons.map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('') + '</select></div>' +
                    '<div id="cx-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Back</button><button class="danger" id="cx-ok">Cancel order</button></div>');
                $('cx-ok').onclick = async () => {
                    try {
                        await api('POST', '/held-sales/' + state.held.id + '/cancel', { reason_id: Number($('cx-reason').value) });
                        closeModal(); toast('Order cancelled — the table is free.');
                        state.held = null; state.session = null; state.cart = []; state.dirty = false; unlockOrderType(); renderCart();
                    } catch (e) { $('cx-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
            } catch (e) { toast(e.message); }
        }

        // ---- Table Board: open / recall / new check / close (the server decides under a lock). ----
        async function viewTables() {
            try {
                const b = await api('GET', '/restaurant/board');
                let html = '<h2>Table Board</h2>';
                (b.floors || []).forEach(f => {
                    html += '<h3>' + esc(f.name) + '</h3><div class="board">';
                    (f.tables || []).forEach(t => {
                        html += '<div class="tbl ' + esc(t.status) + '" data-table=\'' + esc(JSON.stringify(t)) + '\'><strong>' + esc(t.table_no || t.name) + '</strong><div class="muted">' + esc(t.status.replace('_', ' ')) +
                            (t.session?.waiter_name ? ' · ' + esc(t.session.waiter_name) : '') + (t.reservation?.customer_name ? ' · ' + esc(t.reservation.customer_name) : '') + '</div></div>';
                    });
                    html += '</div>';
                });
                if (!(b.floors || []).length) html += '<p class="muted">No floors configured.</p>';
                html += '<div id="table-actions"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button></div>';
                openModal(html);
                document.querySelectorAll('#modal .tbl').forEach(el => el.addEventListener('click', () => { document.querySelectorAll('#modal .tbl').forEach(x => x.classList.remove('selected')); el.classList.add('selected'); tableActions(JSON.parse(el.dataset.table)); }));
            } catch (e) { toast(e.message); }
        }
        function tableActions(t) {
            const box = $('table-actions'); let html = '<h3>Table ' + esc(t.table_no || t.name) + '</h3>';
            if (t.session) {
                const held = t.session.held_orders || [];
                html += '<p class="muted">' + esc(t.session.status) + (t.session.waiter_name ? ' · ' + esc(t.session.waiter_name) : '') + ' · ' + t.session.guest_count + ' guests</p>';
                held.forEach(h => { html += '<div class="list-row" data-recall="' + h.id + '"><span>Open check ' + esc(h.sale_no) + '</span><span>' + money(h.grand_total) + '</span></div>'; });
                html += '<div class="btn-row left">' +
                    (held.length ? '' : '<button class="primary" id="ta-new">New check</button><button class="danger" id="ta-close">Close table (empty)</button>') + '</div>';
            } else {
                const r = t.reservation;
                if (r) {
                    // ONLINE-POS PARITY: reserved table — who / when / note, Open (customer carries onto the order), Cancel.
                    const when = r.reserved_for ? new Date(r.reserved_for).toLocaleString() : 'no time set';
                    html += '<div class="chip hot" style="display:inline-block;margin-bottom:.4rem">Reserved</div>' +
                        '<p><strong>' + esc(r.customer_name || 'Walk-in') + '</strong>' + (r.customer_phone ? ' · ' + esc(r.customer_phone) : '') + '<br><span class="muted">' + esc(when) + (r.note ? ' · ' + esc(r.note) : '') + '</span></p>';
                }
                html += '<div class="field"><label>Waiter</label><select id="ta-waiter"><option value="">—</option>' + DATA.waiters.map(w => '<option value="' + w.id + '">' + esc(w.name) + '</option>').join('') + '</select></div>' +
                    '<div class="field"><label>Guests</label><input type="number" id="ta-guests" value="' + (t.capacity || 2) + '" min="1" max="100"></div>' +
                    '<div class="btn-row left"><button class="primary" id="ta-open">' + (r ? 'Open reserved table' : 'Open table') + '</button>' +
                    (r ? '<button class="danger" id="ta-unreserve">Cancel reservation</button>' : '<button class="ghost" id="ta-reserve-toggle">Reserve…</button>') + '</div>' +
                    (r ? '' : '<div id="ta-reserve-form" hidden><h3>Reserve table ' + esc(t.table_no || t.name) + '</h3>' +
                        '<div class="field"><label>Customer (blank = walk-in)</label><input type="text" id="rs-name" placeholder="Name"></div>' +
                        '<div class="field"><label>Phone</label><input type="text" id="rs-phone"></div>' +
                        '<div class="field"><label>Reserved for</label><input type="datetime-local" id="rs-when"></div>' +
                        '<div class="field"><label>Note</label><input type="text" id="rs-note" placeholder="e.g. birthday, window seat"></div>' +
                        '<div id="rs-err"></div><div class="btn-row left"><button class="primary" id="ta-reserve">Reserve</button></div></div>');
            }
            box.innerHTML = html;
            document.querySelectorAll('#table-actions [data-recall]').forEach(r => r.addEventListener('click', () => { closeModal(); loadHeld(Number(r.dataset.recall)); }));
            const openBtn = $('ta-open'); if (openBtn) openBtn.onclick = () => openTable(t);
            const newBtn = $('ta-new'); if (newBtn) newBtn.onclick = () => { closeModal(); startCheckOnSession(t); };
            const closeBtn = $('ta-close'); if (closeBtn) closeBtn.onclick = () => closeEmptyTable(t);
            const tog = $('ta-reserve-toggle'); if (tog) tog.onclick = () => { $('ta-reserve-form').hidden = false; tog.hidden = true; };
            const rsv = $('ta-reserve'); if (rsv) rsv.onclick = () => reserveTable(t);
            const unr = $('ta-unreserve'); if (unr) unr.onclick = () => cancelReservation(t);
        }
        // ---- Reservations: Edge-owned authority (survives config refresh + recovery; fenced on Cloud during Local Mode). ----
        async function reserveTable(t) {
            try {
                const payload = { customer_name: $('rs-name').value.trim() || null, customer_phone: $('rs-phone').value.trim() || null, note: $('rs-note').value.trim() || null };
                const when = $('rs-when').value; if (when) payload.reserved_for = new Date(when).toISOString();
                await api('POST', '/restaurant/tables/' + t.id + '/reserve', payload);
                toast('Table ' + (t.table_no || t.name) + ' reserved' + (payload.customer_name ? ' for ' + payload.customer_name : '') + '.');
                viewTables();
            } catch (e) { const el = $('rs-err'); if (el) el.innerHTML = '<div class="err">' + esc(e.message) + '</div>'; else toast(e.message); }
        }
        async function cancelReservation(t) {
            try { await api('POST', '/restaurant/tables/' + t.id + '/unreserve', {}); toast('Reservation cancelled.'); viewTables(); }
            catch (e) { toast(e.message); }
        }
        async function openTable(t) {
            try {
                const waiter = Number($('ta-waiter').value || 0) || null, guests = Number($('ta-guests').value || 1);
                const s = await api('POST', '/restaurant/tables/' + t.id + '/open', { restaurant_waiter_id: waiter, guest_count: guests });
                closeModal();
                state.held = null; state.cart = []; state.dirty = false;
                state.session = { id: s.session_id, table_id: t.id, table_no: t.table_no || t.name, waiter_name: waiter ? (DATA.waiters.find(w => w.id === waiter) || {}).name : null };
                lockOrderType('dine_in'); renderCart();
                toast('Table ' + (t.table_no || t.name) + ' opened' + (t.reservation?.customer_name ? ' for ' + t.reservation.customer_name : '') + ' — add items, then Hold + KOT.');
                if (t.reservation?.customer_name) $('customer-name').value = t.reservation.customer_name;
            } catch (e) { toast(e.message); }
        }
        function startCheckOnSession(t) {
            state.held = null; state.cart = []; state.dirty = false;
            state.session = { id: t.session.id, table_id: t.id, table_no: t.table_no || t.name, waiter_name: t.session.waiter_name };
            lockOrderType('dine_in'); renderCart();
        }
        async function closeEmptyTable(t) {
            try {
                await api('POST', '/restaurant/table-sessions/' + t.session.id + '/close', { status: 'closed' });
                toast('Table ' + (t.table_no || t.name) + ' closed and freed.');
                if (state.session && state.session.id === t.session.id) { state.session = null; unlockOrderType(); renderCart(); }
                viewTables();
            } catch (e) { toast(e.message); }
        }

        // ---- Quick Report: the canonical report authority (view / print here / network); email is Internet-required. ----
        async function quickReport() {
            let o;
            try { o = await api('GET', '/quick-report/options'); }
            catch (e) { toast(e.message.includes('Permission') ? 'Quick Report is not enabled for your account.' : e.message); return; }
            const secLabel = { overview: 'Overview', categories: 'Categories', items: 'Items', category_items: 'Items by Category', deals: 'Deals', waiters: 'Waiters', order_types: 'Order Types', order_type_combos: 'Order Type × Combos', cancellations: 'Cancellations', cash_bank: 'Cash / Bank' };
            openModal('<h2>Quick Report</h2>' +
                '<div class="field"><label>Business date</label><input type="text" id="qr-date" value="' + esc(o.date) + '" placeholder="YYYY-MM-DD"></div>' +
                '<div class="field"><label>Sections</label><div style="display:flex;flex-wrap:wrap;gap:.4rem">' + o.sections.map(s => '<label class="chip"><input type="checkbox" class="qr-sec" value="' + s + '" checked> ' + esc(secLabel[s] || s) + '</label>').join('') + '</div></div>' +
                '<div class="field"><label>Categories (blank = all)</label><select id="qr-cats" multiple size="4">' + o.categories.map(c => '<option value="' + c.id + '">' + esc((c.parent_id ? '— ' : '') + c.name) + '</option>').join('') + '</select></div>' +
                '<div class="field"><label>Paper</label><select id="qr-paper"><option value="80mm">80mm</option><option value="58mm">58mm</option></select></div>' +
                '<div class="field"><label>Network printer</label><select id="qr-printer"><option value="">—</option>' + o.printers.map(p => '<option value="' + p.id + '">' + esc(p.name) + '</option>').join('') + '</select></div>' +
                '<p class="muted" id="qr-email-note">Email: ' + esc(o.email.reason) + '</p><div id="qr-err"></div>' +
                '<div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                '<button class="ghost" id="qr-email" title="' + esc(o.email.reason) + '">Email (Internet required)</button>' +
                '<button class="ghost" id="qr-network">Send to network</button>' +
                '<button class="primary" id="qr-view">View / Print here</button></div>');
            const params = () => {
                const secs = Array.from(document.querySelectorAll('.qr-sec:checked')).map(x => x.value);
                const cats = Array.from($('qr-cats').selectedOptions).map(x => x.value);
                const p = new URLSearchParams(); p.set('date', $('qr-date').value.trim()); p.set('paper', $('qr-paper').value);
                secs.forEach(s => p.append('sections[]', s)); cats.forEach(c => p.append('category_ids[]', c));
                return p;
            };
            $('qr-view').onclick = () => { const w = window.open(BASE + '/quick-report/view?' + params().toString(), '_blank', 'width=460,height=760'); if (!w) toast('Allow pop-ups to view the report.'); };
            $('qr-network').onclick = async () => {
                const printer = Number($('qr-printer').value || 0); if (!printer) { $('qr-err').innerHTML = '<div class="err">Choose a network printer.</div>'; return; }
                try {
                    const p = params(); const body = { printer_id: printer, date: p.get('date'), sections: p.getAll('sections[]'), category_ids: p.getAll('category_ids[]') };
                    const r = await api('POST', '/quick-report/network', body); toast('Report → ' + r.printer);
                } catch (e) { $('qr-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
            $('qr-email').onclick = async () => {
                try { await api('POST', '/quick-report/email', {}); }
                catch (e) { $('qr-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
            };
        }

        // ---- Shift: the Online shift screen's truth — operating business date, tender breakup, blind count, zero drawer, terminal lock. ----
        async function shiftAction() {
            try {
                const s = await api('GET', '/shift/summary');
                const sh = s.shift, b = s.breakup;
                const amt = v => v === null || v === undefined ? '*****' : money(v);
                const row = (k, v) => '<div class="row"><span>' + k + '</span><span>' + v + '</span></div>';
                let html = '<h2>Shift</h2><p class="muted">Operating business date <strong>' + esc(s.operating_business_date) + '</strong>' + (s.operating_business_date !== s.current_business_date ? ' (clock says ' + esc(s.current_business_date) + ')' : '') + '</p>';
                if (sh) {
                    html += '<p class="muted">Open since ' + esc(new Date(sh.opened_at).toLocaleString()) + ' · business date ' + esc(sh.business_date) + '</p>' +
                        '<div class="totals">' + row('Opening cash', amt(b.opening_cash)) + row('Total sales', amt(b.total_sales)) +
                        row('Cash', amt(b.cash)) + row('Card', amt(b.card)) + row('Bank', amt(b.bank)) + (Number(b.cheque) ? row('Cheque', amt(b.cheque)) : '') +
                        row('Cancelled bills', b.cancelled_bills + (b.cancelled_amount === null ? '' : ' · ' + money(b.cancelled_amount))) +
                        row('Voided lines', b.voided_lines + ' (' + b.voided_units + ' units)') +
                        '<div class="row grand"><span>Expected cash</span><span>' + amt(b.expected_cash) + '</span></div></div>' +
                        (s.may_see_amounts ? '' : '<p class="muted">Blind count — amounts are hidden for your role. Count the drawer and enter what you have.</p>') +
                        (sh.zero_drawer ? '<p class="muted">Empty drawer — nothing to count; you can close without a count.</p>' : '') +
                        '<div class="field"><label>Counted cash' + (sh.zero_drawer ? ' (optional)' : '') + '</label><input type="number" id="sh-counted" min="0" step="0.01" placeholder="' + (sh.zero_drawer ? '0' : 'type the counted amount') + '"></div>';
                } else {
                    html += '<p class="muted">No open shift on this terminal.</p><div class="field"><label>Opening cash</label><input type="number" id="sh-opening" value="0" min="0" step="0.01"></div>';
                }
                if (s.branch_open_shifts.length) {
                    html += '<h3>Open shifts on this branch</h3>' + s.branch_open_shifts.map(x => '<div class="list-row" style="cursor:default"><span>' + esc(x.terminal_name || ('Terminal ' + x.terminal_id)) + (x.is_current ? ' (this counter)' : '') + '</span><span class="muted">' + esc(x.business_date) + '</span></div>').join('');
                }
                html += '<div id="sh-err"></div><div class="btn-row"><button class="ghost" onclick="EdgePOS.closeModal()">Close</button>' +
                    (sh ? '<button class="danger" id="sh-close">Close shift</button>' : '<button class="ok" id="sh-open">Open shift</button>') + '</div>';
                openModal(html);
                const o = $('sh-open'), c = $('sh-close');
                if (o) o.onclick = async () => { try { await api('POST', '/shift/open', { opening_cash: Number($('sh-opening').value || 0) }); toast('Shift opened.'); closeModal(); } catch (e) { $('sh-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; } };
                if (c) c.onclick = async () => {
                    try {
                        const v = $('sh-counted').value.trim();
                        const body = v === '' ? {} : { counted_cash: Number(v) }; // ZERO-DRAWER: no count typed → the server decides under the lock
                        const r = await api('POST', '/shift/close', body);
                        toast('Shift closed' + (s.may_see_amounts ? ' · variance ' + money(r.cash_variance) : '.')); closeModal();
                    } catch (e) { $('sh-err').innerHTML = '<div class="err">' + esc(e.message) + '</div>'; }
                };
            } catch (e) { toast(e.message); }
        }

        // ---- Sync state chip: business-friendly only (never leases / hashes / epochs on the till). ----
        async function refreshSync() {
            try {
                const s = await api('GET', '/sync/summary');
                const c = $('sync-chip'); c.hidden = false;
                // Q — the business connection state leads (ONLINE / INTERNET CONNECTION UNSTABLE / INTERNET CONNECTION
                // LOST / PREPARING LOCAL MODE / LOCAL MODE ACTIVE / CONNECTION RESTORED / SYNCHRONIZING / RETURNING TO
                // ONLINE); the sync depth follows. Never a timestamp, uuid, hash or epoch on the till.
                const conn = (s.connection || 'ONLINE');
                const sync = s.state === 'up_to_date' ? 'Synced' : (s.state === 'pending' ? 'Pending sync: ' + s.pending_sales : 'Sync needs attention');
                c.textContent = conn === 'ONLINE' ? sync : (conn + ' · ' + sync);
                c.dataset.connection = conn;
                c.className = 'chip ' + (conn !== 'ONLINE' ? 'hot' : (s.state === 'up_to_date' ? '' : (s.state === 'pending' ? 'hot' : 'draft')));
                c.title = s.message;
                document.getElementById('offline-banner').hidden = !(conn === 'INTERNET CONNECTION LOST' || conn === 'PREPARING LOCAL MODE' || conn === 'LOCAL MODE ACTIVE');
            } catch (e) { /* the chip is informational; a failed poll never blocks selling */ }
        }

        $('search').addEventListener('input', renderTiles);
        $('customer-name').addEventListener('input', renderChips);
        $('view-tables-btn').addEventListener('click', viewTables);
        $('shift-btn').addEventListener('click', shiftAction);

        renderOrderTypes(); renderTerminals(); renderTabs(); renderTiles(); renderCart();
        refreshSync(); setInterval(refreshSync, 60000);
        window.EdgePOS = { closeModal, state, loadHeld, viewTables };
    })();
    </script>
</body>
</html>
