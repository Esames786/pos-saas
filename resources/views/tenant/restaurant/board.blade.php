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
.table-card.reserved { border-left-color: #0dcaf0; }
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
                </div>
            </form>

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
                                        <button class="btn btn-sm btn-warning w-100">Request Bill</button>
                                    </form>
                                    @endcan
                                @endif
                                @can('tenant.restaurant.table-sessions.close')
                                <form method="POST" action="{{ url('/restaurant/table-sessions/' . $session->id . '/close') }}">
                                    @csrf
                                    <input type="hidden" name="status" value="closed">
                                    <button class="btn btn-sm btn-success w-100">Close (Paid)</button>
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
                        @elseif($table->status === 'available')
                            @can('tenant.restaurant.table-sessions.open')
                            <button class="btn btn-sm btn-primary mt-2 w-100"
                                    data-bs-toggle="modal"
                                    data-bs-target="#openModal-{{ $table->id }}">
                                Open Session
                            </button>
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
        @if(!$table->openSession && $table->status === 'available')
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
@endsection
