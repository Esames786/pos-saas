@extends('layouts.app')
@section('title', 'Table Board')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Table Board</h3></div>
                </div>
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            @forelse($floors as $floor)
            <div class="mb-4">
                <h5 class="mb-3 text-muted">{{ $floor->name }}</h5>
                <div class="row g-3">
                    @forelse($floor->tables as $table)
                    @php
                        $session = $table->activeSession;
                        $statusColor = match($table->status) {
                            'available' => 'success',
                            'occupied'  => 'danger',
                            'reserved'  => 'warning',
                            'dirty'     => 'secondary',
                            default     => 'secondary',
                        };
                    @endphp
                    <div class="col-6 col-md-3 col-lg-2">
                        <div class="card border-{{ $statusColor }} h-100">
                            <div class="card-body text-center p-3">
                                <div class="fw-bold fs-4 mb-1">{{ $table->table_no }}</div>
                                <div class="text-muted small mb-2">Cap: {{ $table->capacity }}</div>
                                <span class="badge bg-{{ $statusColor }} mb-2">{{ ucfirst($table->status) }}</span>

                                @if($session)
                                    <div class="small text-muted mb-2">
                                        {{ $session->waiter->name ?? 'No waiter' }}<br>
                                        {{ $session->covers }} cover(s)
                                    </div>
                                    @can('tenant.restaurant-table-sessions.close')
                                    <form method="POST" action="{{ url('/restaurant/table-sessions/'.$session->id.'/close') }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-danger w-100">Close</button>
                                    </form>
                                    @endcan
                                    @can('tenant.restaurant-table-sessions.show')
                                    <a href="{{ url('/restaurant/table-sessions/'.$session->id) }}" class="btn btn-sm btn-outline-secondary w-100 mt-1">View</a>
                                    @endcan
                                @else
                                    @can('tenant.restaurant-table-sessions.store')
                                    <button class="btn btn-sm btn-outline-success w-100"
                                            data-bs-toggle="modal" data-bs-target="#openSessionModal"
                                            data-table-id="{{ $table->id }}"
                                            data-table-no="{{ $table->table_no }}">
                                        Open
                                    </button>
                                    @endcan
                                @endif
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="col"><p class="text-muted">No tables on this floor.</p></div>
                    @endforelse
                </div>
            </div>
            @empty
            <div class="alert alert-info">No floors configured. <a href="{{ url('/restaurant/floors/create') }}">Add a floor</a> to get started.</div>
            @endforelse
        </div>
    </div>
</div>

{{-- Open Session Modal --}}
<div class="modal fade" id="openSessionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ url('/restaurant/table-sessions') }}">
                @csrf
                <input type="hidden" name="restaurant_table_id" id="modalTableId">
                <div class="modal-header">
                    <h5 class="modal-title">Open Session — Table <span id="modalTableNo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Waiter</label>
                        <select name="restaurant_waiter_id" class="form-select">
                            <option value="">— No waiter —</option>
                            @foreach($waiters as $waiter)
                                <option value="{{ $waiter->id }}">{{ $waiter->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Covers</label>
                        <input type="number" name="covers" class="form-control" value="1" min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Open Session</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('openSessionModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('modalTableId').value = btn.dataset.tableId;
    document.getElementById('modalTableNo').textContent = btn.dataset.tableNo;
});
</script>
@endpush
