@extends('layouts.app')

@section('title', 'Edit Return ' . $return->return_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $return->return_no }} <span class="badge bg-warning text-dark align-middle">Draft</span></h1>
        <p class="fw-medium text-muted mb-0">
            {{ $return->supplier?->name }} · {{ $return->branch?->name }}
            @if($return->goodsReceipt) · Source: {{ $return->goodsReceipt->grn_no }} @endif
        </p>
    </div>
    <a href="{{ url('/purchase-returns/' . $return->id) }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/purchase-returns/' . $return->id) }}" novalidate id="pret-edit-form">
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-header"><strong>Return Header</strong> <span class="text-muted small ms-2">branch/supplier/source are fixed on a draft — cancel and recreate to change them</span></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="return_date" class="form-label required">Return Date</label>
                <input id="return_date" type="date" name="return_date" class="form-control" required
                       value="{{ old('return_date', $return->return_date?->format('Y-m-d')) }}">
            </div>
            <div class="col-md-3">
                <label for="reason_code" class="form-label required">Reason</label>
                <select id="reason_code" name="reason_code" class="form-select">
                    <option value="">— Select —</option>
                    @foreach($reasonCodes as $code)
                        <option value="{{ $code }}" @selected(old('reason_code', $return->reason_code) === $code)>{{ ucwords(str_replace('_', ' ', $code)) }}</option>
                    @endforeach
                </select>
                @error('reason_code') <div class="text-danger small">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label for="notes" class="form-label">Notes</label>
                <input id="notes" name="notes" class="form-control" value="{{ old('notes', $return->notes) }}">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Lines</strong> <span class="text-muted small ms-2">set quantity to 0 to drop a line</span></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-nowrap align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th><th>Variant</th><th>Source</th>
                        <th class="text-end">Returnable</th>
                        <th style="width:120px">Return Qty</th><th class="text-end">Unit Cost</th>
                        <th class="text-end">Line Total</th><th style="width:150px">Reason</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($return->lines as $i => $line)
                    @php $max = $line->source_line_id ? ($returnable[$line->id] ?? null) : null; @endphp
                    <tr class="pret-line" data-cost="{{ (float) $line->unit_cost }}">
                        <td>
                            {{ $line->product?->name }}
                            <input type="hidden" name="lines[{{ $i }}][product_id]" value="{{ $line->product_id }}">
                            <input type="hidden" name="lines[{{ $i }}][product_variant_id]" value="{{ $line->product_variant_id }}">
                            <input type="hidden" name="lines[{{ $i }}][source_line_id]" value="{{ $line->source_line_id }}">
                            <input type="hidden" name="lines[{{ $i }}][unit_cost]" value="{{ (float) $line->unit_cost }}">
                        </td>
                        <td>{{ $line->variant?->name ?? 'Default' }}</td>
                        <td class="small">{{ $line->source_line_id ? 'GRN line #' . $line->source_line_id : 'standalone' }}</td>
                        <td class="text-end">
                            @if($max !== null)
                                <span class="badge bg-success-subtle text-success-emphasis">{{ number_format($max, 3) }}</span>
                            @else — @endif
                        </td>
                        <td>
                            <input type="number" step="0.001" min="0" {{ $max !== null ? 'max=' . $max : '' }}
                                   name="lines[{{ $i }}][quantity]"
                                   value="{{ old('lines.' . $i . '.quantity', $line->quantity) }}"
                                   class="form-control form-control-sm text-end pret-qty">
                        </td>
                        <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                        <td class="text-end pret-line-total">{{ number_format($line->line_total, 2) }}</td>
                        <td>
                            <select name="lines[{{ $i }}][reason_code]" class="form-select form-select-sm">
                                <option value="">Header reason</option>
                                @foreach($reasonCodes as $code)
                                    <option value="{{ $code }}" @selected($line->reason_code === $code)>{{ ucwords(str_replace('_', ' ', $code)) }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr><td colspan="6" class="text-end">Return Total</td><td class="text-end" id="pret-grand">{{ number_format($return->grand_total, 2) }}</td><td></td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary" type="submit">Update Draft</button>
        <a href="{{ url('/purchase-returns/' . $return->id) }}" class="btn btn-light">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
(function () {
    function recalc() {
        var grand = 0;
        document.querySelectorAll('tr.pret-line').forEach(function (row) {
            var qty = parseFloat(row.querySelector('.pret-qty').value || 0);
            var cost = parseFloat(row.dataset.cost || 0);
            var qtyEl = row.querySelector('.pret-qty');
            if (qtyEl.max) qtyEl.classList.toggle('is-invalid', qty > parseFloat(qtyEl.max));
            var total = qty * cost;
            row.querySelector('.pret-line-total').textContent = total.toFixed(2);
            grand += total;
        });
        document.getElementById('pret-grand').textContent = grand.toFixed(2);
    }
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('pret-qty')) recalc();
    });
    recalc();
})();
</script>
@endpush
@endsection
