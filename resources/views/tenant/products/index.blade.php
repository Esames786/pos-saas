@extends('layouts.app')

@php
    $context = $context ?? 'catalog';
    $isManufacturing = $context === 'manufacturing';
    // Catering reuses the manufacturing context but lives at its own path, so
    // every link is built from the base the controller resolved. The fallback
    // reproduces the previous hardcoded behaviour exactly.
    $base = $contextBase ?? ($isManufacturing ? '/manufacturing/products' : '/products');
    $isCateringMaterials = $base === '/catering/materials';
    $indexUrl = url($base);
    $createUrl = url($base . '/create');
    $createPermission = $isCateringMaterials
        ? 'tenant.catering.materials.create'
        : ($isManufacturing ? 'tenant.manufacturing.products.create' : 'tenant.products.create');
    $heading = $isCateringMaterials
        ? 'Materials'
        : ($isManufacturing ? 'Manufacturing Products' : 'Products');
@endphp

@section('title', $heading)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $heading }}</h1>
        <p class="fw-medium">
            @if($isCateringMaterials)
                Raw materials consumed by catering recipes — mutton, rice, oil, masala.
                Their <strong>purchase</strong> cost lives here; their <strong>quoting</strong>
                rate lives in the Material Rate Book.
            @elseif($isManufacturing)
                Manage materials and finished goods used in manufacturing.
            @else
                Manage products sold in POS, sales, restaurant, and kitchen workflows.
            @endif
        </p>
    </div>
    <div class="d-flex gap-2">
        @if(! $isManufacturing)
        @can('tenant.products.bulk-import.create')
            <a href="{{ url('/products-bulk-import') }}" class="btn btn-light">
                <i class="ti ti-upload me-1"></i>Bulk Import
            </a>
        @endcan
        @endif
        @can($createPermission)
            <a href="{{ $createUrl }}" class="btn btn-primary">
                <i class="ti ti-plus me-1"></i>{{ $isCateringMaterials ? 'Create Material' : ($isManufacturing ? 'Create Manufacturing Product' : 'Create Product') }}
            </a>
        @endcan
    </div>
</div>

@if($isCateringMaterials)
    {{-- KASHIF-CATERING-PRODUCT-UX-1 (item 6) — the Materials LIST states its
         impact like every other catering action screen. Gated on the catering
         path, so the generic catalog and the manufacturing list are untouched. --}}
    @include('tenant.catering.partials.tooltips')
    @include('tenant.catering.partials.screen-impact', [
        'manages' => 'The ingredients and packaging your kitchen buys and consumes — never sold to a customer directly.',
        'managesUr' => 'خام مال اور پیکنگ جو کچن خریدتا اور استعمال کرتا ہے — گاہک کو براہِ راست نہیں بکتا۔',
        'reversible' => 'safe',
        'note' => 'Adding or editing a material posts nothing and issues no stock. Stock moves only when materials are issued against a production release. The rate you QUOTE a customer at lives in the Material Rate Book, not here.',
        'noteUr' => 'یہاں تبدیلی سے نہ کھاتے میں اندراج ہوتا ہے نہ اسٹاک کم ہوتا ہے۔ گاہک کو دیا جانے والا ریٹ میٹیریل ریٹ بک میں ہے۔',
    ])
@endif

