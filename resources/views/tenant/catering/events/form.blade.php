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

    <button type="submit" class="btn btn-primary">{{ $event ? 'Save Changes' : 'Create Event' }}</button>
</form>

@include('tenant.catering.events.partials.event-form-support')
@endsection

@push('scripts')
<script>
// KASHIF-LEGACY-ALIGN-6: the event entry screen works full-width, like the POS.
document.body.classList.remove('mini-sidebar', 'expand-menu');
document.body.classList.add('nosidebar');
document.getElementById('catering-sidebar-toggle')?.addEventListener('click', function () {
    const hidden = document.body.classList.toggle('nosidebar');
    this.querySelector('i').className = hidden ? 'ti ti-layout-sidebar-left-expand' : 'ti ti-layout-sidebar-left-collapse';
    this.title = hidden ? 'Show navigation' : 'Hide navigation';
});
</script>
@endpush
