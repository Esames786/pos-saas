@extends('layouts.app')

@section('title', 'Inventory Batches')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Inventory Batches</h1>
        <p class="fw-medium">Batch and lot tracking by product.</p>
    </div>
    @if($manage ?? false)
        <button type="button" class="btn btn-dark" id="bt-new-btn" aria-controls="bt-new" aria-expanded="false">+ New batch <span class="fw-normal" lang="ur">نیا بیچ</span></button>
    @endif
</div>
@if($manage ?? false)
    {{-- PURCHASING-V2-2: a batch made before the goods come — the GRN's batch box offers it with its expiry. --}}
    <div class="card mb-3 border-2" id="bt-new" style="border-color:#B8902E" hidden>
        <div class="card-body">
            <div class="fw-bold mb-1">New batch</div>
            <p class="text-muted small mb-3">Write the batch and expiry printed on the box. Nothing goes into stock now — the GRN fills it.</p>
            <div class="row g-3 align-items-end">
                <div class="col-md-4 position-relative">
                    <label class="form-label" for="bt-item">Item</label>
                    <input id="bt-item" class="form-control" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="bt-item-list" placeholder="Type the item name">
                    <div id="bt-item-list" class="list-group position-absolute w-100 shadow" style="z-index:30" role="listbox" hidden></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="bt-new-branch">Branch</label>
                    <select id="bt-new-branch" class="form-select">
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label" for="bt-no">Batch no.</label><input id="bt-no" class="form-control" maxlength="100" autocomplete="off" placeholder="PN2410-07"></div>
                <div class="col-md-1"><label class="form-label" for="bt-exp">Expiry</label><input id="bt-exp" class="form-control" maxlength="7" autocomplete="off" placeholder="09/28"></div>
                <div class="col-md-2 d-flex gap-2"><button type="button" class="btn btn-dark" id="bt-save">Save batch</button><button type="button" class="btn btn-light" id="bt-cancel">Cancel</button></div>
            </div>
            <div class="text-danger fw-bold small mt-2" id="bt-msg" role="alert" hidden></div>
        </div>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/inventory/batches') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="bt-branch" class="form-label">Branch</label>
                <select id="bt-branch" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="bt-expiry" class="form-label">Expiry Status</label>
                <select id="bt-expiry" name="expiry_status" class="form-select">
                    <option value="">All</option>
                    <option value="expiring" @selected(request('expiry_status') === 'expiring')>Expiring (next 30 days)</option>
                    <option value="expired"  @selected(request('expiry_status') === 'expired')>Expired</option>
                </select>
            </div>
            @if($manage ?? false)
                <div class="col-md-3">
                    <label for="bt-q" class="form-label">Item or batch</label>
                    <input id="bt-q" name="q" class="form-control" value="{{ request('q') }}" placeholder="e.g. panadol or PN2410">
                </div>
            @endif
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/inventory/batches') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Inventory batches list</caption>
            <thead>
                <tr>
                    <th scope="col">Branch</th>
                    <th scope="col">Product</th>
                    <th scope="col">Batch No</th>
                    <th scope="col">Received</th>
                    <th scope="col">Expiry Date</th>
                    <th scope="col">Unit Cost</th>
                    <th scope="col">Status</th>
                    @if($manage ?? false)
                        <th scope="col">Stock left</th>
                        <th scope="col"><span class="visually-hidden">Change</span></th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse($batches as $batch)
                @php
                    $isExpired  = $batch->expiry_date && $batch->expiry_date->isPast();
                    $isExpiring = !$isExpired && $batch->expiry_date && $batch->expiry_date->diffInDays(now()) <= 30;
                @endphp
                <tr class="{{ $isExpired ? 'table-danger' : ($isExpiring ? 'table-warning' : '') }}" data-batch="{{ $batch->id }}">
                    <td>{{ $batch->branch?->name ?? '—' }}</td>
                    <td>
                        {{ $batch->product?->name }}
                        @if($batch->variant)
                            <br><small class="text-muted">{{ $batch->variant->name }}</small>
                        @endif
                    </td>
                    <td><code>{{ $batch->batch_no ?? '—' }}</code></td>
                    <td>{{ $batch->received_date?->format('d M Y') ?? '—' }}</td>
                    <td class="bt-exp-cell">
                        @if($batch->expiry_date)
                            {{ $batch->expiry_date->format('d M Y') }}
                            @if($isExpired)
                                <span class="badge bg-danger ms-1">Expired</span>
                            @elseif($isExpiring)
                                <span class="badge bg-warning text-dark ms-1">Expiring</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ number_format($batch->unit_cost, 4) }}</td>
                    <td>
                        @if($batch->status === 'active')
                            <span class="badge bg-success">Active</span>
                        @elseif($batch->status === 'expired')
                            <span class="badge bg-danger">Expired</span>
                        @else
                            <span class="badge bg-secondary">Closed</span>
                        @endif
                    </td>
                    @if($manage ?? false)
                        <td>{{ $stockWords[$batch->id] ?? '—' }}</td>
                        <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" data-exp-edit="{{ $batch->id }}" data-exp="{{ $batch->expiry_date?->format('m/y') }}" aria-label="Change the expiry of batch {{ $batch->batch_no }}">Change expiry</button></td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ ($manage ?? false) ? 9 : 7 }}" class="text-center text-muted py-4">No batches found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $batches->links() }}</div>
    </div>
</div>
@endsection

