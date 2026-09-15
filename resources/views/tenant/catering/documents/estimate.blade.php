{{-- CATERING-SLICE-3: client-facing A4 estimate (spec §17). Standalone document,
     browser print (platform A4 architecture). $lang: en | ur | both. --}}
@php
    $isUr = $lang === 'ur';
    $isBoth = $lang === 'both';
    $t = function (string $en, string $ur) use ($isUr) { return $isUr ? $ur : $en; };
@endphp
<!DOCTYPE html>
<html lang="{{ $isUr ? 'ur' : 'en' }}" dir="{{ $isUr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
{{-- The same three-way name: this is what the saved PDF is called, and a file
     named "Estimate" for an agreed booking is the same mistake in the
     filing cabinet. --}}
@php($docName = $estimate->isDraft() ? 'Draft Estimate' : ((
    $estimate->status === \App\Models\Tenant\CateringEstimate::STATUS_ACCEPTED
    || in_array($event->status, [
        \App\Models\Tenant\CateringEvent::STATUS_CONFIRMED,
        \App\Models\Tenant\CateringEvent::STATUS_PRODUCTION_READY,
        \App\Models\Tenant\CateringEvent::STATUS_RELEASED,
        \App\Models\Tenant\CateringEvent::STATUS_COMPLETED,
        \App\Models\Tenant\CateringEvent::STATUS_CLOSED,
    ], true)
) ? 'Booking Confirmation' : 'Quotation'))
<title>{{ $event->event_no }} / Q{{ $estimate->version_no }} — {{ $docName }}</title>
@include("tenant.catering.documents.partials.estimate-style")
{{-- Last, so it overrides — and only when a PDF is being drawn. --}}
@include("tenant.catering.documents.partials.pdf-overrides")
</head>
<body>
    @include("tenant.catering.documents.partials.estimate-body")
</body>
</html>
