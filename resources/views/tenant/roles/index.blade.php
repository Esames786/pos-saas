@extends('layouts.app')

@section('title', 'Roles')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Roles & Permissions</h1>
        <p class="fw-medium">Manage employee roles and screen access.</p>
    </div>

    <div class="d-flex gap-2">
        @can('tenant.permissions.sync')
            <form method="POST" action="{{ url('/permissions/sync') }}">
                @csrf
                <button class="btn btn-dark">
                    <i class="ti ti-refresh me-1"></i>Sync Permissions
                </button>
            </form>
        @endcan

        @can('tenant.roles.create')
            <a href="{{ url('/roles/create') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1"></i>Create Role
            </a>
        @endcan
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/roles') }}" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Role name">
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/roles') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Guard</th>
                    <th>Permissions</th>
                    <th>Created</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>

            <tbody>
            @forelse($roles as $role)
                <tr>
                    <td>
                        <span class="fw-semibold">{{ $role->name }}</span>
                        @if($role->name === 'Owner')
                            <span class="badge bg-success ms-1">System</span>
                        @endif
                    </td>
                    <td>{{ $role->guard_name }}</td>
                    <td><span class="badge bg-light text-dark">{{ $role->permissions_count }}</span></td>
                    <td>{{ $role->created_at?->format('Y-m-d') }}</td>
                    <td class="text-end">
                        @can('tenant.roles.permissions.edit')
                            <a href="{{ url('/roles/' . $role->id . '/permissions') }}" class="btn btn-sm btn-dark">
                                Permissions
                            </a>
                        @endcan

                        @can('tenant.roles.edit')
                            <a href="{{ url('/roles/' . $role->id . '/edit') }}" class="btn btn-sm btn-primary">
                                Edit
                            </a>
                        @endcan

                        @if($role->name !== 'Owner')
                            @can('tenant.roles.destroy')
                                <form method="POST" action="{{ url('/roles/' . $role->id) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this role?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No roles found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $roles->links() }}</div>
    </div>
</div>
@endsection
