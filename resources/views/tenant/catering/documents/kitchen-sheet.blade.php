{{-- CATERING-SLICE-3: kitchen/service sheet (spec §18). Operational A4 —
     large event header, localized items, qty/instructions, NO prices. --}}
@php
    $isUr = $lang === 'ur';
    $isBoth = $lang === 'both';
    $t = function (string $en, string $ur) use ($isUr) { return $isUr ? $ur : $en; };
    $snapshot = $release->event_snapshot;
    $byStation = $release->lines->groupBy(fn ($line) => $line->production_station ?: '');
    $requirements = $release->requirements_snapshot['requirements'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="{{ $isUr ? 'ur' : 'en' }}" dir="{{ $isUr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ $release->release_no }} — Kitchen Sheet</title>
@include("tenant.catering.documents.partials.kitchen-sheet-style")
</head>
<body>
    @include("tenant.catering.documents.partials.kitchen-sheet-body")
</body>
</html>
