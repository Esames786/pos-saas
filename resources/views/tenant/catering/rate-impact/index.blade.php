@extends('layouts.app')

@section('title', 'Rate Impact Center')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Rate Impact Center</h1>
        <div class="text-muted">Only DRAFT quotations can be updated. Sent, accepted, and confirmed documents are never repriced.</div>
    </div>
    <a href="{{ url('/catering/material-rates') }}" class="btn btn-light">Rate Book</a>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Material</label>
                <select name="product_id" id="impact-product" class="form-select">
                    @if($product)
                        <option value="{{ $product->id }}" selected>{{ $product->sku ? $product->sku . ' — ' : '' }}{{ $product->name }}</option>
                    @endif
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Show Impact</button>
            </div>
            @if($product && $currentRate)
                <div class="col-auto ms-auto text-end">
                    <div class="text-muted fs-12">Current rate book entry</div>
                    <div class="fw-bold">{{ number_format($currentRate->rate, 2) }} / {{ $currentRate->unit?->code ?? $product->unit?->code }}
                        <span class="text-muted">since {{ $currentRate->effective_from->format('d M Y') }}</span>
                    </div>
                </div>
            @endif
        </form>
    </div>
</div>

@if($product)
<form method="POST" action="{{ url('/catering/rate-impact/apply') }}">
    @csrf
    <input type="hidden" name="product_id" value="{{ $product->id }}">
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">Affected Draft Quotations — {{ $product->name }}</h5>
            @if($rows->isNotEmpty())
                @can('tenant.catering.rate-impact.apply')
                <div class="d-flex gap-2">
                    <button name="action" value="selected" class="btn btn-sm btn-primary"
                            onclick="return confirm('Reprice the selected drafts with the current rate book?')">Update Selected</button>
                    <button name="action" value="all" class="btn btn-sm btn-outline-primary"
                            onclick="return confirm('Reprice ALL listed drafts with the current rate book?')">Update All Drafts</button>
                    <button name="action" value="skip" class="btn btn-sm btn-light">Skip Existing Drafts</button>
                </div>
                @endcan
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:30px;"></th>
                            <th>Estimate</th>
                            <th>Event</th>
                            <th>Event Date</th>
                            <th class="text-end">Quote Total</th>
                            <th class="text-end">Current Cost</th>
                            <th class="text-end">New Cost</th>
                            <th class="text-end">Cost Δ</th>
                            <th class="text-end">New Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                        <tr>
                            <td><input type="checkbox" class="form-check-input" name="estimate_ids[]" value="{{ $row['estimate']->id }}"></td>
                            <td><a href="{{ url('/catering/events/' . $row['event']->id) }}">{{ $row['event']->event_no }} / Q{{ $row['estimate']->version_no }}</a></td>
                            <td>{{ $row['event']->customer_name }}</td>
                            <td>{{ $row['event']->event_date->format('d M Y') }}</td>
                            <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end">{{ $row['old_cost'] !== null ? number_format($row['old_cost'], 2) : '—' }}</td>
                            <td class="text-end fw-bold">{{ number_format($row['new_cost'], 2) }}</td>
                            <td class="text-end {{ ($row['cost_delta'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                {{ $row['cost_delta'] !== null ? ($row['cost_delta'] > 0 ? '+' : '') . number_format($row['cost_delta'], 2) : '—' }}
                            </td>
                            <td class="text-end {{ $row['new_margin'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row['new_margin'], 2) }}</td>
                        </tr>
                        @if(!empty($row['warnings']))
                        <tr class="table-warning">
                            <td></td>
                            <td colspan="8" class="fs-12"><i class="ti ti-alert-triangle me-1"></i>{{ implode(' ', array_slice($row['warnings'], 0, 2)) }}</td>
                        </tr>
                        @endif
                        @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No open draft quotations consume this material. New quotations automatically use the latest rate.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

{{-- ── CATERING-V1-CLOSURE-1 (§3): agreed/confirmed events — READ-ONLY ───────
     Sent/accepted quotations are never auto-repriced. The owner keeps full
     visibility of the margin impact and chooses: keep the agreed selling
     price (no change of any kind) or create a revision (a new draft). --}}
<div class="card mt-3">
    <div class="card-header">
        <h5 class="mb-0">Agreed / Confirmed Events — {{ $product->name }} <span class="badge bg-secondary">Read-only</span></h5>
        <div class="text-muted fs-12">These quotations are locked. Choose “Keep Agreed Price” (nothing changes) or create a revised quote as a new draft version.</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Estimate</th>
                        <th>Customer</th>
                        <th>Event Date</th>
                        <th>Status</th>
                        <th class="text-end">Agreed Total</th>
                        <th class="text-end">Cost at Agreement</th>
                        <th class="text-end">New Est. Cost</th>
                        <th class="text-end">Margin Δ</th>
                        <th class="text-end">New Margin</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($agreedRows as $row)
                    <tr id="agreed-row-{{ $row['estimate']->id }}">
                        <td><a href="{{ url('/catering/events/' . $row['event']->id) }}">{{ $row['event']->event_no }} / Q{{ $row['estimate']->version_no }}</a></td>
                        <td>{{ $row['event']->customer_name }}</td>
                        <td>{{ $row['event']->event_date->format('d M Y') }}</td>
                        <td><span class="badge bg-{{ $row['estimate']->status === 'accepted' ? 'success' : 'info' }}">{{ ucfirst($row['estimate']->status) }}</span></td>
                        <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                        <td class="text-end">{{ $row['old_cost'] !== null ? number_format($row['old_cost'], 2) : '—' }}</td>
                        <td class="text-end fw-bold">{{ number_format($row['new_cost'], 2) }}</td>
                        <td class="text-end {{ ($row['old_margin'] !== null && $row['new_margin'] < $row['old_margin']) ? 'text-danger' : 'text-success' }}">
                            {{ $row['old_margin'] !== null ? number_format($row['new_margin'] - $row['old_margin'], 2) : '—' }}
                        </td>
                        <td class="text-end {{ $row['new_margin'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row['new_margin'], 2) }}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light keep-agreed" data-row="{{ $row['estimate']->id }}"
                                    title="No change of any kind — the agreed quote stands.">Keep Agreed Price</button>
                            @can('tenant.catering.estimates.revise')
                                <form method="POST" action="{{ url('/catering/estimates/' . $row['estimate']->id . '/revise') }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="return confirm('Create revision Q{{ $row['estimate']->version_no + 1 }} as a new draft for {{ $row['event']->event_no }}? The agreed version stays untouched.')">
                                        Create Revision
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">No agreed/confirmed future events consume this material.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
$(function () {
    // "Keep Agreed Price" performs NO server mutation — it only tidies the review list.
    $(document).on('click', '.keep-agreed', function () {
        $('#agreed-row-' + $(this).data('row')).fadeOut(200);
    });
    $('#impact-product').select2({
        width: '100%',
        placeholder: 'Search materials…',
        ajax: {
            url: '{{ url('/ajax/products') }}',
            dataType: 'json',
            delay: 200,
            data: params => ({ q: params.term, page: params.page || 1 }),
            processResults: data => ({ results: data.results || [], pagination: data.pagination || {} }),
        },
    });
});
</script>
@endpush
