@extends('layouts.app')

@section('title', 'Event ' . $event->event_no)

@section('content')
{{-- KASHIF-CATERING-NO-RELOAD-1: every action on this page posts in place and
     the workspace re-renders from the response — the operator never loses
     scroll position or an open panel to a full page reload. --}}
<div id="event-workspace">
@include('tenant.catering.events.partials.workspace-ajax')
@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.submit-guard')
@php
    $current = $event->currentEstimate;
    $isDraft = $current && $current->isDraft();
    // Render callers that predate the instruction vocabulary may not pass it.
    $activeInstructions = $activeInstructions ?? collect();
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
                    <a target="_blank" href="{{ url('/catering/documents/estimate/' . $current->id . '?lang=en') }}" class="btn btn-outline-secondary"
                       data-bs-toggle="tooltip" title="Opens the A4 quotation and lets you print it on any printer through the browser. Posts nothing."><i class="ti ti-printer me-1"></i>A4 Estimate</a>
                    <a target="_blank" href="{{ url('/catering/documents/estimate/' . $current->id . '?lang=ur') }}" class="btn btn-outline-secondary"
                       data-bs-toggle="tooltip" title="اردو — A4 only. Urdu cannot be rendered on a thermal printer.">اردو</a>
                    <a target="_blank" href="{{ url('/catering/documents/estimate/' . $current->id . '?lang=both') }}" class="btn btn-outline-secondary"
                       data-bs-toggle="tooltip" title="English and Urdu on one A4 sheet.">Both</a>
                </div>
                @include('tenant.catering.partials.document-print', [
                    'action' => url('/catering/documents/estimate/' . $current->id . '/print'),
                    'label' => 'Print Quotation',
                    'printers' => $printers ?? collect(),
                    'permission' => 'tenant.catering.documents.estimate-print',
                ])
            @endcan
        @endif
        @if($event->isOpen())
            @can('tenant.catering.events.edit')
                {{-- KASHIF-CATERING-NO-RELOAD-2: editing happens beside the work,
                     in an offcanvas — the operator never leaves the booking. --}}
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas"
                        data-bs-target="#editEventOffcanvas">Edit Event</button>
            @endcan
            @if(in_array($event->status, ['draft', 'quoted']))
                {{-- KASHIF-CATERING-OPERATOR-UI-1: Confirm Booking is a decision
                     made on a quotation the customer has seen and agreed to. While
                     the quotation is still an editable draft the next action is
                     Finalize Quotation, and showing a green Confirm here made the
                     wrong step look like the obvious one. --}}
                @if(! $current || $current->status === 'accepted')
                    @can('tenant.catering.events.confirm')
                        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/confirm') }}" class="d-inline">
                            @csrf
                            <button class="btn btn-success"
                                    data-bs-toggle="tooltip"
                                    title="Marks the booking as agreed so advances, production and the final invoice unlock. No money is posted and no stock moves yet."
                                    onclick="return confirm('Confirm this booking?')">Confirm Booking</button>
                        </form>
                    @endcan
                @elseif($isDraft)
                    <span class="text-muted fs-12 align-self-center"
                          data-bs-toggle="tooltip"
                          title="Finalize the quotation below first — a booking is confirmed on a quotation the customer has agreed to, not on an editable draft.">
                        <i class="ti ti-info-circle me-1"></i>Finalize the quotation to confirm
                    </span>
                @endif
            @endif
            @can('tenant.catering.events.cancel')
                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"
                        title="Closes the booking permanently and records why. Any advance already received stays on the ledger.">
                    Cancel Booking
                </button>
            @endcan
        @endif
    </div>
</div>

{{-- The impact header belongs INSIDE the content section and BELOW the page
     header. Placed above @section it rendered outside the layout body and sat
     underneath the fixed top nav. --}}
