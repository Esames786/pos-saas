{{-- Reusable payment rows. Expects: $paymentMethods, $payCount (optional), $caption (optional) --}}
@php $payCount = $payCount ?? max(count(old('payments', [])), 1); @endphp

<div id="payments-body">
@for($p = 0; $p < $payCount; $p++)
<div class="payment-row border rounded p-2 mb-2">
    <div class="row g-2">
        <div class="col-12">
            <label class="form-label small required">Method</label>
            <select name="payments[{{ $p }}][payment_method_id]"
                    class="form-select form-select-sm method-select"
                    aria-label="Payment method {{ $p + 1 }}">
                <option value="">— Select —</option>
                @foreach($paymentMethods as $pm)
                    <option value="{{ $pm->id }}"
                            data-type="{{ $pm->method_type }}"
                            @selected(old("payments.$p.payment_method_id") == $pm->id)>
                        {{ $pm->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-6">
            <label class="form-label small required">Amount</label>
            <input type="number" step="0.01" min="0.01"
                   name="payments[{{ $p }}][amount]"
                   class="form-control form-control-sm pay-amount"
                   aria-label="Payment amount {{ $p + 1 }}"
                   value="{{ old("payments.$p.amount", 0) }}">
        </div>
        <div class="col-6 cash-tendered-wrap" style="display:none">
            <label class="form-label small">Tendered</label>
            <input type="number" step="0.01" min="0"
                   name="payments[{{ $p }}][tendered_amount]"
                   class="form-control form-control-sm"
                   aria-label="Tendered amount {{ $p + 1 }}"
                   value="{{ old("payments.$p.tendered_amount") }}">
        </div>
        <div class="col-12 ref-wrap" style="display:none">
            <label class="form-label small">Reference / Cheque No / Card Last 4</label>
            <input type="text" name="payments[{{ $p }}][transaction_ref]"
                   class="form-control form-control-sm"
                   aria-label="Reference {{ $p + 1 }}"
                   value="{{ old("payments.$p.transaction_ref") }}"
                   placeholder="Ref / cheque / last 4">
        </div>
    </div>
    <button type="button" class="btn btn-sm btn-link text-danger remove-payment mt-1"
            aria-label="Remove payment {{ $p + 1 }}">
        <i class="ti ti-x" aria-hidden="true"></i> Remove
    </button>
</div>
@endfor
</div>
