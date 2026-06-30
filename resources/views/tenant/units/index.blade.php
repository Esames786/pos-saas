@extends('layouts.app')

@section('title', 'Units of Measure')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Units of Measure</h1>
        <p class="fw-medium">Manage units used for products.</p>
    </div>
    @can('tenant.units.create')
        <a href="{{ url('/units/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>Create Unit
        </a>
    @endcan
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if(request('context') === 'manufacturing')
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="ti ti-info-circle fs-18 mt-1"></i>
        <div>
            <strong>Shared setup.</strong>
            Units are shared across POS, inventory, kitchen, and manufacturing.
            For manufacturing, use units like KG, G, PCS, ROLL, and PKT for BOMs, purchase packs, and production quantities.
        </div>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/units') }}" class="row g-3 align-items-end">
            @if(request('context') === 'manufacturing')
                <input type="hidden" name="context" value="manufacturing">
            @endif
            <div class="col-md-6">
                <label for="unit-search" class="form-label">Search</label>
                <input id="unit-search" type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="Code or name">
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ request('context') === 'manufacturing' ? url('/units?context=manufacturing') : url('/units') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Units of measure list</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col">Base Factor</th>
                    <th scope="col">Base Unit</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($units as $unit)
                <tr>
                    <td><code>{{ $unit->code }}</code></td>
                    <td>{{ $unit->name }}</td>
                    <td>{{ ucfirst($unit->unit_type) }}</td>
                    <td>{{ $unit->base_factor }}</td>
                    <td>
                        @if($unit->is_base)
                            <span class="badge bg-success">Base</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($unit->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.units.edit')
                            <a href="{{ url('/units/' . $unit->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        @can('tenant.units.destroy')
                            <form method="POST" action="{{ url('/units/' . $unit->id) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this unit?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No units found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $units->links() }}</div>
    </div>
</div>
@endsection
