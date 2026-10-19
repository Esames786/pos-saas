{{--
    PURCHASING-V2-2 — the supplier box on the new PO / GRN screen (owner: "supplier ka name search and type …
    easy", "new supplier foran add").

    Type part of a name, phone or code → pick (↑↓ Enter). "+ New" (or F2 in the box) adds a supplier with only a
    name and a phone, without leaving the page. Under the box: what is owed, payment days, and on a GRN the
    orders of this supplier still waiting to be received.

    The real <select name="supplier_id"> stays (hidden) so the form, the entry area's gate and the old checks
    keep working; picking here sets it and fires its change event.

    Expects: $suppliers (collection), $supplierBox (['suppliers' => [...], 'orders' => [supplier_id => [...]]]),
    $selectedSupplierId, $mode ('grn' | 'po'), $currentPoId (GRN opened from a PO, or null).
--}}
@php
    $sbSelected = collect($supplierBox['suppliers'])->firstWhere('id', (int) $selectedSupplierId);
@endphp
<label for="sup-q" class="form-label required">Supplier <span class="text-muted fw-normal" lang="ur">سپلائر</span></label>
<div class="sb" id="sb">
    <div class="d-flex gap-2">
        <div class="flex-grow-1 position-relative">
            <input id="sup-q" type="text" class="form-control @error('supplier_id') is-invalid @enderror" autocomplete="off"
                   role="combobox" aria-expanded="false" aria-controls="sup-list" aria-autocomplete="list" aria-describedby="sup-info"
                   placeholder="Type a name, phone or code" value="{{ $sbSelected['name'] ?? '' }}">
            <div id="sup-list" class="sb-list" role="listbox" aria-label="Suppliers" hidden></div>
        </div>
        <button type="button" class="btn btn-outline-dark text-nowrap" id="sup-new" aria-controls="sup-add" aria-expanded="false">+ New <span class="sb-kbd">F2</span></button>
    </div>
    <select id="supplier_id" name="supplier_id" hidden tabindex="-1" aria-hidden="true">
        <option value="">—</option>
        @foreach($suppliers as $s)
            <option value="{{ $s->id }}" @selected((int) $selectedSupplierId === (int) $s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
    @error('supplier_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
    <div id="sup-info" class="sb-info" aria-live="polite"></div>

    <div id="sup-add" class="sb-add" hidden>
        <div class="fw-bold mb-2">New supplier <span class="text-muted fw-normal" lang="ur">نیا سپلائر</span></div>
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small" for="sup-add-name">Name</label><input id="sup-add-name" class="form-control" maxlength="255" autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label small" for="sup-add-phone">Phone</label><input id="sup-add-phone" class="form-control" maxlength="50" inputmode="tel" autocomplete="off"></div>
        </div>
        <div class="text-danger small fw-bold mt-2" id="sup-add-msg" role="alert" hidden></div>
        <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-dark btn-sm" id="sup-add-save">Save supplier</button>
            <button type="button" class="btn btn-light btn-sm" id="sup-add-cancel">Cancel</button>
            <span class="small text-muted align-self-center">The code is made by itself. More details later in Suppliers.</span>
        </div>
    </div>
</div>

@push('styles')
<style>
    .sb-list { position:absolute; z-index:30; left:0; right:0; top:100%; background:#fff; border:1px solid #D9DEE7; border-radius:10px; box-shadow:0 10px 24px rgba(27,42,74,.14); max-height:300px; overflow:auto; }
    .sb-list button { display:flex; justify-content:space-between; gap:10px; width:100%; border:0; background:none; text-align:left; padding:9px 12px; }
    .sb-list button.on, .sb-list button:hover { background:#F6F1E4; }
    .sb-kbd { border:1px solid #C9CED8; border-radius:5px; padding:0 5px; font-size:.75rem; margin-left:4px; }
    .sb-info { margin-top:6px; font-size:.9rem; color:#465166; }
    .sb-info .owe { font-weight:800; color:#1B2A4A; }
    .sb-info a { font-weight:700; }
    .sb-add { margin-top:10px; padding:12px; border:2px solid #B8902E; border-radius:12px; background:#FFFBF0; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    var BOX = @json($supplierBox);
    var MODE = @json($mode), CURRENT_PO = @json($currentPoId);
    var SUPS = BOX.suppliers || [], ORDERS = BOX.orders || {};
    var q = document.getElementById('sup-q'), list = document.getElementById('sup-list'), sel = document.getElementById('supplier_id');
    var info = document.getElementById('sup-info'), add = document.getElementById('sup-add'), newBtn = document.getElementById('sup-new');
    var hits = [], hi = 0;
    var form = q.closest('form');
    var token = (form && form.querySelector('input[name="_token"]') || {}).value || (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(n) { var r = Math.round(Math.abs(n) * 100) / 100; return 'Rs ' + r.toLocaleString('en-PK', { minimumFractionDigits: r % 1 ? 2 : 0, maximumFractionDigits: 2 }); }
    function byId(id) { return SUPS.filter(function (s) { return String(s.id) === String(id); })[0]; }
    function match(s, t) {
        var digits = t.replace(/\D/g, '');
        return t.split(/\s+/).every(function (w) { return (s.name + ' ' + (s.code || '')).toLowerCase().indexOf(w) !== -1; })
            || (digits.length >= 3 && String(s.phone || '').replace(/\D/g, '').indexOf(digits) !== -1);
    }
    function open(v) { list.hidden = !v; q.setAttribute('aria-expanded', v ? 'true' : 'false'); }
    function draw() {
        var t = q.value.trim().toLowerCase();
        hits = (t ? SUPS.filter(function (s) { return match(s, t); }) : SUPS).slice(0, 8);
        hi = 0;
        if (!hits.length) {
            list.innerHTML = '<div class="p-3 text-muted">No supplier matches. Press <b>+ New</b> (F2) to add "' + esc(q.value.trim()) + '".</div>';
            open(true); return;
        }
        list.innerHTML = hits.map(function (s, i) {
            return '<button type="button" role="option" id="sup-opt-' + i + '" data-i="' + i + '" class="' + (i === hi ? 'on' : '') + '"><span><b>' + esc(s.name) + '</b> <span class="text-muted small">' + esc(s.code || '') + '</span></span><span class="text-muted small">' + esc(s.phone || '') + '</span></button>';
        }).join('');
        q.setAttribute('aria-activedescendant', 'sup-opt-0');
        open(true);
    }
    function mark() {
        list.querySelectorAll('button').forEach(function (b, i) { b.classList.toggle('on', i === hi); });
        q.setAttribute('aria-activedescendant', 'sup-opt-' + hi);
        var b = list.querySelector('#sup-opt-' + hi); if (b) b.scrollIntoView({ block: 'nearest' });
    }
    function pick(s) {
        if (!s) return;
        if (!sel.querySelector('option[value="' + s.id + '"]')) { var o = document.createElement('option'); o.value = s.id; o.textContent = s.name; sel.appendChild(o); }
        sel.value = String(s.id);
        q.value = s.name; q.classList.remove('is-invalid');
        open(false);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        drawInfo();
    }
    function clearPick() { if (sel.value) { sel.value = ''; sel.dispatchEvent(new Event('change', { bubbles: true })); drawInfo(); } }
    function drawInfo() {
        var s = byId(sel.value);
        if (!s) { info.innerHTML = ''; return; }
        var bits = [];
        bits.push(s.balance > 0 ? 'You owe <span class="owe">' + money(s.balance) + '</span>' : (s.balance < 0 ? 'They owe you <span class="owe">' + money(s.balance) + '</span>' : 'Nothing owed'));
        if (s.terms > 0) bits.push('pays in ' + s.terms + ' days');
        if (s.disc) bits.push('usual discount ' + s.disc + '%');
        var html = bits.join(' · ');
        var waiting = (ORDERS[s.id] || []).filter(function (o) { return String(o.id) !== String(CURRENT_PO); });
        if (MODE === 'grn' && waiting.length) {
            html += '<div class="mt-1">' + waiting.length + ' order' + (waiting.length > 1 ? 's' : '') + ' waiting: '
                + waiting.map(function (o) { return '<a href="' + esc(@json(url('/goods-receipts/create')) + '?purchase_order_id=' + o.id) + '">' + esc(o.po_no) + '</a> <span class="text-muted">(' + esc(o.date || '') + ', ' + money(o.total) + (o.part ? ', part received' : '') + ')</span>'; }).join(' · ')
                + '</div>';
        }
        info.innerHTML = html;
    }

    q.addEventListener('input', function () { var s = byId(sel.value); if (!s || s.name !== q.value) clearPick(); draw(); });
    q.addEventListener('focus', function () { if (!sel.value) draw(); });
    q.addEventListener('keydown', function (e) {
        if (e.key === 'F2') { e.preventDefault(); showAdd(true); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) draw(); else { hi = Math.min(hi + 1, hits.length - 1); mark(); } return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); mark(); return; }
        if (e.key === 'Escape') { open(false); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!list.hidden && hits[hi]) { pick(hits[hi]); var b = document.getElementById('branch_id'); if (b && !b.value) b.focus(); }
        }
    });
    list.addEventListener('mousedown', function (e) { var b = e.target.closest('[data-i]'); if (b) { e.preventDefault(); pick(hits[+b.dataset.i]); } });
    q.addEventListener('blur', function () {
        setTimeout(function () { open(false); }, 120);
        var t = q.value.trim().toLowerCase();
        if (!sel.value && t) { var exact = SUPS.filter(function (s) { return s.name.toLowerCase() === t; })[0]; if (exact) pick(exact); }
    });

    // ── + New supplier ───────────────────────────────────────────────────────────────────────────
    var addName = document.getElementById('sup-add-name'), addPhone = document.getElementById('sup-add-phone'), addMsg = document.getElementById('sup-add-msg');
    function showAdd(v) {
        add.hidden = !v; newBtn.setAttribute('aria-expanded', v ? 'true' : 'false'); addMsg.hidden = true; open(false);
        if (v) { if (!sel.value && q.value.trim()) addName.value = q.value.trim(); addName.focus(); }
    }
    function saveNew() {
        addMsg.hidden = true;
        var body = new FormData(); body.append('name', addName.value); body.append('phone', addPhone.value); body.append('_token', token);
        var btn = document.getElementById('sup-add-save'); btn.disabled = true;
        fetch(@json(url('/suppliers/quick')), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }, function () { return { ok: false, status: r.status, d: {} }; }); })
            .then(function (x) {
                btn.disabled = false;
                if (x.ok) {
                    SUPS.push(x.d); SUPS.sort(function (a, b) { return a.name.localeCompare(b.name); });
                    addName.value = ''; addPhone.value = ''; showAdd(false); pick(x.d);
                    info.insertAdjacentHTML('afterbegin', '<div class="text-success fw-bold">New supplier saved — ' + esc(x.d.code) + '</div>');
                    var b = document.getElementById('branch_id'); (b && !b.value ? b : document.getElementById('ea-search') || q).focus();
                    return;
                }
                var errs = (x.d && x.d.errors) || {};
                addMsg.textContent = (errs.name || errs.phone || [x.status === 419 ? 'Your login has expired — log in again in another tab, then save.' : (x.d.message || 'Could not save — check the internet and try again.')])[0];
                addMsg.hidden = false;
            }, function () { btn.disabled = false; addMsg.textContent = 'Could not save — check the internet and try again.'; addMsg.hidden = false; });
    }
    newBtn.addEventListener('click', function () { showAdd(add.hidden); });
    document.getElementById('sup-add-cancel').addEventListener('click', function () { showAdd(false); q.focus(); });
    document.getElementById('sup-add-save').addEventListener('click', saveNew);
    [addName, addPhone].forEach(function (el) {
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); if (el === addName && !addPhone.value) addPhone.focus(); else saveNew(); }
            if (e.key === 'Escape') { e.preventDefault(); showAdd(false); q.focus(); }
        });
    });

    window.__supplierBox = { pick: function (id) { pick(byId(id)); }, clear: function () { q.value = ''; clearPick(); }, current: function () { return byId(sel.value) || null; } };
    drawInfo();
})();
</script>
@endpush
