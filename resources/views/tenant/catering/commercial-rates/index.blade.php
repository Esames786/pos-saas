@extends('layouts.app')

@section('title', 'Commercial Material Rates')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Commercial Material Rates</h1>
        <p class="fw-medium mb-0">What the customer is charged for a material — not what it costs us.</p>
    </div>
    @can('tenant.catering.commercial-rates.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rateModal">
            <i class="ti ti-plus me-1"></i>Set a rate
        </button>
    @endcan
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', [
    'manages' => 'The house price for each material — what a customer is charged for chicken, per kilo of chicken.',
    'managesUr' => 'ہر خام مال کی وہ قیمت جو گاہک سے لی جاتی ہے۔',
    'reversible' => 'safe',
    'note' => 'Setting a rate here reprices nothing on its own. It records what the house now charges, and the impact review is where you choose which dishes and which quotations should follow it.',
    'noteUr' => 'یہاں ریٹ بدلنے سے کوئی قیمت خود بخود تبدیل نہیں ہوتی۔',
])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- The distinction this whole screen exists to hold. Two books, two questions,
     and they move for entirely different reasons. --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100 border-primary-subtle">
            <div class="card-body">
                <h6 class="mb-2"><i class="ti ti-receipt-2 text-primary me-1"></i>Commercial Charge Rate</h6>
                <p class="mb-0 fs-13">
                    What the <strong>customer pays</strong> for a material — chicken at 120 a kilo of chicken.
                    A commercial decision. This screen.
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 border-secondary-subtle">
            <div class="card-body">
                <h6 class="mb-2"><i class="ti ti-truck-delivery text-secondary me-1"></i>Material Cost Rate</h6>
                <p class="mb-2 fs-13">
                    What the material <strong>costs us</strong> to buy — chicken at 80 a kilo. A purchasing fact.
                </p>
                <a href="{{ url('/catering/material-rates') }}" class="fs-13">Material Cost Rates &rsaquo;</a>
            </div>
        </div>
    </div>
</div>

{{-- Recorded, dated, and not yet in force. Shown apart from the current rates
     rather than above them: a rate that starts next Monday listed as "current"
     is how somebody quotes today at a price nobody is charged today. --}}
@if($scheduled->isNotEmpty())
<div class="card mb-3 border-warning-subtle">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0"><i class="ti ti-clock text-warning me-1"></i>Scheduled — not in effect yet</h5>
        <span class="text-muted fs-12">Nothing is charged at these rates until their date arrives</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-end">Will be charged</th>
                    <th>Unit</th>
                    <th>Starts</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            @foreach($scheduled as $rate)
                <tr>
                    <td>{{ $rate->product?->name }}</td>
                    <td class="text-end">{{ number_format($rate->rate, 2) }}</td>
                    <td>{{ $rate->unit?->code ?? '—' }}</td>
                    <td>{{ $rate->effective_from?->format('d M Y') }}</td>
                    <td class="text-muted fs-13">{{ $rate->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Current house rates</h5>
        <span class="text-muted fs-12">In force today</span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-end">Charged per unit</th>
                    <th>Unit</th>
                    <th>In effect since</th>
                    <th>Note</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rates as $rate)
                <tr>
                    <td>
                        {{ $rate->product?->name }}
                        <div class="text-muted fs-12">{{ $rate->product?->sku }}</div>
                    </td>
                    <td class="text-end fw-semibold">{{ number_format($rate->rate, 2) }}</td>
                    <td>{{ $rate->unit?->code ?? '—' }}</td>
                    <td>{{ $rate->effective_from?->format('d M Y') }}</td>
                    <td class="text-muted fs-13">{{ $rate->note }}</td>
                    <td class="text-end">
                        @can('tenant.catering.commercial-rates.impact')
                            <a href="{{ url('/catering/commercial-rates/' . $rate->product_id . '/impact') }}"
                               class="btn btn-sm btn-light">What would change?</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">
                    No commercial rates set yet. A dish can still carry its own rate typed by hand.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Every commercial decision, in the order they were made. Two rates on one day
     are not an inconsistency to be tidied away — they are two decisions, and a
     quotation applied against the morning's one has to stay explicable. --}}
@if($history->isNotEmpty())
<div class="card mt-4">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Rate history</h5>
        <span class="text-muted fs-12">Nothing here is ever overwritten</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="text-end">Charged per unit</th>
                    <th>Unit</th>
                    <th>In effect from</th>
                    <th>Recorded</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            @foreach($history as $row)
                <tr>
                    <td>{{ $row->product?->name }}</td>
                    <td class="text-end">{{ number_format($row->rate, 2) }}</td>
                    <td>{{ $row->unit?->code ?? '—' }}</td>
                    <td>{{ $row->effective_from?->format('d M Y') }}</td>
                    <td class="text-muted fs-13">{{ $row->created_at?->format('d M Y H:i') }}</td>
                    <td class="text-muted fs-13">{{ $row->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@can('tenant.catering.commercial-rates.store')
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/catering/commercial-rates') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Set a commercial rate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border fs-13">
                    <i class="ti ti-info-circle me-1"></i>
                    This records what the house now charges. It does not reprice any dish or any
                    quotation — you choose what follows it on the next screen.
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Material <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Choose a material…</option>
                            @foreach($materials as $material)
                                <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->sku }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Charged per unit <span class="text-danger">*</span></label>
                        <input type="number" step="0.0001" min="0" name="rate" class="form-control" required>
                        <div class="form-text">Per unit of the material, e.g. per KG of chicken.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <select name="unit_id" class="form-select" required>
                            <option value="">Choose a unit…</option>
                            @foreach($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            A rate of 120 means nothing until it says 120 per what. A dish can only
                            follow this rate if it measures the material in the same unit.
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">In effect from <span class="text-danger">*</span></label>
                        <input type="date" name="effective_from" class="form-control"
                               value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" class="form-control" maxlength="255"
                               placeholder="e.g. market rose">
                        <div class="form-text">
                            Every rate you record is kept, including a second one on the same day.
                            The most recent one is the current one.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Record rate</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
