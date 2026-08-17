@extends('layouts.app')

@section('title', 'Store Issue')

@section('content')
@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.submit-guard')

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Store Issue</h1>
        <p class="fw-medium mb-0">Material handed over the counter to the kitchen.</p>
    </div>
</div>

@include('tenant.catering.partials.screen-impact', [
    'manages' => 'Material physically handed from the store to the kitchen — in whatever quantity was actually given.',
    'managesUr' => 'اسٹور سے کچن کو دیا گیا خام مال — جتنا واقعی دیا گیا۔',
    'finance' => 'Cost of goods sold posts at the real batch price',
    'stock' => 'Stock is reduced immediately, using FEFO',
    'reversible' => 'irreversible',
    'note' => 'Record this when the material actually leaves the store, not when an order is booked. A booking reference is optional — it is a note, not a requirement, and an issue with no reference is a complete record.',
    'noteUr' => 'یہ اندراج تب کریں جب مال واقعی اسٹور سے نکلے۔ آرڈر نمبر لکھنا اختیاری ہے۔',
])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- ── issue counter ─────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Hand material over</h5></div>
    <div class="card-body">
        @if(! $hasMaterials)
            <div class="alert alert-warning mb-0">
                <i class="ti ti-alert-triangle me-1"></i>
                No stock-tracked materials exist yet. Add them under
                <a href="{{ url('/catering/materials') }}">Catering &rsaquo; Materials</a> first.
            </div>
        @else
        <form method="POST" action="{{ url('/catering/store-issues') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" class="form-select" required>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Against bookings <span class="text-muted fs-12">(optional)</span></label>
                    <select name="event_ids[]" class="form-select" multiple size="4" id="event-picker">
                        @foreach($events as $event)
                            <option value="{{ $event->id }}">
                                {{ $event->event_no }} · {{ $event->customer_name }} · {{ $event->event_date->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                    {{-- References, not allocations: this says the material went
                         out for these bookings, never how much each one took. --}}
                    <div class="form-text">
                        Select as many as this trip covers, or none for daily prep and staff food.
                        The quantities below are not split between them.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Note</label>
                    <input type="text" name="note" class="form-control" maxlength="500"
                           value="{{ old('note') }}" placeholder="e.g. covers tomorrow's three weddings">
                </div>
            </div>

            {{-- KASHIF-CATERING-STORE-2: a searchable picker, not a list.
                 A plain select is fine at fifteen materials and unusable at two
                 hundred — the storeman scrolls past the thing he wants. It reuses
                 the repository's standard select2 + /ajax/products picker, asking
                 for only what a store can physically hand over. --}}
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="issue-lines">
                    <thead>
                        <tr>
                            <th style="width:55%">Material</th>
                            <th style="width:25%">Quantity handed over</th>
                            <th style="width:20%"></th>
                        </tr>
                    </thead>
                    <tbody id="issue-lines-body">
                        @for($i = 0; $i < 3; $i++)
                        <tr class="issue-line">
                            <td>
                                <select name="lines[{{ $i }}][product_id]" class="form-select form-select-sm material-picker">
                                    <option value="">Search materials by name or code…</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.001" min="0" name="lines[{{ $i }}][quantity]"
                                       class="form-control form-control-sm" placeholder="0">
                            </td>
                            <td class="text-muted fs-12">
                                @if($i === 0)Quantity is whatever was actually given — it need not match any order.@endif
                            </td>
                        </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="add-issue-line">
                <i class="ti ti-plus me-1"></i>Add another material
            </button>

            <button class="btn btn-warning" type="submit"
                    data-bs-toggle="tooltip"
                    title="Reduces stock immediately using FEFO and posts cost of goods sold at the real batch price. This cannot be undone from here."
                    onclick="return confirm('Issue this material? Stock will be reduced and cost posted. This cannot be undone.')">
                <i class="ti ti-package-export me-1"></i>Issue material
            </button>
        </form>
        @endif
    </div>
</div>

{{-- ── history ───────────────────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header"><h5 class="mb-0">Recent issues</h5></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Issue</th><th>When</th><th>Branch</th><th>Against</th>
                    <th>Materials</th><th class="text-end">Cost</th>
                </tr>
            </thead>
            <tbody>
            @forelse($issues as $issue)
                <tr>
                    <td><code>{{ $issue->issue_no }}</code></td>
                    <td>{{ $issue->issued_at?->format('d M Y g:i A') }}</td>
                    <td>{{ $issue->branch?->name ?? '—' }}</td>
                    <td>
                        @php $against = $issue->events; @endphp
                        @if($against->isEmpty())
                            <span class="text-muted fst-italic">general issue</span>
                        @else
                            {{-- Two names and a count, not twelve names in one cell.
                                 The full list is a click away rather than a wall. --}}
                            @foreach($against->take(2) as $event)
                                <a href="{{ url('/catering/events/' . $event->id) }}">{{ $event->event_no }}</a>@if(! $loop->last), @endif
                            @endforeach
                            @if($against->count() > 2)
                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline"
                                        data-bs-toggle="modal" data-bs-target="#issueBookings{{ $issue->id }}">
                                    +{{ $against->count() - 2 }} more
                                </button>
                            @endif
                        @endif
                    </td>
                    <td class="fs-13">
                        @foreach($issue->lines->take(4) as $line)
                            {{ $line->item_name }} {{ rtrim(rtrim(number_format($line->issued_qty, 3), '0'), '.') }}{{ $line->unit_code }}@if(! $loop->last), @endif
                        @endforeach
                        @if($issue->lines->count() > 4)<span class="text-muted"> +{{ $issue->lines->count() - 4 }} more</span>@endif
                    </td>
                    <td class="text-end">{{ number_format($issue->total_fefo_cost, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No material has been issued yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($issues->hasPages())
        <div class="card-footer">{{ $issues->withQueryString()->links() }}</div>
    @endif
</div>

{{-- The complete booking list for each issue that has more than fits in a cell. --}}
@foreach($issues as $issue)
    @if($issue->events->count() > 2)
    <div class="modal fade" id="issueBookings{{ $issue->id }}" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $issue->issue_no }} — {{ $issue->events->count() }} bookings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @foreach($issue->events as $event)
                            <tr>
                                <td><a href="{{ url('/catering/events/' . $event->id) }}">{{ $event->event_no }}</a></td>
                                <td>{{ $event->customer_name }}</td>
                                <td class="text-muted">{{ $event->event_date?->format('d M Y') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <span class="text-muted fs-12 me-auto">
                        These bookings are why the material left the store. The quantities were not split between them.
                    </span>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endif
@endforeach
@endsection

@push('scripts')
<script>
$(function () {
    // The repository's standard searchable picker. `context` asks the shared
    // lookup for stock-tracked raw and packaging materials only, so a sale item
    // can never be offered to the store counter.
    function pickerFor($select) {
        $select.select2({
            width: '100%',
            placeholder: 'Search materials by name or code…',
            allowClear: true,
            ajax: {
                url: '{{ url('/ajax/products') }}',
                dataType: 'json',
                delay: 200,
                data: params => ({
                    q: params.term,
                    page: params.page || 1,
                    context: 'catering_store_issue',
                }),
                processResults: data => ({
                    results: data.results || [],
                    pagination: data.pagination || {},
                }),
            },
        });
    }

    $('.material-picker').each(function () { pickerFor($(this)); });

    $('#add-issue-line').on('click', function () {
        const index = $('#issue-lines-body .issue-line').length;
        const $row = $(
            '<tr class="issue-line">' +
              '<td><select name="lines[' + index + '][product_id]" class="form-select form-select-sm material-picker">' +
                '<option value="">Search materials by name or code…</option>' +
              '</select></td>' +
              '<td><input type="number" step="0.001" min="0" name="lines[' + index + '][quantity]" ' +
                'class="form-control form-control-sm" placeholder="0"></td>' +
              '<td></td>' +
            '</tr>'
        );
        $('#issue-lines-body').append($row);
        pickerFor($row.find('.material-picker'));
    });
});
</script>
@endpush
