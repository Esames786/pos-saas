@extends('layouts.app')

@section('title', 'Low Stock Alerts')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Low Stock Alerts</h1>
        <p class="fw-medium">Products at or below their reorder level.</p>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Low stock products</caption>
            <thead>
                <tr>
                    <th scope="col">SKU</th>
                    <th scope="col">Product</th>
                    <th scope="col">Qty on Hand</th>
                    <th scope="col">Reorder Level</th>
                    <th scope="col">Reorder Qty</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($products as $product)
                <tr>
                    <td><code>{{ $product->sku }}</code></td>
                    <td>{{ $product->name }}</td>
                    <td class="text-danger fw-bold">
                        {{ number_format($product->stockBalances->sum('quantity_on_hand'), 3) }}
                    </td>
                    <td>{{ number_format($product->defaultVariant?->reorder_level ?? 0, 3) }}</td>
                    <td>{{ number_format($product->defaultVariant?->reorder_quantity ?? 0, 3) }}</td>
                    <td>
                        @can('tenant.stock-adjustments.create')
                            <a href="{{ url('/stock-adjustments/create') }}" class="btn btn-sm btn-primary">Adjust Stock</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No products are below their reorder level.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
