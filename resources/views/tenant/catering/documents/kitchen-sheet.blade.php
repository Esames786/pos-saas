{{-- CATERING-SLICE-3: kitchen/service sheet (spec §18). Operational A4 —
     large event header, localized items, qty/instructions, NO prices. --}}
@php
    $isUr = $lang === 'ur';
    $isBoth = $lang === 'both';
    $t = function (string $en, string $ur) use ($isUr) { return $isUr ? $ur : $en; };
    $snapshot = $release->event_snapshot;
    $requirements = $release->requirements_snapshot['requirements'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="{{ $isUr ? 'ur' : 'en' }}" dir="{{ $isUr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
{{-- KITCHEN-SHEET-PREVIEW-1: tab ka naam — aur "save as PDF" ki file ka naam —
     bhi sach bole. Ek file jis par "PR-… Kitchen Sheet" likha ho aur andar
     preview ho, wo filing cabinet me wohi ghalti hai jo deewar par hoti. --}}
<title>{{ $release->exists ? $release->release_no : 'PREVIEW '.($snapshot['event_no'] ?? '') }} — Kitchen Sheet</title>
@include("tenant.catering.documents.partials.kitchen-sheet-style")
</head>
<body>
    @include("tenant.catering.documents.partials.kitchen-sheet-body")
</body>
</html>
