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

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Customer</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Existing Customer (optional)</label>
                    <select name="customer_id" class="form-select customer-select">
                        @if(old('customer_id', $event?->customer_id))
                            <option value="{{ old('customer_id', $event?->customer_id) }}" selected>
                                {{ $event?->customer?->name ?? 'Selected customer' }}
                            </option>
                        @endif
                    </select>
                    <div class="form-text">Search by name or phone; leave empty for a walk-in customer.</div>
                </div>
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

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Event</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">—</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id', $event?->branch_id) == $branch->id)>{{ $branch->name }}</option>
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
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-time="13:00">Lunch 1 PM</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-time="20:00">Dinner 8 PM</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-12" data-time="21:00">9 PM</button>
                    </div>
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
