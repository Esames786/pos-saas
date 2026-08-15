{{--
    KASHIF-CATERING-CALENDAR-1 — the booking diary on the dashboard.

    A caterer's two constant questions are "what is coming up" and "am I already
    booked that night". Neither was answerable from the dashboard.

    Colour carries meaning, not decoration. The band that matters most is
    OVERDUE — a date that has passed while the booking is still open. It is
    neither upcoming nor finished, and it is the only one that needs the
    operator to do something today, so it is the loudest thing on the grid.

    Rendered only when catering is on the tenant's PLAN. The controller decides
    that from entitlement, never from @can — every Owner holds every permission,
    so a permission check would put this on a restaurant's dashboard.

    $fragment === true when this is an on-demand month swap, so the outer card
    is not repeated.
--}}
@php
    $cal = $cateringCalendar;
    $fragment = $fragment ?? false;

    $tones = [
        'overdue'   => ['bg' => '#F8E3E0', 'fg' => '#8E2E24', 'label' => 'Date passed, still open'],
        'confirmed' => ['bg' => '#E3F0E8', 'fg' => '#22684C', 'label' => 'Confirmed'],
        'quoted'    => ['bg' => '#E4EDF6', 'fg' => '#245278', 'label' => 'Quoted, awaiting reply'],
        'draft'     => ['bg' => '#EFEFEC', 'fg' => '#5C5F60', 'label' => 'Draft'],
        'done'      => ['bg' => '#E6E4EE', 'fg' => '#4A4470', 'label' => 'Completed / closed'],
        'cancelled' => ['bg' => '#F2F2F0', 'fg' => '#9A9A96', 'label' => 'Cancelled'],
    ];
@endphp

@unless($fragment)
<div class="card border-0 shadow-sm mb-4" id="catering-calendar-card">
    <div class="card-header bg-transparent d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h5 class="mb-0"><i class="ti ti-calendar-event me-1"></i>Booking Calendar</h5>
            <div class="text-muted fs-12">
                Showing the last {{ \App\Services\Catering\CateringCalendarService::WINDOW_MONTHS }} months.
                Step back for older bookings.
            </div>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="text-end">
                <div class="fs-12 text-muted">Upcoming</div>
                <div class="fw-bold">{{ $cal['totals']['upcoming'] }}</div>
            </div>
            @if($cal['totals']['past_open'] > 0)
                <div class="text-end">
                    <div class="fs-12" style="color:#8E2E24">Needs attention</div>
                    <div class="fw-bold" style="color:#8E2E24">{{ $cal['totals']['past_open'] }}</div>
                </div>
            @endif
            <div class="text-end">
                <div class="fs-12 text-muted">Upcoming value</div>
                <div class="fw-bold">{{ number_format($cal['totals']['value'], 2) }}</div>
            </div>
        </div>
    </div>
    <div class="card-body pt-2" id="catering-calendar-body">
