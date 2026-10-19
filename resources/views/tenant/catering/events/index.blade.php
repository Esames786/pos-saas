@extends('layouts.app')

@section('title', 'Catering Events')

@section('content')
@php $q = $q ?? ''; $from = $from ?? ''; $to = $to ?? ''; @endphp
<style>
    /* CATERING-LIST-RESPONSIVE-1 (27 Sep) — "datatable choti screen par bhi
       responsive ho".

       Gyarah columns laptop par to aa jate hain, chhoti screen par nahi. Do
       cheezein ki gayi hain:

       1. HAR DATA COLUMN PAR `min-width` — `width` NAHI. HTML me `width`
          browser ke liye tajweez hai, farsh nahi: jagah kam parey to browser
          us se neeche chala jata hai aur khana kuchal deta hai. Yehi keeda
          punch grid par Qty ko ghayab kar chuka hai.
       2. 992px SE NEECHE VENUE AUR PAX QATAAR SE NIKAL KAR customer ke naam ke
          neeche aa jate hain. Maloomat kahin nahi jaati, sirf jagah badalti
          hai — aur bachi hui chaurai un khanon ko milti hai jin par faisla
          hota hai: raqam, position aur status.

       Table phir bhi apne wrapper me scroll hoti hai; wrapper ka kinara nazar
       aata hai taake operator ko pata rahe ke daayen aur kuch hai. */
    #events-table th { white-space: nowrap; }
    #events-table > :not(caption) > * > * { vertical-align: middle; }
    #events-table-wrap { border-inline-end: 1px solid var(--bs-border-color, #dee2e6); }
    @media (max-width: 991.98px) {
        #events-table { font-size: .84rem; }
        #events-table > :not(caption) > * > * { padding-top: .45rem; padding-bottom: .45rem; }
    }
