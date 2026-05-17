@extends('layouts.app')
@section('title', 'Waiters')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Waiters</h3></div>
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
            <form method="GET" action="{{ url('/restaurant/waiters') }}" class="card card-body mb-3 py-2">
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
                        <a href="{{ url('/restaurant/waiters') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>

            @can('tenant.restaurant.waiters.store')
            <div class="card mb-3">
                <div class="card-header fw-semibold">Add Waiter</div>
                <div class="card-body">
                    <form method="POST" action="{{ url('/restaurant/waiters') }}" class="row g-2 align-items-end">
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
                        <div class="col-sm-3">
                            <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="Full name" maxlength="100" required value="{{ old('name') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Code</label>
                            <input type="text" name="code" class="form-control form-control-sm"
                                   placeholder="W-001" maxlength="20" value="{{ old('code') }}">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Phone</label>
                            <input type="text" name="phone" class="form-control form-control-sm"
                                   maxlength="30" value="{{ old('phone') }}">
                        </div>
                        <div class="col-sm-1">
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
                                    <th>Code</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($waiters as $waiter)
                                <tr>
                                    <td>{{ $waiter->branch->name ?? 'All Branches' }}</td>
                                    <td class="fw-medium">{{ $waiter->name }}</td>
                                    <td>{{ $waiter->code ?? '-' }}</td>
                                    <td>{{ $waiter->phone ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $waiter->status === 'active' ? 'success' : 'secondary' }}">
                                            {{ ucfirst($waiter->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        @can('tenant.restaurant.waiters.update')
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editWaiterModal-{{ $waiter->id }}">Edit</button>
                                        @endcan
                                        @can('tenant.restaurant.waiters.destroy')
                                        <form method="POST" action="{{ url('/restaurant/waiters/' . $waiter->id) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Delete waiter {{ addslashes($waiter->name) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center py-4 text-muted">No waiters found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $waiters->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@foreach($waiters as $waiter)
<div class="modal fade" id="editWaiterModal-{{ $waiter->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Waiter — {{ $waiter->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ url('/restaurant/waiters/' . $waiter->id) }}">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">All Branches</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($b->id == $waiter->branch_id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $waiter->name }}" maxlength="100" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Code</label>
                            <input type="text" name="code" class="form-control"
                                   value="{{ $waiter->code }}" maxlength="20">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="{{ $waiter->phone }}" maxlength="30">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" @selected($waiter->status === 'active')>Active</option>
                            <option value="inactive" @selected($waiter->status === 'inactive')>Inactive</option>
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
