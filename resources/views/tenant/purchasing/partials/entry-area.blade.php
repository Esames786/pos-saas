{{--
    PURCHASING-V2-1 — the entry area for a new PO / GRN (Master switch "New purchase screens").
    The catering event screen's way: one entry area on top (scan or search → the item's own boxes → Enter moves
    on → Ctrl+Enter puts the line in the list), a grid below whose lines open back for editing.
    Every grid line posts what the person typed (boxes per pack, price per pack, disc %, free, batch, MM/YY);
    App\Support\PackLines on the server turns it into today's line. This script only shows the sums.

    Expects: $mode ('grn' | 'po'), $prefillLines (GRN from a PO: product_id, quantity_received, unit_cost,
    purchase_order_line_id, pack_unit_id), $purchaseOrder (nullable).
--}}
@php
    $isGrn = $mode === 'grn';
    $oldLines = old('lines');
    $oldLines = is_array($oldLines) && isset(reset($oldLines)['packs']) ? array_values($oldLines) : [];
    $poLines = collect($prefillLines ?? [])->map(fn ($l) => [
        'po_line_id' => $l['purchase_order_line_id'] ?? null, 'product_id' => $l['product_id'], 'variant_id' => $l['product_variant_id'] ?? null,
        'due' => (float) ($l['quantity_received'] ?? 0), 'unit_cost' => (float) ($l['unit_cost'] ?? 0), 'pack_unit_id' => $l['pack_unit_id'] ?? null,
        'name' => \App\Models\Tenant\Product::whereKey($l['product_id'])->value('name'),
    ])->values()->all();
@endphp

