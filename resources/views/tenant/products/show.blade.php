@extends('layouts.app')

@section('title', $product->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $product->name }}</h1>
        <p class="fw-medium text-muted mb-0"><code>{{ $product->sku }}</code> &middot; {{ ucfirst($product->product_type) }}</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.products.edit')
            <a href="{{ url('/products/' . $product->id . '/edit') }}" class="btn btn-primary">Edit Product</a>
        @endcan
        <a href="{{ url('/products') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

{{-- Product details --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><strong>Product Details</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Category</dt>
                    <dd class="col-sm-8">{{ $product->category?->name ?? '—' }}</dd>

                    <dt class="col-sm-4">Unit</dt>
                    <dd class="col-sm-8">{{ $product->unit?->name ?? '—' }}</dd>

                    <dt class="col-sm-4">Purchase Price</dt>
                    <dd class="col-sm-8">{{ number_format($product->default_purchase_price, 2) }}</dd>

                    <dt class="col-sm-4">Selling Price</dt>
                    <dd class="col-sm-8">{{ number_format($product->default_selling_price, 2) }}</dd>

                    <dt class="col-sm-4">Tax Rate</dt>
                    <dd class="col-sm-8">{{ $product->tax_rate_percent ? $product->tax_rate_percent . '%' : '—' }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        @if($product->status === 'active')
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </dd>

                    @if($product->description)
                    <dt class="col-sm-4">Description</dt>
                    <dd class="col-sm-8">{{ $product->description }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><strong>Settings</strong></div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    @foreach([
                        'is_sellable'      => 'Sellable',
                        'is_purchasable'   => 'Purchasable',
                        'is_stock_tracked' => 'Stock Tracked',
                        'has_variants'     => 'Has Variants',
                        'has_expiry'       => 'Expiry Tracking',
                        'requires_batch'   => 'Batch Tracking',
                        'is_taxable'       => 'Taxable',
                    ] as $flag => $label)
                    <li class="mb-1">
                        @if($product->$flag)
                            <span class="badge bg-success">&#10003;</span>
                        @else
                            <span class="badge bg-light text-muted">&#10005;</span>
                        @endif
                        {{ $label }}
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- Variants panel --}}
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <strong>Variants</strong>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Product variants</caption>
            <thead>
                <tr>
                    <th scope="col">SKU</th>
                    <th scope="col">Name</th>
                    <th scope="col">Barcode</th>
                    <th scope="col">Purchase</th>
                    <th scope="col">Selling</th>
                    <th scope="col">Reorder</th>
                    <th scope="col">Default</th>
                    <th scope="col">Active</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($product->variants as $variant)
                <tr>
                    <td><code>{{ $variant->sku }}</code></td>
                    <td>{{ $variant->name }}</td>
                    <td>{{ $variant->barcode ?? '—' }}</td>
                    <td>{{ number_format($variant->purchase_price, 2) }}</td>
                    <td>{{ number_format($variant->selling_price, 2) }}</td>
                    <td>{{ $variant->reorder_level }}</td>
                    <td>
                        @if($variant->is_default)
                            <span class="badge bg-primary">Default</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($variant->is_active)
                            <span class="badge bg-success">Yes</span>
                        @else
                            <span class="badge bg-secondary">No</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.product-variants.destroy')
                            @if(!$variant->is_default)
                            <form method="POST" action="{{ url('/product-variants/' . $variant->id) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this variant?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-3">No variants.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @can('tenant.product-variants.store')
    <div class="card-footer">
        <details>
            <summary class="fw-medium" style="cursor:pointer">Add Variant</summary>
            <form method="POST" action="{{ url('/products/' . $product->id . '/variants') }}" class="mt-3" novalidate>
                @csrf
                <div class="row g-3">
                    <div class="col-md-2">
                        <label for="v-sku" class="form-label required">SKU</label>
                        <input id="v-sku" type="text" name="sku" class="form-control" maxlength="100" required>
                    </div>
                    <div class="col-md-3">
                        <label for="v-name" class="form-label required">Name</label>
                        <input id="v-name" type="text" name="name" class="form-control" maxlength="190" required>
                    </div>
                    <div class="col-md-2">
                        <label for="v-barcode" class="form-label">Barcode</label>
                        <input id="v-barcode" type="text" name="barcode" class="form-control" maxlength="100">
                    </div>
                    <div class="col-md-2">
                        <label for="v-purchase" class="form-label">Purchase Price</label>
                        <input id="v-purchase" type="number" name="purchase_price" step="0.01" min="0" value="0" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label for="v-selling" class="form-label required">Selling Price</label>
                        <input id="v-selling" type="number" name="selling_price" step="0.01" min="0" value="{{ $product->default_selling_price }}" class="form-control" required>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input id="v-active" type="checkbox" name="is_active" value="1" class="form-check-input" checked>
                            <label for="v-active" class="form-check-label">Active</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-sm btn-primary">Add Variant</button>
                </div>
            </form>
        </details>
    </div>
    @endcan
</div>

{{-- Barcodes panel --}}
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <strong>Barcodes</strong>
        @can('tenant.product-barcodes.generate')
        <form method="POST" action="{{ url('/products/' . $product->id . '/barcodes/generate') }}" class="d-inline">
            @csrf
            <button class="btn btn-sm btn-outline-primary">Auto-Generate Barcode</button>
        </form>
        @endcan
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Product barcodes</caption>
            <thead>
                <tr>
                    <th scope="col">Barcode</th>
                    <th scope="col">Type</th>
                    <th scope="col">Variant</th>
                    <th scope="col">Primary</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($product->barcodes as $barcode)
                <tr>
                    <td><code>{{ $barcode->barcode }}</code></td>
                    <td>{{ ucfirst($barcode->barcode_type) }}</td>
                    <td>{{ $barcode->variant?->name ?? '—' }}</td>
                    <td>
                        @if($barcode->is_primary)
                            <span class="badge bg-primary">Primary</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.product-barcodes.destroy')
                        <form method="POST" action="{{ url('/product-barcodes/' . $barcode->id) }}" class="d-inline"
                              onsubmit="return confirm('Delete this barcode?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-3">No barcodes.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @can('tenant.product-barcodes.store')
    <div class="card-footer">
        <details>
            <summary class="fw-medium" style="cursor:pointer">Add Barcode</summary>
            <form method="POST" action="{{ url('/products/' . $product->id . '/barcodes') }}" class="mt-3 row g-3" novalidate>
                @csrf
                <div class="col-md-3">
                    <label for="bc-code" class="form-label required">Barcode</label>
                    <input id="bc-code" type="text" name="barcode" class="form-control" maxlength="100" required>
                </div>
                <div class="col-md-3">
                    <label for="bc-type" class="form-label required">Type</label>
                    <select id="bc-type" name="barcode_type" class="form-select" required>
                        <option value="manual">Manual</option>
                        <option value="supplier">Supplier</option>
                        <option value="system">System</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="bc-variant" class="form-label">Variant</label>
                    <select id="bc-variant" name="product_variant_id" class="form-select">
                        <option value="">Default</option>
                        @foreach($product->variants as $v)
                            <option value="{{ $v->id }}">{{ $v->name }} ({{ $v->sku }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input id="bc-primary" type="checkbox" name="is_primary" value="1" class="form-check-input">
                        <label for="bc-primary" class="form-check-label">Primary</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-primary">Add Barcode</button>
                </div>
            </form>
        </details>
    </div>
    @endcan
</div>

{{-- Branch prices panel --}}
<div class="card mb-4">
    <div class="card-header"><strong>Branch Prices</strong></div>
    @can('tenant.product-branch-prices.update')
    <form method="POST" action="{{ url('/products/' . $product->id . '/branch-prices') }}" novalidate>
        @csrf
        @method('PUT')
        <div class="card-body table-responsive p-0">
            <table class="table table-nowrap align-middle mb-0">
                <caption class="visually-hidden">Branch selling prices</caption>
                <thead>
                    <tr>
                        <th scope="col">Branch</th>
                        <th scope="col">Selling Price</th>
                        <th scope="col">Min Price</th>
                        <th scope="col">Available</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($branches as $idx => $branch)
                    @php
                        $existing = $product->branchPrices->where('branch_id', $branch->id)->first();
                    @endphp
                    <tr>
                        <td>
                            {{ $branch->name }}
                            <input type="hidden" name="prices[{{ $idx }}][branch_id]" value="{{ $branch->id }}">
                        </td>
                        <td>
                            <label for="bp-sell-{{ $branch->id }}" class="visually-hidden">Selling price for {{ $branch->name }}</label>
                            <input id="bp-sell-{{ $branch->id }}"
                                   type="number" name="prices[{{ $idx }}][selling_price]"
                                   step="0.01" min="0"
                                   value="{{ $existing?->selling_price ?? $product->default_selling_price }}"
                                   class="form-control form-control-sm" style="width:120px" required>
                        </td>
                        <td>
                            <label for="bp-min-{{ $branch->id }}" class="visually-hidden">Minimum price for {{ $branch->name }}</label>
                            <input id="bp-min-{{ $branch->id }}"
                                   type="number" name="prices[{{ $idx }}][minimum_selling_price]"
                                   step="0.01" min="0"
                                   value="{{ $existing?->minimum_selling_price }}"
                                   class="form-control form-control-sm" style="width:120px">
                        </td>
                        <td>
                            <input type="checkbox" name="prices[{{ $idx }}][is_available]" value="1"
                                   class="form-check-input"
                                   @checked($existing ? $existing->is_available : true)
                                   aria-label="Available at {{ $branch->name }}">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">Save Branch Prices</button>
        </div>
    </form>
    @else
    <div class="card-body">
        <p class="text-muted mb-0">No permission to manage branch prices.</p>
    </div>
    @endcan
</div>
@endsection
