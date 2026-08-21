{{-- KASHIF-CATERING-OPERATOR-UI-1 — a print run of quotations, one booking after
     another, each starting on a fresh sheet. The body and stylesheet are the
     SAME partials the single-document screen renders: a bulk copy can never
     drift from the copy a single booking prints. Read-only composition — no
     stock, no postings, no state change, no print queue. --}}
@php
    $isUr = $lang === 'ur';
    $isBoth = $lang === 'both';
    $t = function (string $en, string $ur) use ($isUr) { return $isUr ? $ur : $en; };
@endphp
<!DOCTYPE html>
<html lang="{{ $isUr ? 'ur' : 'en' }}" dir="{{ $isUr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>Quotations — {{ $documents->count() }} bookings</title>
@include('tenant.catering.documents.partials.estimate-style')
<style>
    /* Each booking starts on its own sheet. */
    .bulk-doc { page-break-after: always; }
    .bulk-doc:last-child { page-break-after: auto; }
    .bulk-toolbar { max-width: 210mm; margin: 10px auto 0; text-align: center; font-family: Arial, sans-serif; }
    @media print { .bulk-toolbar { display: none; } }
</style>
</head>
<body>
    <div class="bulk-toolbar">
        <button onclick="window.print()" style="padding:6px 18px;font-size:14px;cursor:pointer">
            Print {{ $documents->count() }} {{ \Illuminate\Support\Str::plural('quotation', $documents->count()) }}
        </button>
        @if(($skipped ?? 0) > 0)
            <div style="color:#92400e;font-size:12px;margin-top:4px">
                {{ $skipped }} selected {{ \Illuminate\Support\Str::plural('booking', $skipped) }} had no priced quotation and {{ $skipped === 1 ? 'was' : 'were' }} skipped.
            </div>
        @endif
    </div>
    @foreach($documents as $doc)
        @php
            $event = $doc['event'];
            $estimate = $doc['estimate'];
            $position = $doc['position'];
            $advanceTotal = $position['net_received'];
        @endphp
        <div class="bulk-doc">
            @include('tenant.catering.documents.partials.estimate-body')
        </div>
    @endforeach
</body>
</html>