</style>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Catering Events &amp; Estimates</h1>
    <div class="d-flex gap-2 flex-wrap">
        {{-- CATERING-SIDEBAR-COLLAPSE-1 (22 Sep) — fehrist bhi kaam wali screen
             hai. Is ki table ke das column hain (Event #, Customer, Date, Venue,
             PAX, Quotation, Position, Status, Next Action, actions), aur khuli
             navigation ke saath dahina kinara katt jata tha. Ab ye screen bhi
             event wali screen ki tarah band navigation ke saath khulti hai, aur
             yehi button usay wapas laata hai. --}}
        @include('tenant.catering.partials.sidebar-collapse')
        @can('tenant.catering.events.create')
            <a href="{{ url('/catering/events/create') }}" class="btn btn-primary">
                <i class="ti ti-plus me-1"></i>New Event
            </a>
        @endcan
    </div>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'Every booking and the quotations attached to it.', 'managesUr' => 'تمام بکنگ اور ان کے تخمینے۔', 'reversible' => 'safe', 'note' => 'Viewing or filtering this list changes nothing. Money and stock only move from inside an individual event.', 'noteUr' => 'یہ فہرست دیکھنے سے کچھ تبدیل نہیں ہوتا۔'])
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row g-3 mb-4">
    @php
        $bucketCards = [
            'today' => ['label' => 'Today', 'icon' => 'ti-calendar-bolt', 'color' => 'danger'],
            'tomorrow' => ['label' => 'Tomorrow', 'icon' => 'ti-calendar-up', 'color' => 'warning'],
            'week' => ['label' => 'Next 7 Days', 'icon' => 'ti-calendar-week', 'color' => 'primary'],
            'unconfirmed' => ['label' => 'Unconfirmed', 'icon' => 'ti-help-circle', 'color' => 'secondary'],
        ];
    @endphp
    @foreach($bucketCards as $key => $card)
        <div class="col-6 col-lg-3">
            <a href="{{ url('/catering/events?filter=' . $key) }}" class="text-decoration-none">
                <div class="card border-{{ $filter === $key ? $card['color'] : 'light' }} h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <i class="ti {{ $card['icon'] }} fs-24 text-{{ $card['color'] }}"></i>
                        <div>
                            <div class="fs-24 fw-bold">{{ $buckets[$key] }}</div>
                            <div class="text-muted">{{ $card['label'] }}</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-6 col-lg-3">
                {{-- KASHIF-CATERING-OPERATOR-UI-1: the fields an operator actually
                     holds when the phone rings — number, name, phone, venue. --}}
                <input type="search" name="q" value="{{ $q }}" class="form-control"
                       placeholder="Search booking #, customer, phone, venue or address…">
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    @foreach(\App\Models\Tenant\CateringEvent::STATUSES as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            {{-- CATERING-LIST-DATE-RANGE-1 — bucket cards sirf aaj/kal/haftay ka
                 jawab dete hain; "is mahine kya kya tha" ka koi raasta nahi tha.
                 Khane ke andar hi "From"/"To" likha hai: bagair label ke do
                 khaali date box ye nahi batate ke kaun sa kaun sa hai, aur alag
                 label satar ki oonchai tor dete. --}}
            <div class="col-6 col-md-3 col-lg-2">
                <div class="input-group">
                    <span class="input-group-text fs-12">From</span>
                    <input type="date" name="from" value="{{ $from }}" class="form-control"
                           aria-label="Event date se">
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="input-group">
                    <span class="input-group-text fs-12">To</span>
                    <input type="date" name="to" value="{{ $to }}" class="form-control"
                           aria-label="Event date tak">
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Search</button>
                <a href="{{ url('/catering/events') }}" class="btn btn-light">Clear</a>
            </div>
            @if($from !== '' && $to !== '' && $from > $to)
                {{-- Khali fehrist khud nahi batati ke wo kyun khali hai. --}}
                <div class="col-12">
                    <div class="fs-12 text-danger mt-1">
                        <i class="ti ti-alert-triangle me-1"></i>"From" ki tareekh "To" se baad ki hai — is liye is fehrist me kuch nahi aayega.
                    </div>
                </div>
            @endif
            <div class="col text-end">
                {{-- Bulk documents for the ticked bookings. GET pages that compose
                     A4 print runs — nothing moves, posts, or changes state. --}}
                <div class="btn-group" id="bulk-print-group" style="display:none">
                    @can('tenant.catering.documents.bulk-quotations')
                        <button type="button" class="btn btn-outline-secondary bulk-print" data-url="{{ url('/catering/documents/bulk/quotations') }}">
                            <i class="ti ti-printer me-1"></i>Quotations
                        </button>
                    @endcan
                    @can('tenant.catering.documents.bulk-kitchen-sheets')
                        {{-- KITCHEN-SHEET-LANG-1 — bare button tenant ki default zabaan
                             kholta hai, jaisa pehle karta tha; caret us ke saath teen
                             sarih chunaav deta hai. Bulk route pehle se ?lang= maanta
                             hai (CateringBulkDocumentController::language), aur
                             bulk-print ka JS data-url ki mojooda query string ke saath
                             ids[] jorta hai — is liye lang wahin rakha ja sakta hai. --}}
                        <button type="button" class="btn btn-outline-secondary bulk-print" data-url="{{ url('/catering/documents/bulk/kitchen-sheets') }}">
                            <i class="ti ti-chef-hat me-1"></i>Kitchen Sheets
                        </button>
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split px-2"
                                data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false">
                            <span class="visually-hidden">Kitchen Sheets — zabaan chunein</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">Kitchen Sheets — zabaan</h6></li>
                            <li><button type="button" class="dropdown-item bulk-print"
                                        data-url="{{ url('/catering/documents/bulk/kitchen-sheets?lang=en') }}">English</button></li>
                            <li><button type="button" class="dropdown-item bulk-print"
                                        data-url="{{ url('/catering/documents/bulk/kitchen-sheets?lang=ur') }}">اردو</button></li>
                            <li><button type="button" class="dropdown-item bulk-print"
                                        data-url="{{ url('/catering/documents/bulk/kitchen-sheets?lang=both') }}">Both</button></li>
                        </ul>
                    @endcan
                    @can('tenant.catering.documents.bulk-address-sheet')
                        <button type="button" class="btn btn-outline-secondary bulk-print" data-url="{{ url('/catering/documents/bulk/address-sheet') }}">
                            <i class="ti ti-map-pin me-1"></i>Address Sheet
                        </button>
                    @endcan
                </div>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        {{-- KASHIF-EVENT-ACTIONS-2: `.table-responsive` is `overflow-x: auto`,
             which clips anything that leaves the box — including a dropdown. On
             a one-row list the box is barely taller than its row, so the Actions
             menu opened into nothing and the operator had to scroll the page to
             read it. The floor gives a short list room to breathe; the menu
             itself escapes the scroller via `data-bs-strategy="fixed"` below,
             so it stays readable however many rows there are. --}}
        <div class="table-responsive" id="events-table-wrap" style="min-height: 320px;">
            <table class="table table-hover mb-0" id="events-table">
                <thead>
                    <tr>
                        {{-- Tick ka khana jaan-boojh kar `width` rakhta hai: ye
                             wahi soorat hai jahan sikuṛna theek hai. --}}
                        <th style="width:30px" class="ps-3">
                            <input type="checkbox" class="form-check-input" id="select-all-events"
                                   title="Select every booking on this page">
                        </th>
                        <th style="min-width:125px;">Event #</th>
                        <th style="min-width:170px;">Customer</th>
                        <th style="min-width:135px;">Event Date</th>
                        <th style="min-width:120px;" class="d-none d-lg-table-cell">Venue</th>
                        <th style="min-width:70px;" class="text-end d-none d-lg-table-cell">PAX</th>
                        <th style="min-width:130px;">Quotation</th>
                        <th style="min-width:120px;" class="text-end">Position</th>
                        <th style="min-width:110px;">Status</th>
                        <th style="min-width:160px;">Next Action</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                    <tr>
                        <td class="ps-3">
                            <input type="checkbox" class="form-check-input event-check" value="{{ $event->id }}">
                        </td>
                        <td><a href="{{ url('/catering/events/' . $event->id) }}">{{ $event->event_no }}</a></td>
                        <td>
                            {{ $event->customer_name }}
                            @if($event->customer_phone)
                                <div class="text-muted fs-12"><i class="ti ti-phone me-1"></i>{{ $event->customer_phone }}</div>
                            @endif
                            {{-- 992px se neeche Venue aur PAX apne columns me
                                 nahi hote — yahan hain. PAX sifar ho to likha
                                 nahi jata: PAX ab ikhtiyari hai, aur "0 PAX"
                                 khabar nahi, shor hai. --}}
                            <div class="d-lg-none text-muted fs-12">
                                @if($event->venue)
                                    <i class="ti ti-map-pin me-1"></i>{{ $event->venue }}
                                @endif
                                @if($event->pax > 0)
                                    <span class="ms-1">· {{ number_format($event->pax) }} PAX</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            {{ $event->event_date->format('d M Y') }}
                            @if($event->service_time)
                                <span class="text-muted">{{ \Carbon\Carbon::parse($event->service_time)->format('g:i A') }}</span>
                            @endif
                        </td>
                        <td class="d-none d-lg-table-cell">{{ $event->venue ?? '—' }}</td>
                        <td class="text-end d-none d-lg-table-cell">{{ number_format($event->pax) }}</td>
                        <td>
                            @if($event->currentEstimate)
                                <div>{{ number_format($event->currentEstimate->grand_total, 2) }}</div>
                                <div class="fs-12 text-muted">Q{{ $event->currentEstimate->version_no }} · {{ ucfirst($event->currentEstimate->status) }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end">
                            {{-- Compact money position from the same inputs the
                                 workspace's position service reads: what was billed
                                 (invoice once issued, else the current quotation)
                                 against what is held net of refunds. --}}
                            @php
                                $received = (float) ($event->advances_sum ?? 0) - (float) ($event->refunds_sum ?? 0);
                                // CATERING-LIVE-BALANCE-1 (28 Sep) — yahan pehle likha tha
                                // "the invoice's own frozen balance is the authority", aur
                                // wohi faisla is poori kharabi ki jarh tha. Wo khaana invoice
                                // jaari hote waqt likha jata hai aur immutable hai; us ke baad
                                // aaya hua paisa us me kabhi nahi pahunchta. Is fehrist par
                                // booking "38,000 baqi" dikhati rehti thi jab ke usi booking
                                // ki apni screen 0 keh rahi thi.
                                //
                                // Invoice ka `grand_total` ab bhi ISI se aata hai — wo us din
                                // ka sauda hai aur usay jamna hi chahiye. Sirf "kitna baqi"
                                // aaj ka hai, aur us ka hisaab wohi qaida lagata hai jo screen
                                // lagati hai.
                                $billed = (float) ($event->finalInvoice?->grand_total
                                    ?? $event->currentEstimate?->grand_total ?? 0);
                                $balance = \App\Services\Catering\CateringFinancialPositionService::outstanding($billed, $received);
                            @endphp
                            @if($received > 0 || ($event->currentEstimate && (float) $event->currentEstimate->grand_total > 0))
                                <div class="fs-12 text-muted">recv {{ number_format($received, 2) }}</div>
                                @if($balance > 0.009)
                                    <div class="fw-semibold text-danger">due {{ number_format($balance, 2) }}</div>
                                @elseif($balance < -0.009)
                                    <div class="fw-semibold text-warning-emphasis">credit {{ number_format(-$balance, 2) }}</div>
                                @else
                                    <div class="fw-semibold text-success">settled</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @php
                                $badge = match($event->status) {
                                    'confirmed', 'production_ready', 'released' => 'success',
                                    'quoted' => 'info',
                                    'completed', 'closed' => 'dark',
                                    'cancelled' => 'danger',
                                    default => 'secondary',
                                };
                            @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucwords(str_replace('_', ' ', $event->status)) }}</span>
                        </td>
                        <td class="fs-12">{{ app(\App\Services\Catering\CateringCalendarService::class)->nextAction($event) }}</td>
                        {{-- KASHIF-EVENT-ACTIONS-1 — the actions an operator was
                             opening the booking to reach: its documents, its
                             production release, and the next lawful step in its
                             lifecycle. Every entry posts through the SAME
                             authority the event screen uses, and only the steps
                             this status actually allows are offered — a status
                             dropdown writing the column directly would walk
                             straight past the quotation, production and finance
                             rules. --}}
                        @php
                            $release = $event->productionReleases->sortByDesc('id')->first();
                            $estimateId = $event->currentEstimate?->id;
                            $isOpen = $event->isOpen();
                            $sentEstimate = $event->currentEstimate && ! $event->currentEstimate->isDraft();
                            $canRelease = $sentEstimate
                                && in_array($event->status, ['quoted', 'confirmed', 'production_ready'], true);
                            $canInvoice = $sentEstimate && ! $event->finalInvoice
                                && in_array($event->status, ['confirmed', 'production_ready', 'released'], true);
                        @endphp
                        <td class="text-end">
                            <div class="btn-group">
                                <a href="{{ url('/catering/events/' . $event->id) }}" class="btn btn-sm btn-light">Open</a>
                                <button type="button" class="btn btn-sm btn-light dropdown-toggle dropdown-toggle-split"
                                        data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false" title="Actions">
                                    <span class="visually-hidden">Actions</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><h6 class="dropdown-header">Documents</h6></li>
                                    @if($estimateId)
                                        <li><a class="dropdown-item" target="_blank"
                                               href="{{ url('/catering/documents/estimate/' . $estimateId) }}">
                                            <i class="ti ti-file-invoice me-2"></i>Quotation</a></li>
                                    @endif
                                    @if($release)
                                        {{-- KITCHEN-SHEET-LANG-1 — zabaan ka chunaav yahan bhi.
                                             Production Release screen par ye teen (EN / اردو / Both)
                                             pehle se thay; events list par sirf ek link tha jo tenant
                                             ki default zabaan kholta tha. Bawarchi Urdu parhta hai aur
                                             daftar English — dono ek hi list se nikalne parte hain.
                                             Route pehle se ?lang= maanta hai; sirf chunaav nahi tha. --}}
                                        <li class="px-3 py-1">
                                            <div class="fs-13 mb-1"><i class="ti ti-tools-kitchen-2 me-2"></i>Kitchen Sheet</div>
                                            <div class="btn-group btn-group-sm w-100" role="group" aria-label="Kitchen Sheet language">
                                                <a class="btn btn-outline-secondary" target="_blank"
                                                   href="{{ url('/catering/documents/kitchen-sheet/' . $release->id . '?lang=en') }}">EN</a>
                                                <a class="btn btn-outline-secondary" target="_blank"
                                                   href="{{ url('/catering/documents/kitchen-sheet/' . $release->id . '?lang=ur') }}">اردو</a>
                                                <a class="btn btn-outline-secondary" target="_blank"
                                                   href="{{ url('/catering/documents/kitchen-sheet/' . $release->id . '?lang=both') }}">Both</a>
                                            </div>
                                        </li>
                                        <li><a class="dropdown-item"
                                               href="{{ url('/catering/production-releases/' . $release->id) }}">
                                            <i class="ti ti-clipboard-check me-2"></i>Production Release
                                            <span class="text-muted fs-12">{{ $release->release_no }}</span></a></li>
                                    @else
                                        {{-- KITCHEN-SHEET-PREVIEW-1 (27 Sep) — yahan pehle sirf
                                             "No production release yet" likha tha aur bas. Malik
                                             ko parcha release se PEHLE chahiye tha, is liye wohi
                                             teen zabaanein yahan bhi — magar preview ke taur par.

                                             Ye raasta kuch MEHFOOZ NAHI karta: na release banti
                                             hai, na number kharch hota hai, na status hilta hai.
                                             Kaghaz khud oopar likhta hai ke wo jaari nahi hua. --}}
                                        @can('tenant.catering.documents.kitchen-sheet-preview')
                                            <li class="px-3 py-1">
                                                <div class="fs-13 mb-1">
                                                    <i class="ti ti-tools-kitchen-2 me-2"></i>Kitchen Sheet
                                                    <span class="badge bg-warning-subtle text-warning-emphasis fs-11">preview</span>
                                                </div>
                                                <div class="fs-12 text-muted mb-1">Release se pehle — sirf chhapne ke liye</div>
                                                <div class="btn-group btn-group-sm w-100" role="group" aria-label="Kitchen Sheet preview language">
                                                    <a class="btn btn-outline-secondary" target="_blank"
                                                       href="{{ url('/catering/documents/kitchen-sheet-preview/' . $event->id . '?lang=en') }}">EN</a>
                                                    <a class="btn btn-outline-secondary" target="_blank"
                                                       href="{{ url('/catering/documents/kitchen-sheet-preview/' . $event->id . '?lang=ur') }}">اردو</a>
                                                    <a class="btn btn-outline-secondary" target="_blank"
                                                       href="{{ url('/catering/documents/kitchen-sheet-preview/' . $event->id . '?lang=both') }}">Both</a>
                                                </div>
                                            </li>
                                        @else
                                            <li><span class="dropdown-item-text text-muted fs-12">No production release yet</span></li>
                                        @endcan
                                    @endif

                                    <li><hr class="dropdown-divider"></li>
                                    <li><h6 class="dropdown-header">Next step</h6></li>

                                    @can('tenant.catering.events.confirm')
                                        @if($isOpen && in_array($event->status, ['quoted', 'draft'], true))
                                            <li>
                                                <button type="button" class="dropdown-item js-event-action"
                                                        data-url="{{ url('/catering/events/' . $event->id . '/confirm') }}"
                                                        data-confirm="Confirm this booking?">
                                                    <i class="ti ti-check me-2 text-success"></i>Confirm Booking
                                                </button>
                                            </li>
                                        @endif
                                    @endcan

                                    @can('tenant.catering.production-releases.store')
                                        @if($canRelease)
                                            <li>
                                                <button type="button" class="dropdown-item js-event-action"
                                                        data-url="{{ url('/catering/events/' . $event->id . '/production-releases') }}"
                                                        data-confirm="Release production for this booking? The kitchen sheet is taken from the quotation as it stands.">
                                                    <i class="ti ti-send me-2 text-primary"></i>Release Production
                                                </button>
                                            </li>
                                        @endif
                                    @endcan

                                    @can('tenant.catering.final-invoices.store')
                                        @if($canInvoice)
                                            <li>
                                                <button type="button" class="dropdown-item js-event-action"
                                                        data-url="{{ url('/catering/events/' . $event->id . '/final-invoice') }}"
                                                        data-confirm="Issue the final invoice? This posts to the general ledger and closes the booking to commercial change.">
                                                    <i class="ti ti-receipt-2 me-2 text-warning"></i>Issue Final Invoice
                                                </button>
                                            </li>
                                        @endif
                                    @endcan

                                    {{-- CATERING-STATUS-ROLLBACK-1 — the same authority the
                                         booking screen POSTs to. This menu has never written a
                                         status itself and does not start here: whether the step
                                         is offered comes from the service, so the list and the
                                         booking screen can never disagree. --}}
                                    @can('tenant.catering.events.move-back')
                                        @if($backTargets[$event->id] ?? null)
                                            <li>
                                                <button type="button" class="dropdown-item js-event-action"
                                                        data-url="{{ url('/catering/events/' . $event->id . '/move-back') }}"
                                                        data-reason-field="reason"
                                                        data-ask-reason="Move {{ $event->event_no }} back to {{ str_replace('_', ' ', $backTargets[$event->id]) }}? Payments, invoices and stock are untouched. Why is it being moved back?">
                                                    <i class="ti ti-arrow-back-up me-2"></i>{{ $event->isCancelled() ? 'Restore Booking' : 'Move Back' }}
                                                    <span class="text-muted fs-12">to {{ str_replace('_', ' ', $backTargets[$event->id]) }}</span>
                                                </button>
                                            </li>
                                        @endif
                                    @endcan

                                    @can('tenant.catering.events.close')
                                        {{-- Closure is offered only where the finance authority would
                                             actually grant it: invoiced, and nothing left owing in
                                             either direction. Offering it earlier would hand the
                                             operator a button whose only outcome is an error. --}}
                                        @if($event->status === 'completed' && $event->finalInvoice && \App\Services\Catering\CateringFinancialPositionService::outstanding((float) $event->finalInvoice->grand_total, (float) (($event->advances_sum ?? 0) - ($event->refunds_sum ?? 0))) <= 0)
                                            <li>
                                                <button type="button" class="dropdown-item js-event-action"
                                                        data-url="{{ url('/catering/events/' . $event->id . '/close') }}"
                                                        data-confirm="Close this booking? It stops accepting further changes.">
                                                    <i class="ti ti-lock me-2"></i>Complete and Close
                                                </button>
                                            </li>
                                        @endif
                                    @endcan

                                    @can('tenant.catering.events.cancel')
                                        @if($isOpen)
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                {{-- A cancellation must say WHY: the reason becomes part
                                                     of the record, so it is asked for here rather than
                                                     quietly skipped. --}}
                                                <button type="button" class="dropdown-item text-danger js-event-action"
                                                        data-url="{{ url('/catering/events/' . $event->id . '/cancel') }}"
                                                        data-ask-reason="Why is this booking being cancelled?">
                                                    <i class="ti ti-ban me-2"></i>Cancel Booking
                                                </button>
                                            </li>
                                        @endif
                                    @endcan
                                </ul>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">
                        {{ $q !== '' ? 'Nothing matches that search.' : 'No catering events yet.' }}
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($events->hasPages())
        <div class="card-footer">{{ $events->links() }}</div>
    @endif
