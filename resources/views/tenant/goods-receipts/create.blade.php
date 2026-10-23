@extends('layouts.app')

@section('title', 'New Goods Receipt')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">New Goods Receipt</h1>
        <p class="fw-medium text-muted mb-0">Step 2 of the purchasing flow — receive goods into a branch.</p>
    </div>
    <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Back</a>
</div>

@include('tenant.purchasing.partials.how-to-use', ['guide' => [
    'what' => 'Record the goods that arrived from a supplier. They go into stock at the chosen branch straight away.',
    'steps' => ['Choose the supplier and the branch (and the Purchase Order, if there is one).', 'Add each item with the quantity received and its rate; batch and expiry where the item asks for them.', 'Add extra charges (cartage, labour) if the supplier charged them.', 'Post the goods receipt, then record the Supplier Invoice for it.'],
    'effects' => ['Stock' => 'Goes up by what you received, at its cost; extra charges are spread over the items by value.', 'Supplier balance' => 'No change yet — it goes up when the Supplier Invoice is recorded.', 'Payments' => 'None.'],
    'example' => '10 cartons arrive at Rs 500 each with Rs 200 cartage: stock goes up by 10 cartons at Rs 520 each. Then record the supplier\'s invoice of Rs 5,200.',
    'blocked' => ['An item that is not bought (not marked as purchasable) cannot be received.', 'A quantity of zero, or a missing supplier or branch.'],
]])

<div class="card border-success-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-success-subtle text-success-emphasis mt-1"><i class="ti ti-package-import me-1"></i>Updates stock</span>
        <div class="small">
            A <strong>Goods Receipt</strong> receives stock into the selected branch — posting it <strong>increases inventory</strong> (purchase movement) immediately.
            If this came from a PO, quantities should match the supplier's actual delivery.
            <div class="text-muted mt-1">Flow: Purchase Order (optional) → <strong>Goods Receipt</strong> (stock) → Supplier Invoice (what you owe) → Payment.</div>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@if($purchaseOrder)
    <div class="alert alert-info" role="status">
        Creating the goods receipt from purchase order <strong>{{ $purchaseOrder->po_no }}</strong>. Lines are still editable.
    </div>
@endif

<form method="POST" action="{{ url('/goods-receipts') }}" novalidate>
    @csrf
    {{-- W3/W4 S0: the old screen's form is posted once too; the new screen's entry area carries its own key. --}}
    @unless($entryV2 ?? false)
        <input type="hidden" name="submission_key" value="{{ \App\Support\SubmissionKey::fresh() }}">
    @endunless
    @if($purchaseOrder)
        <input type="hidden" name="purchase_order_id" value="{{ $purchaseOrder->id }}">
    @endif

    <div class="card mb-3">
        <div class="card-header"><strong>Receipt Header</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                @if($entryV2 ?? false)
                    {{-- PURCHASING-V2-2: type to search, "+ New" without leaving the page. --}}
                    @include('tenant.purchasing.partials.supplier-box', ['selectedSupplierId' => old('supplier_id', $purchaseOrder?->supplier_id), 'mode' => 'grn', 'currentPoId' => $purchaseOrder?->id])
                @else
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
                @endif
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

            {{-- GRN-EXTRA-CHARGES-1: cartage, labour, unloading. A charge is not a product, and
                 without a box of its own a counter books it as one — four receipts here carried
                 "Spoon, qty 1, rate 400" to record cartage. --}}
            <div class="col-md-4">
                <label for="extra_charges" class="form-label">Extra Charges</label>
                <input type="number" step="0.01" min="0" id="extra_charges" name="extra_charges"
                       class="form-control @error('extra_charges') is-invalid @enderror"
                       value="{{ old('extra_charges') }}" placeholder="0.00">
                <div class="form-text">Added to what the received goods cost, split by value. Not a stock item.</div>
                @error('extra_charges') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-8">
                <label for="extra_charges_note" class="form-label">Charges Description</label>
                <input type="text" maxlength="255" id="extra_charges_note" name="extra_charges_note"
                       class="form-control @error('extra_charges_note') is-invalid @enderror"
                       value="{{ old('extra_charges_note') }}" placeholder="e.g. Cartage, Labour, Unloading">
                @error('extra_charges_note') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
            @if($entryV2 ?? false)
                {{-- PURCHASING-V2-1: the entry area (Master switch "New purchase screens"). --}}
                @include('tenant.purchasing.partials.entry-area', ['mode' => 'grn', 'prefillLines' => $prefillLines ?? [], 'purchaseOrder' => $purchaseOrder ?? null])
            @else
            <p class="text-muted small mb-3">Enter batch number and expiry date for products that require batch tracking.</p>
            @include('tenant.purchasing.partials.product-lines', [
                'products'        => $products,
                'quantityField'   => 'quantity_received',
                'showBatch'       => true,
                'showDiscountTax' => false,
                'showNotes'       => true,
                'prefillLines'    => $prefillLines ?? [],
                'stockEffect'     => 'Stock effect: posting this goods receipt will INCREASE the selected branch stock by the received quantity. Items that do not track stock are recorded as a purchase only — no stock, their cost goes to expense at the bill.',
                'extraChargesInput'     => 'extra_charges',
                'extraChargesNoteInput' => 'extra_charges_note',
            ])
            @endif
        </div>
    </div>

    @error('lines') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit" id="grn-post-btn">Post goods receipt</button>
        <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection

{{-- PURCHASING-V2-2: the new screen shows a full preview before posting instead of this question. --}}
@if(!($entryV2 ?? false))
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
            title: 'Post this goods receipt?',
            text: 'Goods will be received and stock updated immediately.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Post goods receipt',
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
@endif
