@extends('layouts.app')

@section('title', 'Categories')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Categories</h1>
        <p class="fw-medium">Manage product categories.</p>
    </div>
    @can('tenant.categories.create')
        <a href="{{ url('/categories/create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1"></i>Create Category
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
            Categories are shared across POS, inventory, kitchen, and manufacturing.
            For manufacturing, use categories like Raw Material, Packing Material, and Finished Goods.
        </div>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/categories') }}" class="row g-3 align-items-end">
            @if(request('context') === 'manufacturing')
                <input type="hidden" name="context" value="manufacturing">
            @endif
            <div class="col-md-6">
                <label for="cat-search" class="form-label">Search</label>
                <input id="cat-search" type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="Code or name">
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ request('context') === 'manufacturing' ? url('/categories?context=manufacturing') : url('/categories') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Categories list</caption>
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Parent</th>
                    <th scope="col">Sort Order</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($categories as $category)
                <tr>
                    <td><code>{{ $category->code }}</code></td>
                    <td>{{ $category->name }}</td>
                    <td>{{ $category->parent?->name ?? '—' }}</td>
                    <td>{{ $category->sort_order }}</td>
                    <td>
                        @if($category->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.categories.edit')
                            <a href="{{ url('/categories/' . $category->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        @can('tenant.categories.destroy')
                            <form method="POST" action="{{ url('/categories/' . $category->id) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this category?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No categories found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $categories->links() }}</div>
    </div>
</div>
@endsection
