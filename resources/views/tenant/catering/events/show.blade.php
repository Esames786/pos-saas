@extends('layouts.app')

@section('title', 'Event ' . $event->event_no)

@section('content')
@php
    $current = $event->currentEstimate;
    $isDraft = $current && $current->isDraft();
    $statusBadge = match($event->status) {
        'confirmed', 'production_ready', 'released' => 'success',
        'quoted' => 'info',
        'completed', 'closed' => 'dark',
        'cancelled' => 'danger',
        default => 'secondary',
    };
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">{{ $event->event_no }}
            <span class="badge bg-{{ $statusBadge }} align-middle fs-12">{{ ucwords(str_replace('_', ' ', $event->status)) }}</span>
        </h1>
        <div class="text-muted">
            {{ $event->event_type ?? 'Event' }} · {{ $event->event_date->format('d M Y') }}
            @if($event->service_time) · {{ \Carbon\Carbon::parse($event->service_time)->format('g:i A') }} @endif
            · {{ number_format($event->pax) }} PAX
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/catering/events') }}" class="btn btn-light">Back</a>
        @if($current && $current->lines->isNotEmpty())
            @can('tenant.catering.documents.estimate')
                <div class="btn-group">
                    <a target="_blank" href="{{ url('/catering/documents/estimate/' . $current->id . '?lang=en') }}" class="btn btn-outline-secondary"><i class="ti ti-printer me-1"></i>A4 Estimate</a>
                    <a target="_blank" href="{{ url('/catering/documents/estimate/' . $current->id . '?lang=ur') }}" class="btn btn-outline-secondary">اردو</a>
                    <a target="_blank" href="{{ url('/catering/documents/estimate/' . $current->id . '?lang=both') }}" class="btn btn-outline-secondary">Both</a>
                </div>
            @endcan
        @endif
        @if($event->isOpen())
            @can('tenant.catering.events.edit')
                <a href="{{ url('/catering/events/' . $event->id . '/edit') }}" class="btn btn-outline-primary">Edit Event</a>
            @endcan
            @if(in_array($event->status, ['draft', 'quoted']))
                @can('tenant.catering.events.confirm')
                    <form method="POST" action="{{ url('/catering/events/' . $event->id . '/confirm') }}" class="d-inline">
                        @csrf
                        <button class="btn btn-success" onclick="return confirm('Confirm this booking?')">Confirm Booking</button>
                    </form>
                @endcan
            @endif
            @can('tenant.catering.events.cancel')
                <form method="POST" action="{{ url('/catering/events/' . $event->id . '/cancel') }}" class="d-inline">
                    @csrf
                    <button class="btn btn-outline-danger" onclick="return confirm('Cancel event {{ $event->event_no }}?')">Cancel</button>
                </form>
            @endcan
        @endif
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- CATERING-V1-CLOSURE-1 (§2): costing readiness — send/confirm are refused
     server-side while hard blockers exist; this panel explains why up front. --}}
