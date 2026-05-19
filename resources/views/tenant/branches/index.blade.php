@extends('layouts.app')

@section('title', 'Branches')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Branches</h1>
        <p class="fw-medium">Manage business locations, tax settings, and receipt options.</p>
    </div>

    @can('tenant.branches.create')
        <a href="{{ url('/branches/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Create Branch
        </a>
    @endcan
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/branches') }}" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="branch-search" class="form-label">Search</label>
                <input type="text" id="branch-search" name="search" value="{{ request('search') }}"
                    class="form-control" placeholder="Name, code, or phone">
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/branches') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption>Branch list</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col">Tax</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($branches as $branch)
                <tr>
                    <td>{{ $branch->code ?? '—' }}</td>
                    <td>
                        <strong>{{ $branch->name }}</strong>
                        @if($branch->address)
                            <small class="d-block text-muted">{{ $branch->address }}</small>
                        @endif
                    </td>
                    <td>{{ ucfirst($branch->business_type) }}</td>
                    <td>{{ $branch->is_tax_enabled ? 'Enabled' : 'Disabled' }}</td>
                    <td>
                        <span class="badge {{ $branch->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                            {{ ucfirst($branch->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="action-toolbar justify-content-end">
                            @can('tenant.branches.edit')
                                <a href="{{ url('/branches/' . $branch->id . '/edit') }}" class="btn btn-sm btn-primary">
                                    Edit
                                </a>
                            @endcan

                            @can('tenant.branches.destroy')
                                <form method="POST" action="{{ url('/branches/' . $branch->id) }}" class="d-inline"
                                      onsubmit="return confirm('Delete branch {{ addslashes($branch->name) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No branches found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mt-3">{{ $branches->links() }}</div>
    </div>
</div>
@endsection
