@extends('layouts.app')

@section('title', 'Create Purchase Bill')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Purchase Bill</h1>
        <p class="fw-medium text-muted mb-0">Step 3 of the purchasing flow — record what you owe the supplier.</p>
    </div>
    <a href="{{ url('/purchase-bills') }}" class="btn btn-light">Back</a>
</div>

<div class="card border-warning-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-warning-subtle text-warning-emphasis mt-1"><i class="ti ti-receipt-2 me-1"></i>Payable only</span>
        <div class="small">
            A <strong>Purchase Bill</strong> records the supplier <strong>payable</strong> from a posted GRN.
            Inventory is <strong>not</strong> updated again here — the GRN already received the stock. The bill lines come from the GRN.
            <div class="text-muted mt-1">Flow: PO → GRN → <strong>Purchase Bill</strong> (payable) → Supplier Payment.</div>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/purchase-bills') }}" novalidate>
    @csrf

    <div class="card mb-3">
        <div class="card-header"><strong>Bill Header</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label for="goods_receipt_id" class="form-label required">Goods Receipt (GRN)</label>
                <select id="goods_receipt_id" name="goods_receipt_id"
                        class="form-select @error('goods_receipt_id') is-invalid @enderror" required>
                    <option value="">— Select GRN —</option>
                    @foreach($receipts as $receipt)
                        <option value="{{ $receipt->id }}"
                                @selected(old('goods_receipt_id', $goodsReceipt?->id) == $receipt->id)>
                            {{ $receipt->grn_no }} — {{ $receipt->supplier?->name }}
                            ({{ $receipt->receipt_date?->format('Y-m-d') }})
                        </option>
                    @endforeach
                </select>
                @error('goods_receipt_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                <div class="form-text">
                    Open from <a href="{{ url('/goods-receipts') }}">GRN list</a> → View → Create Purchase Bill
                    to pre-select and preview lines.
                </div>
            </div>

            <div class="col-md-6">
                <label for="supplier_invoice_no" class="form-label">Supplier Invoice No</label>
                <input id="supplier_invoice_no" name="supplier_invoice_no"
                       class="form-control @error('supplier_invoice_no') is-invalid @enderror"
                       value="{{ old('supplier_invoice_no') }}">
                @error('supplier_invoice_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="bill_date" class="form-label required">Bill Date</label>
                <input id="bill_date" type="date" name="bill_date" required
                       class="form-control @error('bill_date') is-invalid @enderror"
                       value="{{ old('bill_date', now()->toDateString()) }}">
                @error('bill_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="due_date" class="form-label">Due Date</label>
                <input id="due_date" type="date" name="due_date"
                       class="form-control @error('due_date') is-invalid @enderror"
                       value="{{ old('due_date') }}">
                @error('due_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="discount_amount" class="form-label">Bill-level Discount</label>
                <input id="discount_amount" type="number" step="0.01" min="0" name="discount_amount"
                       class="form-control @error('discount_amount') is-invalid @enderror"
                       value="{{ old('discount_amount', 0) }}">
                @error('discount_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="tax_amount" class="form-label">Bill-level Tax</label>
                <input id="tax_amount" type="number" step="0.01" min="0" name="tax_amount"
                       class="form-control @error('tax_amount') is-invalid @enderror"
                       value="{{ old('tax_amount', 0) }}">
                @error('tax_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    @if($goodsReceipt)
    @php $grnSubtotal = $goodsReceipt->lines->sum('line_total'); @endphp
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span><strong>GRN Lines Preview</strong> <span class="text-muted small ms-2">{{ $goodsReceipt->grn_no }}</span></span>
            <span class="small text-muted">
                Supplier: <strong>{{ $goodsReceipt->supplier?->name }}</strong> ·
                Branch: <strong>{{ $goodsReceipt->branch?->name }}</strong> ·
                Received: <strong>{{ $goodsReceipt->receipt_date?->format('Y-m-d') }}</strong>
            </span>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-nowrap align-middle mb-0">
                <caption class="visually-hidden">Lines from selected GRN</caption>
                <thead class="table-light">
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Variant</th>
                    <th scope="col" class="text-end">Qty</th>
                    <th scope="col" class="text-end">Unit Cost</th>
                    <th scope="col" class="text-end">Line Total</th>
                </tr>
                </thead>
                <tbody>
                @foreach($goodsReceipt->lines as $line)
                    <tr>
                        <td>{{ $line->product?->name }}</td>
                        <td>{{ $line->variant?->name ?? 'Default' }}</td>
                        <td class="text-end">{{ number_format($line->quantity_received, 3) }}</td>
                        <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                        <td class="text-end">{{ number_format($line->line_total, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="4" class="text-end">GRN Subtotal</td>
                        <td class="text-end">{{ number_format($grnSubtotal, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer small text-muted">
            <i class="ti ti-info-circle me-1"></i>Bill-level discount/tax above adjust the payable total. Posting the bill creates the supplier payable — <strong>stock will not update again</strong>.
        </div>
    </div>
    @else
    <div class="alert alert-light border small"><i class="ti ti-arrow-up me-1"></i>Select a posted <strong>GRN</strong> above to preview its received lines and totals. The bill is built from the GRN — there are no manual product lines.</div>
    @endif

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit"
                onclick="return confirm('Post this purchase bill?')">Post Purchase Bill</button>
        <a href="{{ url('/purchase-bills') }}" class="btn btn-light">Cancel</a>
    </div>
</form>
@endsection
