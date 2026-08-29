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
                {{-- The storeman's question is "what is going out tonight", never
                     "search every booking". So the date leads, inside a modal,
                     and the selection is summarised out here. --}}
                <div class="col-md-4">
                    <label class="form-label">Against bookings <span class="text-muted fs-12">(optional)</span></label>
                    <div id="selected-bookings-box" class="border rounded p-2">
                        <div id="selected-summary" class="fst-italic text-muted">General issue — no bookings</div>
                        <div id="selected-chips" class="fs-12 mt-1"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2"
                                data-bs-toggle="modal" data-bs-target="#bookingModal" id="open-booking-modal">
                            <i class="ti ti-calendar-search me-1"></i>Select bookings
                        </button>
                    </div>
                    <div id="selected-inputs"></div>
                    {{-- References, not allocations: this says the material went
                         out for these bookings, never how much each one took. --}}
                    <div class="form-text">
                        As many as this trip covers, or none for daily prep and staff food.
                        The quantities below are not split between them.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Note</label>
                    <input type="text" name="note" class="form-control" maxlength="500"
                           value="{{ old('note') }}" placeholder="e.g. covers tomorrow's three weddings">
                </div>
            </div>

            {{-- CAT-STORE-001 / CAT-STORE-002 — what the selected bookings still
                 need, before anything is handed over.
                 Five numbers, because they are five different questions, and the
                 storeman was previously shown none of them: he could see what one
                 production release needed and never what today's bookings still
                 owed after earlier trips. --}}
            <div id="requirement-panel" class="card border-primary-subtle mb-3 d-none">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
                    <h6 class="mb-0"><i class="ti ti-clipboard-list me-1"></i>What these bookings still need</h6>
                    <span class="text-muted fs-12">Planning only — nothing moves until you issue below</span>
                </div>
                <div id="requirement-shared" class="alert alert-warning mb-0 rounded-0 fs-13 d-none"></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Unit</th>
                                <th class="text-end">Kitchen needs</th>
                                <th class="text-end">Customer supplied</th>
                                <th class="text-end">From our store</th>
                                <th class="text-end">Already issued</th>
                                <th class="text-end">Remaining</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="requirement-body"></tbody>
                    </table>
                </div>
                <div class="card-footer py-2 fs-12 text-muted">
                    A booking reference records that material went out <em>for</em> these bookings —
                    never how much each one took. Where an earlier issue also covered a booking you have
                    not selected, its quantity is shown separately rather than guessed at.
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

            {{-- CAT-STORE-001: issuing more than the bookings still need is a
                 real thing — a deg needs more than anyone planned — but it must
                 be a decision somebody made and signed, not the same sheet handed
                 over twice by two people an hour apart. --}}
            <div class="border rounded p-2 mb-3 bg-body-secondary">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="over_issue" value="1"
                           id="over-issue" {{ old('over_issue') ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="over-issue">
                        This is more than these bookings still need
                    </label>
                </div>
                <div class="fs-12 text-muted mb-2">
                    Leave unticked and the issue is limited to what is left. Tick it only when extra
                    material really is going out.
                </div>
                <input type="text" name="over_issue_reason" class="form-control form-control-sm"
                       maxlength="255" value="{{ old('over_issue_reason') }}"
                       placeholder="Why is extra material leaving the store?">
            </div>

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

{{-- ── Booking selection ─────────────────────────────────────────────────
     One trip to the store covers many bookings. The date leads because that is
     how a storeman thinks about the day; search narrows within it. --}}
<div class="modal fade" id="bookingModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Which bookings is this material for?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fs-12 text-muted mb-1">Event date</label>
                        <input type="date" id="booking-date" class="form-control form-control-sm"
                               value="{{ app(\App\Support\TenantClock::class)->now()->format('Y-m-d') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fs-12 text-muted mb-1">Search</label>
                        <input type="text" id="booking-search" class="form-control form-control-sm"
                               placeholder="Booking no, customer, phone or venue…">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-light w-100" id="booking-clear-date"
                                title="Search across every date instead of one">Any date</button>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="booking-select-all">
                        <label class="form-check-label fs-13" for="booking-select-all">
                            Select all shown
                        </label>
                    </div>
                    <span class="text-muted fs-12" id="booking-count"></span>
                </div>

                <div id="booking-results" class="list-group"></div>
                <div id="booking-empty" class="text-center text-muted py-4 d-none">
                    No bookings match. Try another date, or clear the search.
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted fs-12 me-auto">
                    These are the reasons the material left the store — quantities are not split between them.
                </span>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="booking-attach" data-bs-dismiss="modal">
                    Attach bookings
                </button>
            </div>
        </div>
    </div>
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

    // ── Booking selection ────────────────────────────────────────────────
    // `chosen` survives closing and reopening the modal, and survives changing
    // the date — a storeman covering two days should not lose yesterday's ticks
    // by looking at today.
    const chosen = new Map();
    let shown = [];

    const esc = s => $('<div>').text(s == null ? '' : s).html();

    function renderSelection() {
        const n = chosen.size;
        $('#selected-inputs').html(
            [...chosen.keys()].map(id =>
                '<input type="hidden" name="event_ids[]" value="' + id + '">').join('')
        );

        if (n === 0) {
            $('#selected-summary').addClass('fst-italic text-muted').text('General issue — no bookings');
            $('#selected-chips').empty();
            $('#requirement-panel').addClass('d-none');
            return;
        }

        $('#selected-summary').removeClass('fst-italic text-muted')
            .text('Selected bookings: ' + n);

        const labels = [...chosen.values()].slice(0, 3).map(b => esc(b.event_no));
        $('#selected-chips').html(labels.join(' · ') + (n > 3 ? ' · +' + (n - 3) : ''));

        loadRequirements();
    }

    // ── What these bookings still need ────────────────────────────────────
    // CAT-STORE-001: the storeman sees this BEFORE he writes a quantity. It is
    // read-only — asking the question moves no stock and reserves nothing.
    const qty = n => (Math.round(n * 1000) / 1000).toLocaleString(undefined, {maximumFractionDigits: 3});

    function loadRequirements() {
        const ids = [...chosen.keys()];
        if (ids.length === 0) { $('#requirement-panel').addClass('d-none'); return; }

        $.get('{{ url('/catering/store-issues/requirements') }}', {
            event_ids: ids,
            branch_id: $('[name=branch_id]').val() || null,
        }).done(renderRequirements).fail(() => $('#requirement-panel').addClass('d-none'));
    }

    function renderRequirements(data) {
        const rows = (data && data.rows) || [];
        if (rows.length === 0) { $('#requirement-panel').addClass('d-none'); return; }

        // CAT-STORE-002: a quantity we cannot attribute is named, not spread.
        const sharedTotal = rows.reduce((s, r) => s + (r.shared_unallocated_qty || 0), 0);
        if (sharedTotal > 0) {
            const names = rows.filter(r => (r.shared_unallocated_qty || 0) > 0)
                .map(r => esc(r.name) + ' ' + qty(r.shared_unallocated_qty) + ' ' + esc(r.unit_code || ''));
            $('#requirement-shared').removeClass('d-none').html(
                '<strong>Some earlier material cannot be attributed to just these bookings.</strong> ' +
                'It went out on an issue that also covered a booking you have not selected, and the ' +
                'system does not record how it was split — so it is not subtracted from Remaining below: ' +
                names.join(' · ') + '. Select those bookings too, or decide the quantity yourself.'
            );
        } else {
            $('#requirement-shared').addClass('d-none').empty();
        }

        $('#requirement-body').html(rows.map(r => {
            const supplied = r.customer_supplied_qty || 0;
            const certain = r.remaining_is_certain !== false;
            return '<tr>' +
                '<td>' + esc(r.name) +
                    (supplied > 0 ? ' <span class="badge bg-success-subtle text-success-emphasis fs-12">customer supplied</span>' : '') +
                '</td>' +
                '<td class="text-muted">' + esc(r.unit_code || '') + '</td>' +
                '<td class="text-end">' + qty(r.physical_qty || 0) + '</td>' +
                '<td class="text-end ' + (supplied > 0 ? 'text-success' : 'text-muted') + '">' +
                    (supplied > 0 ? qty(supplied) : '—') + '</td>' +
                '<td class="text-end fw-semibold">' + qty(r.required_qty || 0) +
                    (supplied > 0 && (r.required_qty || 0) === 0
                        ? '<div class="fs-12 text-muted">not ours to issue</div>' : '') +
                '</td>' +
                '<td class="text-end">' + ((r.issued_qty || 0) > 0 ? qty(r.issued_qty) : '—') + '</td>' +
                '<td class="text-end ' + (certain ? 'fw-bold' : 'text-warning-emphasis') + '">' +
                    (certain ? qty(r.remaining_qty || 0) : 'not certain') + '</td>' +
                '<td class="text-end">' +
                    (certain && (r.remaining_qty || 0) > 0
                        ? '<button type="button" class="btn btn-sm btn-light use-remaining" ' +
                          'data-product="' + r.product_id + '" data-name="' + esc(r.name) + '" ' +
                          'data-qty="' + (r.remaining_qty || 0) + '">Issue this</button>'
                        : '') +
                '</td>' +
            '</tr>';
        }).join(''));

        $('#requirement-panel').removeClass('d-none');
    }

    $(document).on('change', '[name=branch_id]', loadRequirements);

    function renderResults() {
        if (shown.length === 0) {
            $('#booking-results').empty();
            $('#booking-empty').removeClass('d-none');
            $('#booking-count').text('');
            $('#booking-select-all').prop('checked', false);
            return;
        }

        $('#booking-empty').addClass('d-none');
        $('#booking-count').text(shown.length + ' booking' + (shown.length === 1 ? '' : 's') + ' shown');

        $('#booking-results').html(shown.map(b =>
            '<label class="list-group-item d-flex gap-2 align-items-start">' +
              '<input class="form-check-input mt-1 booking-tick" type="checkbox" value="' + b.id + '"' +
                (chosen.has(String(b.id)) ? ' checked' : '') + '>' +
              '<span class="flex-grow-1">' +
                '<span class="fw-semibold">' + esc(b.event_no) + '</span> · ' + esc(b.customer) +
                (b.phone ? ' <span class="text-muted fs-12">' + esc(b.phone) + '</span>' : '') +
                '<span class="d-block fs-12 text-muted">' +
                  esc(b.date) + (b.time ? ' · ' + esc(b.time) : '') +
                  (b.venue ? ' · ' + esc(b.venue) : '') +
                  ' · ' + b.pax + ' guests' +
                '</span>' +
              '</span>' +
              '<span class="badge bg-secondary-subtle text-secondary-emphasis">' + esc(b.status) + '</span>' +
            '</label>'
        ).join(''));

        syncSelectAll();
    }

    function syncSelectAll() {
        const allTicked = shown.length > 0 && shown.every(b => chosen.has(String(b.id)));
        $('#booking-select-all').prop('checked', allTicked);
    }

    function loadBookings() {
        $.getJSON('{{ url('/catering/store-issues/bookings') }}', {
            date: $('#booking-date').val(),
            q: $('#booking-search').val(),
        }).done(function (data) {
            shown = data.results || [];
            renderResults();
        }).fail(function () {
            shown = [];
            renderResults();
        });
    }

    $('#open-booking-modal').on('click', loadBookings);
    $('#booking-date, #booking-search').on('change', loadBookings);
    $('#booking-search').on('keyup', function (e) { if (e.key === 'Enter') loadBookings(); });
    $('#booking-clear-date').on('click', function () {
        $('#booking-date').val('');
        loadBookings();
    });

    $(document).on('change', '.booking-tick', function () {
        const id = String(this.value);
        if (this.checked) {
            chosen.set(id, shown.find(b => String(b.id) === id));
        } else {
            chosen.delete(id);
        }
        syncSelectAll();
        renderSelection();
    });

    // Only what is currently on screen. Ticking "select all" while a filter is
    // active must never quietly attach bookings the operator cannot see.
    $('#booking-select-all').on('change', function () {
        const on = this.checked;
        shown.forEach(b => on ? chosen.set(String(b.id), b) : chosen.delete(String(b.id)));
        $('.booking-tick').prop('checked', on);
        renderSelection();
    });

    $('#booking-attach').on('click', renderSelection);

    renderSelection();

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

    // "Issue this" on a requirement row — fills a line with the material and the
    // remaining quantity. Offered only where the remainder is certain; where an
    // unattributable earlier issue exists the operator types the number himself,
    // because the system genuinely does not know it.
    $(document).on('click', '.use-remaining', function () {
        const productId = $(this).data('product');
        const name = $(this).data('name');
        const remaining = $(this).data('qty');

        let $row = $('#issue-lines-body .issue-line').filter(function () {
            return String($(this).find('.material-picker').val() || '') === String(productId);
        }).first();

        if ($row.length === 0) {
            $row = $('#issue-lines-body .issue-line').filter(function () {
                return ! $(this).find('.material-picker').val();
            }).first();
        }

        if ($row.length === 0) {
            $('#add-issue-line').trigger('click');
            $row = $('#issue-lines-body .issue-line').last();
        }

        const $picker = $row.find('.material-picker');
        if (! $picker.find('option[value="' + productId + '"]').length) {
            $picker.append(new Option(name, productId, true, true));
        }
        $picker.val(productId).trigger('change');
        $row.find('input[type=number]').val(remaining);
    });
});
</script>
@endpush
