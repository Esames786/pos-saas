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
        // CAL-LEGEND-FILTER-1: the two words the owner asked for. They are not a
        // new idea — a booking is DRAFT until its quotation is finalized and sent,
        // and QUOTED from that moment (CateringEstimateService::send). The chips
        // simply never said which stage they meant.
        'quoted'    => ['bg' => '#E4EDF6', 'fg' => '#245278', 'label' => 'Quotation sent — awaiting reply'],
        'draft'     => ['bg' => '#EFEFEC', 'fg' => '#5C5F60', 'label' => 'Estimate — not yet sent'],
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
                A date shows how many bookings it holds — click it for the list. Click a status below to show only those.
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

        {{-- ── month navigation ─────────────────────────────────────────────
             KASHIF-CATERING-OPERATOR-UI-1: one month at a time, with a Today
             button — the diary question is "next month, previous month, back to
             now", not "jump a quarter". --}}
        @php
            $prev = $cal['anchor']->subMonth()->format('Y-m');
            $next = $cal['anchor']->addMonth()->format('Y-m');
        @endphp
        <div class="d-flex align-items-center justify-content-between mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-month="{{ $prev }}"
                    title="Previous month">
                <i class="ti ti-chevron-left"></i> {{ $cal['anchor']->subMonth()->format('M Y') }}
            </button>
            <span class="fw-semibold">
                {{ $cal['from']->format('M Y') }} &ndash; {{ $cal['anchor']->format('M Y') }}
                <button type="button" class="btn btn-sm btn-link cal-nav p-0 ms-2 align-baseline" data-month=""
                        title="Back to the current month">Today</button>
            </span>
            <button type="button" class="btn btn-sm btn-outline-secondary cal-nav" data-month="{{ $next }}"
                    title="Next month">
                {{ $cal['anchor']->addMonth()->format('M Y') }} <i class="ti ti-chevron-right"></i>
            </button>
        </div>

        {{-- ── legend, which is also the filter ──────────────────────────
             CAL-LEGEND-FILTER-1: these used to explain the colours and do
             nothing. Clicking one now shows only that status; clicking it again
             puts it back. Several can be on at once. --}}
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center cal-legend">
            @foreach($tones as $key => $t)
                <button type="button" class="badge fw-normal fs-12 border-0 cal-tone" data-tone="{{ $key }}"
                        aria-pressed="false" title="Sirf {{ $t['label'] }} dikhayein"
                        style="background:{{ $t['bg'] }};color:{{ $t['fg'] }};box-shadow:inset 0 0 0 1px {{ $t['fg'] }}33;cursor:pointer">
                    {{ $t['label'] }}
                </button>
            @endforeach
            <button type="button" class="btn btn-link btn-sm p-0 fs-12 text-muted d-none" id="cal-clear-tones">sab dikhayein</button>
            <span class="fs-12 text-muted d-none" id="cal-filter-note"></span>
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
                                                    {{-- One indicator with a count — a busy date must not
                                                         fill its square with every booking. The strongest
                                                         tone present colours it; the date modal tells the
                                                         rest. --}}
                                                    @php
                                                        $dayTone = collect(['overdue', 'confirmed', 'quoted', 'draft', 'done', 'cancelled'])
                                                            ->first(fn ($k) => collect($day['events'])->contains('tone', $k)) ?? 'draft';
                                                        $t = $tones[$dayTone] ?? $tones['draft'];
                                                        $dayLabel = \Carbon\CarbonImmutable::parse($day['date'])->format('D, d M Y');
                                                    @endphp
                                                    <div class="d-flex justify-content-center px-1 pb-1">
                                                        <button type="button"
                                                                class="border-0 rounded-pill cal-day-count fw-semibold"
                                                                style="background:{{ $t['bg'] }};color:{{ $t['fg'] }};font-size:.68rem;line-height:1.1;padding:.05rem .4rem"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#calDayModal"
                                                                data-date-label="{{ $dayLabel }}"
                                                                data-events='@json($day['events'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)'
                                                                title="{{ count($day['events']) }} {{ \Illuminate\Support\Str::plural('booking', count($day['events'])) }}"
                                                                aria-label="{{ count($day['events']) }} bookings on {{ $dayLabel }}">
                                                            &bull; {{ count($day['events']) }}
                                                        </button>
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

{{-- ── one date's bookings ──────────────────────────────────────────────────
     KASHIF-CATERING-OPERATOR-UI-1: clicking a date answers the diary question
     in one look — who, when, where, how many, what state, what to do next. --}}
<div class="modal fade" id="calDayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cal-day-title">Bookings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                            <tr class="text-muted fs-12">
                                <th class="ps-3">Booking</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Time</th>
                                <th>Venue</th>
                                <th class="text-end">PAX</th>
                                <th>Quotation</th>
                                <th>Booking</th>
                                <th>Next Action</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cal-day-rows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var card = document.getElementById('catering-calendar-card');

    // CAL-LEGEND-FILTER-1 — which statuses are being shown. Empty means all of
    // them, which is what the calendar has always done.
    var CAL_TONES = @json(collect($tones)->map(fn ($t) => ['bg' => $t['bg'], 'fg' => $t['fg'], 'label' => $t['label']]));
    var TONE_ORDER = ['overdue', 'confirmed', 'quoted', 'draft', 'done', 'cancelled'];
    var activeTones = [];

    function eventsOf(btn) {
        try { return JSON.parse(btn.getAttribute('data-events')) || []; } catch (e) { return []; }
    }

    /**
     * Re-draw every day from the bookings it already carries.
     *
     * Run again after a month is fetched: that request replaces the whole body,
     * so the chips and the counts come back unfiltered unless this is re-applied.
     */
    function applyTones() {
        var body = document.getElementById('catering-calendar-body');
        if (! body) return;

        body.querySelectorAll('.cal-tone').forEach(function (chip) {
            var on = activeTones.indexOf(chip.getAttribute('data-tone')) > -1;
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            chip.style.opacity = (activeTones.length && ! on) ? '.35' : '1';
            chip.style.boxShadow = on
                ? 'inset 0 0 0 2px currentColor'
                : chip.style.boxShadow.replace('inset 0 0 0 2px currentColor', '');
        });

        var clear = document.getElementById('cal-clear-tones');
        if (clear) clear.classList.toggle('d-none', activeTones.length === 0);

        var kept = 0, hidden = 0;

        body.querySelectorAll('.cal-day-count').forEach(function (btn) {
            var all = eventsOf(btn);
            var shown = activeTones.length
                ? all.filter(function (ev) { return activeTones.indexOf(ev.tone) > -1; })
                : all;

            // The modal reads THIS, so the list it opens always matches the
            // number on the badge.
            btn.setAttribute('data-filtered', JSON.stringify(shown));

            if (! shown.length) { btn.classList.add('d-none'); hidden += all.length; return; }
            kept += shown.length;
            btn.classList.remove('d-none');
            btn.textContent = '\u2022 ' + shown.length;

            var tone = TONE_ORDER.find(function (t) {
                return shown.some(function (ev) { return ev.tone === t; });
            }) || 'draft';
            var palette = CAL_TONES[tone] || CAL_TONES.draft;
            if (palette) { btn.style.background = palette.bg; btn.style.color = palette.fg; }

            var word = shown.length === 1 ? 'booking' : 'bookings';
            btn.title = shown.length + ' ' + word;
            btn.setAttribute('aria-label', shown.length + ' ' + word);
        });

        var note = document.getElementById('cal-filter-note');
        if (note) {
            note.classList.toggle('d-none', activeTones.length === 0);
            note.textContent = activeTones.length ? (kept + ' dikha rahe hain · ' + hidden + ' chhupi hain') : '';
        }
    }

    card.addEventListener('click', function (e) {
        var chip = e.target.closest('.cal-tone');
        if (chip) {
            var tone = chip.getAttribute('data-tone');
            var at = activeTones.indexOf(tone);
            if (at > -1) { activeTones.splice(at, 1); } else { activeTones.push(tone); }
            applyTones();

            return;
        }
        if (e.target.closest('#cal-clear-tones')) { activeTones = []; applyTones(); }
    });
    if (!card) return;

    // Fill the day dialog from the count pill that was clicked. All the data is
    // already on the element, so listing a date's bookings costs no request.
    document.addEventListener('show.bs.modal', function (e) {
        if (!e.target || e.target.id !== 'calDayModal') return;
        var btn = e.relatedTarget;
        if (!btn) return;

        // What the badge counted — the filtered list when a filter is on, and
        // the day's own list when it is not. Reading data-events here would open
        // a list that disagrees with the number that was clicked.
        var events;
        try {
            events = JSON.parse(btn.getAttribute('data-filtered') || btn.getAttribute('data-events'));
        } catch (err) { return; }

        var title = document.getElementById('cal-day-title');
        if (title) {
            title.textContent = (btn.getAttribute('data-date-label') || 'Bookings')
                + ' — ' + events.length + ' booking' + (events.length === 1 ? '' : 's');
        }

        var esc = function (s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
            });
        };
        var badge = function (tone, label) {
            var cls = tone === 'overdue' ? 'bg-danger'
                : tone === 'confirmed' ? 'bg-success'
                : tone === 'cancelled' ? 'bg-secondary' : 'bg-info text-dark';
            return '<span class="badge ' + cls + '">' + esc(label) + '</span>';
        };

        var rows = document.getElementById('cal-day-rows');
        if (!rows) return;
        rows.innerHTML = events.map(function (ev) {
            return '<tr>'
                + '<td class="ps-3 fw-semibold">' + esc(ev.event_no) + '</td>'
                + '<td>' + esc(ev.customer) + '</td>'
                + '<td>' + esc(ev.phone || '—') + '</td>'
                + '<td>' + esc(ev.time || '—') + '</td>'
                + '<td>' + esc(ev.venue || '—') + '</td>'
                + '<td class="text-end">' + (ev.pax ? Number(ev.pax).toLocaleString() : '—') + '</td>'
                + '<td class="fs-12">' + esc(ev.quote_label || '—') + '</td>'
                + '<td>' + badge(ev.tone, ev.status_label) + '</td>'
                + '<td class="fs-12">' + esc(ev.next_action || '') + '</td>'
                + '<td class="text-end pe-3"><a class="btn btn-sm btn-outline-primary" href="'
                + esc(ev.url) + '">Open</a></td>'
                + '</tr>';
        }).join('');
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
            .then(function (html) {
                body.innerHTML = html;
                // The fetch replaced the chips and the counts; put the filter back.
                applyTones();
            })
            .catch(function () { body.style.opacity = '1'; })
            .finally(function () { body.style.opacity = '1'; });
    });
})();
</script>
@endpush
@endunless