@include("tenant.catering.partials.screen-impact", ["manages" => "One booking end to end — quotation, advances, production and the final invoice.", "managesUr" => "ایک بکنگ مکمل — تخمینہ، پیشگی، پیداوار اور حتمی بل۔", "finance" => "Recording an advance and issuing the final invoice both post to the general ledger", "stock" => "Only when materials are issued against a production release", "prints" => "Estimate and final invoice as A4, kitchen sheet to the printers", "reversible" => "partly", "note" => "While the estimate is a draft nothing at all is posted. Cancelling after an advance has been received does NOT refund it — the money stays on the ledger and becomes credit owed to the customer, settled by the separate Refund action.", "noteUr" => "ڈرافٹ کی حالت میں کچھ پوسٹ نہیں ہوتا۔ پیشگی رقم کے بعد منسوخی سے رقم خود بخود واپس نہیں ہوتی — وہ گاہک کا کریڈٹ بن جاتی ہے اور ریفنڈ کے ذریعے واپس کی جاتی ہے۔"])

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
                    @if($event->isCancelled())
                        {{-- Recorded at cancellation. Older cancellations predate this
                             field and legitimately have none — an invented reason would
                             be a fabricated record, so the absence is shown plainly. --}}
                        <dt class="col-5 text-danger">Cancelled</dt>
                        <dd class="col-7">
                            @if($event->cancel_reason)
                                {{ $event->cancel_reason }}
                            @else
                                <span class="text-muted fst-italic">No reason was recorded for this cancellation.</span>
                            @endif
                            @if($event->cancelled_at)
                                <div class="fs-12 text-muted mt-1">{{ $event->cancelled_at->format('d M Y g:i A') }}</div>
                            @endif
                        </dd>
                    @endif
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
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip"
                                title="Recomputes the internal material cost from each dish's recipe and the Material Rate Book. Changes no customer price, moves no stock, posts nothing to finance.">
                            <i class="ti ti-calculator me-1"></i>Recalculate Cost
                        </button>
                    </form>
                @endcan
                @can('tenant.catering.estimates.send')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/send') }}">
                        @csrf
                        <button class="btn btn-sm btn-primary" data-bs-toggle="tooltip"
                                title="Validates the costing, freezes this quotation so the customer's copy can never change underneath them, and emails it if the customer has an address."
                                onclick="return confirm('Finalize this quotation? Finalizing freezes it — changes afterwards require a new revision.')">
                            Finalize Quotation
                        </button>
                    </form>
                @endcan
            @endif
            @if($current->status === 'sent')
                @can('tenant.catering.estimates.accept')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/accept') }}">
                        @csrf
                        <button class="btn btn-sm btn-success" data-bs-toggle="tooltip"
                                title="Records that the customer agreed to this quotation. Nothing is posted to finance — confirm the booking to unlock advances and production.">Customer Accepted</button>
                    </form>
                @endcan
            @endif
            @unless($isDraft)
                @can('tenant.catering.estimates.email')
                    {{-- KASHIF-CATERING-EMAIL-1: a deliberate (re)send of the frozen
                         quotation. Mutates nothing; each attempt gets its own log row. --}}
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/email') }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip"
                                title="{{ $event->customer_email ? 'Emails this quotation to '.$event->customer_email.'. Sending again is a deliberate new delivery.' : 'No customer email on file — add one on Edit Event first.' }}"
                                onclick="return confirm('Email quotation Q{{ $current->version_no }} to the customer now?')">
                            <i class="ti ti-mail-forward me-1"></i>Email to Customer
                        </button>
                    </form>
                @endcan
            @endunless
            @if(! $isDraft && $event->isOpen())
                @can('tenant.catering.estimates.revise')
                    <form method="POST" action="{{ url('/catering/estimates/' . $current->id . '/revise') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip"
                                title="Opens Q{{ $current->version_no + 1 }} as a fresh editable draft and marks Q{{ $current->version_no }} superseded. The old quotation is kept for your records, never deleted."
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
                            <th style="min-width:130px;">Urdu Name</th>
                            <th style="width:100px;" class="text-end">Qty</th>
                            <th style="width:100px;">Unit</th>
                            <th style="width:110px;" class="text-end"
                                data-bs-toggle="tooltip" title="What the dish's cost blocks work out to, per unit. Read-only — it moves when quantities or applied rates move.">Calculated</th>
                            <th style="width:130px;" class="text-end"
                                data-bs-toggle="tooltip" title="What the customer is actually quoted. For a block-costed dish it follows the calculated rate unless an agreed rate was set in Cost Details.">Quoted Rate</th>
                            <th style="width:120px;" class="text-end">Amount</th>
                            <th style="min-width:150px;">Instructions</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lines-body">
                        {{-- KASHIF-CATERING-OPERATOR-UI-1: existing lines render
                             SERVER-SIDE so a block-costed line can show its
                             calculated vs quoted rate apart and open its Cost
                             Details without leaving the booking. The old
                             builder rendered every line in JS with one
                             ambiguous editable "Rate" — a number the server
                             rightly ignored for block lines, which made the
                             screen lie about what typing in it would do. --}}
                        @foreach($current->lines as $i => $line)
                        @php $lineBlocks = $line->costBlocks; $lineHasBlocks = $lineBlocks->isNotEmpty(); @endphp
                        <tr data-row="s{{ $i }}" @if($lineHasBlocks) data-rate="{{ $line->rate }}" @endif>
                            <td>
                                <div class="fw-semibold">{{ $line->item_name }}</div>
                                @if($lineHasBlocks)
                                    <button class="btn btn-link btn-sm p-0 align-baseline d-block fs-12" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#cost-details-{{ $line->id }}">
                                        Cost Details
                                    </button>
                                @endif
                                <input type="hidden" name="lines[{{ $i }}][line_uuid]" value="{{ $line->line_uuid }}">
                                <input type="hidden" name="lines[{{ $i }}][product_id]" value="{{ $line->product_id }}">
                                <input type="hidden" name="lines[{{ $i }}][item_name]" value="{{ $line->item_name }}">
                            </td>
                            <td><input type="text" dir="rtl" lang="ur" class="form-control form-control-sm"
                                       name="lines[{{ $i }}][item_name_ur]" value="{{ $line->item_name_ur }}"></td>
                            <td><input type="number" step="0.001" min="0.001"
                                       class="form-control form-control-sm text-end line-qty"
                                       name="lines[{{ $i }}][quantity]"
                                       value="{{ rtrim(rtrim(number_format($line->quantity, 3, '.', ''), '0'), '.') }}" required></td>
                            <td>
                                <select class="form-select form-select-sm" name="lines[{{ $i }}][unit_id]">
                                    <option value="">—</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}" @selected((string) $line->unit_id === (string) $u->id)>{{ $u->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="text-end align-middle">
                                @if($lineHasBlocks)
                                    {{ number_format($line->calculated_rate ?? 0, 2) }}
                                @else
                                    <span class="text-muted" title="This line is not priced from cost blocks">—</span>
                                @endif
                            </td>
                            <td class="text-end align-middle">
                                @if($lineHasBlocks)
                                    <span class="fw-semibold">{{ number_format($line->rate, 2) }}</span>
                                    @if($line->hasQuotedRateOverride())
                                        <div class="fs-12 text-warning-emphasis">agreed rate</div>
                                    @endif
                                    <div class="fs-12 text-muted">change in Cost Details</div>
                                    <input type="hidden" name="lines[{{ $i }}][rate]" value="{{ $line->rate }}">
                                @else
                                    <input type="number" step="0.01" min="0"
                                           class="form-control form-control-sm text-end line-rate"
                                           name="lines[{{ $i }}][rate]" value="{{ $line->rate }}" required>
                                @endif
                            </td>
                            <td class="text-end align-middle line-amount">0.00</td>
                            <td>
                                {{-- KASHIF-CATERING-INSTRUCTIONS-1: managed selections
                                     from the vocabulary + the free note beside them. --}}
                                @if($activeInstructions->isNotEmpty() || $line->managedInstructions->isNotEmpty())
                                    <select class="form-select form-select-sm instr-select" multiple
                                            name="lines[{{ $i }}][instruction_ids][]"
                                            data-placeholder="Kitchen instructions…">
                                        @foreach($activeInstructions as $instr)
                                            <option value="{{ $instr->id }}"
                                                    @selected($line->managedInstructions->contains('id', $instr->id))>{{ $instr->label }}</option>
                                        @endforeach
                                        {{-- A selection whose entry was later deactivated must
                                             survive the next save, so it stays selectable here. --}}
                                        @foreach($line->managedInstructions->whereNotIn('id', $activeInstructions->pluck('id')) as $instr)
                                            <option value="{{ $instr->id }}" selected>{{ $instr->label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="hidden" name="lines[{{ $i }}][instruction_ids]" value="">
                                @endif
                                <input type="text" class="form-control form-control-sm mt-1"
                                       name="lines[{{ $i }}][instructions]" value="{{ $line->instructions }}"
                                       placeholder="Additional note">
                            </td>
                            <td class="align-middle"><button type="button" class="btn btn-sm btn-link text-danger remove-line p-0"><i class="ti ti-x"></i></button></td>
                        </tr>
                        @if($lineHasBlocks)
                        <tr class="collapse cost-details-row" id="cost-details-{{ $line->id }}">
                            <td colspan="9" class="bg-body-secondary">
                                @include('tenant.catering.events.partials.line-cost-details', [
                                    'line' => $line, 'current' => $current, 'event' => $event,
                                ])
                            </td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
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
                    @php $blocks = $line->costBlocks; @endphp
                    <tr>
                        <td>
                            {{ $line->item_name }}
                            @if($blocks->isNotEmpty())
                                {{-- Compact by default: a twenty-dish quotation
                                     with every breakdown open is a wall. --}}
                                <button class="btn btn-link btn-sm p-0 align-baseline d-block fs-12"
                                        type="button" data-bs-toggle="collapse"
                                        data-bs-target="#cost-details-{{ $line->id }}">
                                    Cost Details
                                </button>
                            @endif
                        </td>
                        <td dir="rtl" lang="ur">{{ $line->item_name_ur }}</td>
                        <td class="text-end">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
                        <td>{{ $line->unit_code }}</td>
                        <td class="text-end">
                            {{ number_format($line->rate, 2) }}
                            @if($line->hasQuotedRateOverride())
                                <div class="fs-12 text-muted">
                                    calculated {{ number_format($line->calculated_rate, 2) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-end">
                            {{ number_format($line->amount, 2) }}
                            @if($line->lump_sum_amount > 0)
                                <div class="fs-12 text-muted">
                                    includes {{ number_format($line->lump_sum_amount, 2) }} once
                                </div>
                            @endif
                        </td>
                        <td>{{ $line->instructionSummary() }}</td>
                    </tr>

                    @if($blocks->isNotEmpty())
                    <tr class="collapse" id="cost-details-{{ $line->id }}">
                        <td colspan="7" class="bg-body-secondary">
                            @include("tenant.catering.events.partials.line-cost-details", [
                                "line" => $line, "current" => $current, "event" => $event,
                            ])
                        </td>
                    </tr>
                    @endif
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

{{-- ── Money + Production ────────────────────────────────────────────────
     KASHIF-CATERING-CUSTOMER-CREDIT-1: this card used to show only receipts,
     and only ever a balance clamped at zero. A booking holding more than it had
     billed for read as 0.00 and offered nothing to do about it. Both directions
     are now shown, and the position is named for whichever way it runs. --}}
@php $advanceTotal = $event->advances->sum('amount'); @endphp
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="mb-0">Receipts &amp; Refunds</h5>
                <div class="d-flex gap-2">
                    @if(! $event->isCancelled() && $position['balance_due'] > 0)
                        @can('tenant.catering.advances.store')
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#advanceModal"
                                    title="Posts the payment to the general ledger and increases the selected cash/bank balance.">Record Advance</button>
                        @endcan
                    @endif
                    @if($position['refundable'] > 0)
                        @can('tenant.catering.refunds.store')
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#refundModal"
                                    title="Pays the customer back what they are owed. Posts to the general ledger and reduces the selected cash/bank balance.">
                                <i class="ti ti-arrow-back-up me-1"></i>Refund Customer
                            </button>
                        @endcan
                    @endif
                </div>
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

                        @foreach($event->refunds as $refund)
                        <tr class="text-warning-emphasis">
                            <td>{{ $refund->refund_date->format('d M Y') }}</td>
                            <td>Refund · {{ $refund->paymentMethod?->name ?? '—' }}</td>
                            <td class="text-muted">{{ $refund->refund_no }}</td>
                            <td class="text-end">({{ number_format($refund->amount, 2) }})</td>
                        </tr>
                        @endforeach

                        @if($event->advances->isNotEmpty())
                        <tr class="fw-bold">
                            <td colspan="3">Received{{ $position['refunded'] > 0 ? ', less refunds' : '' }}</td>
                            <td class="text-end">{{ number_format($position['net_received'], 2) }}</td>
                        </tr>
                        <tr class="fw-bold">
                            <td colspan="3">
                                {{ $headline['label'] }}
                                <span class="fw-normal text-muted fs-12">
                                    @if($position['billed_source'] === 'invoice') vs invoice
                                    @elseif($position['billed_source'] === 'cancelled') · nothing billed, booking cancelled
                                    @elseif($current) vs Q{{ $current->version_no }}
                                    @endif
                                </span>
                            </td>
                            <td class="text-end text-{{ $headline['tone'] }}">{{ number_format($headline['amount'], 2) }}</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted fs-12">
                @if($position['customer_credit'] > 0)
                    <i class="ti ti-alert-triangle text-warning"></i>
                    This booking is holding <strong>{{ number_format($position['customer_credit'], 2) }}</strong>
                    that belongs to the customer. No further payment can be taken until it is refunded,
                    or the quotation is raised to cover it.
                @else
                    <i class="ti ti-alert-circle text-primary"></i>
                    Recording an advance <strong>posts to the general ledger</strong> and increases the
                    mapped cash/bank balance. It does not move stock.
                @endif
            </div>
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
                            <button class="btn btn-sm btn-success" data-bs-toggle="tooltip"
                                    title="Freezes the dish list into a kitchen sheet and queues it to the mapped kitchen printers. Still moves no stock — that happens when you issue materials."
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
                                <div class="fs-12">Finalize the quotation first.</div>
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
@php $invoice = $event->finalInvoice; $liveBalance = $position['balance_due']; @endphp
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0">Billing &amp; Closure</h5>
        <div class="d-flex gap-2">
            @if(! $invoice && $current && ! $current->isDraft() && in_array($event->status, ['confirmed', 'production_ready', 'released']))
                @can('tenant.catering.final-invoices.store')
                    <form method="POST" action="{{ url('/catering/events/' . $event->id . '/final-invoice') }}">
                        @csrf
                        <button class="btn btn-sm btn-primary" data-bs-toggle="tooltip"
                                title="Posts revenue and receivables to the general ledger, applies advances already received, and freezes the document. This cannot be edited or deleted afterwards."
                                onclick="return confirm('Issue the final invoice for {{ $event->event_no }}? This posts to the general ledger and freezes the document permanently.')">
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
                @can('tenant.catering.final-invoices.email')
                    {{-- KASHIF-CATERING-EMAIL-1: deliberate (re)send of the frozen invoice. --}}
                    <form method="POST" action="{{ url('/catering/final-invoices/' . $invoice->id . '/email') }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip"
                                title="{{ $event->customer_email ? 'Emails invoice '.$invoice->invoice_no.' to '.$event->customer_email.'.' : 'No customer email on file — add one on Edit Event first.' }}"
                                onclick="return confirm('Email invoice {{ $invoice->invoice_no }} to the customer now?')">
                            <i class="ti ti-mail-forward me-1"></i>Email Invoice
                        </button>
                    </form>
                @endcan
                @can('tenant.catering.documents.final-invoice')
                    @include('tenant.catering.partials.document-print', [
                        'action' => url('/catering/documents/final-invoice/' . $invoice->id . '/print'),
                        'label' => 'Send invoice to printer',
                        'printers' => $printers ?? collect(),
                        'permission' => 'tenant.catering.documents.final-invoice-print',
                    ])
                @endcan
                @if($event->status === 'completed')
                    @can('tenant.catering.events.close')
                        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/close') }}">
                            @csrf
                            <button class="btn btn-sm btn-dark" {{ ! $headline['settled'] ? 'disabled title=Unsettled-money-blocks-closure' : '' }}
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
                <dt class="col-3 text-muted">Received</dt>
                <dd class="col-9">
                    {{ number_format($position['net_received'], 2) }}
                    @if($position['refunded'] > 0)
                        <span class="fs-12 text-muted">— {{ number_format($position['gross_received'], 2) }} taken, {{ number_format($position['refunded'], 2) }} refunded</span>
                    @endif
                </dd>
                <dt class="col-3 text-muted">{{ $headline['label'] }}</dt>
                <dd class="col-9 fw-bold text-{{ $headline['tone'] }}">
                    {{ number_format($headline['amount'], 2) }}
                    @if($position['balance_due'] > 0)
                        <span class="fs-12 fw-normal text-muted">— record the final payment as an advance to enable closure.</span>
                    @elseif($position['customer_credit'] > 0)
                        <span class="fs-12 fw-normal text-muted">— the invoice could not absorb all of it. Refund the difference to close the booking.</span>
                    @else
                        <span class="badge bg-success">Fully settled</span>
                    @endif
                </dd>
            </div>
        @else
            <div class="text-muted">
                No final invoice yet — available once the booking is confirmed.
                <span class="d-block mt-1">
                    <i class="ti ti-alert-circle text-primary"></i>
                    Issuing it <strong>posts revenue and receivables to the general ledger</strong>,
                    applies any advances against the invoice, and freezes the document permanently.
                </span>
            </div>
        @endif
    </div>
</div>

@if(! $event->isCancelled())
@can('tenant.catering.advances.store')
{{-- ── Cancel booking (item 9) ───────────────────────────────────────────
     The reason becomes part of the record, and the advance situation is stated
     here rather than discovered afterwards: money already received stays on the
     ledger, because a cancel button must not rewrite the books. --}}
@can('tenant.catering.events.cancel')
@if($event->isOpen())
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/cancel') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Cancel {{ $event->event_no }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @if($advanceTotal > 0)
                    <div class="alert alert-warning">
                        <i class="ti ti-alert-triangle me-1"></i>
                        <strong>{{ number_format($position['net_received'], 2) }}</strong> has already been received
                        against this booking.
                        <span class="d-block mt-1">
                            Cancelling does <strong>not</strong> refund it. The payment stays on the
                            ledger exactly as recorded. A cancelled booking bills nothing, so this money
                            becomes <strong>credit owed to the customer</strong>, and the Refund
                            action on this page is how you hand it back — deliberately, and on its own record.
                        </span>
                    </div>
                @else
                    <div class="alert alert-light border">
                        <i class="ti ti-info-circle me-1"></i>
                        No advance has been received, so nothing financial changes.
                    </div>
                @endif

                <label class="form-label" for="cancel_reason">
                    Why is this being cancelled? <span class="text-danger">*</span>
                </label>
                <textarea id="cancel_reason" name="cancel_reason" class="form-control" rows="3" required
                          minlength="3" maxlength="2000"
                          placeholder="e.g. customer postponed the wedding to November"></textarea>
                <div class="form-text">Kept on the booking permanently. This cannot be undone.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep booking</button>
                <button type="submit" class="btn btn-danger">Cancel this booking</button>
            </div>
        </form>
    </div>
</div>
@endif
@endcan

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
                {{-- This line used to claim the opposite of what the action does:
                     it said no GL or cash-bank posting happened, while the very
                     same submit posts a journal entry and moves the drawer. --}}
                <div class="text-muted fs-12 mt-2">
                    Posts to the general ledger and increases the selected cash/bank balance.
                    Nothing may be taken beyond {{ number_format($position['balance_due'], 2) }}, the amount still due.
                </div>
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

{{-- ── Refund the customer ───────────────────────────────────────────────
     Deliberately OUTSIDE the "not cancelled" guard above. A cancelled booking
     is precisely when someone needs their money back, so the one action that
     settles it must still be reachable there. --}}
@can('tenant.catering.refunds.store')
@if($position['refundable'] > 0)
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ url('/catering/events/' . $event->id . '/refunds') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Refund Customer — {{ $event->event_no }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="ti ti-alert-triangle me-1"></i>
                    This booking owes the customer <strong>{{ number_format($position['refundable'], 2) }}</strong>.
                    <span class="d-block mt-1 fs-12">
                        This pays real money out: it posts to the general ledger and reduces the selected
                        cash/bank balance. The original receipts are never altered — this is recorded
                        beside them, as its own numbered document.
                    </span>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" max="{{ $position['refundable'] }}"
                               name="amount" class="form-control" value="{{ $position['refundable'] }}" required>
                        <div class="form-text">At most {{ number_format($position['refundable'], 2) }}. Part of it is fine.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Refund Date <span class="text-danger">*</span></label>
                        <input type="date" name="refund_date" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Paid From <span class="text-danger">*</span></label>
                        <select name="payment_method_id" class="form-select" required>
                            <option value="" disabled selected>Choose an account…</option>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                        {{-- Required here, unlike on a receipt. Cash can honestly
                             turn up without a named account; money leaving has to
                             leave from somewhere. --}}
                        <div class="form-text">
                            The cash or bank the money leaves from. It must be linked
                            to a live account, or the refund is refused.
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Cheque / transaction #">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Why is this being refunded? <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control" required maxlength="255"
                               placeholder="e.g. booking cancelled by customer">
                        <div class="form-text">Kept on the refund permanently.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-warning">Pay the customer back</button>
            </div>
        </form>
    </div>
</div>
@endif
@endcan

{{-- ── Booking statement ─────────────────────────────────────────────────
     Every financial event on this booking, in order, ending on exactly the
     figure shown above. Both come from one service, so a customer adding up
     the rows can never reach a different answer from the screen. --}}
@if(count($ledger) > 0)
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="mb-0">Booking Statement</h5>
        <span class="fs-12 text-muted">Every receipt, refund and bill on this booking</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>What happened</th>
                        <th>Reference</th>
                        <th class="text-end">Money in</th>
                        <th class="text-end">Money out</th>
                        <th class="text-end">Charged</th>
                        <th class="text-end">Position</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ledger as $row)
                    <tr class="{{ $row['informational'] ? 'text-muted' : '' }}">
                        <td class="text-nowrap">{{ $row['date'] }}</td>
                        <td>
                            {{ $row['type'] }}
                            @if($row['note'])
                                <div class="fs-12 text-muted">{{ $row['note'] }}</div>
                            @endif
                        </td>
                        <td class="text-muted fs-12">{{ $row['reference'] ?? '—' }}</td>
                        <td class="text-end">{{ $row['money_in'] > 0 ? number_format($row['money_in'], 2) : '' }}</td>
                        <td class="text-end">{{ $row['money_out'] > 0 ? number_format($row['money_out'], 2) : '' }}</td>
                        <td class="text-end">{{ $row['charged'] > 0 ? number_format($row['charged'], 2) : '' }}</td>
                        <td class="text-end fw-semibold {{ $row['running'] > 0 ? 'text-warning' : ($row['running'] < 0 ? 'text-danger' : '') }}">
                            {{ number_format(abs($row['running']), 2) }}
                            @if($row['running'] > 0)
                                <span class="fs-12 fw-normal">Cr</span>
                            @elseif($row['running'] < 0)
                                <span class="fs-12 fw-normal">Dr</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted fs-12">
        <strong>Cr</strong> is money the business is holding for the customer;
        <strong>Dr</strong> is money the customer still owes. Applying an advance moves nothing —
        it records that money already held is now covering the bill.
    </div>
</div>
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
@can('tenant.catering.events.edit')
@if($event->isOpen())
{{-- KASHIF-CATERING-NO-RELOAD-2 — Edit Event beside the work. data-no-ajax
     keeps the generic workspace engine away: this form has its OWN handler,
     because a validation mistake must keep the panel open with every typed
     value intact, and only a SUCCESS closes it and re-renders the details. --}}
<div class="offcanvas offcanvas-end" tabindex="-1" id="editEventOffcanvas" style="width:min(720px,95vw)">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title">Edit Event {{ $event->event_no }}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <form method="POST" action="{{ url('/catering/events/' . $event->id) }}"
              data-event-ajax="refresh" data-no-ajax>
            @csrf
            @method('PUT')
            @include('tenant.catering.events.partials.event-form-fields', ['event' => $event])
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>
@endif
@endcan
@include('tenant.catering.events.partials.event-form-support')
</div>{{-- /#event-workspace --}}
@endsection

@push('scripts')
@if($current && $isDraft && $event->isOpen())
@php
    // Build the JS payloads in PHP, NOT inline inside @json(...). Blade matches a directive
    // argument with a RECURSIVE paren regex; on a long multi-line payload that
    // match hits PCRE limits and SILENTLY TRUNCATES, emitting an unbalanced
    // bracket — invalid PHP that `view:cache` still reports as "cached successfully".
    // KASHIF-CATERING-OPERATOR-UI-1: existing lines render server-side now (so a
    // block-costed line can show calculated vs quoted apart, with Cost Details
    // inline); this script only manages NEWLY ADDED rows and the running totals.
    $unitsPayload = $units->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name])->values();
    $instructionsPayload = $activeInstructions->map(fn ($i) => ['id' => $i->id, 'label' => $i->label])->values();
@endphp
<script>
$(function () {
    const units = @json($unitsPayload);
    const profiles = @json($profileMap);
    const instructions = @json($instructionsPayload);
    const defaultPax = {{ (int) $event->pax }};

    // Name indexes continue after the HIGHEST index present in the DOM — read
    // fresh every time, because the workspace re-renders in place after each
    // action and a stale counter would collide with the new server rows.
    function nextRowIndex() {
        let max = -1;
        $('#lines-body [name^="lines["]').each(function () {
            const m = /^lines\[(\d+)\]/.exec($(this).attr('name') || '');
            if (m) max = Math.max(max, parseInt(m[1], 10));
        });
        return max + 1;
    }

    function instructionOptions() {
        return instructions.map(i => `<option value="${i.id}">${_.escape(i.label)}</option>`).join('');
    }

    function initInstrSelect(scope) {
        $(scope || document).find('.instr-select').each(function () {
            if ($(this).hasClass('select2-hidden-accessible')) return;
            $(this).select2({ width: '100%', placeholder: $(this).data('placeholder') || 'Kitchen instructions…' });
        });
    }

    function unitOptions(selectedId) {
        let html = '<option value="">—</option>';
        units.forEach(u => {
            html += `<option value="${u.id}" ${String(selectedId) === String(u.id) ? 'selected' : ''}>${u.code}</option>`;
        });
        return html;
    }

    function addRow(line) {
        line = line || {};
        const i = nextRowIndex();
        const row = $(`
            <tr data-row="${i}">
                <td>
                    <select class="form-select form-select-sm product-select" name="lines[${i}][product_id]"></select>
                    <input type="hidden" name="lines[${i}][item_name]" class="line-name" value="${_.escape(line.item_name || '')}">
                    <input type="hidden" name="lines[${i}][line_uuid]" value="${_.escape(line.line_uuid || '')}">
                </td>
                <td><input type="text" dir="rtl" lang="ur" class="form-control form-control-sm line-name-ur" name="lines[${i}][item_name_ur]" value="${_.escape(line.item_name_ur || '')}"></td>
                <td><input type="number" step="0.001" min="0.001" class="form-control form-control-sm text-end line-qty" name="lines[${i}][quantity]" value="${line.quantity || defaultPax || 1}" required></td>
                <td><select class="form-select form-select-sm line-unit" name="lines[${i}][unit_id]">${unitOptions(line.unit_id)}</select></td>
                <td class="text-end align-middle text-muted line-calc" title="A block-costed dish prices itself from its Cost Blocks">—</td>
                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end line-rate" name="lines[${i}][rate]" value="${line.rate || 0}" required></td>
                <td class="text-end align-middle line-amount">0.00</td>
                <td>
                    ${instructions.length ? `<select class="form-select form-select-sm instr-select" multiple name="lines[${i}][instruction_ids][]" data-placeholder="Kitchen instructions…">${instructionOptions()}</select>` : ''}
                    <input type="text" class="form-control form-control-sm mt-1" name="lines[${i}][instructions]" value="${_.escape(line.instructions || '')}" placeholder="Additional note">
                </td>
                <td class="align-middle"><button type="button" class="btn btn-sm btn-link text-danger remove-line p-0"><i class="ti ti-x"></i></button></td>
            </tr>`);
        $('#lines-body').append(row);
        initInstrSelect(row);

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
                    // KASHIF-CLIENT-MENU-3: a block-costed dish shows its price the
                    // moment it is picked. The rate box locks — this number comes
                    // from the dish's Cost Blocks; after Save the full breakdown
                    // (Cost Details) appears on the line, where agreed-rate
                    // overrides live. Typing here would be discarded on save
                    // anyway, and a box that lies is how operators get lost.
                    if (p.blocks) {
                        row.find('.line-rate').val(p.rate).prop('readonly', true)
                            .attr('title', 'Priced from Cost Blocks — save the estimate, then adjust in Cost Details');
                        row.find('.line-calc').text(Number(p.rate).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))
                            .removeClass('text-muted');
                    } else {
                        row.find('.line-rate').prop('readonly', false).removeAttr('title');
                        row.find('.line-calc').text('—').addClass('text-muted');
                    }
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
        $('#lines-body tr').not('.cost-details-row').each(function () {
            const qty = parseFloat($(this).find('.line-qty').val()) || 0;
            // A block-costed line's quoted rate is not an input here — the row
            // carries it as data-rate and it only changes through Cost Details.
            const rate = $(this).find('.line-rate').length
                ? (parseFloat($(this).find('.line-rate').val()) || 0)
                : (parseFloat($(this).data('rate')) || 0);
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

    $(document).on('click', '#add-line', () => addRow());
    $(document).on('input change', '.line-qty, .line-rate, .t-input', recalc);
    $(document).on('click', '.remove-line', function () {
        const tr = $(this).closest('tr');
        // A block-costed row travels with its Cost Details row.
        tr.next('.cost-details-row').remove();
        tr.remove();
        recalc();
    });

    // KASHIF-CATERING-OPERATOR-UI-1: Cost Details actions post through a form
    // built on the fly. They cannot be real <form> tags — this whole table sits
    // inside the estimate form, and HTML silently drops a nested form, which is
    // why these controls could never live on the draft screen before.
    $(document).on('click', '.js-act', function () {
        const box = $(this).closest('[data-act]');
        // KASHIF-CATERING-NO-RELOAD-1: post through the workspace pipeline —
        // the page swaps in place instead of navigating.
        const fd = new FormData();
        fd.append('_token', '{{ csrf_token() }}');
        const method = String(box.data('act-method') || 'POST').toUpperCase();
        if (method !== 'POST') fd.append('_method', method);
        box.find('[data-field]').each(function () {
            fd.append($(this).data('field'), $(this).val());
        });
        window.cateringAjaxSubmit(box.data('act'), fd);
    });
    // Enter inside a Cost Details control fires that control's action — not the
    // surrounding Save Estimate form.
    $(document).on('keydown', '[data-act] input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('[data-act]').find('.js-act').first().trigger('click');
        }
    });

    // Runs at load AND after every in-place swap: fresh selects need select2,
    // an emptied estimate needs its first row, totals need recomputing.
    window.initEstimateBuilder = function () {
        initInstrSelect(document);
        if ($('#lines-body tr').not('.cost-details-row').length === 0) {
            addRow();
        }
        recalc();
    };
    window.initEstimateBuilder();
});
</script>
@endif
@endpush
