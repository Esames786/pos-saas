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
@php
    $bulkMm = \App\Models\Tenant\CateringSetting::paperMm(
        \App\Models\Tenant\CateringSetting::tenantDefault()->kitchen_sheet_paper,
        'a5_portrait'
    );
@endphp
<style>
    /* KITCHEN-SHEET-FOOT-BOTTOM-2 (16 Oct) — malik: "footer footer ki jagah
       aaye, chahe list me ek item ho ya bees."

       27 September wala hal sirf TANHA parche par chal raha tha. Wahan paer
       `body` ka seedha beta hai, aur `body` ek khara flex hai — is liye paer
       ka `margin-top: auto` bachi hui saari jagah kha kar usay tal par le
       jata hai.

       Is safhe par har parcha `.bulk-doc` me lipta hai. Ek aam block ke andar
       `margin-top: auto` ka matlab SIFAR hai, is liye paer aakhri satar se
       chipak jata tha. Chrome se naap kar dekha: tanha parche par paer 95%
       neeche tha, isi safhe par 22% — aur client isi safhe se chhapta hai.

       Hal: har parcha KHUD ek khara flex bane aur poore safhe jitna ooncha
       ho. Bulandi `kaghaz - 16mm` hai: chhapte waqt @page ka 8mm upar aur
       8mm neeche nikal jata hai, aur screen par body ki apni 8mm padding —
       dono soorton me bachne wali jagah yehi hai. Is se zyada rakhein to har
       parcha do safhon par phail jata hai. */
    .bulk-doc { page-break-after: always;
                display: flex; flex-direction: column;
                min-height: calc({{ $bulkMm['h'] }}mm - 16mm); }
    .bulk-doc:last-child { page-break-after: auto; }
    .bulk-toolbar { max-width: 210mm; margin: 10px auto 0; text-align: center; font-family: Arial, sans-serif; }
    @media print { .bulk-toolbar { display: none; } }
</style>
</head>
<body>
    <div class="bulk-toolbar">
        <button onclick="window.print()" class="doc-btn">
            Print {{ $releases->count() }} kitchen {{ \Illuminate\Support\Str::plural('sheet', $releases->count()) }}
        </button>
        @include('tenant.catering.documents.partials.send-to-network', ['kind' => 'kitchen_sheet'])
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