@push('styles')
<style>
    .ea { --navy:#092C4C; --gold:#B8902E; --goldbg:#FFF8E6; --line:#E3E8EE; --ink2:#4E5B6B; --ok:#1E7A50; --bad:#B42318; }
    .ea .ea-card { border:2px solid var(--gold); border-radius:14px; padding:16px; background:#fff; box-shadow:0 8px 22px rgba(184,144,46,.12); }
    .ea .ea-lbl { font-size:.75rem; font-weight:800; color:var(--ink2); letter-spacing:.02em; text-transform:uppercase; display:block; margin-bottom:3px; }
    .ea .ea-in { height:44px; border:1px solid #CBD3DE; border-radius:10px; padding:0 12px; font-size:1rem; width:100%; background:#fff; }
    .ea .ea-in:focus { outline:3px solid var(--gold); outline-offset:0; border-color:var(--gold); }
    .ea .ea-num { text-align:right; font-variant-numeric:tabular-nums; }
    .ea .ea-search { height:52px; font-size:1.1rem; }
    .ea .ea-res { position:absolute; left:0; right:0; top:100%; z-index:30; background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 12px 28px rgba(9,44,76,.16); max-height:320px; overflow:auto; }
    .ea .ea-res button { display:flex; justify-content:space-between; gap:12px; width:100%; padding:10px 12px; border:0; border-bottom:1px solid #EEF2F6; background:#fff; text-align:left; }
    .ea .ea-res button.on, .ea .ea-res button:hover { background:var(--goldbg); }
    .ea .ea-item { display:flex; gap:18px; flex-wrap:wrap; align-items:center; background:#F8FAFC; border:1px solid var(--line); border-radius:12px; padding:10px 14px; }
    .ea .ea-fields { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    .ea .ea-kbd { display:inline-block; border:1px solid #CBD3DE; border-bottom-width:2px; border-radius:6px; padding:0 6px; font-size:.75rem; font-weight:800; background:#fff; }
    .ea .ea-warn { border-radius:10px; padding:8px 12px; font-weight:700; }
    .ea .ea-tbl td, .ea .ea-tbl th { vertical-align:middle; }
    .ea .ea-tbl tr.editing td { background:var(--goldbg); }
    .ea .ea-po { display:flex; gap:10px; align-items:center; padding:10px 0; border-bottom:1px solid #EEF2F6; }
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

    <div class="ea-card mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <b style="color:var(--navy);font-size:1.05rem" id="ea-title">{{ $isGrn ? 'Receive an item' : 'Add an item' }}</b>
            <span class="small text-muted"><span class="ea-kbd">Enter</span> next box · <span class="ea-kbd">Ctrl</span>+<span class="ea-kbd">Enter</span> put in the list · <span class="ea-kbd">Esc</span> clear</span>
        </div>
        <div class="text-warning-emphasis small mb-2" id="ea-gate">Choose the supplier and the branch above first.</div>
        <div class="position-relative">
            <label class="ea-lbl" for="ea-search">Item — scan its barcode{{ $isGrn ? ' (a 2D code fills batch and expiry too)' : '' }}, or type a name</label>
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

    <div class="table-responsive">
        <table class="table ea-tbl mb-1">
            <thead class="table-light"><tr><th>#</th><th>Item</th>@if($isGrn)<th>Batch</th><th>Expiry</th>@endif<th>Quantity</th>@if($isGrn)<th>Free</th>@endif<th class="text-end">Price</th><th class="text-end">Disc</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody id="ea-grid"><tr id="ea-empty"><td colspan="{{ $isGrn ? 10 : 7 }}" class="text-center text-muted py-4">Nothing yet — {{ $isGrn ? 'press Receive on an order line, or scan a box' : 'pick an item above and press Ctrl + Enter' }}.</td></tr></tbody>
        </table>
    </div>
    <div class="d-flex justify-content-end gap-3 align-items-center"><span class="text-muted" id="ea-count"></span><b style="font-size:1.25rem;color:var(--navy)" id="ea-total">Rs 0</b></div>
    <div id="ea-hidden"></div>
</div>

@push('scripts')
<script>
(function () {
    var root = document.getElementById('ea');
    if (!root) return;
    var IS_GRN = root.dataset.mode === 'grn';
    var LOOKUP = @json(url('/ajax/products'));
    var PO_LINES = @json($poLines);
    var OLD = @json($oldLines);
    var form = root.closest('form');
    var $ = function (id) { return document.getElementById(id); };
    var search = $('ea-search'), res = $('ea-res'), body = $('ea-body');
    var S = null, LINES = [], editAt = null, hi = 0, results = [], fieldOrder = [];

    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    // Whole rupees stay whole ("Rs 8,920"); paisa always shows two places ("Rs 4,593.80", never "4,593.8").
    function money(n) { var r = Math.round(n * 100) / 100; return 'Rs ' + r.toLocaleString('en-PK', { minimumFractionDigits: r % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
    function num(n) { return (Math.round(n * 1000) / 1000).toLocaleString('en-PK', { maximumFractionDigits: 3 }); }
    function words(item, q) {
        if (item.allow_decimal) return num(q) + ' ' + item.unit_name;
        var left = Math.abs(q), out = [];
        item.packs.forEach(function (p) { var k = Math.floor(left / p.factor + 1e-9); if (k > 0) { out.push(k + ' ' + p.name); left -= k * p.factor; } });
        return out.length ? out.join(' ') : '0 ' + item.unit_name;
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
              poLineId: o.poLineId || null, variantId: o.variantId || item.variant_id || null };
        S.priceKey = S.buyKey;   // the pack the price box is in — switching "Price per" converts from it
        search.value = item.name;
        drawEntry();
        $('ea-msg').hidden = true;
        if (o.note) { $('ea-exp').hidden = false; $('ea-exp').style.cssText = 'background:#E7F5EE;color:#155F3E'; $('ea-exp').textContent = o.note; }
        focusField(o.focus || (IS_GRN && needsBatch() && !S.batch ? 'batch' : 'pack:' + item.packs[0].key));
    }
    function needsBatch() { return S && S.item.is_stock_tracked && (S.item.requires_batch || S.item.has_expiry); }
    function drawEntry() {
        var it = S.item, bp = buyPack(it, S.buyKey);
        var packWords = it.packs.length > 1 ? it.packs.slice(0, -1).map(function (p) { return '1 ' + esc(p.name) + ' = ' + num(p.factor) + ' ' + esc(it.unit_name); }).join(' · ') : 'Bought in ' + esc(it.unit_name);
        $('ea-item').innerHTML = '<div style="min-width:220px"><b style="font-size:1.05rem">' + esc(it.name) + '</b><div class="text-muted small">' + packWords + '</div></div>'
            + '<div><span class="ea-lbl">In stock now</span><b>' + esc(it.current_stock == null ? '—' : words(it, it.current_stock)) + '</b></div>'
            + (it.last_cost ? '<div><span class="ea-lbl">Last bought from this supplier</span><b>' + money(it.last_cost.unit_cost * bp.factor) + ' / ' + esc(bp.name) + '</b> <span class="text-muted small">' + esc(it.last_cost.date) + '</span></div>' : '')
            + (IS_GRN ? '<div><span class="ea-lbl">Batch &amp; expiry</span><b>' + (needsBatch() ? 'Needed' : 'Not kept for this item') + '</b></div>' : '');
        var f = [];
        fieldOrder = [];
        if (IS_GRN && needsBatch()) {
            f.push(field('batch', 'Batch no.', S.batch, 'e.g. PN2410-07', 'text', 150));
            f.push(field('expiry', 'Expiry MM/YY', S.expiry, '09/28', 'text', 110));
        }
        it.packs.forEach(function (p) {
            f.push(field('pack:' + p.key, p.name + (it.packs.length > 1 && p.factor > 1 ? ' (' + num(p.factor) + ')' : ''), S.qty[p.key] || '', '0', it.allow_decimal ? 'decimal' : 'numeric', 110));
        });
        if (IS_GRN) f.push(field('free', 'Free ' + bp.name, S.free, '0', 'numeric', 110));
        var buyables = it.packs.filter(function (p) { return p.can_buy; });
        f.push('<div style="width:200px"><label class="ea-lbl" for="ea-f-price">Price per ' + (buyables.length > 1 ? '<select id="ea-buy" class="form-select form-select-sm d-inline-block w-auto py-0" aria-label="Price is per">' + buyables.map(function (p) { return '<option value="' + esc(p.key) + '"' + (p.key === bp.key ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select>' : esc(bp.name)) + '</label>'
            + '<input id="ea-f-price" class="ea-in ea-num" inputmode="decimal" value="' + esc(S.price) + '" data-k="price"></div>');
        fieldOrder.push('price');
        f.push(field('disc', 'Disc %', S.disc, '0', 'decimal', 90));
        $('ea-fields').innerHTML = f.join('');
        body.hidden = false;
        $('ea-add').textContent = editAt !== null ? 'Update line' : 'Put in the list';
        $('ea-title').textContent = editAt !== null ? 'Changing line ' + (editAt + 1) : (IS_GRN ? 'Receive an item' : 'Add an item');
        sums();
    }
    function field(k, label, v, ph, mode, w) {
        fieldOrder.push(k);
        var id = 'ea-f-' + k.replace(':', '-');
        return '<div style="width:' + w + 'px"><label class="ea-lbl" for="' + id + '">' + esc(label) + '</label><input id="' + id + '" class="ea-in' + (mode === 'text' ? '' : ' ea-num') + '" inputmode="' + mode + '" value="' + esc(v) + '" placeholder="' + esc(ph) + '" data-k="' + esc(k) + '" autocomplete="off"></div>';
    }
    // At once, not on a timer: a scanner or a fast hand types the next key straight after Enter.
    function focusField(k) { var el = root.querySelector('[data-k="' + k + '"]'); if (el) { el.focus(); el.select && el.select(); } }
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
        var ml = IS_GRN && needsBatch() ? monthsLeft(S.expiry) : null, w = $('ea-exp');
        if (ml !== null && ml < 0) { w.hidden = false; w.style.cssText = 'background:#FDECEC;color:#8E1C14'; w.textContent = 'Already expired — this batch cannot be received. Send it back to the supplier.'; }
        else if (ml !== null && ml < 6) { w.hidden = false; w.style.cssText = 'background:#FFF4D6;color:#6B5310'; w.textContent = 'Short expiry: only ' + ml + ' month' + (ml === 1 ? '' : 's') + ' left. It will show on the near-expiry list.'; }
        else if (w.textContent.indexOf('2D code') !== 0) w.hidden = true;
    }
    function msg(t) { var m = $('ea-msg'); m.hidden = !t; m.textContent = t || ''; }
    function clearEntry() { S = null; editAt = null; body.hidden = true; search.value = ''; msg(''); $('ea-exp').hidden = true; drawGrid(); search.focus(); }

    $('ea-fields').addEventListener('input', function () { msg(''); sums(); });
    $('ea-fields').addEventListener('change', function (e) {
        if (e.target.dataset.k === 'expiry') { e.target.value = normExpiry(e.target.value); sums(); return; }
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
        if (IS_GRN && needsBatch() && !S.batch.trim()) { msg('Write the batch number printed on the box.'); focusField('batch'); return; }
        var ml = IS_GRN && needsBatch() ? monthsLeft(S.expiry) : null;
        if (IS_GRN && needsBatch() && ml === null) { msg('Write the expiry as month/year, e.g. 09/28.'); focusField('expiry'); return; }
        if (ml !== null && ml < 0) { msg('This batch has already expired — it cannot be received.'); focusField('expiry'); return; }
        if (base <= 0 && free <= 0) { msg('How many? Type it in any of the boxes.'); focusField('pack:' + it.packs[0].key); return; }
        if (!it.allow_decimal) {
            var bad = it.packs.filter(function (p) { var v = S.qty[p.key]; return v && !/^\d+$/.test(String(v).trim()); })[0];
            if (bad) { msg(bad.name + ': a whole number, please.'); focusField('pack:' + bad.key); return; }
        }
        var line = { item: it, qty: Object.assign({}, S.qty), batch: S.batch.trim().toUpperCase(), expiry: normExpiry(S.expiry), free: S.free, price: S.price, buyKey: bp.key, disc: S.disc, poLineId: S.poLineId, variantId: S.variantId };
        if (editAt !== null) LINES[editAt] = line; else LINES.push(line);
        S = null; editAt = null; body.hidden = true; search.value = ''; msg(''); $('ea-exp').hidden = true;
        drawGrid(); drawPo(); search.focus();
    }

    // ── the grid (and the hidden inputs the server reads) ─────────────────────────────────────────
    function drawGrid() {
        var g = $('ea-grid'), h = [], hid = [], total = 0;
        LINES.forEach(function (l, i) {
            var it = l.item, bp = buyPack(it, l.buyKey), base = baseOf(l), amt = amountOf(l); total += amt;
            h.push('<tr class="' + (editAt === i ? 'editing' : '') + '"><td class="text-muted">' + (i + 1) + '</td><td><b>' + esc(it.name) + '</b></td>'
                + (IS_GRN ? '<td>' + esc(needsBatchOf(it) ? l.batch : '—') + '</td><td>' + esc(needsBatchOf(it) ? l.expiry : '—') + '</td>' : '')
                + '<td><b>' + esc(words(it, base)) + '</b>' + (it.packs.length > 1 ? ' <span class="text-muted small">= ' + num(base) + ' ' + esc(it.unit_name) + '</span>' : '') + '</td>'
                + (IS_GRN ? '<td>' + ((parseFloat(l.free) || 0) > 0 ? '+' + esc(l.free) + ' ' + esc(bp.name) : '—') + '</td>' : '')
                + '<td class="text-end">' + money(parseFloat(l.price) || 0) + ' / ' + esc(bp.name) + '</td><td class="text-end">' + (l.disc ? esc(l.disc) + '%' : '—') + '</td>'
                + '<td class="text-end"><b>' + money(amt) + '</b></td>'
                + '<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" data-edit="' + i + '" aria-label="Edit line ' + (i + 1) + '">Edit</button> <button type="button" class="btn btn-sm btn-light text-danger" data-del="' + i + '" aria-label="Remove line ' + (i + 1) + '">Remove</button></td></tr>');
            var n = 'lines[' + i + ']';
            hid.push(hidden(n + '[product_id]', it.id), hidden(n + '[product_variant_id]', l.variantId || ''), hidden(n + '[purchase_order_line_id]', l.poLineId || ''),
                hidden(n + '[buy_pack]', l.buyKey), hidden(n + '[price]', l.price), hidden(n + '[disc]', l.disc || ''));
            if (IS_GRN) hid.push(hidden(n + '[batch_no]', l.batch), hidden(n + '[expiry]', l.expiry), hidden(n + '[free]', l.free || ''));
            it.packs.forEach(function (p) { hid.push(hidden(n + '[packs][' + p.key + ']', l.qty[p.key] || '')); });
        });
        g.innerHTML = h.length ? h.join('') : '<tr><td colspan="' + (IS_GRN ? 10 : 7) + '" class="text-center text-muted py-4">Nothing yet — ' + (IS_GRN ? 'press Receive on an order line, or scan a box' : 'pick an item above and press Ctrl + Enter') + '.</td></tr>';
        $('ea-hidden').innerHTML = hid.join('');
        $('ea-total').textContent = money(total);
        $('ea-count').textContent = LINES.length + (LINES.length === 1 ? ' line' : ' lines');
    }
    function needsBatchOf(it) { return it.is_stock_tracked && (it.requires_batch || it.has_expiry); }
    function hidden(n, v) { return '<input type="hidden" name="' + esc(n) + '" value="' + esc(v) + '">'; }
    $('ea-grid').addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
        if (ed) { var i = +ed.dataset.edit, l = LINES[i]; editAt = i; load(l.item, { qty: Object.assign({}, l.qty), batch: l.batch, expiry: l.expiry, free: l.free, price: l.price, disc: l.disc, buyKey: l.buyKey, poLineId: l.poLineId, variantId: l.variantId }); drawGrid(); }
        if (del) { LINES.splice(+del.dataset.del, 1); editAt = null; drawGrid(); drawPo(); }
    });

    // ── GRN from an order ─────────────────────────────────────────────────────────────────────────
    var poItems = {};
    function drawPo() {
        var box = $('ea-po'); if (!box) return;
        box.innerHTML = PO_LINES.map(function (o, i) {
            var it = poItems[o.product_id], got = LINES.filter(function (l) { return String(l.poLineId) === String(o.po_line_id); }).reduce(function (t, l) { return t + baseOf(l); }, 0);
            var left = Math.max(0, o.due - got);
            var dueWords = it ? words(it, left) : num(left);
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
                load(item, { qty: qty, price: Math.round(o.unit_cost * bp.factor * 100) / 100, buyKey: bp.key, poLineId: o.po_line_id, variantId: o.variant_id });
            };
            if (it) go(it); else fetchItems({ product_id: o.product_id }).then(function (r) { if (r[0]) { poItems[o.product_id] = r[0]; go(r[0]); } });
        });
    }

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
                LINES.push({ item: it, qty: l.packs || {}, batch: l.batch_no || '', expiry: l.expiry || '', free: l.free || '', price: l.price || '', buyKey: l.buy_pack || '', disc: l.disc || '', poLineId: l.purchase_order_line_id || null, variantId: l.product_variant_id || null });
            });
            drawGrid(); drawPo();
        });
    } else { drawGrid(); drawPo(); }

    // Enter in the entry area or the search never sends the form; the page's own Save button does.
    if (form) form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.closest && e.target.closest('#ea')) e.preventDefault(); }, true);
})();
</script>
@endpush
