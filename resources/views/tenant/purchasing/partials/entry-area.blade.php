{{--
    PURCHASING-V2-1 — the entry area for a new PO / GRN (Master switch "New purchase screens").
    The catering event screen's way: one entry area on top (scan or search → the item's own boxes → Enter moves
    on → Ctrl+Enter puts the line in the list), a grid below whose lines open back for editing.
    Every grid line posts what the person typed (boxes per pack, price per pack, disc %, free, batch, MM/YY);
    App\Support\PackLines on the server turns it into today's line. This script only shows the sums.

    Expects: $mode ('grn' | 'po'), $prefillLines (GRN from a PO: product_id, quantity_received, unit_cost,
    purchase_order_line_id, pack_unit_id), $purchaseOrder (nullable).

    PURCHASING-V2-2: the batch box (↓ lists this item's batches in this branch, nearest expiry first; a known
    batch fills and locks its expiry; a new number asks for its expiry), Post saves without leaving the page
    (errors land on their own line), and the lines being typed are kept in the browser until saved.
--}}
@php
    $isGrn = $mode === 'grn';
    $isRet = $mode === 'return';   // PURCHASE-RETURN-V2
    $oldLines = old('lines');
    $oldLines = is_array($oldLines) && isset(reset($oldLines)['packs']) ? array_values($oldLines) : [];
    $poLines = $isRet
        // A return's panel: the receipt's lines that can still go back, each with its batch.
        ? collect($prefillLines ?? [])->map(fn ($l) => [
            'po_line_id' => $l['source_line_id'], 'product_id' => $l['product_id'], 'variant_id' => $l['product_variant_id'] ?? null,
            'due' => (float) $l['due'], 'unit_cost' => (float) $l['unit_cost'], 'pack_unit_id' => $l['pack_unit_id'] ?? null, 'name' => $l['name'],
            'batch_id' => $l['batch_id'] ?? null, 'batch_no' => $l['batch_no'] ?? null, 'expiry' => $l['expiry'] ?? null, 'free' => (bool) ($l['free'] ?? false),
            'sold_out' => (bool) ($l['sold_out'] ?? false),
        ])->values()->all()
        : collect($prefillLines ?? [])->map(fn ($l) => [
            'po_line_id' => $l['purchase_order_line_id'] ?? null, 'product_id' => $l['product_id'], 'variant_id' => $l['product_variant_id'] ?? null,
            'due' => (float) ($l['quantity_received'] ?? 0), 'unit_cost' => (float) ($l['unit_cost'] ?? 0), 'pack_unit_id' => $l['pack_unit_id'] ?? null,
            'name' => \App\Models\Tenant\Product::whereKey($l['product_id'])->value('name'),
        ])->values()->all();
    $reasonWords = ['expired' => 'Expired', 'damaged' => 'Damaged / broken', 'wrong_item' => 'Wrong item', 'over_supply' => 'Too many sent',
        'quality_issue' => 'Quality problem', 'price_dispute' => 'Price problem', 'other' => 'Other'];
@endphp

@push('styles')
<style>
    .ea { --navy:#092C4C; --gold:#B8902E; --goldbg:#FFF8E6; --line:#E3E8EE; --ink2:#4E5B6B; --ok:#1E7A50; --bad:#B42318; }
    .ea .ea-card { border:2px solid var(--gold); border-radius:14px; padding:16px; background:#fff; box-shadow:0 8px 22px rgba(184,144,46,.12); }
    .ea .ea-lbl { font-size:.75rem; font-weight:800; color:var(--ink2); letter-spacing:.02em; text-transform:uppercase; display:block; margin-bottom:3px; height:18px; line-height:18px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .ea .ea-in { height:44px; border:1px solid #CBD3DE; border-radius:10px; padding:0 12px; font-size:1rem; width:100%; background:#fff; }
    .ea .ea-in:focus { outline:3px solid var(--gold); outline-offset:0; border-color:var(--gold); }
    .ea .ea-num { text-align:right; font-variant-numeric:tabular-nums; }
    .ea .ea-search { height:52px; font-size:1.1rem; }
    .ea .ea-res { position:absolute; left:0; right:0; top:100%; z-index:30; background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 12px 28px rgba(9,44,76,.16); max-height:320px; overflow:auto; }
    .ea .ea-res button { display:flex; justify-content:space-between; gap:12px; width:100%; padding:10px 12px; border:0; border-bottom:1px solid #EEF2F6; background:#fff; text-align:left; }
    .ea .ea-res button.on, .ea .ea-res button:hover { background:var(--goldbg); }
    .ea .ea-item { display:flex; gap:18px; flex-wrap:wrap; align-items:center; background:#F8FAFC; border:1px solid var(--line); border-radius:12px; padding:10px 14px; }
    .ea .ea-fields { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start; }
    .ea .ea-f { display:flex; flex-direction:column; position:relative; }
    .ea .ea-pg { display:flex; }
    .ea .ea-pg .ea-in { border-top-right-radius:0; border-bottom-right-radius:0; }
    .ea .ea-pg .ea-buy, .ea .ea-pg .ea-unit { flex:0 0 auto; width:auto; height:44px; border:1px solid #CBD3DE; border-left:0; border-radius:0 10px 10px 0; background:#F4F6FA; padding:0 8px; display:flex; align-items:center; font-weight:700; color:var(--navy); }
    .ea .ea-pv { position:fixed; inset:0; z-index:1060; background:rgba(9,20,40,.45); display:flex; align-items:flex-start; justify-content:center; padding:5vh 16px; overflow:auto; }
    .ea .ea-pv-box { background:#fff; border-radius:16px; width:100%; max-width:1000px; padding:20px 22px; box-shadow:0 24px 60px rgba(9,44,76,.3); }
    .ea .ea-pv-who { display:flex; flex-wrap:wrap; gap:6px 22px; background:#F8FAFC; border:1px solid var(--line); border-radius:12px; padding:10px 14px; margin-bottom:12px; }
    .ea .ea-pv-who b { color:var(--navy); }
    .ea .btn .ea-kbd { background:transparent; color:inherit; border-color:currentColor; opacity:.85; }
    .ea .ea-kbd { display:inline-block; border:1px solid #CBD3DE; border-bottom-width:2px; border-radius:6px; padding:0 6px; font-size:.75rem; font-weight:800; background:#fff; }
    .ea .ea-warn { border-radius:10px; padding:8px 12px; font-weight:700; }
    .ea .ea-tbl td, .ea .ea-tbl th { vertical-align:middle; }
    .ea .ea-tbl tr.editing td { background:var(--goldbg); }
    .ea .ea-po { display:flex; gap:10px; align-items:center; padding:10px 0; border-bottom:1px solid #EEF2F6; }
    .ea .ea-bl { position:absolute; left:0; top:100%; z-index:31; min-width:470px; background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 12px 28px rgba(9,44,76,.16); max-height:280px; overflow:auto; }
    .ea .ea-bl button { display:grid; grid-template-columns:1.2fr .9fr .9fr 1.4fr; gap:8px; width:100%; padding:9px 12px; border:0; border-bottom:1px solid #EEF2F6; background:#fff; text-align:left; font-size:.92rem; }
    .ea .ea-bl button.on, .ea .ea-bl button:hover { background:var(--goldbg); }
    .ea .ea-bl button > * { white-space:nowrap; }
    .ea .ea-tag { font-size:.78rem; color:var(--ink2); margin-top:3px; min-height:1em; }
    .ea .ea-in[readonly] { background:#F1F4F8; color:#2A3A4E; }
    .ea .ea-tbl tr.err td { background:#FDECEC; }
    .ea .ea-tbl tr.err-msg td { background:#FDECEC; color:var(--bad); font-weight:700; padding-top:0; border-top:0; }
    .ea .ea-sign { color:#8A5A00; font-weight:700; }
    .ea .ea-sign.ok { color:var(--ok); }
    .ea .ea-tot { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
    .ea .ea-tot table { min-width:340px; font-variant-numeric:tabular-nums; }
    .ea .ea-tot td { padding:3px 0 3px 18px; }
    .ea .ea-tot td:last-child { text-align:right; font-weight:700; }
    .ea .ea-tot tr.grand td { border-top:2px solid var(--navy); padding-top:6px; font-size:1.25rem; font-weight:800; color:var(--navy); }
    .ea [hidden] { display:none !important; }
</style>
@endpush

<div class="ea" id="ea" data-mode="{{ $mode }}">
    <input type="hidden" name="_entry_v2" value="1">

    @if($isGrn && count($poLines))
        <div class="card mb-3"><div class="card-body py-2">
            <b>From the order {{ $purchaseOrder?->po_no }}</b> <span class="text-muted small">— press Receive: the line comes up with what is still due.</span>
            <div id="ea-po"></div>
        </div></div>
    @endif
    @if($isRet && count($poLines))
        <div class="card mb-3"><div class="card-body py-2">
            <b>From the receipt {{ $goodsReceipt?->grn_no }}</b> <span class="text-muted small">— press Return: the line comes up with what can still go back, from its batch.</span>
            <div id="ea-po"></div>
        </div></div>
    @endif
    @if($isRet)
        {{-- The expiry sweep (owner: "expired maal wapas"): this supplier's batches here that have expired, or will by a month. --}}
        <div class="card mb-3"><div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div><b>This supplier's expired stock</b> <span class="text-muted small d-block">Batches that came from them, still in stock, expired or expiring by:</span></div>
                <div><label class="ea-lbl" for="ea-sw-month">Month</label><input type="month" id="ea-sw-month" class="form-control form-control-sm" value="{{ now()->addMonths(2)->format('Y-m') }}"></div>
                <button type="button" class="btn btn-sm btn-outline-dark" id="ea-sw-go">Show</button>
            </div>
            <div id="ea-sw" class="mt-2" hidden></div>
        </div></div>
    @endif

    <div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center" id="ea-draft" role="status" hidden></div>
    <div class="alert alert-success" id="ea-done" role="status" tabindex="-1" hidden></div>
    <div class="ea-card mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <b style="color:var(--navy);font-size:1.05rem" id="ea-title">{{ $isRet ? 'Return an item' : ($isGrn ? 'Receive an item' : 'Add an item') }}</b>
            <span class="small text-muted"><span class="ea-kbd">Enter</span> next box · <span class="ea-kbd">Ctrl</span>+<span class="ea-kbd">Enter</span> put in the list · <span class="ea-kbd">Esc</span> clear · <span class="ea-kbd">Ctrl</span>+<span class="ea-kbd">S</span> check &amp; {{ $isRet ? 'post' : ($isGrn ? 'post' : 'save') }} · <span class="ea-kbd">F2</span> new supplier</span>
        </div>
        <div class="text-warning-emphasis small mb-2" id="ea-gate">Choose the supplier and the branch above first.</div>
        <div class="position-relative">
            <label class="ea-lbl" for="ea-search">Item — scan its barcode{{ $isGrn ? ' (a 2D code fills batch and expiry too)' : ($isRet ? ' (a 2D code picks its batch)' : '') }}, or type a name</label>
            <input id="ea-search" class="ea-in ea-search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ea-res" aria-autocomplete="list" placeholder="Scan, or type e.g. panadol">
            <div class="ea-res" id="ea-res" role="listbox" hidden></div>
        </div>
        <div id="ea-body" class="mt-3" hidden>
            <div class="ea-item mb-3" id="ea-item"></div>
            <div class="ea-fields" id="ea-fields"></div>
            <div class="d-flex gap-3 flex-wrap align-items-center mt-2" style="font-size:1rem">
                <b id="ea-sum-qty"></b><span class="text-muted" id="ea-sum-base"></span><span style="color:var(--ok);font-weight:700" id="ea-sum-cost"></span><b class="ms-auto" id="ea-sum-amt"></b>
            </div>
            <div class="ea-warn mt-2" id="ea-exp" role="alert" hidden></div>
            <div class="text-danger fw-bold mt-2" id="ea-msg" role="alert" hidden></div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-dark" id="ea-add">Put in the list</button>
                <button type="button" class="btn btn-light" id="ea-clear">Clear</button>
            </div>
        </div>
    </div>

    @if($errors->has('lines') || collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'lines.')))
        <div class="alert alert-danger">
            @foreach($errors->getMessages() as $k => $ms) @if(str_starts_with($k, 'lines')) @foreach($ms as $m)<div>{{ $m }}</div>@endforeach @endif @endforeach
        </div>
    @endif

    <div class="alert alert-danger" id="ea-saveerr" role="alert" hidden></div>

    <div class="table-responsive">
        <table class="table ea-tbl mb-1">
            <thead class="table-light"><tr><th>#</th><th>Item</th>@if($isGrn || $isRet)<th>Batch</th><th>Expiry</th>@endif<th>Quantity</th>@if($isGrn)<th>Free</th>@endif<th class="text-end">Price</th>@if($isRet)<th>Reason</th>@else<th class="text-end">Disc</th>@endif<th class="text-end">Amount</th><th></th></tr></thead>
            <tbody id="ea-grid"><tr id="ea-empty"><td colspan="{{ $isGrn ? 10 : ($isRet ? 9 : 7) }}" class="text-center text-muted py-4">Nothing yet.</td></tr></tbody>
        </table>
    </div>
    <div class="ea-tot">
        <span class="text-muted" id="ea-count"></span>
        <table aria-label="Totals">
            <tr id="ea-t-gross-r" hidden><td>Goods before discount</td><td id="ea-t-gross"></td></tr>
            <tr id="ea-t-disc-r" hidden><td>Discount</td><td id="ea-t-disc"></td></tr>
            <tr><td>{{ $isRet ? 'Goods going back' : 'Goods' }}</td><td id="ea-t-sub">Rs 0</td></tr>
            @if($isGrn)<tr id="ea-t-chg-r" hidden><td>Extra charges (cartage, labour…)</td><td id="ea-t-chg"></td></tr>@endif
            <tr class="grand"><td>{{ $isRet ? 'Supplier owes you less by' : ($isGrn ? 'Total of this GRN' : 'Order total') }}</td><td id="ea-total">Rs 0</td></tr>
        </table>
    </div>
    <div id="ea-hidden"></div>

    {{-- Owner, 19 Oct: "draft post kare to preview aaye". Everything once more, before anything is saved. --}}
    <div class="ea-pv" id="ea-pv" hidden>
        <div class="ea-pv-box" role="dialog" aria-modal="true" aria-labelledby="ea-pv-title" tabindex="-1">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <b id="ea-pv-title" style="font-size:1.15rem;color:var(--navy)"></b>
                <span class="small text-muted"><span class="ea-kbd">Enter</span> {{ $isGrn || $isRet ? 'post' : 'save' }} · <span class="ea-kbd">Esc</span> back to edit</span>
            </div>
            <div id="ea-pv-body"></div>
            <div class="d-flex gap-2 flex-wrap mt-3" id="ea-pv-btns"></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var root = document.getElementById('ea');
    if (!root) return;
    var IS_GRN = root.dataset.mode === 'grn';
    var LOOKUP = @json(url('/ajax/products'));
    var BATCH_URL = @json(url('/ajax/batches'));
    var NEW_URL = @json(url($isRet ? '/purchase-returns/create' : ($isGrn ? '/goods-receipts/create' : '/purchase-orders/create')));
    var IS_RET = root.dataset.mode === 'return', BATCHY = IS_GRN || IS_RET;
    var BATCHES_PAGE = @json(url('/inventory/batches'));
    var PRODUCT_EDIT = @json(url('/products/__ID__/edit'));
    var REASONS = @json($reasonWords);
    var PO_ID = @json($purchaseOrder?->id);
    var PO_LINES = @json($poLines);
    var OLD = @json($oldLines);
    var form = root.closest('form');
    var $ = function (id) { return document.getElementById(id); };
    var search = $('ea-search'), res = $('ea-res'), body = $('ea-body');
    var S = null, LINES = [], editAt = null, hi = 0, results = [], fieldOrder = [];
    var BATCHES = {}, bl = { hits: [], hi: 0, moved: false }, rowErr = {}, saved = false, draftReady = false;

    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    // Whole rupees stay whole ("Rs 8,920"); paisa always shows two places ("Rs 4,593.80", never "4,593.8").
    function money(n) { var r = Math.round(n * 100) / 100; return 'Rs ' + r.toLocaleString('en-PK', { minimumFractionDigits: r % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
    function num(n) { return (Math.round(n * 1000) / 1000).toLocaleString('en-PK', { maximumFractionDigits: 3 }); }
    function words(item, q) {
        // Whole packs first ("2 Sack", "3 Box 1 Strip"), then what is left in the item's own unit — decimals for kg / litre.
        var left = Math.abs(q), out = [];
        item.packs.forEach(function (p) { if (p.factor <= 1) return; var k = Math.floor(left / p.factor + 1e-9); if (k > 0) { out.push(k + ' ' + p.name); left -= k * p.factor; } });
        if (left > 0.0005 || !out.length) out.push((item.allow_decimal ? num(left) : Math.round(left)) + ' ' + item.unit_name);
        return (q < 0 ? '−' : '') + out.join(' ');
    }
    function packOf(item, key) { return item.packs.filter(function (p) { return p.key === String(key); })[0]; }
    function buyPack(item, key) { return packOf(item, key) || item.packs.filter(function (p) { return p.can_buy; })[0] || item.packs[0]; }
    function baseOf(line) { return line.item.packs.reduce(function (t, p) { return t + (parseFloat(line.qty[p.key]) || 0) * p.factor; }, 0); }
    function amountOf(line) { var bp = buyPack(line.item, line.buyKey); return baseOf(line) / bp.factor * (parseFloat(line.price) || 0) * (1 - (parseFloat(line.disc) || 0) / 100); }
    // "0928", "09-28", "9/28", "09/2028" → "09/28"; anything else comes back as typed.
    function normExpiry(v) { var t = String(v || '').trim(), m = /^(\d{1,2})[\/\-.\s]?(\d{2}|\d{4})$/.exec(t); if (!m || +m[1] < 1 || +m[1] > 12) return t; return ('0' + +m[1]).slice(-2) + '/' + m[2].slice(-2); }
    function monthsLeft(exp) { var m = /^(\d{2})\/(\d{2})$/.exec(normExpiry(exp)); if (!m) return null; var now = new Date(); return (2000 + +m[2]) * 12 + (+m[1]) - (now.getFullYear() * 12 + now.getMonth() + 1); }
    function headerReady() { var s = $('supplier_id'), b = $('branch_id'); return !!(s && s.value && b && b.value); }
    function gate() { var ok = headerReady(); $('ea-gate').hidden = ok; search.disabled = !ok; }

    function fetchItems(params) {
        var u = new URL(LOOKUP, location.href);
        Object.keys(params).forEach(function (k) { u.searchParams.set(k, params[k]); });
        u.searchParams.set('context', 'purchase'); u.searchParams.set('rich', '1'); u.searchParams.set('packs', '1');
        u.searchParams.set('branch_id', ($('branch_id') || {}).value || ''); u.searchParams.set('supplier_id', ($('supplier_id') || {}).value || '');
        return fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) { return d.results || []; });
    }

    // ── search / scan ─────────────────────────────────────────────────────────────────────────────
    var timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        var q = search.value.trim();
        if (!q) { closeRes(); return; }
        timer = setTimeout(function () { fetchItems({ q: q }).then(function (r) { results = r; hi = 0; drawRes(); }); }, 180);
    });
    function closeRes() { res.hidden = true; search.setAttribute('aria-expanded', 'false'); }
    function drawRes() {
        if (!results.length) { res.innerHTML = '<div class="p-3 text-muted">No item matches. Add it on the product screen first.</div>'; res.hidden = false; return; }
        res.innerHTML = results.map(function (r, i) {
            return '<button type="button" role="option" id="ea-opt-' + i + '" class="' + (i === hi ? 'on' : '') + '" data-i="' + i + '"><span><b>' + esc(r.name) + '</b></span><span class="text-muted small">In stock: ' + esc(r.current_stock == null ? '—' : words(r, r.current_stock)) + '</span></button>';
        }).join('');
        res.hidden = false; search.setAttribute('aria-expanded', 'true'); search.setAttribute('aria-activedescendant', 'ea-opt-' + hi);
    }
    res.addEventListener('click', function (e) { var b = e.target.closest('[data-i]'); if (b) load(results[+b.dataset.i], {}); });

    // GS1 / DRAP 2D: (01) GTIN, (17) expiry YYMMDD, (10) batch, (21) serial — group separator or fixed order.
    function parseGs1(raw) {
        var s = raw.replace(/^\]d2/, '').replace(/[()]/g, '');
        if (!/^01\d{14}/.test(s)) return null;
        var out = { gtin: s.substr(2, 14) }, rest = s.substr(16), gs = String.fromCharCode(29);
        while (rest.length) {
            var ai = rest.substr(0, 2);
            if (ai === '17') { var d = rest.substr(2, 6); out.expiry = d.substr(2, 2) + '/' + d.substr(0, 2); rest = rest.substr(8); }
            else if (ai === '10' || ai === '21') { var end = rest.indexOf(gs, 2); var v = end < 0 ? rest.substr(2) : rest.substr(2, end - 2); if (ai === '10') out.batch = v; rest = end < 0 ? '' : rest.substr(end + 1); }
            else break;
        }
        return out;
    }
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && results.length) { e.preventDefault(); hi = Math.min(hi + 1, results.length - 1); drawRes(); return; }
        if (e.key === 'ArrowUp' && results.length) { e.preventDefault(); hi = Math.max(hi - 1, 0); drawRes(); return; }
        if (e.key === 'Escape') { e.preventDefault(); closeRes(); clearEntry(); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var q = search.value.trim(), g = IS_GRN ? parseGs1(q) : null;
        if (g) {
            var code = g.gtin.replace(/^0/, '');
            fetchItems({ q: code }).then(function (r) {
                if (!r.length) { msg('No item has the barcode ' + code + '. Add it to the product first.'); return; }
                load(r[0], { batch: g.batch || '', expiry: g.expiry || '', note: '2D code read: batch ' + (g.batch || '—') + ', expiry ' + (g.expiry || '—') + ' — only the quantity is left.' });
            });
            return;
        }
        if (!q) return;
        clearTimeout(timer);
        fetchItems({ q: q }).then(function (r) {
            if (!r.length) { results = []; drawRes(); return; }
            // A scanned box: its own barcode picks the item AND the pack (1 of it).
            if (r[0].scanned_pack) { var qty = {}; qty[r[0].scanned_pack] = '1'; load(r[0], { qty: qty }); return; }
            load(r[hi] || r[0], {});
        });
    });

    // ── the entry area for one item ──────────────────────────────────────────────────────────────
    function load(item, o) {
        closeRes();
        var bp = item.packs.filter(function (p) { return p.can_buy; })[0] || item.packs[0];
        if (o.buyKey) bp = packOf(item, o.buyKey) || bp;
        var perUnit = item.last_cost ? item.last_cost.unit_cost : (item.purchase_price || 0);
        S = { item: item, qty: o.qty || {}, batch: o.batch || '', expiry: o.expiry || '', free: o.free || '', disc: o.disc || '',
              buyKey: bp.key, price: o.price != null ? String(o.price) : (perUnit ? String(Math.round(perUnit * bp.factor * 100) / 100) : ''),
              poLineId: o.poLineId || null, variantId: o.variantId || item.variant_id || null,
              batchId: o.batchId || null, batchStock: null, reason: o.reason || '', priceFrom: o.priceFrom || '', note: o.note || '' };
        S.priceKey = S.buyKey;   // the pack the price box is in — switching "Price per" converts from it
        S.batchLocked = false;
        search.value = item.name;
        drawEntry();
        // A batch already known here keeps its one expiry (a 2D code or an edited line included).
        if (IS_RET && needsBatch()) loadBatches(function () {
            // A return line keeps its batch (from the receipt, or picked); the list tells how much is left in it.
            var k = S && S.batch ? knownBatch(S.batch) : null;
            if (k) { S.batchId = k.id; S.batchStock = k.stock; S.expiry = k.expiry || ''; batchTag(); }
            else if (S && S.batchId) { S.batchStock = 0; var t = $('ea-btag'); if (t) t.textContent = 'Nothing is left in batch ' + S.batch + ' — sold or already returned.'; }
        });
        if (IS_GRN && needsBatch()) loadBatches(function () {
            var k = S && S.batch ? knownBatch(S.batch) : null;
            if (k && k.expiry && !S.batchLocked) {
                if (S.expiry && normExpiry(S.expiry) !== k.expiry) { $('ea-exp').hidden = false; $('ea-exp').style.cssText = 'background:#FFF4D6;color:#6B5310'; $('ea-exp').textContent = 'Batch ' + k.batch_no + ' is already known with expiry ' + k.expiry + ' — that expiry is used.'; }
                readEntry(); S.expiry = k.expiry; S.batchLocked = true; redraw();
            }
        });
        $('ea-msg').hidden = true;
        if (o.note) { $('ea-exp').hidden = false; $('ea-exp').style.cssText = 'background:#E7F5EE;color:#155F3E'; $('ea-exp').textContent = o.note; }
        focusField(o.focus || (BATCHY && needsBatch() && !S.batch ? 'batch' : 'pack:' + item.packs[0].key));
    }
    function needsBatch() { return S && S.item.is_stock_tracked && (S.item.requires_batch || S.item.has_expiry); }
    function drawEntry() {
        var it = S.item, bp = buyPack(it, S.buyKey);
        var packWords = it.packs.length > 1 ? it.packs.slice(0, -1).map(function (p) { return '1 ' + esc(p.name) + ' = ' + num(p.factor) + ' ' + esc(it.unit_name); }).join(' · ') : 'Bought in ' + esc(it.unit_name);
        $('ea-item').innerHTML = '<div style="min-width:220px"><b style="font-size:1.05rem">' + esc(it.name) + '</b><div class="text-muted small">' + packWords + '</div></div>'
            + '<div><span class="ea-lbl">Counted in</span><b>' + esc(it.unit_name) + '</b>' + (it.unit_set === false ? ' <a class="small ms-1" href="' + esc(PRODUCT_EDIT.replace('__ID__', it.id)) + '" target="_blank" rel="noopener" title="No unit is set on this item">set its unit ↗</a>' : '') + '</div>'
            + '<div><span class="ea-lbl">In stock now</span><b>' + esc(it.current_stock == null ? '—' : words(it, it.current_stock)) + '</b></div>'
            + (it.last_cost ? '<div><span class="ea-lbl">Last bought from this supplier</span><b>' + money(it.last_cost.unit_cost * bp.factor) + ' / ' + esc(bp.name) + '</b> <span class="text-muted small">' + esc(it.last_cost.date) + '</span></div>' : '')
            + (BATCHY ? '<div><span class="ea-lbl">Batch &amp; expiry</span><b>' + (needsBatch() ? (IS_RET ? 'Pick the batch it goes back from' : (it.requires_batch ? 'Needed' : (it.has_expiry ? 'Expiry needed, batch optional' : 'Batch optional'))) : 'Not kept for this item') + '</b>'
                + (needsBatch() ? ' <a class="small ms-1" href="' + esc(BATCHES_PAGE + '?q=' + encodeURIComponent(it.name)) + '" target="_blank" rel="noopener">All batches ↗</a>' : '') + '</div>' : '');
        var f = [];
        fieldOrder = [];
        if (BATCHY && needsBatch()) {
            f.push(field('batch', IS_RET ? 'Batch (↓ list)' : 'Batch no.', S.batch, IS_RET ? '↓ pick' : (it.requires_batch ? 'e.g. PN2410-07' : 'optional'), 'text', 190));
            if (IS_GRN && it.has_expiry) f.push(field('expiry', 'Expiry MM/YY', S.expiry, '09/28', 'text', 105));
        }
        it.packs.forEach(function (p) {
            f.push(field('pack:' + p.key, p.name + (it.packs.length > 1 && p.factor > 1 ? ' (' + (p.holds || num(p.factor)) + ')' : ''), S.qty[p.key] || '', '0', it.allow_decimal ? 'decimal' : 'numeric', p.holds ? 150 : 110));
        });
        if (IS_GRN) f.push(field('free', 'Free ' + bp.name, S.free, '0', 'numeric', 110));
        var buyables = it.packs.filter(function (p) { return p.can_buy; });
        f.push('<div class="ea-f" style="width:220px"><label class="ea-lbl" for="ea-f-price">Price</label><div class="ea-pg">'
            + '<input id="ea-f-price" class="ea-in ea-num" inputmode="decimal" value="' + esc(S.price) + '" data-k="price" autocomplete="off">'
            + (buyables.length > 1
                ? '<select id="ea-buy" class="ea-buy" aria-label="Price is for one">' + buyables.map(function (p) { return '<option value="' + esc(p.key) + '"' + (p.key === bp.key ? ' selected' : '') + '>/ ' + esc(p.name) + '</option>'; }).join('') + '</select>'
                : '<span class="ea-unit">/ ' + esc(bp.name) + '</span>')
            + '</div><div class="ea-tag"></div></div>');
        fieldOrder.push('price');
        if (IS_RET) {
            f.push(field('note', 'Note (optional)', S.note || '', 'e.g. box torn', 'text', 170));
            f.push('<div class="ea-f" style="width:190px"><label class="ea-lbl" for="ea-f-reason">Why it goes back</label><select id="ea-f-reason" class="ea-in" data-k="reason"><option value="">— pick —</option>'
                + Object.keys(REASONS).map(function (k) { return '<option value="' + esc(k) + '"' + (S.reason === k ? ' selected' : '') + '>' + esc(REASONS[k]) + '</option>'; }).join('') + '</select><div class="ea-tag"></div></div>');
            fieldOrder.push('reason');
        } else {
            f.push(field('disc', 'Disc %', S.disc, '0', 'decimal', 90));
            f.push(field('note', 'Note (optional)', S.note || '', 'e.g. 2 cartons torn', 'text', 190));
        }
        $('ea-fields').innerHTML = f.join('');
        body.hidden = false;
        $('ea-add').textContent = editAt !== null ? 'Update line' : 'Put in the list';
        $('ea-title').textContent = editAt !== null ? 'Changing line ' + (editAt + 1) : (IS_RET ? 'Return an item' : (IS_GRN ? 'Receive an item' : 'Add an item'));
        sums();
        batchTag();   // "Known batch · 5 Box in stock" stays under the box after a pick
    }
    function field(k, label, v, ph, mode, w) {
        fieldOrder.push(k);
        var id = 'ea-f-' + k.replace(':', '-');
        var extra = '', after = '';
        if (k === 'batch') { extra = ' role="combobox" aria-expanded="false" aria-controls="ea-bl" aria-autocomplete="list" aria-describedby="ea-btag"'; after = '<div class="ea-bl" id="ea-bl" role="listbox" aria-label="Batches of this item" hidden></div><div class="ea-tag" id="ea-btag"></div>'; }
        if (k === 'expiry') { if (S && S.batchLocked) extra = ' readonly aria-describedby="ea-etag"'; after = '<div class="ea-tag" id="ea-etag">' + (S && S.batchLocked ? 'From the batch' : '') + '</div>'; }
        if (!after) after = '<div class="ea-tag"></div>';
        return '<div class="ea-f" style="width:' + w + 'px"><label class="ea-lbl" for="' + id + '">' + esc(label) + '</label><input id="' + id + '" class="ea-in' + (mode === 'text' ? '' : ' ea-num') + '" inputmode="' + mode + '" value="' + esc(v) + '" placeholder="' + esc(ph) + '" data-k="' + esc(k) + '" autocomplete="off"' + extra + '>' + after + '</div>';
    }
    // At once, not on a timer: a scanner or a fast hand types the next key straight after Enter.
    function focusField(k) { var el = root.querySelector('[data-k="' + k + '"]'); if (el) { el.focus(); el.select && el.select(); } }
    // Re-draw the boxes and keep the cursor where it was (or on the first quantity if that box is now locked).
    function redraw() {
        var a = document.activeElement, k = a && a.dataset ? a.dataset.k : null;
        drawEntry();
        if (k) { var el = root.querySelector('[data-k="' + k + '"]'); if (el && el.readOnly) focusField('pack:' + S.item.packs[0].key); else if (el) el.focus(); }
    }

    // ── the batch box ─────────────────────────────────────────────────────────────────────────────
    function batchSlot() { return S ? S.item.id + ':' + (($('branch_id') || {}).value || '') : ''; }
    function loadBatches(cb) {
        var slot = batchSlot();
        if (!slot) return;
        if (BATCHES[slot]) { cb(BATCHES[slot]); return; }
        var u = new URL(BATCH_URL, location.href);
        u.searchParams.set('product_id', S.item.id); u.searchParams.set('branch_id', ($('branch_id') || {}).value || '');
        if (IS_RET) { u.searchParams.set('expired', '1'); u.searchParams.set('stocked', '1'); }
        fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { batches: [] }; })
            .then(function (d) { BATCHES[slot] = d.batches || []; if (S && batchSlot() === slot) cb(BATCHES[slot]); }, function () { BATCHES[slot] = []; });
    }
    function knownBatch(no) { var t = String(no || '').trim().toUpperCase(); return t ? (BATCHES[batchSlot()] || []).filter(function (b) { return String(b.batch_no || '').toUpperCase() === t; })[0] : null; }
    function batchInput() { return root.querySelector('[data-k="batch"]'); }
    function batchTag() {
        var tag = $('ea-btag'), inp = batchInput(); if (!tag || !inp) return;
        if (!BATCHES[batchSlot()]) { tag.textContent = ''; return; }   // the list is still on its way
        var v = inp.value.trim(), k = knownBatch(v), any = (BATCHES[batchSlot()] || []).length;
        if (IS_RET) {
            tag.textContent = !v ? (any ? '↓ pick the batch' : 'No batch of this item has stock here') : (k ? words(S.item, k.stock) + ' in it' + (k.expiry ? ' · exp ' + k.expiry + (k.expired ? ' (expired)' : '') : '') : 'No batch with this number has stock here');
            return;
        }
        tag.textContent = !v ? (any ? '↓ pick a batch, or type a new one' : (S.item.requires_batch ? 'Type the batch number on the box' : 'Optional for this item'))
            : (k ? 'Known batch' + (k.stock > 0 ? ' · ' + words(S.item, k.stock) + ' in stock' : '') : (S.item.has_expiry ? 'New batch — write its expiry' : 'New batch'));
    }
    function closeBl() { var box = $('ea-bl'), inp = batchInput(); if (box) box.hidden = true; if (inp) inp.setAttribute('aria-expanded', 'false'); }
    function drawBl() {
        var box = $('ea-bl'), inp = batchInput(); if (!box || !inp || !S) return;
        var t = inp.value.trim().toUpperCase();
        bl.hits = (BATCHES[batchSlot()] || []).filter(function (b) { return !t || String(b.batch_no || '').toUpperCase().indexOf(t) !== -1; }).slice(0, 8);
        batchTag();
        if (!bl.hits.length || document.activeElement !== inp) { closeBl(); return; }
        bl.hi = Math.max(0, Math.min(bl.hi, bl.hits.length - 1));
        box.innerHTML = bl.hits.map(function (b, i) {
            var soon = b.months_left !== null && b.months_left < 6;
            return '<button type="button" role="option" tabindex="-1" id="ea-bo-' + i + '" data-b="' + i + '" class="' + (i === bl.hi ? 'on' : '') + '" aria-selected="' + (i === bl.hi) + '">'
                + '<b>' + esc(b.batch_no) + '</b><span>' + (b.expiry ? 'Exp ' + esc(b.expiry) : 'No expiry') + '</span>'
                + '<span class="' + (soon ? 'fw-bold' : 'text-muted') + '" style="' + (soon ? 'color:#8A5A00' : '') + '">' + (b.months_left === null ? '' : (b.months_left <= 0 ? 'This month' : b.months_left + ' mo left')) + '</span>'
                + '<span class="text-muted">' + esc(words(S.item, b.stock)) + ' in stock</span></button>';
        }).join('');
        box.hidden = false; inp.setAttribute('aria-expanded', 'true'); inp.setAttribute('aria-activedescendant', 'ea-bo-' + bl.hi);
    }
    function pickBatch(b) {
        readEntry();
        S.batch = b.batch_no; S.expiry = b.expiry || ''; S.batchLocked = !!b.expiry;
        if (IS_RET) {
            S.batchId = b.id; S.batchStock = b.stock;
            // No receipt behind the line: the price is what one unit of this batch cost (keeps the books and stock equal).
            if (!S.poLineId && b.unit_cost > 0) { S.price = String(Math.round(b.unit_cost * buyPack(S.item, S.buyKey).factor * 100) / 100); S.priceFrom = 'what this batch cost'; }
            if (!S.reason) S.reason = b.expired ? 'expired' : ((($('reason_code') || {}).value) || '');
        }
        drawEntry(); closeBl();
        focusField(S.batchLocked || IS_RET ? 'pack:' + S.item.packs[0].key : 'expiry');
    }
    function readEntry() {
        root.querySelectorAll('#ea-fields [data-k]').forEach(function (el) {
            var k = el.dataset.k, v = el.value;
            if (k.indexOf('pack:') === 0) S.qty[k.substr(5)] = v; else S[k] = v;
        });
        var b = $('ea-buy'); if (b) S.buyKey = b.value;
    }
    function sums() {
        if (!S) return;
        readEntry();
        var it = S.item, bp = buyPack(it, S.buyKey), base = baseOf(S), free = (parseFloat(S.free) || 0) * bp.factor, amt = amountOf(S);
        $('ea-sum-qty').textContent = words(it, base) + (free > 0 ? ' + ' + S.free + ' ' + bp.name + ' free' : '');
        $('ea-sum-base').textContent = it.packs.length > 1 && base + free > 0 ? '= ' + num(base + free) + ' ' + it.unit_name + (IS_GRN ? ' into stock' : '') : '';
        $('ea-sum-cost').textContent = it.packs.length > 1 && base > 0 ? 'Cost per ' + it.unit_name + ': ' + money(amt / base) + (free > 0 ? ' → ' + money(amt / (base + free)) + ' with the bonus' : '') : '';
        $('ea-sum-amt').textContent = money(amt);
        if (IS_RET && S.priceFrom) $('ea-sum-cost').textContent = 'Price: ' + S.priceFrom + ($('ea-sum-cost').textContent ? ' · ' + $('ea-sum-cost').textContent : '');
        var ml = IS_GRN && needsBatch() ? monthsLeft(S.expiry) : null, w = $('ea-exp');
        if (ml !== null && ml < 0) { w.hidden = false; w.style.cssText = 'background:#FDECEC;color:#8E1C14'; w.textContent = 'Already expired — this batch cannot be received. Send it back to the supplier.'; }
        else if (ml !== null && ml < 6) { w.hidden = false; w.style.cssText = 'background:#FFF4D6;color:#6B5310'; w.textContent = 'Short expiry: only ' + ml + ' month' + (ml === 1 ? '' : 's') + ' left. It will show on the near-expiry list.'; }
        else if (w.textContent.indexOf('2D code') !== 0) w.hidden = true;
    }
    function msg(t) { var m = $('ea-msg'); m.hidden = !t; m.textContent = t || ''; }
    function clearEntry() { S = null; editAt = null; body.hidden = true; search.value = ''; msg(''); $('ea-exp').hidden = true; drawGrid(); search.focus(); }

    $('ea-fields').addEventListener('input', function (e) {
        msg('');
        if (e.target.dataset.k === 'batch') {
            bl.moved = false; bl.hi = 0;
            if (IS_RET && S.batchId && e.target.value.trim().toUpperCase() !== String(S.batch).toUpperCase()) { S.batchId = null; S.batchStock = null; }
            if (S.batchLocked && e.target.value.trim().toUpperCase() !== String(S.batch).toUpperCase()) {
                S.batchLocked = false; S.expiry = ''; var ex = root.querySelector('[data-k="expiry"]'); if (ex) { ex.readOnly = false; ex.value = ''; } if ($('ea-etag')) $('ea-etag').textContent = '';
            }
            drawBl();
        }
        sums();
    });
    $('ea-fields').addEventListener('focusin', function (e) { if (e.target.dataset.k === 'batch') loadBatches(drawBl); });
    $('ea-fields').addEventListener('focusout', function (e) { if (e.target.dataset.k === 'batch') setTimeout(closeBl, 150); });
    $('ea-fields').addEventListener('mousedown', function (e) { var b = e.target.closest('[data-b]'); if (b) { e.preventDefault(); pickBatch(bl.hits[+b.dataset.b]); } });
    $('ea-fields').addEventListener('change', function (e) {
        if (e.target.dataset.k === 'expiry') { e.target.value = normExpiry(e.target.value); sums(); return; }
        if (e.target.dataset.k === 'batch') {
            var kb = knownBatch(e.target.value);
            if (IS_RET) { if (kb && !S.batchId) { var keep = (document.activeElement && document.activeElement.dataset || {}).k; pickBatch(kb); if (keep) focusField(keep); } return; }
            if (kb && kb.expiry && !S.batchLocked) { readEntry(); S.batch = kb.batch_no; S.expiry = kb.expiry; S.batchLocked = true; redraw(); }
            return;
        }
        if (e.target.id !== 'ea-buy') return;
        var was = buyPack(S.item, S.priceKey), now = buyPack(S.item, e.target.value);
        readEntry();
        if (was.factor !== now.factor) {
            var p = parseFloat(String(S.price).replace(/,/g, ''));
            if (p > 0) S.price = String(Math.round(p / was.factor * now.factor * 10000) / 10000);
            var fr = parseFloat(S.free) * was.factor / now.factor;
            if (fr > 0 && Math.abs(fr - Math.round(fr)) < 1e-9) S.free = String(Math.round(fr));
        }
        S.priceKey = now.key;
        drawEntry(); focusField('price');
    });
    root.addEventListener('keydown', function (e) {
        if (!S || !e.target.closest('#ea-fields')) return;
        if (e.target.dataset.k === 'batch') {
            var blOpen = $('ea-bl') && !$('ea-bl').hidden;
            if (e.key === 'ArrowDown') { e.preventDefault(); if (!blOpen) { loadBatches(drawBl); } else { bl.hi = Math.min(bl.hi + 1, bl.hits.length - 1); bl.moved = true; drawBl(); } return; }
            if (e.key === 'ArrowUp' && blOpen) { e.preventDefault(); bl.hi = Math.max(bl.hi - 1, 0); bl.moved = true; drawBl(); return; }
            if (e.key === 'Escape' && blOpen) { e.preventDefault(); closeBl(); return; }
            if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
                var typed = e.target.value.trim(), exact = knownBatch(typed);
                if (exact) { e.preventDefault(); pickBatch(exact); return; }
                if (blOpen && bl.hits[bl.hi] && (bl.moved || !typed)) { e.preventDefault(); pickBatch(bl.hits[bl.hi]); return; }
                if (IS_RET) { e.preventDefault(); msg(typed ? 'No batch with this number has stock here — pick one from the list (↓).' : 'Pick the batch it goes back from (↓).'); return; }
                closeBl();   // a new batch: Enter goes on to its expiry
            }
        }
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); add(); return; }
        if (e.key === 'Escape') { e.preventDefault(); clearEntry(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            var k = e.target.dataset.k, i = fieldOrder.indexOf(k);
            if (k === 'expiry') { e.target.value = normExpiry(e.target.value); sums(); }
            if (k === 'disc' || i === fieldOrder.length - 1) add(); else focusField(fieldOrder[i + 1]);
        }
    });
    $('ea-add').addEventListener('click', add);
    $('ea-clear').addEventListener('click', clearEntry);

    function add() {
        if (!S) return;
        readEntry();
        var it = S.item, base = baseOf(S), bp = buyPack(it, S.buyKey), free = (parseFloat(S.free) || 0);
        // The old screen's rule: a batch only for items that require one, an expiry only for items that have one.
        if (IS_GRN && needsBatch() && it.requires_batch && !S.batch.trim()) { msg('Write the batch number printed on the box.'); focusField('batch'); return; }
        var ml = IS_GRN && it.has_expiry ? monthsLeft(S.expiry) : null;
        if (IS_GRN && it.has_expiry && ml === null) { msg('Write the expiry as month/year, e.g. 09/28.'); focusField('expiry'); return; }
        if (ml !== null && ml < 0) { msg('This batch has already expired — it cannot be received.'); focusField('expiry'); return; }
        if (IS_RET && needsBatch() && !S.batchId) { msg('Pick the batch it goes back from (↓ in the batch box).'); focusField('batch'); return; }
        if (base <= 0 && free <= 0) { msg('How many? Type it in any of the boxes.'); focusField('pack:' + it.packs[0].key); return; }
        if (IS_RET && S.batchId && S.batchStock !== null) {
            var others = LINES.filter(function (l, j) { return j !== editAt && String(l.batchId) === String(S.batchId); }).reduce(function (t, l) { return t + baseOf(l); }, 0);
            if (base + others > S.batchStock + 1e-6) { var leftNow = Math.max(0, S.batchStock - others); msg(leftNow > 0 ? 'Batch ' + S.batch + ' has only ' + words(it, leftNow) + ' left.' : 'Nothing more is left in batch ' + S.batch + (others > 0 ? ' — the list already takes all of it.' : '.')); focusField('pack:' + it.packs[0].key); return; }
        }
        if (IS_RET && !S.reason) { msg('Why is it going back? Pick a reason.'); focusField('reason'); return; }
        if (!it.allow_decimal) {
            var bad = it.packs.filter(function (p) { var v = S.qty[p.key]; return v && !/^\d+$/.test(String(v).trim()); })[0];
            if (bad) { msg(bad.name + ': a whole number, please.'); focusField('pack:' + bad.key); return; }
        }
        var line = { item: it, qty: Object.assign({}, S.qty), batch: S.batch.trim().toUpperCase(), expiry: normExpiry(S.expiry), free: S.free, price: S.price, buyKey: bp.key, disc: S.disc, poLineId: S.poLineId, variantId: S.variantId,
                     batchId: S.batchId, reason: S.reason, priceFrom: S.priceFrom, note: (S.note || '').trim() };
        if (editAt !== null) { LINES[editAt] = line; delete rowErr[editAt]; } else LINES.push(line);
        saved = false; $('ea-done').hidden = true;
        S = null; editAt = null; body.hidden = true; search.value = ''; msg(''); $('ea-exp').hidden = true;
        drawGrid(); drawPo(); search.focus();
    }

    // ── the grid (and the hidden inputs the server reads) ─────────────────────────────────────────
    function drawGrid() {
        var g = $('ea-grid'), h = [], hid = [], total = 0, gross = 0;
        LINES.forEach(function (l, i) {
            var it = l.item, bp = buyPack(it, l.buyKey), base = baseOf(l), amt = amountOf(l); total += amt;
            gross += base / bp.factor * (parseFloat(l.price) || 0);
            h.push('<tr class="' + (editAt === i ? 'editing' : '') + (rowErr[i] ? ' err' : '') + '"><td class="text-muted">' + (i + 1) + '</td><td><b>' + esc(it.name) + '</b>'
                + (it.current_stock != null ? '<div class="small text-muted">In stock now: ' + esc(words(it, it.current_stock)) + '</div>' : '')
                + (l.note ? '<div class="small fst-italic">' + esc(l.note) + '</div>' : '') + '</td>'
                + (BATCHY ? '<td>' + esc(needsBatchOf(it) ? l.batch : '—') + '</td><td>' + esc(needsBatchOf(it) ? (l.expiry || '—') : '—') + '</td>' : '')
                + '<td><b>' + esc(words(it, base)) + '</b>' + (it.packs.length > 1 && words(it, base) !== num(base) + ' ' + it.unit_name ? ' <span class="text-muted small">= ' + num(base) + ' ' + esc(it.unit_name) + '</span>' : '') + '</td>'
                + (IS_GRN ? '<td>' + ((parseFloat(l.free) || 0) > 0 ? '+' + esc(l.free) + ' ' + esc(bp.name) : '—') + '</td>' : '')
                + '<td class="text-end">' + money(parseFloat(l.price) || 0) + ' / ' + esc(bp.name) + '</td>'
                + (IS_RET ? '<td>' + esc(REASONS[l.reason] || '—') + '</td>' : '<td class="text-end">' + (l.disc ? esc(l.disc) + '%' : '—') + '</td>')
                + '<td class="text-end"><b>' + money(amt) + '</b></td>'
                + '<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" data-edit="' + i + '" aria-label="Edit line ' + (i + 1) + '">Edit</button> <button type="button" class="btn btn-sm btn-light text-danger" data-del="' + i + '" aria-label="Remove line ' + (i + 1) + '">Remove</button></td></tr>'
                + (rowErr[i] ? '<tr class="err-msg"><td></td><td colspan="' + (IS_GRN ? 9 : (IS_RET ? 8 : 6)) + '">' + esc(rowErr[i]) + ' — press Edit to fix it.</td></tr>' : ''));
            var n = 'lines[' + i + ']';
            hid.push(hidden(n + '[product_id]', it.id), hidden(n + '[product_variant_id]', l.variantId || ''), hidden(n + (IS_RET ? '[source_line_id]' : '[purchase_order_line_id]'), l.poLineId || ''),
                hidden(n + '[buy_pack]', l.buyKey), hidden(n + '[price]', l.price), hidden(n + '[disc]', l.disc || ''), hidden(n + '[note]', l.note || ''));
            if (IS_RET) hid.push(hidden(n + '[inventory_batch_id]', l.batchId || ''), hidden(n + '[reason_code]', l.reason || ''));
            if (IS_GRN) hid.push(hidden(n + '[batch_no]', l.batch), hidden(n + '[expiry]', l.expiry), hidden(n + '[free]', l.free || ''));
            it.packs.forEach(function (p) { hid.push(hidden(n + '[packs][' + p.key + ']', l.qty[p.key] || '')); });
        });
        g.innerHTML = h.length ? h.join('') : '<tr><td colspan="' + (IS_GRN ? 10 : (IS_RET ? 9 : 7)) + '" class="text-center text-muted py-4">Nothing yet — ' + (IS_RET ? 'press Return on a receipt line, show the expired stock, or pick an item above' : (IS_GRN ? 'press Receive on an order line, or scan a box' : 'pick an item above and press Ctrl + Enter')) + '.</td></tr>';
        $('ea-hidden').innerHTML = hid.join('');
        var disc = Math.max(0, gross - total), charges = IS_GRN ? (parseFloat((($('extra_charges') || {}).value || '0').replace(/,/g, '')) || 0) : 0;
        $('ea-t-gross-r').hidden = !(disc > 0.004); $('ea-t-gross').textContent = money(gross);
        $('ea-t-disc-r').hidden = !(disc > 0.004); $('ea-t-disc').textContent = '− ' + money(disc);
        $('ea-t-sub').textContent = money(total);
        if ($('ea-t-chg-r')) { $('ea-t-chg-r').hidden = !(charges > 0); $('ea-t-chg').textContent = '+ ' + money(charges); }
        $('ea-total').textContent = money(total + charges);
        $('ea-count').innerHTML = saved ? '<span class="ea-sign ok">Saved ✓</span>' : (LINES.length + (LINES.length === 1 ? ' line' : ' lines') + (LINES.length ? ' · <span class="ea-sign">Not saved yet</span>' : ''));
        saveDraft();
    }

    // ── kept in this browser until saved (owner: nothing typed is lost) ──────────────────────────
    var DRAFT_KEY = 'ea-draft:' + location.pathname + (PO_ID ? ':' + PO_ID : '');
    function saveDraft() {
        if (!draftReady) return;
        try {
            if (!LINES.length) { localStorage.removeItem(DRAFT_KEY); return; }
            localStorage.setItem(DRAFT_KEY, JSON.stringify({ at: Date.now(), supplier: ($('supplier_id') || {}).value || '', branch: ($('branch_id') || {}).value || '', lines: LINES }));
        } catch (e) {}
    }
    function offerDraft() {
        var d = null; try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) {}
        if (!d || !d.lines || !d.lines.length) return;
        var mins = Math.max(1, Math.round((Date.now() - (d.at || Date.now())) / 60000)), bar = $('ea-draft'), many = d.lines.length > 1;
        bar.innerHTML = '<span>You were typing <b>' + d.lines.length + ' line' + (many ? 's' : '') + '</b> ' + (mins < 60 ? mins + ' min' : Math.round(mins / 60) + ' h') + ' ago and did not save ' + (many ? 'them' : 'it') + '.</span>'
            + ' <button type="button" class="btn btn-sm btn-dark" id="ea-draft-yes">Bring ' + (many ? 'them' : 'it') + ' back</button> <button type="button" class="btn btn-sm btn-light" id="ea-draft-no">Discard</button>';
        bar.hidden = false;
        $('ea-draft-yes').addEventListener('click', function () {
            if (d.supplier && window.__supplierBox) window.__supplierBox.pick(d.supplier);
            var br = $('branch_id'); if (br && d.branch && !br.value) { br.value = d.branch; br.dispatchEvent(new Event('change', { bubbles: true })); }
            LINES = LINES.concat(d.lines); bar.hidden = true; drawGrid(); drawPo(); search.focus();
        });
        $('ea-draft-no').addEventListener('click', function () { try { localStorage.removeItem(DRAFT_KEY); } catch (e) {} bar.hidden = true; search.focus(); });
    }
    function needsBatchOf(it) { return it.is_stock_tracked && (it.requires_batch || it.has_expiry); }
    function hidden(n, v) { return '<input type="hidden" name="' + esc(n) + '" value="' + esc(v) + '">'; }
    $('ea-grid').addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
        if (ed) { var i = +ed.dataset.edit, l = LINES[i]; editAt = i; load(l.item, { qty: Object.assign({}, l.qty), batch: l.batch, expiry: l.expiry, free: l.free, price: l.price, disc: l.disc, buyKey: l.buyKey, poLineId: l.poLineId, variantId: l.variantId, batchId: l.batchId, reason: l.reason, priceFrom: l.priceFrom, note: l.note }); drawGrid(); }
        if (del) { LINES.splice(+del.dataset.del, 1); editAt = null; rowErr = {}; drawGrid(); drawPo(); }
    });

    // ── GRN from an order ─────────────────────────────────────────────────────────────────────────
    var poItems = {};
    function drawPo() {
        var box = $('ea-po'); if (!box) return;
        box.innerHTML = PO_LINES.map(function (o, i) {
            var it = poItems[o.product_id], got = LINES.filter(function (l) { return String(l.poLineId) === String(o.po_line_id); }).reduce(function (t, l) { return t + baseOf(l); }, 0);
            var left = Math.max(0, o.due - got);
            var dueWords = it ? words(it, left) : num(left);
            if (IS_RET) {
                return '<div class="ea-po"><div class="flex-grow-1"><b>' + esc(o.name) + '</b>' + (o.free ? ' <span class="badge bg-light text-dark border">free (bonus)</span>' : '')
                    + '<div class="small text-muted">Can still go back: ' + esc(dueWords) + (o.batch_no ? ' · B:' + esc(o.batch_no) + (o.expiry ? ' EXP:' + esc(o.expiry) : '') : '') + '</div></div>'
                    + (o.sold_out ? '<span class="badge bg-light text-dark border">Nothing left in this batch</span>'
                        : (left > 0 ? '<button type="button" class="btn btn-sm btn-outline-dark" data-po="' + i + '" aria-label="Return ' + esc(o.name) + '">Return</button>' : '<span class="badge bg-success">In the list</span>')) + '</div>';
            }
            return '<div class="ea-po"><div class="flex-grow-1"><b>' + esc(o.name) + '</b><div class="small text-muted">Still due: ' + esc(dueWords) + '</div></div>'
                + (left > 0 ? '<button type="button" class="btn btn-sm btn-outline-dark" data-po="' + i + '" aria-label="Receive ' + esc(o.name) + '">Receive</button>' : '<span class="badge bg-success">All in</span>') + '</div>';
        }).join('');
    }
    if ($('ea-po')) {
        $('ea-po').addEventListener('click', function (e) {
            var b = e.target.closest('[data-po]'); if (!b || !headerReady()) return;
            var o = PO_LINES[+b.dataset.po], it = poItems[o.product_id];
            var go = function (item) {
                var got = LINES.filter(function (l) { return String(l.poLineId) === String(o.po_line_id); }).reduce(function (t, l) { return t + baseOf(l); }, 0);
                var left = Math.max(0, o.due - got), qty = {};
                item.packs.forEach(function (p) { var k = Math.floor(left / p.factor + 1e-9); if (k > 0) { qty[p.key] = String(k); left -= k * p.factor; } });
                var bp = (o.pack_unit_id && packOf(item, o.pack_unit_id)) || item.packs.filter(function (p) { return p.can_buy; })[0] || item.packs[0];
                if (IS_RET) {
                    load(item, { qty: qty, price: Math.round(o.unit_cost * bp.factor * 100) / 100, buyKey: bp.key, poLineId: o.po_line_id, variantId: o.variant_id,
                        batch: o.batch_no || '', batchId: o.batch_id, expiry: o.expiry || '', reason: (($('reason_code') || {}).value) || '',
                        priceFrom: o.free ? 'free goods (bonus) — nothing to pay back' : 'the receipt rate', focus: 'reason' });
                    return;
                }
                load(item, { qty: qty, price: Math.round(o.unit_cost * bp.factor * 100) / 100, buyKey: bp.key, poLineId: o.po_line_id, variantId: o.variant_id });
            };
            if (it) go(it); else fetchItems({ product_id: o.product_id }).then(function (r) { if (r[0]) { poItems[o.product_id] = r[0]; go(r[0]); } });
        });
    }

    // ── the expiry sweep (return): this supplier's batches here that expired, or will by a month ───
    if (IS_RET && $('ea-sw-go')) {
        var SW = [];
        $('ea-sw-go').addEventListener('click', function () {
            var box = $('ea-sw');
            if (!headerReady()) { box.hidden = false; box.innerHTML = '<div class="text-warning-emphasis">Choose the supplier and the branch above first.</div>'; return; }
            var u = new URL(BATCH_URL, location.href);
            u.searchParams.set('supplier_id', $('supplier_id').value); u.searchParams.set('branch_id', $('branch_id').value); u.searchParams.set('before', $('ea-sw-month').value);
            box.hidden = false; box.innerHTML = '<div class="text-muted">Looking…</div>';
            fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : { batches: [] }; })
                .then(function (d) {
                    SW = d.batches || [];
                    if (!SW.length) { box.innerHTML = '<div class="text-muted">Nothing from this supplier has expired here by then. 👍</div>'; return; }
                    box.innerHTML = '<table class="table table-sm mb-2"><thead><tr><th><input type="checkbox" id="ea-sw-all" checked aria-label="Tick all"></th><th>Item</th><th>Batch</th><th>Expiry</th><th class="text-end">Left</th></tr></thead><tbody>'
                        + SW.map(function (b, i) { return '<tr><td><input type="checkbox" data-sw="' + i + '" checked aria-label="Return ' + esc(b.product) + ' ' + esc(b.batch_no) + '"></td><td>' + esc(b.product) + '</td><td>' + esc(b.batch_no) + '</td><td>' + esc(b.expiry) + (b.expired ? ' <span class="badge bg-danger">expired</span>' : '') + '</td><td class="text-end">' + num(b.stock) + '</td></tr>'; }).join('')
                        + '</tbody></table><button type="button" class="btn btn-sm btn-dark" id="ea-sw-add">Put the ticked ones in the list</button>';
                    $('ea-sw-all').addEventListener('change', function () { box.querySelectorAll('[data-sw]').forEach(function (c) { c.checked = $('ea-sw-all').checked; }); });
                    $('ea-sw-add').addEventListener('click', function () {
                        var picked = Array.prototype.filter.call(box.querySelectorAll('[data-sw]'), function (c) { return c.checked; }).map(function (c) { return SW[+c.dataset.sw]; });
                        Promise.all(picked.map(function (b) {
                            return (poItems[b.product_id] ? Promise.resolve(poItems[b.product_id]) : fetchItems({ product_id: b.product_id }).then(function (r) { if (r[0]) poItems[b.product_id] = r[0]; return r[0]; }))
                                .then(function (item) {
                                    if (!item) return;
                                    var left = b.stock, qty = {};
                                    item.packs.forEach(function (p) { var k = Math.floor(left / p.factor + 1e-9); if (k > 0) { qty[p.key] = String(k); left -= k * p.factor; } });
                                    var bp = item.packs.filter(function (p) { return p.can_buy; })[0] || item.packs[0];
                                    if (LINES.some(function (l) { return String(l.batchId) === String(b.id); })) return;
                                    LINES.push({ item: item, qty: qty, batch: b.batch_no, batchId: b.id, expiry: b.expiry || '', price: String(Math.round(b.unit_cost * bp.factor * 100) / 100), buyKey: bp.key,
                                        reason: 'expired', priceFrom: 'what this batch cost', poLineId: null, variantId: item.variant_id || null });
                                });
                        })).then(function () { saved = false; drawGrid(); box.innerHTML = '<div class="text-success fw-bold">' + picked.length + ' put in the list.</div>'; });
                    });
                });
        });
    }

    // The GRN's extra charges show in the totals as they are typed.
    if ($('extra_charges')) $('extra_charges').addEventListener('input', function () { drawGrid(); });

    // ── start: header gate, the order's lines, and lines coming back from a refused save ──────────
    ['supplier_id', 'branch_id'].forEach(function (id) { var el = $(id); if (el) el.addEventListener('change', gate); });
    gate();
    var want = {};
    PO_LINES.forEach(function (o) { want[o.product_id] = 1; });
    OLD.forEach(function (l) { want[l.product_id] = 1; });
    var ids = Object.keys(want);
    if (ids.length && headerReady()) {
        Promise.all(ids.map(function (id) { return fetchItems({ product_id: id }).then(function (r) { if (r[0]) poItems[id] = r[0]; }); })).then(function () {
            OLD.forEach(function (l) {
                var it = poItems[l.product_id]; if (!it) return;
                LINES.push({ item: it, qty: l.packs || {}, batch: l.batch_no || '', expiry: l.expiry || '', free: l.free || '', price: l.price || '', buyKey: l.buy_pack || '', disc: l.disc || '', poLineId: l.purchase_order_line_id || l.source_line_id || null, variantId: l.product_variant_id || null,
                    batchId: l.inventory_batch_id || null, reason: l.reason_code || '', note: l.note || '' });
            });
            drawGrid(); drawPo();
        });
    } else { drawGrid(); drawPo(); }
    if (!OLD.length) offerDraft();
    draftReady = true;

    // ── Post without leaving the page (owner: "reloading kam, ajax") ───────────────────────────────
    function saveErr(list) { var b = $('ea-saveerr'); b.innerHTML = list.map(function (m) { return '<div>' + esc(m) + '</div>'; }).join(''); b.hidden = !list.length; if (list.length) b.scrollIntoView({ block: 'center' }); }
    function busy(v) { form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = v; }); if (v) $('ea-count').innerHTML = '<span class="ea-sign">Saving…</span>'; }
    function done(d) {
        saved = true; LINES = []; rowErr = {}; editAt = null; drawGrid();
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
        if ($('ea-po')) $('ea-po').closest('.card').hidden = true;
        var box = $('ea-done');
        box.innerHTML = '<div class="d-flex flex-wrap align-items-center gap-2"><b style="font-size:1.05rem">✓ ' + esc(d.number) + (IS_RET ? (d.posted ? ' posted — stock is out and the supplier owes you less.' : ' saved as a draft — open it to post.') : ' saved.') + '</b>'
            + '<a class="btn btn-sm btn-success" href="' + esc(d.url) + '">Open it</a>'
            + '<a class="btn btn-sm btn-outline-dark" href="' + esc(NEW_URL) + '">New ' + (IS_RET ? 'return' : (IS_GRN ? 'GRN' : 'order')) + '</a>'
            + '<span class="text-muted small">Or add more lines below and Post again for another one.</span></div>';
        box.hidden = false; box.focus(); box.scrollIntoView({ block: 'center' });
    }
    var LINE_FIELD = /^lines\.(\d+)\.(packs|price|disc|free|expiry_date|batch_no|product_id)$/;
    function failed(x) {
        busy(false);
        if (x.status === 422) {
            var errs = (x.d && x.d.errors) || {}, top = []; rowErr = {};
            Object.keys(errs).forEach(function (k) { var m = LINE_FIELD.exec(k); if (m && LINES[+m[1]]) rowErr[+m[1]] = errs[k][0]; else top.push(errs[k][0]); });
            drawGrid();
            saveErr(top.length ? top : ['Some lines need a fix — they are red below.']);
            return;
        }
        drawGrid();
        saveErr([x.status === 419 ? 'Your login has expired. Log in again in another tab, then press Post again — nothing is lost.'
            : (x.status === 403 ? ((x.d && x.d.message) || 'You cannot save here.') : 'Could not save — check the internet and press Post again. Nothing is lost.')]);
    }
    if (form) form.addEventListener('submit', function (e) {
        e.preventDefault();
        saveErr([]);
        if (S) { msg(editAt !== null ? 'Press "Update line" (Ctrl+Enter) first, or Clear.' : 'This item is not in the list yet — press Ctrl+Enter, or Clear.'); focusField(fieldOrder[0]); return; }
        if (!LINES.length) { saveErr(['Nothing in the list yet — add an item first.']); search.focus(); return; }
        if (!pvOk) { openPreview(e.submitter); return; }
        pvOk = false;
        busy(true);
        var body = new FormData(form);
        if (e.submitter && e.submitter.name) body.set(e.submitter.name, e.submitter.value);   // "Post return" vs "Save draft"
        fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }, function () { return { ok: false, status: r.status, d: {} }; }); })
            .then(function (x) { if (x.ok && x.d && x.d.ok) { busy(false); done(x.d); } else failed(x); }, function () { failed({ status: 0, d: {} }); });
    });
    window.addEventListener('beforeunload', function (e) { if (LINES.length && !saved) { e.preventDefault(); e.returnValue = ''; } });

    // ── the preview (Ctrl+S, or the Post / Save button): everything once more, Enter posts, Esc goes back ──
    var pvOk = false, pvSubmitter = null;
    function defaultSubmitter() { return form.querySelector('#grn-post-btn, #ret-post-btn') || form.querySelector('button[type="submit"]'); }
    function selText(id) { var el = $(id); return el && el.selectedIndex > 0 ? el.options[el.selectedIndex].text.trim() : ''; }
    function openPreview(submitter) {
        saveErr([]);
        if (S) { msg(editAt !== null ? 'Press "Update line" (Ctrl+Enter) first, or Clear.' : 'This item is not in the list yet — press Ctrl+Enter, or Clear.'); focusField(fieldOrder[0]); return; }
        if (!headerReady()) { saveErr(['Choose the supplier and the branch first.']); ($('sup-q') || $('supplier_id')).focus(); return; }
        if (!LINES.length) { saveErr(['Nothing in the list yet — add an item first.']); search.focus(); return; }
        pvSubmitter = submitter || defaultSubmitter();
        var date = ($('receipt_date') || $('order_date') || $('return_date') || {}).value || '';
        var charges = parseFloat((($('extra_charges') || {}).value || '0').replace(/,/g, '')) || 0;
        var total = 0, warn = [], rows = LINES.map(function (l, i) {
            var it = l.item, bp = buyPack(it, l.buyKey), base = baseOf(l), amt = amountOf(l); total += amt;
            var ml = IS_GRN && l.expiry ? monthsLeft(l.expiry) : null;
            if (ml !== null && ml < 6) warn.push(it.name + ' — batch ' + (l.batch || '—') + ' expires in ' + ml + ' month' + (ml === 1 ? '' : 's') + '.');
            return '<tr><td class="text-muted">' + (i + 1) + '</td><td><b>' + esc(it.name) + '</b>' + (l.note ? '<div class="small fst-italic">' + esc(l.note) + '</div>' : '') + '</td>'
                + (BATCHY ? '<td>' + esc(l.batch || '—') + '</td><td>' + esc(l.expiry || '—') + '</td>' : '')
                + '<td><b>' + esc(words(it, base)) + '</b>' + (it.packs.length > 1 && words(it, base) !== num(base) + ' ' + it.unit_name ? ' <span class="text-muted small">= ' + num(base) + ' ' + esc(it.unit_name) + '</span>' : '') + '</td>'
                + (IS_GRN ? '<td>' + ((parseFloat(l.free) || 0) > 0 ? '+' + esc(l.free) + ' ' + esc(bp.name) : '—') + '</td>' : '')
                + '<td class="text-end">' + money(parseFloat(l.price) || 0) + ' / ' + esc(bp.name) + '</td>'
                + (IS_RET ? '<td>' + esc(REASONS[l.reason] || '—') + '</td>' : '<td class="text-end">' + (l.disc ? esc(l.disc) + '%' : '—') + '</td>')
                + '<td class="text-end"><b>' + money(amt) + '</b></td></tr>';
        });
        var head = '<tr><th>#</th><th>Item</th>' + (BATCHY ? '<th>Batch</th><th>Expiry</th>' : '') + '<th>Quantity</th>' + (IS_GRN ? '<th>Free</th>' : '') + '<th class="text-end">Price</th>' + (IS_RET ? '<th>Reason</th>' : '<th class="text-end">Disc</th>') + '<th class="text-end">Amount</th></tr>';
        var what = IS_RET ? 'Stock leaves these batches, and this supplier will owe you <b>' + money(total) + '</b> less.'
            : (IS_GRN ? 'Stock goes up by these quantities. The supplier\'s bill comes from this GRN: <b>' + money(total + charges) + '</b>' + (charges > 0 ? ' (goods ' + money(total) + ' + charges ' + money(charges) + ', spread over the goods by value)' : '') + '.'
                : 'Nothing moves yet — this is the order to send. Total <b>' + money(total) + '</b>.');
        $('ea-pv-title').textContent = IS_RET ? 'Check the return' : (IS_GRN ? 'Check the goods received' : 'Check the order');
        $('ea-pv-body').innerHTML = '<div class="ea-pv-who"><span>Supplier: <b>' + esc(($('sup-q') || {}).value || selText('supplier_id')) + '</b></span><span>Branch: <b>' + esc(selText('branch_id')) + '</b></span><span>Date: <b>' + esc(date) + '</b></span>'
            + (PO_ID ? '<span>Order: <b>' + esc(@json($purchaseOrder?->po_no)) + '</b></span>' : '') + (IS_RET && @json($goodsReceipt?->grn_no ?? null) ? '<span>Receipt: <b>' + esc(@json($goodsReceipt?->grn_no ?? null)) + '</b></span>' : '') + '</div>'
            + '<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead class="table-light">' + head + '</thead><tbody>' + rows.join('') + '</tbody></table></div>'
            + (warn.length ? '<div class="ea-warn mb-2" style="background:#FFF4D6;color:#6B5310">' + warn.map(esc).join('<br>') + '</div>' : '')
            + '<div class="d-flex justify-content-between flex-wrap gap-2 align-items-center"><span>' + what + '</span><b style="font-size:1.3rem;color:var(--navy)">' + LINES.length + ' line' + (LINES.length === 1 ? '' : 's') + ' · ' + money(total + charges) + '</b></div>';
        var main = IS_RET ? 'Post return' : (IS_GRN ? 'Post GRN' : 'Save order');
        $('ea-pv-btns').innerHTML = '<button type="button" class="btn btn-primary" data-pv="main">' + main + ' <span class="ea-kbd ms-1">Enter</span></button>'
            + (IS_RET ? '<button type="button" class="btn btn-outline-dark" data-pv="draft">Save as draft</button>' : '')
            + '<button type="button" class="btn btn-light" data-pv="back">Back to edit <span class="ea-kbd ms-1">Esc</span></button>';
        if (IS_RET && pvSubmitter && pvSubmitter.id === 'ret-save-btn') $('ea-pv-btns').querySelector('[data-pv="draft"]').classList.replace('btn-outline-dark', 'btn-dark');
        $('ea-pv').hidden = false;
        $('ea-pv-btns').querySelector(IS_RET && pvSubmitter && pvSubmitter.id === 'ret-save-btn' ? '[data-pv="draft"]' : '[data-pv="main"]').focus();
    }
    function closePreview() { $('ea-pv').hidden = true; pvSubmitter = null; search.focus(); }
    function go(sub) { pvOk = true; $('ea-pv').hidden = true; if (form.requestSubmit) form.requestSubmit(sub || undefined); else form.submit(); }
    $('ea-pv-btns').addEventListener('click', function (e) {
        var b = e.target.closest('[data-pv]'); if (!b) return;
        if (b.dataset.pv === 'back') { closePreview(); return; }
        go(b.dataset.pv === 'draft' ? $('ret-save-btn') : (IS_RET ? $('ret-post-btn') : (pvSubmitter && pvSubmitter.id !== 'ret-save-btn' ? pvSubmitter : defaultSubmitter())));
    });
    $('ea-pv').addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); closePreview(); }
        // Enter presses the focused button (Post, Save as draft, Back) — or Post when nothing is focused. The form's
        // own Enter guard swallows a button's default, so it is pressed here.
        if (e.key === 'Enter') { e.preventDefault(); var b = e.target.closest && e.target.closest('[data-pv]'); (b || $('ea-pv-btns').querySelector('[data-pv="main"]')).click(); }
    });
    $('ea-pv').addEventListener('mousedown', function (e) { if (e.target === $('ea-pv')) closePreview(); });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); if ($('ea-pv').hidden) openPreview(null); }
    });

    // ── Enter moves through the header too (supplier → branch → date → … → the item search) ───────────
    if (form) form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.defaultPrevented || e.ctrlKey || e.altKey || e.metaKey) return;
        var t = e.target;
        if (!t || !t.closest || t.closest('#ea') || t.closest('#sb') || t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.tagName === 'A') return;
        e.preventDefault();
        var all = Array.prototype.filter.call(form.querySelectorAll('input, select'), function (el) {
            return !el.disabled && el.type !== 'hidden' && !el.hidden && el.offsetParent !== null && !el.closest('#ea') && !el.closest('#sb') && !el.readOnly;
        });
        var next = all[all.indexOf(t) + 1];
        (next || search).focus();
    });

    // Enter in the entry area or the search never sends the form; the page's own Save button does.
    if (form) form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.closest && e.target.closest('#ea')) e.preventDefault(); }, true);
})();
</script>
@endpush
