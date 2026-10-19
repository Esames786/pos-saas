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
@include('tenant.catering.documents.partials.doc-toolbar-style')
</head>
<body>
    {{-- Dekho estimate.blade.php ka wohi note: toolbar safhe ka chrome hai,
         document ka hissa nahi. --}}
    <div class="print-bar">
        <button onclick="window.print()" style="padding: 8px 18px; cursor: pointer;">Print</button>
        @include('tenant.catering.documents.partials.send-to-network', ['kind' => 'kitchen_sheet'])
    </div>
    @include("tenant.catering.documents.partials.kitchen-sheet-body")
</body>
</html>
