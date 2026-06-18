@extends('layouts.app')

@section('title', 'Create GRN')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Goods Receipt Note</h1>
        <p class="fw-medium">Receiving goods posts inventory purchase movements immediately.</p>
    </div>
    <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if($purchaseOrder)
    <div class="alert alert-info" role="status">
        Creating GRN from PO <strong>{{ $purchaseOrder->po_no }}</strong>. Lines are still editable.
    </div>
@endif

<form method="POST" action="{{ url('/goods-receipts') }}" novalidate>
    @csrf
    @if($purchaseOrder)
        <input type="hidden" name="purchase_order_id" value="{{ $purchaseOrder->id }}">
    @endif

    <div class="card mb-3">
        <div class="card-header"><strong>Receipt Header</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="supplier_id" class="form-label required">Supplier</label>
                <select id="supplier_id" name="supplier_id"
                        class="form-select @error('supplier_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}"
                                @selected(old('supplier_id', $purchaseOrder?->supplier_id) == $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
                @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id"
                        class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}"
                                @selected(old('branch_id', $purchaseOrder?->branch_id) == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="receipt_date" class="form-label required">Receipt Date</label>
                <input id="receipt_date" type="date" name="receipt_date" required
                       class="form-control @error('receipt_date') is-invalid @enderror"
                       value="{{ old('receipt_date', now()->toDateString()) }}">
                @error('receipt_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
        <div class="card-header"><strong>Received Products</strong></div>
        <div class="card-body">
            <p class="text-muted small mb-3">Enter batch number and expiry date for products that require batch tracking.</p>
            @include('tenant.purchasing.partials.product-lines', [
                'products'        => $products,
                'quantityField'   => 'quantity_received',
                'showBatch'       => true,
                'showDiscountTax' => false,
                'showNotes'       => true,
                'prefillLines'    => $prefillLines ?? [],
            ])
        </div>
    </div>

    @error('lines') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit" id="grn-post-btn">Post GRN</button>
        <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var btn = document.getElementById('grn-post-btn');
    if (!btn) return;
    btn.addEventListener('click', function (e) {
        var form = btn.closest('form');
        if (!form || !window.Swal) return; // no Swal → normal submit
        e.preventDefault();
        Swal.fire({
            title: 'Post this GRN?',
            text: 'Goods will be received and stock updated immediately.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Post GRN',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#0d6efd'
        }).then(function (r) {
            if (r.isConfirmed) {
                // requestSubmit fires the form 'submit' event so blank-row cleanup runs.
                if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
            }
        });
    });
})();
</script>
@endpush
