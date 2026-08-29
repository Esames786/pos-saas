@extends('layouts.app')
@section('title', 'Table Board')

@push('styles')
<style>
.floor-panel { margin-bottom: 2rem; }
.floor-title { font-weight: 600; font-size: 1rem; border-bottom: 2px solid #dee2e6; padding-bottom: .5rem; margin-bottom: 1rem; }
.table-grid { display: flex; flex-wrap: wrap; gap: 1rem; }
.table-card { width: 170px; border: 1px solid #dee2e6; border-radius: .5rem; padding: .75rem; background: #fff; border-left: 5px solid #6c757d; }
.table-card.available { border-left-color: #198754; }
.table-card.occupied { border-left-color: #dc3545; }
.table-card.bill_requested { border-left-color: #fd7e14; }
.table-card.reserved { border-left-color: #a855f7; }
.table-card.cleaning { border-left-color: #adb5bd; }
.table-card.inactive { border-left-color: #343a40; opacity: .6; }
.table-card .t-no { font-size: 1.25rem; font-weight: 700; }
.table-card .t-status { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #6c757d; }
</style>
@endpush

@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header mb-3">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Table Board</h3></div>
                </div>
            </div>

            @if(session('status'))
                <div class="alert alert-success alert-dismissible">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Branch filter --}}
            <form method="GET" action="{{ url('/restaurant/board') }}" class="mb-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <label class="form-label mb-0 fw-medium">Branch:</label>
                    <select name="branch_id" class="form-select w-auto" onchange="this.form.submit()">
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected($b->id == $selectedBranchId)>{{ $b->name }}</option>
                        @endforeach
                    </select>

                    {{-- SHIFT-POS-INTEGRATION-CLOSURE-1: a table binds to THIS terminal's open shift. --}}
                    <label class="form-label mb-0 fw-medium ms-3">Terminal:</label>
                    <select id="board-terminal" class="form-select w-auto" data-branch="{{ $selectedBranchId }}">
                        <option value="">— Select terminal —</option>
                        @foreach($terminals as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if($terminals->isEmpty())
                    <div class="small text-warning-emphasis mt-1"><i class="ti ti-alert-triangle me-1"></i>No active terminals in this branch — open a terminal/shift before opening tables.</div>
                @endif
            </form>

            @push('scripts')
            <script>
            (function () {
                // Remember the board terminal per branch, and stamp it onto every Open-Table form so
                // the session binds to the exact terminal shift (server enforces this too).
                var sel = document.getElementById('board-terminal');
                if (!sel) return;
                var key = 'board_terminal_' + (sel.getAttribute('data-branch') || '');
                try { var saved = localStorage.getItem(key); if (saved) sel.value = saved; } catch (e) {}
                sel.addEventListener('change', function () { try { localStorage.setItem(key, sel.value); } catch (e) {} });

                document.querySelectorAll('form[action$="/open"]').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!sel.value) {
                            event.preventDefault();
                            alert('Select a terminal (with an open shift) before opening a table.');
                            return;
                        }
                        var hidden = form.querySelector('input[name="terminal_id"]');
                        if (!hidden) {
                            hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'terminal_id';
                            form.appendChild(hidden);
                        }
                        hidden.value = sel.value;
                    });
                });
            })();
            </script>
            @endpush

            @forelse($floors as $floor)
            <div class="floor-panel">
                <div class="floor-title">{{ $floor->name }}</div>
                <div class="table-grid">
                    @forelse($floor->tables as $table)
                    @php $session = $table->openSession; @endphp
                    <div class="table-card {{ $table->status }}">
                        <div class="t-no">{{ $table->table_no }}</div>
                        @if($table->name)
                            <div class="small text-muted">{{ $table->name }}</div>
                        @endif
                        <div class="t-status">{{ str_replace('_', ' ', $table->status) }}</div>

                        @if($session)
                            <div class="mt-2 small">
                                <div><i class="ti ti-users me-1"></i>{{ $session->guest_count }} guests</div>
                                @if($session->waiter)
                                    <div><i class="ti ti-user me-1"></i>{{ $session->waiter->name }}</div>
                                @endif
                                <div class="text-muted">{{ $session->session_no }}</div>
                                <div class="text-muted">{{ $session->salesOrders->count() }} order(s)</div>
                            </div>
                            <div class="mt-2 d-flex flex-column gap-1">
                                @can('tenant.restaurant.table-sessions.show')
                                <a href="{{ url('/restaurant/table-sessions/' . $session->id) }}"
                                   class="btn btn-sm btn-outline-primary">View Orders</a>
                                @endcan
                                @can('tenant.restaurant.table-sessions.bill-preview')
                                <a href="{{ url('/restaurant/table-sessions/' . $session->id . '/bill-preview') }}"
                                   class="btn btn-sm btn-dark">Bill Preview</a>
                                @endcan
                                @if($session->status === 'open')
                                    @can('tenant.restaurant.table-sessions.bill-requested')
                                    <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/bill-requested') }}">
                                        @csrf
                                        <button class="btn btn-sm btn-warning w-100"
                                                title="Signal that the guest wants their bill. Marks this table as 'Bill Requested' so the cashier knows to prepare and close it — it does not charge anything.">Request Bill</button>
                                    </form>
                                    @endcan
                                @endif
                                @can('tenant.restaurant.table-sessions.close')
                                @php $__heldOrders = $session->salesOrders->whereIn('status', ['held', 'draft']); @endphp
                                @if($__heldOrders->isNotEmpty())
                                    <div class="small text-danger">{{ $__heldOrders->count() }} unpaid order(s) — pay or cancel before closing.</div>
                                    @foreach($__heldOrders as $__ho)
                                    <a href="{{ url('/pos') . '?held_sale_id=' . $__ho->id . '&table_session_id=' . $session->id . '&mode=dine_in&branch_id=' . $selectedBranchId }}"
                                       class="btn btn-sm btn-warning w-100"><i class="ti ti-cash me-1"></i>Recall &amp; Pay {{ $__ho->sale_no }}</a>
                                    @endforeach
                                @endif
                                <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/close') }}">
                                    @csrf
                                    <input type="hidden" name="status" value="closed">
                                    <button class="btn btn-sm btn-success w-100" @if($__heldOrders->isNotEmpty()) disabled @endif>Close (Paid)</button>
                                </form>
                                <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/close') }}">
                                    @csrf
                                    <input type="hidden" name="status" value="cancelled">
                                    <button class="btn btn-sm btn-outline-danger w-100"
                                            onclick="return confirm('Cancel this session?')">Cancel</button>
                                </form>
                                @endcan
                                @can('tenant.restaurant.table-sessions.move')
                                <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/move') }}" class="w-100 mt-1">
                                    @csrf
                                    <div class="input-group input-group-sm">
                                        <select name="target_table_id" class="form-select" required>
                                            <option value="">Move to…</option>
                                            @foreach($floor->tables->where('status', 'available') as $targetTable)
                                                @if($targetTable->id !== $table->id)
                                                    <option value="{{ $targetTable->id }}">{{ $targetTable->table_no }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <button class="btn btn-outline-secondary" type="submit" onclick="return confirm('Move table session?')">Go</button>
                                    </div>
                                </form>
                                @endcan
                            </div>
                        @elseif($table->status === 'reserved')
                            {{-- TABLE-RESERVATION-1: reserved (no session yet) — who + when + actions. --}}
                            <div class="mt-2 small">
                                <div><strong>{{ $table->reserved_name ?: 'Reserved' }}</strong></div>
                                @if($table->reserved_phone)<div class="text-muted">{{ $table->reserved_phone }}</div>@endif
                                @if($table->reserved_for)<div class="text-muted"><i class="ti ti-clock me-1"></i>{{ $table->reserved_for->format('d-M h:i A') }}</div>@endif
                                @if($table->reservation_note)<div class="text-muted text-truncate" title="{{ $table->reservation_note }}">{{ $table->reservation_note }}</div>@endif
                            </div>
                            <div class="mt-2 d-flex flex-column gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-reservation-details="{{ $table->id }}">Details</button>
                                @can('tenant.restaurant.table-sessions.open')
                                <button class="btn btn-sm btn-success"
                                        data-bs-toggle="modal" data-bs-target="#openModal-{{ $table->id }}">Open Session</button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-table-unreserve="{{ $table->id }}">Cancel Reservation</button>
                                @endcan
                            </div>
                        @elseif($table->status === 'available')
                            @can('tenant.restaurant.table-sessions.open')
                            <div class="mt-2 d-flex flex-column gap-1">
                                <button class="btn btn-sm btn-primary w-100"
                                        data-bs-toggle="modal"
                                        data-bs-target="#openModal-{{ $table->id }}">
                                    Open Session
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                        data-table-reserve="{{ $table->id }}" data-table-no="{{ $table->table_no }}">
                                    <i class="ti ti-calendar-plus me-1"></i>Reserve
                                </button>
                            </div>
                            @endcan
                        @endif
                    </div>
                    @empty
                    <p class="text-muted small">No tables on this floor.</p>
                    @endforelse
                </div>
            </div>
            @empty
            <div class="alert alert-info">No active floors configured for this branch.</div>
            @endforelse
        </div>
    </div>
</div>

{{-- Open session modals --}}
@foreach($floors as $floor)
    @foreach($floor->tables as $table)
        @if(!$table->openSession && in_array($table->status, ['available', 'reserved'], true))
        <div class="modal fade" id="openModal-{{ $table->id }}" tabindex="-1">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Open Table {{ $table->table_no }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="{{ url('/restaurant/tables/' . $table->id . '/open') }}">
                        @csrf
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Guests <span class="text-danger">*</span></label>
                                <input type="number" name="guest_count" class="form-control"
                                       value="2" min="1" max="100" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Waiter</label>
                                <select name="restaurant_waiter_id" class="form-select">
                                    <option value="">— None —</option>
                                    @foreach($waiters as $waiter)
                                        <option value="{{ $waiter->id }}">{{ $waiter->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <input type="text" name="notes" class="form-control" maxlength="255">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Open Session</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif
    @endforeach
@endforeach

{{-- TABLE-RESERVATION-1 — reserve / details modals (self-contained, mirror the POS board). --}}
<div class="modal fade" id="reserveTableModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0"><i class="ti ti-calendar-plus me-1"></i>Reserve Table <span id="reserve-table-no"></span></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="reserve-table-id">
                <input type="hidden" id="reserve-customer-id">
                <div id="reserve-toast" class="alert d-none py-2 small mb-3" role="alert"></div>
                <label class="form-label small mb-1">Customer <span class="text-muted">(search the book, or just type a name below)</span></label>
                <input type="text" class="form-control form-control-sm mb-1" id="reserve-customer-search" placeholder="Search phone or name…" autocomplete="off">
                <div id="reserve-customer-suggest" class="list-group mb-2 d-none" style="max-height:180px;overflow:auto"></div>
                <div id="reserve-customer-chip" class="mb-2 d-none">
                    <span class="badge bg-primary-subtle text-primary-emphasis border">Attached: <span id="reserve-customer-name"></span> <a href="#" class="text-danger ms-1" id="reserve-customer-clear">&times;</a></span>
                </div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small mb-1">Name</label><input type="text" class="form-control form-control-sm" id="reserve-name"></div>
                    <div class="col-6"><label class="form-label small mb-1">Phone</label><input type="text" class="form-control form-control-sm" id="reserve-phone"></div>
                </div>
                <label class="form-label small mb-1 mt-2">Reserved for (date &amp; time)</label>
                <input type="datetime-local" class="form-control form-control-sm" id="reserve-for">
                <label class="form-label small mb-1 mt-2">Note</label>
                <textarea class="form-control form-control-sm" id="reserve-note" rows="2" placeholder="e.g. 6 guests, window table"></textarea>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="reserve-save-btn"><i class="ti ti-calendar-check me-1"></i>Reserve Table</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="reservationDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0"><i class="ti ti-calendar me-1"></i>Reservation</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="reservation-details-body"></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var TABLES = '{{ url('/restaurant/tables') }}';
    var CUST   = '{{ url('/ajax/customers') }}';
    var CSRF   = '{{ csrf_token() }}';
    function $id(id) { return document.getElementById(id); }
    function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

    document.addEventListener('click', function (e) {
        var r = e.target.closest('[data-table-reserve]');
        if (r) { openReserveModal(r.dataset.tableReserve, r.dataset.tableNo); return; }
        var u = e.target.closest('[data-table-unreserve]');
        if (u) { cancelReservation(u.dataset.tableUnreserve); return; }
        var d = e.target.closest('[data-reservation-details]');
        if (d) { showReservationDetails(d.dataset.reservationDetails); return; }
    });

    function openReserveModal(tableId, tableNo) {
        $id('reserve-table-id').value = tableId;
        $id('reserve-table-no').textContent = tableNo || '';
        ['reserve-customer-search', 'reserve-name', 'reserve-phone', 'reserve-for', 'reserve-note', 'reserve-customer-id'].forEach(function (id) { var el = $id(id); if (el) el.value = ''; });
        $id('reserve-customer-chip').classList.add('d-none');
        $id('reserve-customer-suggest').classList.add('d-none');
        $id('reserve-toast').classList.add('d-none');
        bootstrap.Modal.getOrCreateInstance($id('reserveTableModal')).show();
    }

    function cancelReservation(tableId) {
        if (!confirm('Cancel this reservation? The table becomes available.')) return;
        fetch(TABLES + '/' + tableId + '/unreserve', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' } })
            .then(function () { location.reload(); }).catch(function () {});
    }

    function showReservationDetails(tableId) {
        fetch(TABLES + '/' + tableId + '/reservation', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) return;
                var v = res.reservation, rows = '';
                var add = function (l, x) { if (x) rows += '<div class="mb-1"><span class="text-muted small">' + l + ':</span> ' + esc(x) + '</div>'; };
                add('Name', v.name); add('Phone', v.phone); add('Reserved for', v.reserved_for); add('Note', v.note); add('Reserved by', v.reserved_by); add('Marked at', v.reserved_at);
                $id('reservation-details-body').innerHTML = rows || '<div class="text-muted small">No details.</div>';
                bootstrap.Modal.getOrCreateInstance($id('reservationDetailsModal')).show();
            }).catch(function () {});
    }

    var search = $id('reserve-customer-search'), suggest = $id('reserve-customer-suggest');
    if (search) {
        var t;
        search.addEventListener('input', function () {
            clearTimeout(t);
            var q = search.value.trim();
            if (q.length < 2) { suggest.classList.add('d-none'); return; }
            t = setTimeout(function () {
                fetch(CUST + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        suggest.innerHTML = '';
                        (data.customers || []).slice(0, 8).forEach(function (c) {
                            var a = document.createElement('a');
                            a.href = '#'; a.className = 'list-group-item list-group-item-action py-1 small';
                            a.textContent = (c.name || '') + ' · ' + (c.phone || '');
                            a.addEventListener('click', function (ev) {
                                ev.preventDefault();
                                $id('reserve-customer-id').value = c.id;
                                $id('reserve-customer-name').textContent = (c.name || '') + ' · ' + (c.phone || '');
                                $id('reserve-customer-chip').classList.remove('d-none');
                                if (!$id('reserve-name').value) $id('reserve-name').value = c.name || '';
                                if (!$id('reserve-phone').value) $id('reserve-phone').value = c.phone || '';
                                suggest.classList.add('d-none'); search.value = '';
                            });
                            suggest.appendChild(a);
                        });
                        suggest.classList.toggle('d-none', !suggest.children.length);
                    }).catch(function () {});
            }, 250);
        });
        $id('reserve-customer-clear') && $id('reserve-customer-clear').addEventListener('click', function (e) { e.preventDefault(); $id('reserve-customer-id').value = ''; $id('reserve-customer-chip').classList.add('d-none'); });
        $id('reserve-save-btn') && $id('reserve-save-btn').addEventListener('click', function () {
            var fd = new FormData();
            fd.append('reserved_customer_id', $id('reserve-customer-id').value || '');
            fd.append('reserved_name', $id('reserve-name').value || '');
            fd.append('reserved_phone', $id('reserve-phone').value || '');
            fd.append('reserved_for', $id('reserve-for').value || '');
            fd.append('reservation_note', $id('reserve-note').value || '');
            fetch(TABLES + '/' + $id('reserve-table-id').value + '/reserve', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' }, body: fd })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    if (!res.ok) { var el = $id('reserve-toast'); el.className = 'alert alert-danger py-2 small mb-3'; el.textContent = res.d.message || 'Could not reserve the table.'; el.classList.remove('d-none'); return; }
                    bootstrap.Modal.getOrCreateInstance($id('reserveTableModal')).hide();
                    location.reload();
                }).catch(function () {});
        });
    }
})();
</script>
@endpush
@endsection
