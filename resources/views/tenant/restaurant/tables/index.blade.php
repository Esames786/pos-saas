@extends('layouts.app')
@section('title', 'Restaurant Tables')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Restaurant Tables</h3></div>
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

            {{-- Filters --}}
            <form method="GET" action="{{ url('/restaurant/tables') }}" class="card card-body mb-3 py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label small mb-1">Branch</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">All Branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small mb-1">Floor</label>
                        <select name="restaurant_floor_id" class="form-select form-select-sm">
                            <option value="">All Floors</option>
                            @foreach($floors as $fl)
                                <option value="{{ $fl->id }}" @selected(request('restaurant_floor_id') == $fl->id)>{{ $fl->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach(['available','occupied','reserved','bill_requested','cleaning','inactive'] as $s)
                                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-secondary">Filter</button>
                        <a href="{{ url('/restaurant/tables') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>

            @can('tenant.restaurant.tables.store')
            <div class="card mb-3">
                <div class="card-header fw-semibold">Add Table</div>
                <div class="card-body">
                    <form method="POST" action="{{ url('/restaurant/tables') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Branch <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select form-select-sm" required>
                                <option value="">— Branch —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Floor <span class="text-danger">*</span></label>
                            <select name="restaurant_floor_id" class="form-select form-select-sm" required>
                                <option value="">— Floor —</option>
                                @foreach($floors as $fl)
                                    <option value="{{ $fl->id }}" @selected(old('restaurant_floor_id') == $fl->id)>{{ $fl->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-1">
                            <label class="form-label small mb-1">No. <span class="text-danger">*</span></label>
                            <input type="text" name="table_no" class="form-control form-control-sm"
                                   placeholder="T1" maxlength="20" required value="{{ old('table_no') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Name</label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="Window Table" maxlength="100" value="{{ old('name') }}">
                        </div>
                        <div class="col-sm-1">
                            <label class="form-label small mb-1">Cap.</label>
                            <input type="number" name="capacity" class="form-control form-control-sm"
                                   min="1" max="100" value="{{ old('capacity', 4) }}">
                        </div>
                        <div class="col-sm-1">
                            <label class="form-label small mb-1">Sort</label>
                            <input type="number" name="sort_order" class="form-control form-control-sm"
                                   min="0" value="{{ old('sort_order', 0) }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="available" @selected(old('status','available') === 'available')>Available</option>
                                <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                            </select>
                        </div>
                        <div class="col-sm-1">
                            <button class="btn btn-sm btn-primary w-100">Add</button>
                        </div>
                    </form>
                </div>
            </div>
            @endcan

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <th>Floor</th>
                                    <th>No.</th>
                                    <th>Name</th>
                                    <th>Cap.</th>
                                    <th>Sort</th>
                                    <th>Status</th>
                                    <th>Session</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                $statusColors = [
                                    'available'     => 'success',
                                    'occupied'      => 'danger',
                                    'reserved'      => 'info',
                                    'bill_requested'=> 'warning',
                                    'cleaning'      => 'secondary',
                                    'inactive'      => 'dark',
                                ];
                                @endphp
                                @forelse($tables as $table)
                                <tr>
                                    <td>{{ $table->branch->name ?? '-' }}</td>
                                    <td>{{ $table->floor->name ?? '-' }}</td>
                                    <td class="fw-medium">{{ $table->table_no }}</td>
                                    <td>{{ $table->name ?? '-' }}</td>
                                    <td>{{ $table->capacity }}</td>
                                    <td>{{ $table->sort_order }}</td>
                                    <td>
                                        <span class="badge bg-{{ $statusColors[$table->status] ?? 'secondary' }}">
                                            {{ ucfirst(str_replace('_',' ',$table->status)) }}
                                        </span>
                                    </td>
                                    <td>{{ $table->openSession ? $table->openSession->session_no : '-' }}</td>
                                    <td>
                                        @can('tenant.restaurant.tables.update')
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editTableModal-{{ $table->id }}">Edit</button>
                                        @endcan
                                        @can('tenant.restaurant.tables.destroy')
                                        <form method="POST" action="{{ url('/restaurant/tables/' . $table->id) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Delete table {{ addslashes($table->table_no) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="9" class="text-center py-4 text-muted">No tables found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $tables->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@foreach($tables as $table)
<div class="modal fade" id="editTableModal-{{ $table->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Table {{ $table->table_no }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ url('/restaurant/tables/' . $table->id) }}">
                @csrf @method('PUT')
                <div class="modal-body row g-3">
                    <div class="col-6">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($b->id == $table->branch_id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Floor</label>
                        <select name="restaurant_floor_id" class="form-select" required>
                            @foreach($floors as $fl)
                                <option value="{{ $fl->id }}" @selected($fl->id == $table->restaurant_floor_id)>{{ $fl->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Table No.</label>
                        <input type="text" name="table_no" class="form-control"
                               value="{{ $table->table_no }}" maxlength="20" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $table->name }}" maxlength="100">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Capacity</label>
                        <input type="number" name="capacity" class="form-control"
                               value="{{ $table->capacity }}" min="1" max="100">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="{{ $table->sort_order }}" min="0">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            @foreach(['available','occupied','reserved','bill_requested','cleaning','inactive'] as $s)
                                <option value="{{ $s }}" @selected($table->status === $s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
@endsection