<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="ti ti-info-circle fs-18 mt-1"></i>
    <div>
        @php
            // Where this tenant's raw materials actually live. Pointing a
            // catering-only tenant at Manufacturing was a dead end — that module
            // is not on their plan, so the sentence named a screen they cannot open.
            $sub = app()->bound('tenant') ? app('tenant')->subscription : null;
            $keys = $sub?->plan?->loadMissing('enabledModules')->enabledModules->pluck('key')->all() ?? [];
            $materialsHome = in_array('manufacturing', $keys, true)
                ? 'Manufacturing &gt; Products'
                : (in_array('catering', $keys, true) ? 'Catering &gt; Materials' : null);
        @endphp
        @if($isCateringMaterials)
            Raw materials only. Dishes you quote and sell live under Catalog &gt; Products;
            editing a rate here changes <strong>purchase</strong> cost, not the price you quote.
        @elseif($isManufacturing)
            This list is for manufacturing raw materials, BOM components, BOM outputs, and manufactured finished goods. Shared kitchen or restaurant stock items stay hidden unless you include them.
        @else
            This list is for items sold in POS, restaurant, sales, or kitchen recipes.
            @if($materialsHome)
                Raw materials are managed under {!! $materialsHome !!}.
            @endif
        @endif
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
        <form method="GET" action="{{ $indexUrl }}" class="row g-3 align-items-end">
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
            @if($isManufacturing)
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input id="include-shared-materials" class="form-check-input" type="checkbox"
                               name="include_shared_materials" value="1" @checked(request()->boolean('include_shared_materials'))>
                        <label class="form-check-label" for="include-shared-materials">Include shared stock materials</label>
                    </div>
                </div>
            @endif
            <div class="col-md-2">
                <button class="btn btn-dark">Filter</button>
                <a href="{{ $indexUrl }}" class="btn btn-light">Reset</a>
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
                    @if($isManufacturing)
                        <th scope="col">Role</th>
                        <th scope="col">Track Stock</th>
                        <th scope="col">Purchasable</th>
                        <th scope="col">BOM Component</th>
                        <th scope="col">BOM Output</th>
                        <th scope="col">Manufactured FG</th>
                    @else
                        <th scope="col">Type</th>
                        <th scope="col">Role / Visibility</th>
                        <th scope="col">Sell Price</th>
                    @endif
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
                    @if($isManufacturing)
                        <td><span class="badge bg-light text-dark">{{ $product->kind_label }}</span></td>
                        <td>{{ $product->is_stock_tracked ? 'Yes' : 'No' }}</td>
                        <td>{{ $product->is_purchasable ? 'Yes' : 'No' }}</td>
                        <td>{{ $product->can_be_bom_component ? 'Yes' : 'No' }}</td>
                        <td>{{ $product->can_be_bom_output ? 'Yes' : 'No' }}</td>
                        <td>{{ $product->is_manufactured_finished_good ? 'Yes' : 'No' }}</td>
                    @else
                        <td><span class="badge bg-light text-dark">{{ ucfirst($product->product_type) }}</span></td>
                        <td class="text-nowrap">
                            <span class="d-inline-flex flex-wrap gap-1">@include('tenant.products.partials.product-role-badges', ['badgeContext' => 'catalog'])</span>
                        </td>
                        <td>{{ number_format($product->default_selling_price, 2) }}</td>
                    @endif
                    <td>
                        @if($product->status === 'active')
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if(! $isManufacturing)
                        @can('tenant.products.show')
                            <a href="{{ url('/products/' . $product->id) }}" class="btn btn-sm btn-light">View</a>
                        @endcan
                        @endif
                        @can($isCateringMaterials ? 'tenant.catering.materials.edit' : ($isManufacturing ? 'tenant.manufacturing.products.edit' : 'tenant.products.edit'))
                            <a href="{{ url($base . '/' . $product->id . '/edit') }}" class="btn btn-sm btn-primary">Edit</a>
                        @endcan
                        @if(! $isManufacturing)
                        @can('tenant.products.destroy')
                            <form method="POST" action="{{ url('/products/' . $product->id) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this product?')">
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
                    <td colspan="{{ $isManufacturing ? 11 : 8 }}" class="text-center text-muted py-4">
                        @if($isManufacturing)
                            No manufacturing products found yet. Create a Manufacturing Raw Material or Manufacturing Finished Good to start. Shared kitchen/restaurant ingredients remain under Catalog/Kitchen Inventory.
                        @else
                            No products found.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $products->links() }}</div>
    </div>
</div>
@endsection
