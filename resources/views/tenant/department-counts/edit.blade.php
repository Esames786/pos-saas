@extends('layouts.app')

@section('title', 'Count ' . $session->count_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $session->count_no }} <span class="badge bg-warning text-dark align-middle">Draft</span></h1>
        <p class="fw-medium text-muted mb-0">{{ $session->department?->name }} · {{ $session->branch?->name }} · {{ $session->count_date?->format('Y-m-d') }}</p>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.department-counts.submit')
            <form method="POST" action="{{ url('/department-counts/' . $session->id . '/submit') }}"
                  onsubmit="return confirm('Submit this count for approval? Counts cannot be edited after submission.')">
                @csrf
                <button class="btn btn-success"><i class="ti ti-send me-1"></i>Submit for Approval</button>
            </form>
        @endcan
        <a href="{{ url('/department-counts') }}" class="btn btn-light">Back</a>
    </div>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body py-2 small">
        <i class="ti ti-info-circle me-1"></i>
        Count the physical stock in <strong>{{ $session->department?->name }}</strong> and enter the actual quantities.
        Counted Qty defaults to Expected Qty — leave untouched if it matches.
        A <strong>reason is required</strong> for every line with a variance.
        Reconciles custody stock only — official branch stock is not changed.
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/department-counts/' . $session->id) }}" novalidate>
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <strong>Count Lines</strong>
            <span class="small text-muted">Total variance value: <strong id="total-variance">0.00</strong></span>
        </div>
        <div class="card-body table-responsive">
            <table class="table align-middle" id="count-lines-table">
                <thead class="table-light">
                    <tr>
                        <th>Product</th><th>SKU</th>
                        <th class="text-end">Expected Qty</th>
                        <th style="width:130px">Counted Qty</th>
                        <th class="text-end">Variance Qty</th>
                        <th class="text-end">Avg Cost</th>
                        <th class="text-end">Variance Value</th>
                        <th style="width:180px">Reason</th>
                        <th style="width:160px">Notes</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($session->lines as $line)
                    <tr class="count-line" data-expected="{{ (float) $line->expected_qty }}" data-cost="{{ (float) $line->average_cost }}">
                        <td>{{ $line->product?->name }}</td>
                        <td><code>{{ $line->product?->sku }}</code></td>
                        <td class="text-end">{{ number_format($line->expected_qty, 3) }} {{ $line->product?->unit?->code }}</td>
                        <td>
                            <input type="number" step="0.001" min="0" name="lines[{{ $line->id }}][counted_qty]"
                                   class="form-control form-control-sm text-end counted-qty"
                                   value="{{ old('lines.' . $line->id . '.counted_qty', $line->counted_qty) }}" required>
                        </td>
                        <td class="text-end variance-qty">0.000</td>
                        <td class="text-end">{{ number_format($line->average_cost, 4) }}</td>
                        <td class="text-end variance-value">0.00</td>
                        <td>
                            <select name="lines[{{ $line->id }}][reason_code]" class="form-select form-select-sm reason-code">
                                <option value="">— No variance —</option>
                                @foreach($reasonCodes as $code)
                                    <option value="{{ $code }}" @selected(old('lines.' . $line->id . '.reason_code', $line->reason_code) === $code)>
                                        {{ ucwords(str_replace('_', ' ', $code)) }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td><input name="lines[{{ $line->id }}][notes]" class="form-control form-control-sm" value="{{ old('lines.' . $line->id . '.notes', $line->notes) }}"></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No custody stock in this department — nothing to count.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-8">
                <label for="notes" class="form-label">Session Notes</label>
                <input id="notes" name="notes" class="form-control" value="{{ old('notes', $session->notes) }}">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button class="btn btn-primary">Save Counts</button>
            </div>
        </div>
    </div>
</form>

@can('tenant.department-counts.cancel')
    <form method="POST" action="{{ url('/department-counts/' . $session->id . '/cancel') }}" class="mb-4"
          onsubmit="return confirm('Cancel this draft count?')">
        @csrf
        <button class="btn btn-outline-danger btn-sm">Cancel Count</button>
    </form>
@endcan

@push('scripts')
<script>
(function () {
    function recalc() {
        var total = 0;
        document.querySelectorAll('tr.count-line').forEach(function (row) {
            var expected = parseFloat(row.dataset.expected || 0);
            var cost = parseFloat(row.dataset.cost || 0);
            var counted = parseFloat(row.querySelector('.counted-qty').value || 0);
            var variance = counted - expected;
            var value = variance * cost;
            row.querySelector('.variance-qty').textContent = variance.toFixed(3);
            row.querySelector('.variance-qty').className = 'text-end variance-qty ' + (variance < -0.0005 ? 'text-danger fw-semibold' : (variance > 0.0005 ? 'text-success fw-semibold' : ''));
            row.querySelector('.variance-value').textContent = value.toFixed(2);
            var reason = row.querySelector('.reason-code');
            var needs = Math.abs(variance) > 0.0005;
            reason.required = needs;
            reason.classList.toggle('border-warning', needs && !reason.value);
            total += value;
        });
        var t = document.getElementById('total-variance');
        t.textContent = total.toFixed(2);
        t.className = total < 0 ? 'text-danger' : '';
    }
    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('counted-qty')) recalc();
    });
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('reason-code')) recalc();
    });
    recalc();
})();
</script>
@endpush
@endsection
