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
<title>{{ $event->event_no }} / Q{{ $estimate->version_no }} — Estimate</title>
@include("tenant.catering.documents.partials.estimate-style")
{{-- Last, so it overrides — and only when a PDF is being drawn. --}}
@include("tenant.catering.documents.partials.pdf-overrides")
</head>
<body>
    @include("tenant.catering.documents.partials.estimate-body")
</body>
</html>
