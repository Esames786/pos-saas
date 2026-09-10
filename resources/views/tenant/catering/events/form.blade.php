@extends('layouts.app')

@section('title', $event ? 'Edit Event ' . $event->event_no : 'New Catering Event')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">{{ $event ? 'Edit Event ' . $event->event_no : 'New Catering Event' }}</h1>
    <div class="d-flex gap-2">
        {{-- KASHIF-LEGACY-ALIGN-6: full-width entry screen, like the POS. --}}
        <button type="button" class="btn btn-light" id="catering-sidebar-toggle"
                title="Show navigation" aria-label="Show navigation">
            <i class="ti ti-layout-sidebar-left-expand"></i>
        </button>
        <a href="{{ $event ? url('/catering/events/' . $event->id) : url('/catering/events') }}" class="btn btn-light">Back</a>
    </div>
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.submit-guard')
@include('tenant.catering.partials.screen-impact', ['manages' => 'The booking itself — customer, date, venue and guest count.', 'managesUr' => 'بکنگ کی بنیادی تفصیل — گاہک، تاریخ، مقام، مہمان۔', 'reversible' => 'safe', 'note' => 'Creating a booking commits nothing. Pricing happens on the event screen afterwards.', 'noteUr' => 'بکنگ بنانے سے کوئی مالی اثر نہیں ہوتا۔'])

{{-- KASHIF-CATERING-NO-RELOAD-2: the form posts by fetch — a validation
     mistake renders in place with every typed value kept, and only a
     SUCCESSFUL create performs one clean GET into the new event. --}}
<form method="POST" action="{{ $event ? url('/catering/events/' . $event->id) : url('/catering/events') }}"
      data-event-ajax="navigate">
    @csrf
    @if($event) @method('PUT') @endif

    @include('tenant.catering.events.partials.event-form-fields')

    {{-- EVENT-FORM-KEYBOARD-1: the key is PRINTED on the button. A shortcut
         nobody is told about is a shortcut nobody uses. --}}
    <button type="submit" class="btn btn-primary" id="event-save">
        {{ $event ? 'Save Changes' : 'Create Event' }}
        <span class="fs-12 opacity-75 ms-1">(Ctrl+S)</span>
    </button>
</form>

@include('tenant.catering.events.partials.event-form-support')
@endsection

@push('scripts')
<script>
// KASHIF-LEGACY-ALIGN-6 (revised): the CREATE/EDIT form keeps the sidebar —
// a vanished menu on a small form reads as breakage, not focus. The toggle
// still offers full-width for whoever wants it.
// EVENT-FORM-KEYBOARD-1 — Ctrl+S saves the BOOKING, not the browser page.
// The event screen has had this key for a while; the form that creates the
// booking did not, so the operator had to reach for the mouse exactly once per
// booking. requestSubmit() rather than submit(): it runs the browser's own
// validation and fires the submit event the ajax pipeline listens for, where
// submit() would skip both.
document.addEventListener('keydown', function (e) {
    if (! (e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') return;
    const form = document.querySelector('form[data-event-ajax]');
    if (! form) return;
    e.preventDefault();
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
});

document.getElementById('catering-sidebar-toggle')?.addEventListener('click', function () {
    const hidden = document.body.classList.toggle('nosidebar');
    this.querySelector('i').className = hidden ? 'ti ti-layout-sidebar-left-expand' : 'ti ti-layout-sidebar-left-collapse';
    this.title = hidden ? 'Show navigation' : 'Hide navigation';
});
</script>
@endpush
