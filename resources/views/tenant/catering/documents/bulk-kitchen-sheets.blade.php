{{-- KASHIF-CATERING-OPERATOR-UI-1 — a print run of kitchen sheets, one
     booking per sheet, rendered from the same body partial as the single
     document.

     KITCHEN-SHEET-PREVIEW-1 (27 Sep): ab HAR status ki booking aati hai. Jis
     ki production release ho chuki hai us ka asli parcha, aur jis ki nahi hui
     us ka aarzi — aur aarzi parcha khud apne oopar likhta hai ke wo jaari
     nahi hua. Yahan pehle likha tha ke draft se parcha gharna wohi cheez hai
     jise release ka nizam rokta hai; malik ne us ke khilaf faisla diya.

     Read-only; prints nothing by itself. --}}
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
                Koi quotation nahi, is liye chhoot gayin: {{ implode(", ", $skippedEvents) }}
            </div>
        @endif
    </div>
    @foreach($releases as $release)
        @php
            $snapshot = $release->event_snapshot;
            $requirements = $release->requirements_snapshot['requirements'] ?? [];
        @endphp
        <div class="bulk-doc">
            @include('tenant.catering.documents.partials.kitchen-sheet-body')
        </div>
    @endforeach
</body>
</html>
