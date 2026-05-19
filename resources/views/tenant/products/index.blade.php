@extends('layouts.app')

@section('title', 'Products')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Products</h1>
        <p class="fw-medium">Manage your product catalog.</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.products.bulk-import.create')
            <a href="{{ url('/products-bulk-import') }}" class="btn btn-light">
                <i class="ti ti-upload me-1"></i>Bulk Import
            </a>
        @endcan
        @can('tenant.products.create')
            <a href="{{ url('/products/create') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1"></i>Create Product
            </a>
        @endcan
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/products') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="prod-search" class="form-label">Search</label>
                <input id="prod-search" type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="SKU, name or barcode">
            </div>
            <div class="col-md-2">
                <label for="filter-category" class="form-label">Category</label>
                <select id="filter-category" name="category_id" class="form-select">
                    <option value="">All</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter-type" class="form-label">Type</label>
                <select id="filter-type" name="product_type" class="form-select">
                    <option value="">All</option>
                    @foreach(['simple','recipe','hybrid','service'] as $type)
                        <option value="{{ $type }}" @selected(request('product_type') === $type)>
                            {{ ucfirst($type) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter-status" class="form-label">Status</label>
                <select id="filter-status" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="active"   @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ url('/products') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Products list</caption>
            <thead>
                <tr>
                    <th scope="col">SKU</th>
                    <th scope="col">Name</th>
                    <th scope="col">Category</th>
                    <th scope="col">Type</th>
                    <th scope="col">Sell Price</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($products as $product)
                <tr>
                    <td><code>{{ $product->sku }}</code></td>
                    <td>
                        <a href="{{ url('/products/' . $product->id) }}">{{ $product->name }}</a>
                    </td>
                    <td>{{ $product->category?->name ?? '—' }}</td>
                    <td><span class="badge bg-light text-dark">{{ ucfirst($product->product_type) }}</span></td>
                    <td>{{ number_format($product->default_selling_price, 2) }}</td>
                    <td>
                        @if($product->status === 'active')
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.products.show')
                            <a href="{{ url('/products/' . $product->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @can('tenant.products.edit')
                            <a href="{{ url('/products/' . $product->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        @can('tenant.products.destroy')
                            <form method="POST" action="{{ url('/products/' . $product->id) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this product?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No products found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $products->links() }}</div>
    </div>
</div>
@endsection
