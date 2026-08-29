{{-- KASHIF-CATERING-OPERATOR-UI-1 — a print run of kitchen sheets, one released
     booking per sheet, rendered from the same body partial as the single
     document. Only bookings with a RELEASED production snapshot appear — a
     kitchen sheet invented from a draft estimate is exactly what the release
     authority exists to prevent. Read-only; prints nothing by itself. --}}
@php
    $isUr = $lang === 'ur';
    $isBoth = $lang === 'both';
    $t = function (string $en, string $ur) use ($isUr) { return $isUr ? $ur : $en; };
@endphp
<!DOCTYPE html>
<html lang="{{ $isUr ? 'ur' : 'en' }}" dir="{{ $isUr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>Kitchen Sheets — {{ $releases->count() }} bookings</title>
@include('tenant.catering.documents.partials.kitchen-sheet-style')
<style>
    .bulk-doc { page-break-after: always; }
    .bulk-doc:last-child { page-break-after: auto; }
    .bulk-toolbar { max-width: 210mm; margin: 10px auto 0; text-align: center; font-family: Arial, sans-serif; }
    @media print { .bulk-toolbar { display: none; } }
</style>
</head>
<body>
    <div class="bulk-toolbar">
        <button onclick="window.print()" style="padding:6px 18px;font-size:14px;cursor:pointer">
            Print {{ $releases->count() }} kitchen {{ \Illuminate\Support\Str::plural('sheet', $releases->count()) }}
        </button>
        @if(! empty($skippedEvents))
            <div style="color:#92400e;font-size:12px;margin-top:4px">
                No released kitchen sheet yet, skipped: {{ implode(', ', $skippedEvents) }}
            </div>
        @endif
    </div>
    @foreach($releases as $release)
        @php
            $snapshot = $release->event_snapshot;
            $byStation = $release->lines->groupBy(fn ($line) => $line->production_station ?: '');
            $requirements = $release->requirements_snapshot['requirements'] ?? [];
        @endphp
        <div class="bulk-doc">
            @include('tenant.catering.documents.partials.kitchen-sheet-body')
        </div>
    @endforeach
</body>
</html>