@endunless

        {{-- ── month navigation ─────────────────────────────────────────── --}}
        @php
            $prev = $cal['anchor']->subMonths(\App\Services\Catering\CateringCalendarService::WINDOW_MONTHS)->format('Y-m');
            $next = $cal['anchor']->addMonths(\App\Services\Catering\CateringCalendarService::WINDOW_MONTHS)->format('Y-m');
        @endphp
        <div class="d-flex align-items-center justify-content-between mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-month="{{ $prev }}"
                    title="Load the previous {{ \App\Services\Catering\CateringCalendarService::WINDOW_MONTHS }} months">
                <i class="ti ti-chevron-left"></i> Older
            </button>
            <span class="fw-semibold">
                {{ $cal['from']->format('M Y') }} &ndash; {{ $cal['anchor']->format('M Y') }}
            </span>
            <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-month="{{ $next }}"
                    title="Move forward">
                Newer <i class="ti ti-chevron-right"></i>
            </button>
        </div>

        {{-- ── legend ───────────────────────────────────────────────────── --}}
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach($tones as $key => $t)
                <span class="badge fw-normal fs-12"
                      style="background:{{ $t['bg'] }};color:{{ $t['fg'] }};border:1px solid {{ $t['fg'] }}33">
                    {{ $t['label'] }}
                </span>
            @endforeach
        </div>

        {{-- ── the months ───────────────────────────────────────────────── --}}
        <div class="row g-3">
            @foreach($cal['months'] as $month)
                <div class="col-12 col-xl-4">
                    <div class="border rounded h-100">
                        <div class="px-2 py-1 border-bottom fw-semibold fs-13">{{ $month['label'] }}</div>
                        <table class="table table-sm mb-0 cal-grid" style="table-layout:fixed">
                            <thead>
                                <tr class="text-muted" style="font-size:.68rem">
                                    @foreach(['M','T','W','T','F','S','S'] as $d)
                                        <th class="text-center px-0 py-1 fw-normal">{{ $d }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(array_chunk($month['days'], 7) as $week)
                                    <tr>
                                        @foreach($week as $day)
                                            <td class="p-0 align-top text-center position-relative
                                                       {{ $day['in_month'] ? '' : 'opacity-25' }}"
                                                style="height:2.5rem;{{ $day['is_today'] ? 'outline:2px solid var(--bs-primary);outline-offset:-2px' : '' }}">
                                                <div class="fs-12 pt-1 {{ $day['is_today'] ? 'fw-bold' : 'text-muted' }}">{{ $day['day'] }}</div>
                                                @if($day['events'])
                                                    <div class="d-flex justify-content-center gap-1 flex-wrap px-1 pb-1">
                                                        @foreach($day['events'] as $ev)
                                                            @php $t = $tones[$ev['tone']] ?? $tones['draft']; @endphp
                                                            <button type="button"
                                                                    class="border-0 rounded-circle cal-dot p-0"
                                                                    style="width:9px;height:9px;background:{{ $t['fg'] }}"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#calEventModal"
                                                                    data-event='@json($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)'
                                                                    title="{{ $ev['event_no'] }} — {{ $ev['customer'] }}"
                                                                    aria-label="{{ $ev['event_no'] }} {{ $ev['customer'] }}"></button>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        @php
            $all = collect($cal['months'])->flatMap(fn ($m) => collect($m['days'])->flatMap(fn ($d) => $d['events']));
            $attention = $all->where('needs_attention', true)->values();
        @endphp
        @if($attention->isNotEmpty())
            <div class="alert mt-3 mb-0" style="background:#F8E3E0;border:1px solid #8E2E2433;color:#8E2E24">
                <strong><i class="ti ti-alert-triangle me-1"></i>{{ $attention->count() }}
                    {{ \Illuminate\Support\Str::plural('booking', $attention->count()) }} past their date and still open.</strong>
                <div class="fs-12 mt-1">
                    @foreach($attention->take(4) as $a)
                        <a href="{{ url($a['url']) }}" class="text-decoration-underline me-2" style="color:#8E2E24">
                            {{ $a['event_no'] }} — {{ $a['customer'] }}
                        </a>
                    @endforeach
                    @if($attention->count() > 4)<span>and {{ $attention->count() - 4 }} more</span>@endif
                </div>
            </div>
        @endif

@unless($fragment)
    </div>
</div>

{{-- ── event detail ─────────────────────────────────────────────────────── --}}
<div class="modal fade" id="calEventModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cal-ev-no">Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="fs-5 fw-semibold" id="cal-ev-customer"></div>
                        <div class="text-muted fs-13" id="cal-ev-when"></div>
                    </div>
                    <span class="badge" id="cal-ev-status"></span>
                </div>
                <dl class="row mb-0 fs-14">
                    <dt class="col-4 text-muted">Venue</dt><dd class="col-8" id="cal-ev-venue"></dd>
                    <dt class="col-4 text-muted">Guests</dt><dd class="col-8" id="cal-ev-pax"></dd>
                    <dt class="col-4 text-muted">Amount</dt>
                    <dd class="col-8 fw-bold" id="cal-ev-amount"></dd>
                </dl>
                <div class="alert alert-light border mt-3 mb-0 fs-12" id="cal-ev-note" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <a href="#" class="btn btn-primary" id="cal-ev-link">
                    <i class="ti ti-external-link me-1"></i>Open booking
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var card = document.getElementById('catering-calendar-card');
    if (!card) return;

    // Fill the detail dialog from the dot that was clicked. All the data is
    // already on the element, so opening a booking summary costs no request.
    document.addEventListener('show.bs.modal', function (e) {
        if (!e.target || e.target.id !== 'calEventModal') return;
        var btn = e.relatedTarget;
        if (!btn) return;

        var ev;
        try { ev = JSON.parse(btn.getAttribute('data-event')); } catch (err) { return; }

        var set = function (id, text) { var n = document.getElementById(id); if (n) n.textContent = text; };

        set('cal-ev-no', ev.event_no || 'Booking');
        set('cal-ev-customer', ev.customer || '');
        set('cal-ev-when', [ev.date, ev.time].filter(Boolean).join(' · '));
        set('cal-ev-venue', ev.venue || '—');
        set('cal-ev-pax', ev.pax ? Number(ev.pax).toLocaleString() : '—');
        set('cal-ev-amount', ev.quoted
            ? Number(ev.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})
            : 'Not priced yet');

        var badge = document.getElementById('cal-ev-status');
        if (badge) {
            badge.textContent = ev.status_label || '';
            badge.className = 'badge ' + (ev.tone === 'overdue' ? 'bg-danger'
                : ev.tone === 'confirmed' ? 'bg-success'
                : ev.tone === 'cancelled' ? 'bg-secondary' : 'bg-info text-dark');
        }

        var note = document.getElementById('cal-ev-note');
        if (note) {
            if (ev.needs_attention) {
                note.textContent = 'This date has passed and the booking is still open. Complete it, close it, or cancel it.';
                note.style.display = '';
            } else {
                note.style.display = 'none';
            }
        }

        var link = document.getElementById('cal-ev-link');
        if (link) link.setAttribute('href', ev.url);
    });

    // Older/newer months are fetched on demand — the first paint stays small
    // even for a kitchen with years of bookings behind it.
    card.addEventListener('click', function (e) {
        var nav = e.target.closest('.cal-nav');
        if (!nav) return;

        var body = document.getElementById('catering-calendar-body');
        if (!body) return;

        var url = new URL('{{ url('/dashboard/catering-calendar') }}', window.location.origin);
        url.searchParams.set('month', nav.getAttribute('data-month'));
        @if($selectedBranch ?? null)
            url.searchParams.set('branch_id', '{{ $selectedBranch }}');
        @endif

        body.style.opacity = '.5';
        fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () { body.style.opacity = '1'; })
            .finally(function () { body.style.opacity = '1'; });
    });
})();
</script>
@endpush
@endunless
