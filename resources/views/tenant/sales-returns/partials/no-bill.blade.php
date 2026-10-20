{{-- RETURN-NO-BILL-1: a return without a bill. Items are searched here (the screen's own JSON), priced at today's price
     at most, put back into their batch; the reason is required. SalesReturnController::storeNoBill checks it all again. --}}
@php
    $nbOld = old('nb_cart');
    $nbMulti = $noBillBranches->count() > 1;
@endphp
<form method="POST" action="{{ url('/sales-returns') }}" id="nb-form" novalidate>
    @csrf
    <input type="hidden" name="mode" value="no_bill">
    {{-- UB-06: posted once --}}
    <input type="hidden" name="submission_key" value="{{ old('submission_key') ?: \App\Support\SubmissionKey::fresh() }}">
    @if(request('terminal_id'))
        <input type="hidden" name="terminal_id" value="{{ (int) request('terminal_id') }}">
    @endif
    <input type="hidden" name="nb_cart" id="nb-cart" value="{{ $nbOld }}">

    <div class="card mb-3 border-primary-subtle">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <strong>Items coming back</strong>
            <span class="small text-muted">Scan or type · <kbd>↑</kbd> <kbd>↓</kbd> <kbd>Enter</kbd> adds · numbers only in Qty</span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end mb-2">
                @if($nbMulti)
                    <div class="col-md-4">
                        <label for="nb-branch" class="form-label required">Branch</label>
                        <select id="nb-branch" name="branch_id" class="form-select">
                            @foreach($noBillBranches as $b)
                                <option value="{{ $b->id }}" @selected((int) $b->id === (int) $noBillBranchId)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" id="nb-branch" name="branch_id" value="{{ (int) $noBillBranchId }}">
                @endif
                <div class="{{ $nbMulti ? 'col-md-8' : 'col-md-12' }} position-relative">
                    <label for="nb-search" class="form-label">Item</label>
                    <input id="nb-search" class="form-control form-control-lg" autocomplete="off" role="combobox"
                           aria-expanded="false" aria-controls="nb-results" placeholder="Scan its barcode, or type its name or salt">
                    <ul id="nb-results" class="list-group position-absolute w-100 shadow-sm" style="z-index:20;display:none;max-height:320px;overflow:auto" role="listbox"></ul>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0" id="nb-table">
                    <caption class="visually-hidden">Items coming back without a bill</caption>
                    <thead class="table-light" style="white-space:normal">
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Comes back as</th>
                            <th scope="col" style="width:90px">Qty</th>
                            <th scope="col" style="width:120px">Price each</th>
                            <th scope="col">Batch</th>
                            <th scope="col">Back on shelf?</th>
                            <th scope="col" class="text-end">Refund</th>
                            <th scope="col"><span class="visually-hidden">Remove</span></th>
                        </tr>
                    </thead>
                    <tbody id="nb-body">
                        <tr id="nb-empty"><td colspan="8" class="text-muted">No items yet. Scan or search above.</td></tr>
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr><td colspan="6" class="text-end">Refund</td><td class="text-end" id="nb-total">0.00</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Refund</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="nb-method" class="form-label required">Refund method</label>
                @php $nbMethod = old('refund_method', 'cash'); @endphp
                <select id="nb-method" name="refund_method" class="form-select" required>
                    <option value="cash" @selected($nbMethod === 'cash')>Cash</option>
                    <option value="bank_transfer" @selected($nbMethod === 'bank_transfer')>Bank Transfer</option>
                    <option value="card" @selected($nbMethod === 'card')>Card</option>
                    <option value="other" @selected($nbMethod === 'other')>Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="nb-amount" class="form-label">Refund amount</label>
                <input id="nb-amount" name="refund_amount" class="form-control" readonly value="0.00">
            </div>
            <div class="col-md-12">
                <label for="nb-reason" class="form-label required">Reason</label>
                <textarea id="nb-reason" name="reason" rows="2" class="form-control" required maxlength="500"
                          placeholder="e.g. doctor changed the medicine, wrong strength">{{ old('reason') }}</textarea>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary" type="submit" id="nb-post">Post Return</button>
        <a href="{{ url('/sales-returns') }}" class="btn btn-light">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var lookupUrl = @json(url('/sales-returns/create'));
    var approvalByBranch = @json($noBillApproval);
    var form = document.getElementById('nb-form');
    var search = document.getElementById('nb-search');
    var results = document.getElementById('nb-results');
    var body = document.getElementById('nb-body');
    var branchEl = document.getElementById('nb-branch');
    var cartEl = document.getElementById('nb-cart');
    var rows = [];        // {item, unit (index), qty, price, batch ('' | id | 'new'), batchNo, expiry, restock}
    var found = [];
    var picked = -1;
    var timer = null;

    function money(n) { return (Math.round(n * 100) / 100).toFixed(2); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function lineAmount(r) {
        var sub = Math.round((parseFloat(r.qty) || 0) * (parseFloat(r.price) || 0) * 100) / 100;
        var tax = r.item.tax > 0 ? Math.round(sub * r.item.tax) / 100 : 0;
        return Math.round((sub + tax) * 100) / 100;
    }

    function save() {
        try { cartEl.value = JSON.stringify(rows); } catch (e) { cartEl.value = ''; }
    }

    function total() {
        var t = rows.reduce(function (s, r) { return s + lineAmount(r); }, 0);
        t = Math.round(t * 100) / 100;
        document.getElementById('nb-total').textContent = money(t);
        document.getElementById('nb-amount').value = money(t);
        save();
        return t;
    }

    function render(focusIndex, focusField) {
        body.innerHTML = '';
        if (!rows.length) {
            body.innerHTML = '<tr id="nb-empty"><td colspan="8" class="text-muted">No items yet. Scan or search above.</td></tr>';
            total();
            return;
        }
        rows.forEach(function (r, i) {
            var u = r.item.units[r.unit] || r.item.units[0];
            var tr = document.createElement('tr');
            var unitOpts = r.item.units.map(function (x, k) {
                return '<option value="' + k + '"' + (k === r.unit ? ' selected' : '') + '>' + esc(x.name) + ' · ' + money(x.price) + '</option>';
            }).join('');
            var batch = '<span class="text-muted small">Not kept in stock</span>';
            if (r.item.tracked) {
                var opts = '<option value="">' + (r.item.has_expiry ? 'Pick its batch' : 'No batch') + '</option>'
                    + r.item.batches.map(function (b) { return '<option value="' + b.id + '"' + (String(r.batch) === String(b.id) ? ' selected' : '') + '>' + esc(b.no) + (b.exp ? ' · exp ' + esc(b.exp) : '') + '</option>'; }).join('')
                    + '<option value="new"' + (r.batch === 'new' ? ' selected' : '') + '>New batch (from the strip)…</option>';
                batch = '<select class="form-select form-select-sm" data-f="batch" aria-label="Batch of ' + esc(r.item.name) + '">' + opts + '</select>'
                    + (r.batch === 'new'
                        ? '<div class="d-flex gap-1 mt-1"><input class="form-control form-control-sm" data-f="batchNo" placeholder="Batch no" value="' + esc(r.batchNo) + '" aria-label="Batch number">'
                          + '<input class="form-control form-control-sm" data-f="expiry" placeholder="MM/YY" style="max-width:80px" value="' + esc(r.expiry) + '" aria-label="Expiry, month and year"></div>'
                        : '');
            }
            var shelf = r.item.tracked
                ? '<select class="form-select form-select-sm" data-f="restock" aria-label="Does ' + esc(r.item.name) + ' go back on the shelf?">'
                    + '<option value="1"' + (r.restock ? ' selected' : '') + '>Back on shelf</option><option value="0"' + (!r.restock ? ' selected' : '') + '>Thrown away</option></select>'
                : '<span class="text-muted small">—</span>';
            tr.innerHTML =
                '<td><div class="fw-semibold">' + esc(r.item.name) + '</div>'
                    + '<input type="hidden" name="lines[' + i + '][product_id]" value="' + r.item.product_id + '">'
                    + (r.item.variant_id ? '<input type="hidden" name="lines[' + i + '][product_variant_id]" value="' + r.item.variant_id + '">' : '')
                    + (u.id ? '<input type="hidden" name="lines[' + i + '][pack_unit_id]" value="' + u.id + '">' : '')
                    + (r.item.tracked ? '<input type="hidden" name="lines[' + i + '][restock]" value="' + (r.restock ? 1 : 0) + '">' : '')
                    + (r.batch && r.batch !== 'new' ? '<input type="hidden" name="lines[' + i + '][inventory_batch_id]" value="' + esc(r.batch) + '">' : '')
                    + (r.batch === 'new' ? '<input type="hidden" name="lines[' + i + '][batch_no]" value="' + esc(r.batchNo) + '"><input type="hidden" name="lines[' + i + '][expiry]" value="' + esc(r.expiry) + '">' : '')
                + '</td>'
                + '<td style="min-width:150px"><select class="form-select form-select-sm" data-f="unit" aria-label="' + esc(r.item.name) + ' comes back as">' + unitOpts + '</select></td>'
                + '<td style="min-width:80px"><input class="form-control form-control-sm text-end" data-f="qty" inputmode="' + (r.item.loose && !u.id ? 'decimal' : 'numeric') + '" name="lines[' + i + '][quantity]" value="' + esc(r.qty) + '" aria-label="How many"></td>'
                + '<td style="min-width:120px"><input class="form-control form-control-sm text-end" data-f="price" inputmode="decimal" name="lines[' + i + '][unit_price]" value="' + esc(r.price) + '" aria-label="Price each">'
                    + '<div class="form-text mt-0">today ' + money(u.price) + ' (most)</div></td>'
                + '<td style="min-width:210px">' + batch + '</td>'
                + '<td style="min-width:165px">' + shelf + '</td>'
                + '<td class="text-end">' + money(lineAmount(r)) + '</td>'
                + '<td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-f="remove" aria-label="Remove ' + esc(r.item.name) + '"><i class="ti ti-x"></i></button></td>';
            tr.querySelectorAll('[data-f]').forEach(function (el) {
                var f = el.dataset.f;
                if (f === 'remove') { el.addEventListener('click', function () { rows.splice(i, 1); render(); search.focus(); }); return; }
                var ev = el.tagName === 'SELECT' ? 'change' : 'input';
                el.addEventListener(ev, function () {
                    if (f === 'qty') {
                        var dec = r.item.loose && !(r.item.units[r.unit] || {}).id;
                        var clean = el.value.replace(dec ? /[^0-9.]/g : /[^0-9]/g, '');
                        if (clean !== el.value) { el.value = clean; }
                        r.qty = clean;
                    } else if (f === 'price') {
                        var p = el.value.replace(/[^0-9.]/g, '');
                        if (p !== el.value) { el.value = p; }
                        var cap = (r.item.units[r.unit] || r.item.units[0]).price;
                        if ((parseFloat(p) || 0) > cap) { el.value = money(cap); p = el.value; el.classList.add('is-invalid'); setTimeout(function () { el.classList.remove('is-invalid'); }, 1200); }
                        r.price = p;
                    } else if (f === 'unit') {
                        r.unit = parseInt(el.value, 10) || 0; r.price = money(r.item.units[r.unit].price); render(i, 'qty'); return;
                    } else if (f === 'batch') {
                        r.batch = el.value; render(i, el.value === 'new' ? 'batchNo' : 'qty'); return;
                    } else if (f === 'restock') {
                        r.restock = el.value === '1'; render(i, 'restock'); return;
                    } else {
                        r[f] = el.value;
                    }
                    // amounts and the hidden copies follow without losing the cursor
                    tr.children[6].textContent = money(lineAmount(r));
                    var hb = tr.querySelector('input[name$="[batch_no]"]'); if (hb && f === 'batchNo') { hb.value = r.batchNo; }
                    var he = tr.querySelector('input[name$="[expiry]"]'); if (he && f === 'expiry') { he.value = r.expiry; }
                    total();
                });
                if (el.tagName === 'INPUT') {
                    el.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') { e.preventDefault(); search.focus(); }
                    });
                }
            });
            body.appendChild(tr);
            if (i === focusIndex) {
                var target = tr.querySelector('[data-f="' + (focusField || 'qty') + '"]');
                if (target) { setTimeout(function () { target.focus(); if (target.select) { target.select(); } }, 0); }
            }
        });
        total();
    }

    function add(item) {
        var at = rows.findIndex(function (r) { return r.item.product_id === item.product_id && r.item.variant_id === item.variant_id; });
        if (at === -1) {
            // the smallest pack first (a Strip, not the Box or a loose Tablet), as the counter does
            var unit = 0;
            item.units.forEach(function (u, k) { if (u.factor > 1 && (unit === 0 || u.factor < item.units[unit].factor)) { unit = k; } });
            // UB-04: a medicine (an item with an expiry) back without a bill starts as "Thrown away" — the cashier
            // puts it back on the shelf only after checking it.
            rows.push({ item: item, unit: unit, qty: '1', price: money(item.units[unit].price), batch: '', batchNo: '', expiry: '', restock: !!item.shelf && item.tracked && !item.has_expiry });
            at = rows.length - 1;
        }
        hide();
        search.value = '';
        render(at, 'qty');
    }

    function hide() { results.style.display = 'none'; search.setAttribute('aria-expanded', 'false'); picked = -1; }

    function show() {
        results.innerHTML = '';
        if (!found.length) {
            results.innerHTML = '<li class="list-group-item text-muted small">Nothing found. Try the name, salt or barcode.</li>';
        }
        found.forEach(function (it, k) {
            var li = document.createElement('li');
            li.className = 'list-group-item list-group-item-action' + (k === picked ? ' active' : '');
            li.setAttribute('role', 'option');
            li.innerHTML = '<b>' + esc(it.name) + '</b> <span class="small ' + (k === picked ? '' : 'text-muted') + '">' + it.units.map(function (u) { return esc(u.name) + ' ' + money(u.price); }).join(' · ') + '</span>';
            li.addEventListener('mousedown', function (e) { e.preventDefault(); add(it); });
            results.appendChild(li);
        });
        results.style.display = 'block';
        search.setAttribute('aria-expanded', 'true');
    }

    function lookup(q) {
        var url = lookupUrl + '?mode=no_bill&branch_id=' + encodeURIComponent(branchEl.value) + '&lookup=' + encodeURIComponent(q);
        return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { items: [] }; })
            .then(function (d) { found = d.items || []; picked = found.length ? 0 : -1; return found; });
    }

    search.addEventListener('input', function () {
        clearTimeout(timer);
        var q = search.value.trim();
        if (!q) { hide(); return; }
        timer = setTimeout(function () { lookup(q).then(show); }, 180);
    });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!found.length) { return; }
            e.preventDefault();
            picked = (picked + (e.key === 'ArrowDown' ? 1 : -1) + found.length) % found.length;
            show();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var q = search.value.trim();
            if (!q) { return; }
            clearTimeout(timer);
            // a scanner types fast and presses Enter: look it up now, add the first match
            (results.style.display === 'block' && found.length ? Promise.resolve(found) : lookup(q)).then(function (list) {
                if (list.length) { add(list[picked >= 0 ? picked : 0]); } else { show(); }
            });
        } else if (e.key === 'Escape') {
            search.value = ''; hide();
        }
    });
    search.addEventListener('blur', function () { setTimeout(hide, 150); });
    if (branchEl.tagName === 'SELECT') {
        branchEl.addEventListener('change', function () { rows = []; render(); search.focus(); });
    }

    // Back after an error: the rows the cashier had come back as they were.
    try { var restored = cartEl.value ? JSON.parse(cartEl.value) : []; if (Array.isArray(restored)) { rows = restored; } } catch (e) { rows = []; }
    render();
    search.focus();
    // Typed (or scanned) before this script was ready: search it now, the text is not lost.
    if (search.value.trim()) { search.dispatchEvent(new Event('input')); }

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed === '1') { return; }
        e.preventDefault();
        var t = total();
        var problem = null;
        if (!rows.length) { problem = 'Add the item that is coming back.'; }
        rows.forEach(function (r) {
            if (problem) { return; }
            if (!(parseFloat(r.qty) > 0)) { problem = r.item.name + ': enter how many.'; }
            else if (r.item.tracked && r.restock && r.item.has_expiry && (!r.batch || (r.batch === 'new' && (!r.batchNo || !r.expiry)))) {
                problem = r.item.name + ' has an expiry: pick its batch, or type the batch and expiry printed on it.';
            }
        });
        if (!problem && !document.getElementById('nb-reason').value.trim()) { problem = 'Write why the item is coming back.'; }
        if (problem) {
            if (window.Swal) { Swal.fire({ icon: 'warning', title: 'Not yet', text: problem, confirmButtonText: 'OK (Enter)' }); } else { alert(problem); }
            return;
        }
        var needsPin = !!approvalByBranch[String(branchEl.value)];
        var post = function (approvalId) {
            if (approvalId) {
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'manager_approval_id'; h.value = approvalId; form.appendChild(h);
            }
            form.dataset.confirmed = '1';
            form.submit();
        };
        Swal.fire({
            title: 'Post this return without a bill?',
            html: 'Refund: <strong>' + money(t) + '</strong>', icon: 'question',
            showCancelButton: true, confirmButtonText: needsPin ? 'Next: manager PIN' : 'Post Return',
        }).then(function (res) {
            if (!res.isConfirmed) { return; }
            if (!needsPin) { post(null); return; }
            Swal.fire({
                title: 'Manager approval',
                html: 'This branch needs a manager to approve a return.<br>Refund: <strong>' + money(t) + '</strong>'
                    + '<input id="nb-pin" type="password" inputmode="numeric" autocomplete="off" class="swal2-input" placeholder="Manager PIN">',
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Approve & post', focusConfirm: false,
                didOpen: function () { document.getElementById('nb-pin').focus(); },
                preConfirm: function () {
                    var pin = document.getElementById('nb-pin').value;
                    if (!pin) { Swal.showValidationMessage('Enter the manager PIN'); return false; }
                    return fetch(@json(url('/api/manager-approvals/verify')), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
                        body: JSON.stringify({ pin: pin, action_type: 'sales_return', payload: {
                            sales_order_id: null, branch_id: parseInt(branchEl.value, 10),
                            refund_method: document.getElementById('nb-method').value, refund_amount: Math.round(t * 100) / 100,
                        } }),
                    }).then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.message || 'Approval failed'); } return d; }); })
                      .catch(function (err) { Swal.showValidationMessage(err.message || 'Approval failed'); return false; });
                },
            }).then(function (r2) { if (r2.isConfirmed && r2.value && r2.value.approval_id) { post(r2.value.approval_id); } });
        });
    });
})();
</script>
@endpush
