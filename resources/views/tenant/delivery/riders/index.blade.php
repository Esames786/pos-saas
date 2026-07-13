@extends('layouts.app')
@section('title', 'Delivery Riders')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Delivery Riders</h3></div>
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
            <form method="GET" action="{{ url('/delivery/riders') }}" class="card card-body mb-3 py-2">
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
                    <div class="col-sm-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="active" @selected(request('status') === 'active')>Active</option>
                            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-secondary">Filter</button>
                        <a href="{{ url('/delivery/riders') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>

            @can('tenant.delivery-riders.store')
            <div class="card mb-3">
                <div class="card-header fw-semibold">Add Rider</div>
                <div class="card-body">
                    <form method="POST" action="{{ url('/delivery/riders') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-sm-3">
                            <label class="form-label small mb-1">Branch</label>
                            <select name="branch_id" class="form-select form-select-sm">
                                <option value="">All Branches</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="Rider full name" maxlength="100" required value="{{ old('name') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Phone</label>
                            <input type="text" name="phone" class="form-control form-control-sm"
                                   maxlength="30" value="{{ old('phone') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="active" @selected(old('status','active') === 'active')>Active</option>
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
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($riders as $rider)
                                <tr>
                                    <td>{{ $rider->branch->name ?? 'All Branches' }}</td>
                                    <td class="fw-medium">{{ $rider->name }}</td>
                                    <td>{{ $rider->phone ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $rider->status === 'active' ? 'success' : 'secondary' }}">
                                            {{ ucfirst($rider->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        @can('tenant.delivery-riders.update')
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editRiderModal-{{ $rider->id }}">Edit</button>
                                        @endcan
                                        @can('tenant.delivery-riders.destroy')
                                        <form method="POST" action="{{ url('/delivery/riders/' . $rider->id) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Delete rider {{ addslashes($rider->name) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center py-4 text-muted">No riders found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $riders->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@foreach($riders as $rider)
<div class="modal fade" id="editRiderModal-{{ $rider->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Rider - {{ $rider->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ url('/delivery/riders/' . $rider->id) }}">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All Branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($b->id == $rider->branch_id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $rider->name }}" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"
                               value="{{ $rider->phone }}" maxlength="30">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" @selected($rider->status === 'active')>Active</option>
                            <option value="inactive" @selected($rider->status === 'inactive')>Inactive</option>
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
