@extends('layouts.app')

@section('title', 'Modifier Groups')

@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h3 class="page-title">Modifier Groups</h3>
                    </div>
                    <div class="col-auto">
                        @can('tenant.modifier-groups.create')
                            <a href="{{ url('/modifier-groups/create') }}" class="btn btn-primary">Add Modifier Group</a>
                        @endcan
                    </div>
                </div>
            </div>

            @if(session('status'))
                <div class="alert alert-success alert-dismissible">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <form method="GET" action="{{ url('/modifier-groups') }}" class="card card-body mb-3 py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="Crust, toppings, sauces">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Branch</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">All Branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="active" @selected(request('status') === 'active')>Active</option>
                            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-secondary">Filter</button>
                        <a href="{{ url('/modifier-groups') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </div>
            </form>

            <div class="card table-list-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table datanew align-middle mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Group</th>
                                    <th>Branch</th>
                                    <th>Rules</th>
                                    <th>Options</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($groups as $group)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $group->name }}</div>
                                        <div class="text-muted small">Sort {{ $group->sort_order }}</div>
                                    </td>
                                    <td>{{ $group->branch?->name ?? 'All Branches' }}</td>
                                    <td>
                                        @if($group->is_required)
                                            <span class="badge bg-warning text-dark">Required</span>
                                        @else
                                            <span class="badge bg-light text-muted">Optional</span>
                                        @endif
                                        <span class="ms-1">{{ $group->min_select }} min / {{ $group->max_select ?? 'Any' }} max</span>
                                    </td>
                                    <td>
                                        @forelse($group->modifiers->take(4) as $modifier)
                                            <span class="badge bg-light text-dark border me-1 mb-1">
                                                {{ $modifier->name }}
                                                @if((float) $modifier->price_delta !== 0.0)
                                                    {{ (float) $modifier->price_delta > 0 ? '+' : '' }}{{ number_format($modifier->price_delta, 2) }}
                                                @endif
                                            </span>
                                        @empty
                                            <span class="text-muted">No options</span>
                                        @endforelse
                                        @if($group->modifiers->count() > 4)
                                            <span class="text-muted small">+{{ $group->modifiers->count() - 4 }} more</span>
                                        @endif
                                    </td>
                                    <td>{{ $group->products_count }}</td>
                                    <td>
                                        <span class="badge bg-{{ $group->status === 'active' ? 'success' : 'secondary' }}">
                                            {{ ucfirst($group->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @can('tenant.modifier-groups.edit')
                                            <a href="{{ url('/modifier-groups/' . $group->id . '/edit') }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        @endcan
                                        @can('tenant.modifier-groups.destroy')
                                            <form method="POST" action="{{ url('/modifier-groups/' . $group->id) }}" class="d-inline" onsubmit="return confirm('Delete modifier group {{ addslashes($group->name) }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No modifier groups found.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $groups->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