@if($manage ?? false)
@push('scripts')
<script>
(function () {
    var TOKEN = @json(csrf_token());
    var LOOKUP = @json(url('/ajax/products')), SAVE = @json(url('/inventory/batches')), EXP = @json(url('/inventory/batches/__ID__/expiry'));
    var $ = function (id) { return document.getElementById(id); };
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function post(url, data) {
        var body = new FormData(); Object.keys(data).forEach(function (k) { body.append(k, data[k]); }); body.append('_token', TOKEN);
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }, function () { return { ok: false, status: r.status, d: {} }; }); });
    }
    function firstError(x) { var e = (x.d && x.d.errors) || {}; var k = Object.keys(e)[0]; return k ? e[k][0] : (x.status === 419 ? 'Your login has expired — log in again.' : 'Could not save — check the internet and try again.'); }

    // ── + New batch ──
    var panel = $('bt-new'), btn = $('bt-new-btn'), item = $('bt-item'), list = $('bt-item-list'), msg = $('bt-msg'), picked = null, hits = [], timer = null;
    function show(v) { panel.hidden = !v; btn.setAttribute('aria-expanded', v ? 'true' : 'false'); msg.hidden = true; if (v) item.focus(); }
    btn.addEventListener('click', function () { show(panel.hidden); });
    $('bt-cancel').addEventListener('click', function () { show(false); });
    item.addEventListener('input', function () {
        picked = null; clearTimeout(timer);
        var q = item.value.trim(); if (!q) { list.hidden = true; return; }
        timer = setTimeout(function () {
            var u = new URL(LOOKUP, location.href); u.searchParams.set('q', q); u.searchParams.set('context', 'purchase'); u.searchParams.set('rich', '1');
            fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : { results: [] }; }).then(function (d) {
                    hits = (d.results || []).slice(0, 8);
                    list.innerHTML = hits.length ? hits.map(function (r, i) { return '<button type="button" class="list-group-item list-group-item-action" role="option" data-i="' + i + '">' + esc(r.name) + '</button>'; }).join('') : '<div class="list-group-item text-muted">No item matches.</div>';
                    list.hidden = false; item.setAttribute('aria-expanded', 'true');
                });
        }, 180);
    });
    list.addEventListener('mousedown', function (e) { var b = e.target.closest('[data-i]'); if (!b) return; e.preventDefault(); picked = hits[+b.dataset.i]; item.value = picked.name; list.hidden = true; item.setAttribute('aria-expanded', 'false'); $('bt-no').focus(); });
    item.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (hits[0] && !list.hidden) { picked = hits[0]; item.value = picked.name; list.hidden = true; $('bt-no').focus(); } } });
    $('bt-no').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('bt-exp').focus(); } });
    $('bt-exp').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('bt-save').click(); } });
    $('bt-save').addEventListener('click', function () {
        msg.hidden = true;
        if (!picked) { msg.textContent = 'Pick the item from the list.'; msg.hidden = false; item.focus(); return; }
        post(SAVE, { product_id: picked.id, branch_id: $('bt-new-branch').value, batch_no: $('bt-no').value, expiry: $('bt-exp').value }).then(function (x) {
            if (!x.ok) { msg.textContent = firstError(x); msg.hidden = false; return; }
            var tb = document.querySelector('table tbody'), empty = tb.querySelector('td[colspan]');
            if (empty) empty.closest('tr').remove();
            tb.insertAdjacentHTML('afterbegin', '<tr class="table-success" data-batch="' + x.d.id + '"><td>' + esc(x.d.branch) + '</td><td>' + esc(x.d.product) + '</td><td><code>' + esc(x.d.batch_no) + '</code></td><td>—</td><td class="bt-exp-cell">' + (x.d.expiry ? 'Exp ' + esc(x.d.expiry) : '—') + '</td><td>0.0000</td><td><span class="badge bg-success">Active</span></td><td>0</td>'
                + '<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" data-exp-edit="' + x.d.id + '" data-exp="' + esc(x.d.expiry || '') + '">Change expiry</button></td></tr>');
            item.value = ''; picked = null; $('bt-no').value = ''; $('bt-exp').value = '';
            msg.className = 'text-success fw-bold small mt-2'; msg.textContent = 'Batch ' + x.d.batch_no + ' saved. The GRN batch box offers it now.'; msg.hidden = false;
            setTimeout(function () { msg.className = 'text-danger fw-bold small mt-2'; }, 4000);
            item.focus();
        });
    });

    // ── Change expiry (a typing mistake, or the supplier's corrected date) ──
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-exp-edit]'); if (!b) return;
        var tr = b.closest('tr'), cell = tr.querySelector('.bt-exp-cell'), was = cell.innerHTML;
        cell.innerHTML = '<div class="d-flex gap-1 align-items-center"><input class="form-control form-control-sm" style="width:90px" maxlength="7" value="' + esc(b.dataset.exp || '') + '" aria-label="New expiry, month/year"><button type="button" class="btn btn-sm btn-dark">Save</button><button type="button" class="btn btn-sm btn-light">Cancel</button></div><div class="text-danger small fw-bold" role="alert"></div>';
        var inp = cell.querySelector('input'), save = cell.querySelectorAll('button')[0], cancel = cell.querySelectorAll('button')[1], err = cell.querySelector('[role="alert"]');
        inp.focus(); inp.select();
        cancel.addEventListener('click', function () { cell.innerHTML = was; });
        function go() {
            post(EXP.replace('__ID__', b.dataset.expEdit), { expiry: inp.value }).then(function (x) {
                if (!x.ok) { err.textContent = firstError(x); return; }
                b.dataset.exp = x.d.expiry; cell.innerHTML = esc(x.d.expiry_date) + ' <span class="badge bg-success ms-1">Changed</span>';
                tr.classList.remove('table-danger', 'table-warning');
            });
        }
        save.addEventListener('click', go);
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); go(); } if (ev.key === 'Escape') cell.innerHTML = was; });
    });
})();
</script>
@endpush
@endif
