@extends('layouts.app')

@section('title', 'Kitchen Display')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Kitchen Display</h1>
        <p class="text-muted mb-0">Live kitchen order board</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Branch</label>
                <select id="kds-branch" class="form-select form-select-sm">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Category / Station</label>
                <select id="kds-category" class="form-select form-select-sm">
                    <option value="">All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select id="kds-status" class="form-select form-select-sm">
                    <option value="all">All active</option>
                    <option value="pending">Pending</option>
                    <option value="preparing">Preparing</option>
                    <option value="ready">Ready</option>
                </select>
            </div>

            <div class="col-md-2">
                <button type="button" id="kds-refresh" class="btn btn-primary btn-sm w-100">Refresh</button>
            </div>

            <div class="col-md-2 text-end">
                <div class="small text-muted">Server time</div>
                <div id="kds-server-time" class="fw-semibold">—</div>
            </div>
        </div>
    </div>
</div>

<div id="kds-empty" class="alert alert-light border text-center d-none">
    No active kitchen orders.
</div>

<div id="kds-board"
     class="row g-3"
     data-orders-url="{{ url('/api/kitchen-display/orders') }}"
     data-line-status-url-template="{{ url('/api/kitchen-display/lines/__LINE_ID__/status') }}"
     data-order-status-url-template="{{ url('/api/kitchen-display/orders/__ORDER_ID__/status') }}">
</div>
@endsection

