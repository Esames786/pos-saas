@extends('layouts.app')

@section('title', 'New Purchase Order')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Purchase Order</h1>
        <p class="fw-medium text-muted mb-0">Step 1 of the purchasing flow — request goods from a supplier.</p>
    </div>
    <a href="{{ url('/purchase-orders') }}" class="btn btn-light">Back</a>
</div>

@include('tenant.purchasing.partials.how-to-use', ['guide' => [
    'what' => 'Write down what you plan to buy from a supplier. A Purchase Order is optional — goods can be received without one.',
    'steps' => ['Choose the supplier and the branch the goods are for.', 'Add each item with the quantity and rate you agreed.', 'Save the order. When the goods arrive, record them on a Goods Receipt from this order.'],
    'effects' => ['Stock' => 'No change — stock goes up only when the goods are received.', 'Supplier balance' => 'No change — you owe the supplier only once their invoice is recorded.', 'Payments' => 'None.'],
    'example' => 'Order 10 boxes at Rs 892 a box. When 8 arrive, record a Goods Receipt for 8: the order then shows 2 still to come.',
    'blocked' => ['The supplier, the branch or an item\'s quantity is missing.', 'An item that is not bought (not marked as purchasable) cannot be ordered.'],
]])

<div class="card border-info-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-info-subtle text-info-emphasis mt-1"><i class="ti ti-file-text me-1"></i>Request only</span>
        <div class="small">
            A <strong>Purchase Order</strong> is a request to buy goods — it does <strong>not</strong> update stock or accounting.
            Stock increases only when a <strong>Goods Receipt</strong> is posted against it.
            <div class="text-muted mt-1">Flow: <strong>Purchase Order</strong> (optional) → Goods Receipt (stock) → Supplier Invoice (what you owe) → Payment.</div>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/purchase-orders') }}" novalidate>
    @csrf

    <div class="card mb-3">
        <div class="card-header"><strong>Order Header</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                @if($entryV2 ?? false)
                    {{-- PURCHASING-V2-2: type to search, "+ New" without leaving the page. --}}
                    @include('tenant.purchasing.partials.supplier-box', ['selectedSupplierId' => old('supplier_id'), 'mode' => 'po', 'currentPoId' => null])
                @else
                    <label for="supplier_id" class="form-label required">Supplier</label>
                    <select id="supplier_id" name="supplier_id"
                            class="form-select @error('supplier_id') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                @endif
            </div>

            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id"
                        class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="order_date" class="form-label required">Order Date</label>
                <input id="order_date" type="date" name="order_date" required
                       class="form-control @error('order_date') is-invalid @enderror"
                       value="{{ old('order_date', now()->toDateString()) }}">
                @error('order_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-2">
                <label for="expected_delivery_date" class="form-label">Expected Date</label>
                <input id="expected_delivery_date" type="date" name="expected_delivery_date"
                       class="form-control @error('expected_delivery_date') is-invalid @enderror"
                       value="{{ old('expected_delivery_date') }}">
                @error('expected_delivery_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Product Lines</strong></div>
        <div class="card-body">
            @if($entryV2 ?? false)
                {{-- PURCHASING-V2-1: the entry area (Master switch "New purchase screens"). --}}
                @include('tenant.purchasing.partials.entry-area', ['mode' => 'po', 'prefillLines' => [], 'purchaseOrder' => null])
            @else
            @include('tenant.purchasing.partials.product-lines', [
                'products'       => $products,
                'quantityField'  => 'quantity_ordered',
                'showBatch'      => false,
                'showDiscountTax' => true,
                'showNotes'      => false,
                'stockEffect'    => 'Stock effect: none. This order does not change stock — stock updates only when the Goods Receipt is posted.',
            ])
            @endif
        </div>
    </div>

    @error('lines') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">Save Purchase Order</button>
        <a href="{{ url('/purchase-orders') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection
