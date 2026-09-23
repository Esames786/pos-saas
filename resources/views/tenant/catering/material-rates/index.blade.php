@extends('layouts.app')

@section('title', 'Material Rate Book')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        {{-- CAT-RATE-UX-001 — this screen called itself "Commercial quote rates",
             which is the name of the OTHER book. An owner reading it would put
             what chicken costs them into the place that decides what the
             customer is charged for it. The two books now say what they are, in
             the same two words everywhere. --}}
        <h1 class="mb-1">Material Cost Rates</h1>
        <div class="text-muted">
            What each material <strong>costs our business</strong> — internal only, never added to a
            customer's quotation. Inventory average cost, FEFO cost and POS prices are never changed from here.
        </div>
        <div class="fs-13 mt-1">
            Looking for what the <strong>customer</strong> is charged?
            <a href="{{ url('/catering/commercial-rates') }}">Commercial Charge Rates &rsaquo;</a>
        </div>
    </div>
    @can('tenant.catering.material-rates.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rateModal">
            <i class="ti ti-plus me-1"></i>New Rate
        </button>
    @endcan
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'The rates you COST a material at when quoting — not what you paid for it, and not a POS price.', 'managesUr' => 'تخمینے میں مال کی لاگت کا ریٹ — خریداری کی قیمت نہیں۔', 'reversible' => 'safe', 'note' => 'Changing a rate never edits a quotation that has already been sent. Use Rate Impact to see which open drafts it would affect.', 'noteUr' => 'ریٹ بدلنے سے بھیجا ہوا تخمینہ تبدیل نہیں ہوتا۔'])
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search material / SKU…" value="{{ $search }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Search</button>
                <a href="{{ url('/catering/material-rates') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Urdu Name</th>
                        <th class="text-end">Current Rate</th>
                        <th>Per Unit</th>
                        <th>Effective From</th>
                        <th>Note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($latestRates as $row)
                    <tr>
                        <td>{{ $row->product->name }}<div class="text-muted fs-12">{{ $row->product->sku }}</div></td>
                        <td dir="rtl" lang="ur">{{ optional($row->product->translations->firstWhere('language_code', 'ur'))->name }}</td>
                        <td class="text-end fw-bold">{{ number_format($row->rate, 2) }}</td>
                        <td>{{ $row->unit?->code ?? $row->product->unit?->code ?? '—' }}</td>
                        <td>{{ $row->effective_from->format('d M Y') }}</td>
                        <td>{{ $row->note }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-light" href="{{ url('/catering/material-rates?product_id=' . $row->product_id) }}">History</a>
                            @can('tenant.catering.rate-impact.index')
                                <a class="btn btn-sm btn-outline-primary" href="{{ url('/catering/rate-impact?product_id=' . $row->product_id) }}">Impact</a>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No catering material rates yet — quotes fall back to product purchase prices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($latestRates->hasPages())
        <div class="card-footer">{{ $latestRates->links() }}</div>
    @endif
</div>

{{-- CATERING-RATE-PARITY-1 — recorded, dated, and not in force yet.

     These used to sit in the table above as though they were today's rate,
     because "current" was the highest id rather than the latest date that had
     arrived. The costing never agreed: it has always resolved by date. So the
     screen said one number and every quotation used another. --}}
@if(isset($scheduled) && $scheduled->isNotEmpty())
<div class="card mt-3 border-warning-subtle">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="ti ti-clock-hour-4 me-1"></i>Aage ki tareekh wali rates</h5>
        <span class="fs-13 text-body-secondary">abhi nafiz nahi — costing in ko istemaal nahi karti</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr class="fs-13 fw-semibold text-body-secondary">
                    <th>Material</th><th class="text-end">Rate</th><th>Per Unit</th>
                    <th>Effective From</th><th>Note</th>
                </tr>
            </thead>
            <tbody>
                @foreach($scheduled as $row)
                    <tr>
                        <td>{{ $row->product?->name }}</td>
                        <td class="text-end">{{ number_format((float) $row->rate, 2) }}</td>
                        <td>{{ $row->unit?->code ?? $row->product?->unit?->code }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($row->effective_from)->format('d M Y') }}</td>
                        <td class="text-body-secondary">{{ $row->note }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if($history && $history->isNotEmpty())
<div class="card mt-3">
    <div class="card-header"><h5 class="mb-0">Rate History — {{ $history->first()->product->name }}</h5></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Effective From</th><th class="text-end">Rate</th><th>Per Unit</th><th>Note</th><th>Recorded</th></tr></thead>
            <tbody>
                @foreach($history as $row)
                <tr>
                    <td>{{ $row->effective_from->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format($row->rate, 2) }}</td>
                    <td>{{ $row->unit?->code ?? '—' }}</td>
                    <td>{{ $row->note }}</td>
                    <td>{{ app(\App\Support\TenantClock::class)->format($row->created_at, 'd M Y g:i A') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- New rate modal --}}
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog">
        {{-- `?? []` because the history panel is a CONVENIENCE, not part of the
             page's meaning: a caller that renders this view without it (an older
             guard does) should get the screen, not a 500. The controller always
             passes it, and CateringRateBookParityMySqlTest asserts the attribute
             is present when the page is rendered through the controller — so a
             controller that stopped passing it would still be caught. --}}
        <form method="POST" action="{{ url('/catering/material-rates') }}" class="modal-content"
              data-rate-history='@json($historyByMaterial ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)'>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">New Material Rate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Material <span class="text-danger">*</span></label>
                    {{-- CATERING-RATE-PARITY-1: a plain list of MATERIALS, not a
                         search across the whole catalogue. The catalogue search is
                         how the DISH "Chatni" acquired a material cost rate — it
                         offered 900 products of which six are materials. The
                         server refuses the rest now as well; this stops offering
                         them in the first place. --}}
                    <select name="product_id" id="rate-product" class="form-select" required>
                        <option value="">Choose a material…</option>
                        {{-- `?? collect()` for the same reason as the history above:
                             an older guard renders this view with a minimal data set
                             to check its wording, and it should get the screen rather
                             than a 500. The picker being empty is visible; a blank
                             page is not. --}}
                        @foreach($materials ?? collect() as $material)
                            <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->sku }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Rate <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" name="rate" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Per Unit <span class="text-danger">*</span></label>
                        <select name="unit_id" class="form-select" required>
                            <option value="">Choose a unit…</option>
                            @foreach($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            A rate of 450 means nothing until it says 450 per what.
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Effective From <span class="text-danger">*</span></label>
                        <input type="date" name="effective_from" class="form-control" value="{{ app(\App\Support\TenantClock::class)->now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" class="form-control" placeholder="e.g. market price update">
                        <div class="form-text">
                            Har rate mehfooz rehti hai — ek hi din ki doosri bhi. Jo aaj nafiz ho,
                            wohi current hai.
                        </div>
                    </div>

                    @include('tenant.catering.partials.rate-history-panel', [
                        'historyLabel' => 'Is material ki pichli cost rates',
                    ])
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save Rate</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    // CATERING-RATE-PARITY-1: select2 over the MATERIALS already in the box.
    // It used to search /ajax/products — the whole catalogue — which is how a
    // dish came to hold a material cost rate. No ajax now: there are six
    // materials, and they are already on the page.
    $('#rate-product').select2({
        width: '100%',
        dropdownParent: $('#rateModal'),
        placeholder: 'Choose a material…',
    });
});
</script>
@endpush