@push('scripts')
<script>
(function () {
    var board = document.getElementById('kds-board');
    if (!board) return;

    var empty = document.getElementById('kds-empty');
    var branch = document.getElementById('kds-branch');
    var category = document.getElementById('kds-category');
    var status = document.getElementById('kds-status');
    var refreshBtn = document.getElementById('kds-refresh');
    var serverTime = document.getElementById('kds-server-time');

    var knownPendingLineIds = new Set();
    var firstLoad = true;

    function csrfToken() {
        return '{{ csrf_token() }}';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function statusBadgeClass(s) {
        if (s === 'ready') return 'bg-success';
        if (s === 'preparing') return 'bg-warning text-dark';
        return 'bg-secondary';
    }

    function orderCardClass(order) {
        return order.is_late ? 'border-danger' : '';
    }

    function buildLine(line) {
        var qty = line.quantity;
        if (line.unit_code) qty += ' ' + line.unit_code;
        var modifiers = (line.modifiers || []).map(function (modifier) {
            return `<div class="small text-muted ps-2">+ ${escapeHtml(modifier.name)}</div>`;
        }).join('');

        return `
            <div class="border rounded p-2 mb-2 kds-line" data-line-id="${line.id}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">${escapeHtml(line.product_name)}</div>
                        ${line.variant_name ? `<div class="small text-muted">${escapeHtml(line.variant_name)}</div>` : ''}
                        ${modifiers}
                        <div class="small text-muted">
                            Qty: ${escapeHtml(qty)}${line.category ? ` · ${escapeHtml(line.category)}` : ''}
                        </div>
                        ${line.kitchen_note ? `<div class="small text-danger">Note: ${escapeHtml(line.kitchen_note)}</div>` : ''}
                    </div>
                    <span class="badge ${statusBadgeClass(line.status)}">${escapeHtml(line.status)}</span>
                </div>
                <div class="btn-group btn-group-sm mt-2 w-100" role="group">
                    <button type="button" class="btn btn-outline-warning kds-line-status" data-status="preparing">Start</button>
                    <button type="button" class="btn btn-outline-success kds-line-status" data-status="ready">Ready</button>
                    <button type="button" class="btn btn-outline-secondary kds-line-status" data-status="served">Served</button>
                </div>
            </div>
        `;
    }

    function buildOrder(order) {
        var tableLabel = order.table ? `Table: ${escapeHtml(order.table)}` : escapeHtml(order.order_type || '');
        var lateLabel = order.is_late ? `<span class="badge bg-danger ms-2">Late ${order.elapsed_minutes}m</span>` : '';

        return `
            <div class="col-xl-4 col-lg-6">
                <div class="card h-100 ${orderCardClass(order)}" data-order-id="${order.id}">
                    <div class="card-header">
                        <div class="d-flex justify-content-between gap-2">
                            <div>
                                <div class="fw-bold">${escapeHtml(order.sale_no || ('Order #' + order.id))}</div>
                                <div class="small text-muted">${tableLabel}</div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-info text-dark">${escapeHtml(order.status)}</span>
                                ${lateLabel}
                            </div>
                        </div>
                        <div class="small text-muted mt-1">
                            ${order.branch ? escapeHtml(order.branch) + ' · ' : ''}
                            ${order.waiter ? 'Waiter: ' + escapeHtml(order.waiter) + ' · ' : ''}
                            ${escapeHtml(order.created_at || '')}
                        </div>
                    </div>
                    <div class="card-body">
                        ${order.lines.map(buildLine).join('')}
                    </div>
                    <div class="card-footer">
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <button type="button" class="btn btn-warning kds-order-status" data-status="preparing">Start All</button>
                            <button type="button" class="btn btn-success kds-order-status" data-status="ready">Ready All</button>
                            <button type="button" class="btn btn-secondary kds-order-status" data-status="served">Served All</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function playNewOrderSound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            gain.gain.value = 0.05;
            osc.start();
            setTimeout(function () { osc.stop(); ctx.close(); }, 180);
        } catch (e) {}
    }

    function fetchOrders() {
        var url = new URL(board.dataset.ordersUrl, window.location.origin);
        if (branch.value) url.searchParams.set('branch_id', branch.value);
        if (category.value) url.searchParams.set('category_id', category.value);
        if (status.value) url.searchParams.set('status', status.value);

        return fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('Failed to load kitchen orders.'); return r.json(); })
            .then(function (payload) {
                serverTime.textContent = payload.server_time || '—';
                var orders = payload.orders || [];
                var pendingNow = new Set();

                orders.forEach(function (order) {
                    (order.lines || []).forEach(function (line) {
                        if (line.status === 'pending') pendingNow.add(line.id);
                    });
                });

                var hasNewPending = false;
                pendingNow.forEach(function (id) { if (!knownPendingLineIds.has(id)) hasNewPending = true; });
                knownPendingLineIds = pendingNow;

                if (!firstLoad && hasNewPending) playNewOrderSound();
                firstLoad = false;

                empty.classList.toggle('d-none', orders.length > 0);
                board.innerHTML = orders.map(buildOrder).join('');
            })
            .catch(function (e) { console.error(e); });
    }

    function postStatus(url, newStatus) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify({ status: newStatus })
        })
            .then(function (r) {
                if (!r.ok) {
                    return r.json().catch(function () { return {}; }).then(function (p) {
                        throw new Error(p.message || 'Status update failed.');
                    });
                }
                return r.json();
            })
            .then(fetchOrders)
            .catch(function (e) { alert(e.message || 'Status update failed.'); });
    }

    board.addEventListener('click', function (event) {
        var lineButton = event.target.closest('.kds-line-status');
        if (lineButton) {
            var line = lineButton.closest('.kds-line');
            var url = board.dataset.lineStatusUrlTemplate.replace('__LINE_ID__', line.dataset.lineId);
            postStatus(url, lineButton.dataset.status);
            return;
        }
        var orderButton = event.target.closest('.kds-order-status');
        if (orderButton) {
            var order = orderButton.closest('.card');
            var url = board.dataset.orderStatusUrlTemplate.replace('__ORDER_ID__', order.dataset.orderId);
            postStatus(url, orderButton.dataset.status);
        }
    });

    [branch, category, status].forEach(function (el) {
        if (el) el.addEventListener('change', fetchOrders);
    });
    if (refreshBtn) refreshBtn.addEventListener('click', fetchOrders);

    fetchOrders();
    setInterval(fetchOrders, 10000);
})();
</script>
@endpush
