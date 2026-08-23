{{-- KASHIF-CATERING-NO-RELOAD-2 — the event form's fields, shared verbatim by
     the standalone create/edit page and the workspace's Edit Event offcanvas.
     Everything is CLASS-scoped under [data-event-form-root] (no element ids),
     so two instances can exist without colliding and the behavior script can
     initialise each root independently. --}}
<div data-event-form-root data-booked='@json($bookedDates ?? [], JSON_UNESCAPED_UNICODE)'>
    {{-- Where AJAX validation renders; server-rendered errors land here too. --}}
    <div class="alert alert-danger event-form-errors {{ $errors->any() ? '' : 'd-none' }}">
        {{ $errors->first() }}
    </div>

    {{-- KASHIF-EVENT-FORM-2 — the POS's customer flow, here: ONE button opens
         a big search; typing a phone or a name finds the customer or offers to
         add them; the attached customer shows as a chip. The detail fields stay
         underneath (the booking's own copy of the customer, as always) and fill
         themselves from the choice. --}}
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">Customer</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="cust-chip badge bg-primary-subtle text-primary-emphasis d-none fs-13 py-2 px-3">
                    <i class="ti ti-user me-1"></i><span class="cust-chip-name"></span>
                    <span class="cust-chip-phone opacity-75 ms-1"></span>
                    <button type="button" class="btn-close ms-2 cust-chip-clear" style="font-size:.55rem" aria-label="Remove customer"></button>
                </span>
                <button type="button" class="btn btn-outline-dark btn-sm cust-open">
                    <i class="ti ti-user-search me-1"></i>Search / Add Customer
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <select name="customer_id" class="d-none customer-select">
                    @if(old('customer_id', $event?->customer_id))
                        <option value="{{ old('customer_id', $event?->customer_id) }}" selected>
                            {{ $event?->customer?->name ?? 'Selected customer' }}
                        </option>
                    @endif
                </select>
                <div class="col-md-4">
                    <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control" required
                           value="{{ old('customer_name', $event?->customer_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Customer Name (Urdu, optional)</label>
                    <input type="text" name="customer_name_ur" class="form-control" dir="rtl" lang="ur"
                           value="{{ old('customer_name_ur', $event?->customer_name_ur) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="customer_phone" class="form-control"
                           value="{{ old('customer_phone', $event?->customer_phone) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="customer_email" class="form-control"
                           value="{{ old('customer_email', $event?->customer_email) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Address</label>
                    <input type="text" name="customer_address" class="form-control"
                           value="{{ old('customer_address', $event?->customer_address) }}">
                </div>
            </div>
        </div>
    </div>

    {{-- The POS's own modal shape: one big box, results underneath, and a
         "not found → add them" panel that appears only when nothing matches. --}}
    <div class="modal fade cust-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title h6 mb-0">Customer</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="input-group input-group-lg mb-2">
                        <span class="input-group-text"><i class="ti ti-search"></i></span>
                        <input class="form-control cust-q" autocomplete="off"
                               placeholder="Phone number or name…" aria-label="Search customer by phone or name">
                    </div>
                    <div class="cust-results list-group mb-2" style="max-height:260px;overflow-y:auto"></div>
                    <div class="cust-new border rounded p-3 bg-light d-none">
                        <div class="fw-semibold mb-2"><i class="ti ti-user-plus me-1"></i>New customer</div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Name</label>
                                <input class="form-control form-control-sm cust-new-name" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Phone</label>
                                <input class="form-control form-control-sm cust-new-phone" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Address</label>
                                <input class="form-control form-control-sm cust-new-address" maxlength="500" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <button type="button" class="btn btn-primary btn-sm cust-new-use">
                                    <i class="ti ti-check me-1"></i>Use this customer <span class="opacity-75 small">(Enter)</span>
                                </button>
                                <span class="text-muted small ms-2">Saved with the booking — no separate step.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Event</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Branch</label>
                    {{-- KASHIF-EVENT-FORM-1: a single-branch house should never
                         have to choose. The first branch is preselected on a NEW
                         booking; an existing one keeps whatever it was saved with. --}}
                    @php $branchDefault = old('branch_id', $event?->branch_id ?? $branches->first()?->id); @endphp
                    <select name="branch_id" class="form-select">
                        <option value="">—</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($branchDefault == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Event Type</label>
                    <input type="text" name="event_type" class="form-control" list="event-types"
                           value="{{ old('event_type', $event?->event_type) }}">
                    <datalist id="event-types">
                        <option value="Wedding"><option value="Walima"><option value="Mehndi">
                        <option value="Corporate"><option value="Birthday"><option value="Other">
                    </datalist>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Booking Date <span class="text-danger">*</span></label>
                    <input type="date" name="booking_date" class="form-control" required
                           value="{{ old('booking_date', $event?->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}">
                    <div class="form-text">The day the customer booked with you.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Event Date <span class="text-danger">*</span></label>
                    <input type="date" name="event_date" class="form-control" required
                           value="{{ old('event_date', $event?->event_date?->format('Y-m-d')) }}">
                    {{-- Filled by JS: weekday, days away, and any clashing booking. --}}
                    <div class="form-text event-date-hint"></div>
                    <div class="d-flex flex-wrap gap-1 mt-2 date-chips">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-days="0">Today</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-days="1">Tomorrow</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-weekend="1">This weekend</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-days="30">In a month</button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Service Time</label>
                    <input type="time" name="service_time" class="form-control"
                           value="{{ old('service_time', $event?->service_time ? \Carbon\Carbon::parse($event->service_time)->format('H:i') : '') }}">
                    {{-- KASHIF-EVENT-FORM-1: the house's OWN sittings, from the
                         tenant's data — renameable, retimeable, retirable on the
                         Catering Settings screen. --}}
                    @php
                        $timePresets = \App\Models\Tenant\CateringServiceTimePreset::active()->ordered()->get();
                    @endphp
                    @if($timePresets->isNotEmpty())
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @foreach($timePresets as $preset)
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12"
                                        data-time="{{ $preset->inputTime() }}"
                                        title="{{ $preset->displayTime() }}">{{ $preset->label }}</button>
                            @endforeach
                            <a href="{{ url('/catering/settings') }}#service-times" class="btn btn-sm btn-link py-0 px-1 fs-12">edit list</a>
                        </div>
                    @endif
                </div>
                <div class="col-md-6">
                    <label class="form-label">Venue</label>
                    <input type="text" name="venue" class="form-control"
                           value="{{ old('venue', $event?->venue) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">PAX (guests) <span class="text-danger">*</span></label>
                    <input type="number" name="pax" class="form-control" min="0" required
                           value="{{ old('pax', $event?->pax ?? 100) }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $event?->notes) }}</textarea>
                </div>
            </div>
        </div>
    </div>
</div>