</div>

@push('scripts')
<script>
(function () {
    // KASHIF-CATERING-OPERATOR-UI-1 — bulk document selection. Ticking rows only
    // reveals print buttons; the pages they open are read-only compositions.
    var group = document.getElementById('bulk-print-group');
    if (!group) return;

    var refresh = function () {
        var any = document.querySelectorAll('.event-check:checked').length > 0;
        group.style.display = any ? '' : 'none';
    };

    document.addEventListener('change', function (e) {
        if (e.target.id === 'select-all-events') {
            document.querySelectorAll('.event-check').forEach(function (c) { c.checked = e.target.checked; });
        }
        if (e.target.id === 'select-all-events' || e.target.classList.contains('event-check')) refresh();
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.bulk-print');
        if (!btn) return;
        var ids = Array.from(document.querySelectorAll('.event-check:checked')).map(function (c) { return c.value; });
        if (!ids.length) return;
        var url = new URL(btn.getAttribute('data-url'), window.location.origin);
        ids.forEach(function (id) { url.searchParams.append('ids[]', id); });
        window.open(url.toString(), '_blank');
    });
})();

(function () {
    // KASHIF-EVENT-ACTIONS-1 — a lifecycle action from the list is the SAME
    // POST the event screen makes: a real form submit, so the controller's
    // redirect, flash message and validation errors all land normally. No
    // status is ever written directly from here.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-event-action');
        if (!btn) return;
        e.preventDefault();

        var reason = null;
        if (btn.dataset.askReason) {
            reason = window.prompt(btn.dataset.askReason);
            // Cancelled out, or too short for the reason the server insists on.
            if (reason === null) return;
            if (reason.trim().length < 3) {
                window.alert('Please give a reason of at least 3 characters.');
                return;
            }
        } else if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) {
            return;
        }

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = btn.dataset.url;
        form.style.display = 'none';

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = @json(csrf_token());
        form.appendChild(token);

        if (reason !== null) {
            var input = document.createElement('input');
            input.type = 'hidden';
            // Cancel calls it cancel_reason; move-back calls it reason. The
            // field name travels with the button rather than being assumed,
            // so a second action asking for a reason does not silently post
            // it under the first one's name and fail validation.
            input.name = btn.dataset.reasonField || 'cancel_reason';
            input.value = reason.trim();
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    });
})();
</script>
@endpush
@endsection