@if($costingReadiness !== null)
    @if(! $costingReadiness['ready'])
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="ti ti-lock-x me-1"></i>Quotation cannot be sent or confirmed — cost basis incomplete:</div>
            <ul class="mb-0">
                @foreach($costingReadiness['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </div>
    @elseif($isDraft)
        <div class="alert alert-success py-2">
            <i class="ti ti-shield-check me-1"></i>Costing basis complete — every material has an effective Catering Material Rate.
        </div>
    @endif
    @if($costingReadiness['warnings'] !== [])
        <div class="alert alert-warning py-2">
            <div class="fw-bold">Costing notes (do not block sending):</div>
            <ul class="mb-0">
                @foreach($costingReadiness['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Customer</h5></div>
            <div class="card-body">
                <div class="fw-bold">{{ $event->customer_name }}</div>
                @if($event->customer_name_ur)
                    <div dir="rtl" lang="ur" class="fs-16">{{ $event->customer_name_ur }}</div>
                @endif
                <div class="text-muted mt-2">
                    @if($event->customer_phone)<div><i class="ti ti-phone me-1"></i>{{ $event->customer_phone }}</div>@endif
                    @if($event->customer_email)<div><i class="ti ti-mail me-1"></i>{{ $event->customer_email }}</div>@endif
                    @if($event->customer_address)<div><i class="ti ti-map-pin me-1"></i>{{ $event->customer_address }}</div>@endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Event Details</h5></div>
            <div class="card-body">
                <div class="row">
                    <dt class="col-5 text-muted">Booking Date</dt><dd class="col-7">{{ $event->booking_date->format('d M Y') }}</dd>
                    <dt class="col-5 text-muted">Event Date</dt><dd class="col-7">{{ $event->event_date->format('d M Y') }}</dd>
                    <dt class="col-5 text-muted">Service Time</dt><dd class="col-7">{{ $event->service_time ? \Carbon\Carbon::parse($event->service_time)->format('g:i A') : '—' }}</dd>
                    <dt class="col-5 text-muted">Venue</dt><dd class="col-7">{{ $event->venue ?? '—' }}</dd>
                    <dt class="col-5 text-muted">PAX</dt><dd class="col-7">{{ number_format($event->pax) }}</dd>
                    <dt class="col-5 text-muted">Branch</dt><dd class="col-7">{{ $event->branch?->name ?? '—' }}</dd>
                    @if($event->notes)<dt class="col-5 text-muted">Notes</dt><dd class="col-7">{{ $event->notes }}</dd>@endif
                </div>
            </div>
        </div>
    </div>
</div>

@if($current)
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">
            Estimate {{ $current->displayNo() }}
            <span class="badge bg-{{ $isDraft ? 'secondary' : ($current->status === 'accepted' ? 'success' : 'info') }}">{{ ucfirst($current->status) }}</span>
            @unless($isDraft)<i class="ti ti-lock ms-1" title="Commercially immutable"></i>@endunless
        </h5>
        <div class="d-flex gap-2">
            @if($isDraft && $event->isOpen())
                @can('tenant.catering.estimates.reprice')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/reprice') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" title="Recompute material cost from recipes + rate book (no stock movement)">
                            <i class="ti ti-calculator me-1"></i>Recalculate Cost
                        </button>
                    </form>
                @endcan
                @can('tenant.catering.estimates.send')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/send') }}">
                        @csrf
                        <button class="btn btn-sm btn-primary"
                                onclick="return confirm('Mark this estimate as sent? It becomes locked; further pricing changes need a revision.')">
                            Mark Sent / Lock
                        </button>
                    </form>
                @endcan
            @endif
            @if($current->status === 'sent')
                @can('tenant.catering.estimates.accept')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/accept') }}">
                        @csrf
                        <button class="btn btn-sm btn-success">Customer Accepted</button>
                    </form>
                @endcan
            @endif
            @if(! $isDraft && $event->isOpen())
                @can('tenant.catering.estimates.revise')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/revise') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="return confirm('Create revision Q{{ $current->version_no + 1 }} as a new draft?')">
                            Create Revision
                        </button>
                    </form>
                @endcan
            @endif
        </div>
    </div>

    @if($isDraft && $event->isOpen())
    {{-- ── Draft estimate builder ─────────────────────────────────────── --}}
    @can('tenant.catering.estimates.update')
    <form method="POST" action="{{ url('/catering/estimates/' . $current->id) }}" id="estimate-form">
        @csrf @method('PUT')
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0" id="lines-table">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">Item</th>
                            <th style="min-width:150px;">Urdu Name</th>
                            <th style="width:110px;" class="text-end">Qty</th>
                            <th style="width:120px;">Unit</th>
                            <th style="width:130px;" class="text-end">Rate</th>
                            <th style="width:130px;" class="text-end">Amount</th>
                            <th style="min-width:160px;">Instructions</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body"></tbody>
                </table>
            </div>
            <div class="p-3">
                <button type="button" class="btn btn-sm btn-outline-primary" id="add-line">
                    <i class="ti ti-plus me-1"></i>Add Item
                </button>
            </div>
        </div>
        <div class="card-body border-top">
            <div class="row justify-content-end">
                <div class="col-lg-5">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-muted">Subtotal</td>
                            <td class="text-end" id="t-subtotal">0.00</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Service Charges</td>
                            <td class="text-end" style="width:180px;">
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end t-input"
                                       name="service_charge_amount" value="{{ $current->service_charge_amount }}">
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <input type="text" class="form-control form-control-sm" name="other_charge_label"
                                       placeholder="Other charge (label)" value="{{ $current->other_charge_label }}">
                            </td>
                            <td class="text-end">
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end t-input"
                                       name="other_charge_amount" value="{{ $current->other_charge_amount }}">
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">
                                <div class="d-flex gap-1">
                                    <select name="discount_type" class="form-select form-select-sm t-input" style="width:110px;">
                                        <option value="none" @selected($current->discount_type === 'none')>No Disc.</option>
                                        <option value="fixed" @selected($current->discount_type === 'fixed')>Fixed</option>
                                        <option value="percent" @selected($current->discount_type === 'percent')>%</option>
                                    </select>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm t-input"
                                           name="discount_value" value="{{ $current->discount_value }}">
                                </div>
                            </td>
                            <td class="text-end text-danger" id="t-discount">0.00</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tax</td>
                            <td class="text-end">
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end t-input"
                                       name="tax_amount" value="{{ $current->tax_amount }}">
                            </td>
                        </tr>
                        <tr class="fw-bold fs-16">
                            <td>Net Total</td>
                            <td class="text-end" id="t-grand">0.00</td>
                        </tr>
                    </table>
                    @if($current->estimated_material_cost !== null)
                        <div class="border rounded p-2 mt-2 bg-light">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Estimated Material Cost</span>
                                <span>{{ number_format($current->estimated_material_cost, 2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold {{ ($current->grand_total - $current->estimated_material_cost) < 0 ? 'text-danger' : 'text-success' }}">
                                <span>Estimated Margin</span>
                                <span>{{ number_format($current->grand_total - $current->estimated_material_cost, 2) }}</span>
                            </div>
                            <div class="text-muted fs-12">Internal figures — never printed on customer documents.</div>
                        </div>
                    @endif
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-primary">Save Estimate</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
    @endcan
    @else
    {{-- ── Immutable estimate view ────────────────────────────────────── --}}
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Urdu Name</th>
                        <th class="text-end">Qty</th>
                        <th>Unit</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                        <th>Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($current->lines as $line)
                    <tr>
                        <td>{{ $line->item_name }}</td>
                        <td dir="rtl" lang="ur">{{ $line->item_name_ur }}</td>
                        <td class="text-end">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
                        <td>{{ $line->unit_code }}</td>
                        <td class="text-end">{{ number_format($line->rate, 2) }}</td>
                        <td class="text-end">{{ number_format($line->amount, 2) }}</td>
                        <td>{{ $line->instructions }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-body border-top">
        <div class="row justify-content-end">
            <div class="col-lg-4">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Subtotal</td><td class="text-end">{{ number_format($current->subtotal, 2) }}</td></tr>
                    @if($current->service_charge_amount > 0)
                        <tr><td class="text-muted">Service Charges</td><td class="text-end">{{ number_format($current->service_charge_amount, 2) }}</td></tr>
                    @endif
                    @if($current->other_charge_amount > 0)
                        <tr><td class="text-muted">{{ $current->other_charge_label ?? 'Other Charges' }}</td><td class="text-end">{{ number_format($current->other_charge_amount, 2) }}</td></tr>
                    @endif
                    @if($current->discount_amount > 0)
                        <tr><td class="text-muted">Discount</td><td class="text-end text-danger">-{{ number_format($current->discount_amount, 2) }}</td></tr>
                    @endif
                    @if($current->tax_amount > 0)
                        <tr><td class="text-muted">Tax</td><td class="text-end">{{ number_format($current->tax_amount, 2) }}</td></tr>
                    @endif
                    <tr class="fw-bold fs-16"><td>Net Total</td><td class="text-end">{{ number_format($current->grand_total, 2) }}</td></tr>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endif

{{-- ── Advances + Production (CATERING-SLICE-3) ─────────────────────── --}}
@php $advanceTotal = $event->advances->sum('amount'); @endphp
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">Advances</h5>
                @if(! $event->isCancelled())
                    @can('tenant.catering.advances.store')
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#advanceModal">Record Advance</button>
                    @endcan
                @endif
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse($event->advances as $advance)
                        <tr>
                            <td>{{ $advance->received_date->format('d M Y') }}</td>
                            <td>{{ $advance->paymentMethod?->name ?? '—' }}</td>
                            <td class="text-muted">{{ $advance->reference }}</td>
                            <td class="text-end">{{ number_format($advance->amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td class="text-center text-muted py-3">No advances recorded.</td></tr>
                        @endforelse
                        @if($event->advances->isNotEmpty() && $current)
                        <tr class="fw-bold">
                            <td colspan="3">Advance Total</td>
                            <td class="text-end">{{ number_format($advanceTotal, 2) }}</td>
                        </tr>
                        <tr class="fw-bold">
                            <td colspan="3">Balance (vs Q{{ $current->version_no }})</td>
                            <td class="text-end">{{ number_format(max($current->grand_total - $advanceTotal, 0), 2) }}</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted fs-12">Operational records only in V1 — no accounting entries are posted.</div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">Production</h5>
                @if($current && ! $current->isDraft() && in_array($event->status, ['quoted', 'confirmed', 'production_ready']))
                    @can('tenant.catering.production-releases.store')
                        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/production-releases') }}">
                            @csrf
                            <button class="btn btn-sm btn-success"
                                    onclick="return confirm('Release production for {{ $event->event_no }}? This creates an immutable kitchen snapshot (no stock is moved).')">
                                <i class="ti ti-chef-hat me-1"></i>Release Production
                            </button>
                        </form>
                    @endcan
                @endif
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        @forelse($event->productionReleases as $release)
                        <tr>
                            <td><a href="{{ url('/catering/production-releases/' . $release->id) }}">{{ $release->release_no }}</a></td>
                            <td>{{ $release->released_at->format('d M Y g:i A') }}</td>
                            <td><span class="badge bg-{{ $release->status === 'released' ? 'success' : 'danger' }}">{{ ucfirst($release->status) }}</span></td>
                        </tr>
                        @empty
                        <tr><td class="text-center text-muted py-3">
                            Not released yet.
                            @if($current && $current->isDraft())
                                <div class="fs-12">Send/lock the estimate first.</div>
                            @endif
                        </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Billing & closure (CATERING-V1-CLOSURE-1 §5) ─────────────────── --}}
@php $invoice = $event->finalInvoice; $liveBalance = $invoice ? round($invoice->grand_total - $advanceTotal, 2) : null; @endphp
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Billing &amp; Closure</h5>
        <div class="d-flex gap-2">
            @if(! $invoice && $current && ! $current->isDraft() && in_array($event->status, ['confirmed', 'production_ready', 'released']))
                @can('tenant.catering.final-invoices.store')
                    <form method="POST" action="{{ url('/catering/events/' . $event->id . '/final-invoice') }}">
                        @csrf
                        <button class="btn btn-sm btn-primary"
                                onclick="return confirm('Issue the final invoice for {{ $event->event_no }}? Totals and advances freeze into an immutable document.')">
                            Issue Final Invoice
                        </button>
                    </form>
                @endcan
            @endif
            @if($invoice)
                @can('tenant.catering.documents.final-invoice')
                    <div class="btn-group">
                        <a target="_blank" href="{{ url('/catering/documents/final-invoice/' . $invoice->id . '?lang=en') }}" class="btn btn-sm btn-outline-secondary"><i class="ti ti-printer me-1"></i>A4 Invoice</a>
                        <a target="_blank" href="{{ url('/catering/documents/final-invoice/' . $invoice->id . '?lang=ur') }}" class="btn btn-sm btn-outline-secondary">اردو</a>
                        <a target="_blank" href="{{ url('/catering/documents/final-invoice/' . $invoice->id . '?lang=both') }}" class="btn btn-sm btn-outline-secondary">Both</a>
                    </div>
                @endcan
                @if($event->status === 'completed')
                    @can('tenant.catering.events.close')
                        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/close') }}">
                            @csrf
                            <button class="btn btn-sm btn-dark" {{ $liveBalance > 0 ? 'disabled title=Outstanding-balance-blocks-closure' : '' }}
                                    onclick="return confirm('Close event {{ $event->event_no }}?')">
                                Close Event
                            </button>
                        </form>
                    @endcan
                @endif
            @endif
        </div>
    </div>
    <div class="card-body">
        @if($invoice)
            <div class="row">
                <dt class="col-3 text-muted">Invoice</dt><dd class="col-9">{{ $invoice->invoice_no }} · issued {{ $invoice->issued_at->format('d M Y g:i A') }} <i class="ti ti-lock" title="Immutable"></i></dd>
                <dt class="col-3 text-muted">Net Total</dt><dd class="col-9">{{ number_format($invoice->grand_total, 2) }}</dd>
                <dt class="col-3 text-muted">Advances</dt><dd class="col-9">{{ number_format($advanceTotal, 2) }}</dd>
                <dt class="col-3 text-muted">Balance</dt>
                <dd class="col-9 fw-bold {{ $liveBalance > 0 ? 'text-danger' : 'text-success' }}">
                    {{ number_format(max($liveBalance, 0), 2) }}
                    @if($liveBalance > 0)
                        <span class="fs-12 fw-normal text-muted">— record the final payment as an advance to enable closure.</span>
                    @else
                        <span class="badge bg-success">Fully settled</span>
                    @endif
                </dd>
            </div>
        @else
            <div class="text-muted">No final invoice yet. Available once the booking is confirmed (V1 posts no accounting entries — settlement is operational).</div>
        @endif
    </div>
</div>

@if(! $event->isCancelled())
@can('tenant.catering.advances.store')
<div class="modal fade" id="advanceModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/advances') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Record Advance — {{ $event->event_no }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Received Date <span class="text-danger">*</span></label>
                        <input type="date" name="received_date" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method_id" class="form-select">
                            <option value="">—</option>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Slip / transaction #">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control">
                    </div>
                </div>
                <div class="text-muted fs-12 mt-2">V1 records the advance for the event balance only — no GL/cash-bank posting.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Record Advance</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endif

@if($event->estimates->count() > 1)
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">Version History</h5></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Version</th><th>Status</th><th class="text-end">Net Total</th><th>Sent</th><th>Superseded</th></tr></thead>
            <tbody>
                @foreach($event->estimates as $version)
                <tr class="{{ $version->id === $current?->id ? 'table-active' : '' }}">
                    <td>Q{{ $version->version_no }}</td>
                    <td><span class="badge bg-{{ $version->status === 'accepted' ? 'success' : ($version->status === 'draft' ? 'secondary' : 'info') }}">{{ ucfirst($version->status) }}</span></td>
                    <td class="text-end">{{ number_format($version->grand_total, 2) }}</td>
                    <td>{{ $version->sent_at?->format('d M Y g:i A') ?? '—' }}</td>
                    <td>{{ $version->superseded_at?->format('d M Y g:i A') ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection

@push('scripts')
@if($current && $isDraft && $event->isOpen())
<script>
$(function () {
    const units = @json($units->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name]));
    const profiles = @json($profileMap);
    const existing = @json($current->lines->map(fn ($l) => [
        'product_id' => $l->product_id,
        'product_text' => $l->item_name,
        'item_name' => $l->item_name,
        'item_name_ur' => $l->item_name_ur,
        'quantity' => (float) $l->quantity,
        'unit_id' => $l->unit_id,
        'rate' => (float) $l->rate,
        'instructions' => $l->instructions,
    ]));
    const defaultPax = {{ (int) $event->pax }};
    let rowSeq = 0;

    function unitOptions(selectedId) {
        let html = '<option value="">—</option>';
        units.forEach(u => {
            html += `<option value="${u.id}" ${String(selectedId) === String(u.id) ? 'selected' : ''}>${u.code}</option>`;
        });
        return html;
    }

    function addRow(line) {
        line = line || {};
        const i = rowSeq++;
        const row = $(`
            <tr data-row="${i}">
                <td>
                    <select class="form-select form-select-sm product-select" name="lines[${i}][product_id]"></select>
                    <input type="hidden" name="lines[${i}][item_name]" class="line-name" value="${_.escape(line.item_name || '')}">
                </td>
                <td><input type="text" dir="rtl" lang="ur" class="form-control form-control-sm line-name-ur" name="lines[${i}][item_name_ur]" value="${_.escape(line.item_name_ur || '')}"></td>
                <td><input type="number" step="0.001" min="0.001" class="form-control form-control-sm text-end line-qty" name="lines[${i}][quantity]" value="${line.quantity || defaultPax || 1}" required></td>
                <td><select class="form-select form-select-sm line-unit" name="lines[${i}][unit_id]">${unitOptions(line.unit_id)}</select></td>
                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end line-rate" name="lines[${i}][rate]" value="${line.rate || 0}" required></td>
                <td class="text-end align-middle line-amount">0.00</td>
                <td><input type="text" class="form-control form-control-sm" name="lines[${i}][instructions]" value="${_.escape(line.instructions || '')}"></td>
                <td class="align-middle"><button type="button" class="btn btn-sm btn-link text-danger remove-line p-0"><i class="ti ti-x"></i></button></td>
            </tr>`);
        $('#lines-body').append(row);

        const select = row.find('.product-select');
        select.select2({
            width: '100%',
            tags: true, // free-text items allowed (custom menu lines)
            placeholder: 'Search products or type a custom item…',
            ajax: {
                url: '{{ url('/ajax/products') }}',
                dataType: 'json',
                delay: 200,
                data: params => ({ q: params.term, sellable: 1, page: params.page || 1 }),
                processResults: data => ({ results: data.results || [], pagination: data.pagination || {} }),
            },
        });

        if (line.product_id) {
            select.append(new Option(line.product_text || line.item_name, line.product_id, true, true)).trigger('change');
        } else if (line.item_name) {
            select.append(new Option(line.item_name, line.item_name, true, true)).trigger('change');
        }

        select.on('select2:select', function (e) {
            const data = e.params.data;
            const isProduct = /^\d+$/.test(String(data.id));
            const name = (data.text || '').replace(/^[^—]*—\s*/, '');
            row.find('.line-name').val(name);
            if (isProduct) {
                const p = profiles[data.id];
                if (p) {
                    if (p.rate > 0) row.find('.line-rate').val(p.rate);
                    if (p.unit_id) row.find('.line-unit').val(String(p.unit_id));
                    if (p.name_ur && !row.find('.line-name-ur').val()) row.find('.line-name-ur').val(p.name_ur);
                    if (p.minimum_qty > 0 && parseFloat(row.find('.line-qty').val() || 0) < p.minimum_qty) {
                        row.find('.line-qty').val(p.minimum_qty);
                    }
                }
            } else {
                // free-text line: id === text, no product_id submitted
                row.find('.product-select').attr('name', '');
                row.find('.line-name').val(data.id);
            }
            recalc();
        });

        return row;
    }

    function recalc() {
        let subtotal = 0;
        $('#lines-body tr').each(function () {
            const qty = parseFloat($(this).find('.line-qty').val()) || 0;
            const rate = parseFloat($(this).find('.line-rate').val()) || 0;
            const amount = Math.round(qty * rate * 100) / 100;
            $(this).find('.line-amount').text(amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            subtotal += amount;
        });
        const svc = parseFloat($('[name=service_charge_amount]').val()) || 0;
        const other = parseFloat($('[name=other_charge_amount]').val()) || 0;
        const tax = parseFloat($('[name=tax_amount]').val()) || 0;
        const dType = $('[name=discount_type]').val();
        const dVal = parseFloat($('[name=discount_value]').val()) || 0;
        let discount = 0;
        if (dType === 'fixed') discount = Math.min(dVal, subtotal);
        if (dType === 'percent') discount = Math.round(subtotal * Math.min(dVal, 100)) / 100;
        const grand = Math.max(subtotal + svc + other + tax - discount, 0);
        const fmt = n => n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        $('#t-subtotal').text(fmt(subtotal));
        $('#t-discount').text(discount > 0 ? '-' + fmt(discount) : '0.00');
        $('#t-grand').text(fmt(grand));
    }

    // Minimal escape helper (no lodash dependency in this template).
    const _ = { escape: s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) };

    $('#add-line').on('click', () => addRow());
    $(document).on('input change', '.line-qty, .line-rate, .t-input', recalc);
    $(document).on('click', '.remove-line', function () {
        $(this).closest('tr').remove();
        recalc();
    });

    if (existing.length) {
        existing.forEach(addRow);
    } else {
        addRow();
    }
    recalc();
});
</script>
@endif
@endpush
