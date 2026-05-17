@extends('layouts.app')
@section('title', 'Restaurant Floors')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Restaurant Floors</h3></div>
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

            @can('tenant.restaurant.floors.store')
            <div class="card mb-3">
                <div class="card-header fw-semibold">Add Floor</div>
                <div class="card-body">
                    <form method="POST" action="{{ url('/restaurant/floors') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-sm-3">
                            <label class="form-label small mb-1">Branch <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select form-select-sm" required>
                                <option value="">— Branch —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="e.g. Ground Floor" maxlength="100" required value="{{ old('name') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Code</label>
                            <input type="text" name="code" class="form-control form-control-sm"
                                   placeholder="e.g. GF" maxlength="20" value="{{ old('code') }}">
                        </div>
                        <div class="col-sm-1">
                            <label class="form-label small mb-1">Sort</label>
                            <input type="number" name="sort_order" class="form-control form-control-sm"
                                   min="0" value="{{ old('sort_order', 0) }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
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
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Sort</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($floors as $floor)
                                <tr>
                                    <td>{{ $floor->branch->name ?? '-' }}</td>
                                    <td>{{ $floor->name }}</td>
                                    <td>{{ $floor->code ?? '-' }}</td>
                                    <td>{{ $floor->sort_order }}</td>
                                    <td>
                                        <span class="badge bg-{{ $floor->status === 'active' ? 'success' : 'secondary' }}">
                                            {{ ucfirst($floor->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        @can('tenant.restaurant.floors.update')
                                        <button class="btn btn-sm btn-outline-primary"
                                                onclick="document.getElementById('editFloorModal-{{ $floor->id }}').dispatchEvent(new Event('show'))">
                                            Edit
                                        </button>
                                        @endcan
                                        @can('tenant.restaurant.floors.destroy')
                                        <form method="POST" action="{{ url('/restaurant/floors/' . $floor->id) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Delete floor {{ addslashes($floor->name) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center py-4 text-muted">No floors found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $floors->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@foreach($floors as $floor)
<div class="modal fade" id="editFloorModal-{{ $floor->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Floor — {{ $floor->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ url('/restaurant/floors/' . $floor->id) }}">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($b->id == $floor->branch_id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $floor->name }}" maxlength="100" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Code</label>
                            <input type="text" name="code" class="form-control"
                                   value="{{ $floor->code }}" maxlength="20">
                        </div>
                        <div class="col-3 mb-3">
                            <label class="form-label">Sort</label>
                            <input type="number" name="sort_order" class="form-control"
                                   value="{{ $floor->sort_order }}" min="0">
                        </div>
                        <div class="col-3 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" @selected($floor->status === 'active')>Active</option>
                                <option value="inactive" @selected($floor->status === 'inactive')>Inactive</option>
                            </select>
                        </div>
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

@push('scripts')
<script>
document.querySelectorAll('[id^="editFloorModal-"]').forEach(function(el) {
    el.addEventListener('show', function() {
        new bootstrap.Modal(this).show();
    });
});
</script>
@endpush
@endsection
