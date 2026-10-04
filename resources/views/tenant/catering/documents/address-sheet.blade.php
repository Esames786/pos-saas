{{-- CATERING-ADDRESS-LABEL-1 (5 Oct) — delivery ka LABEL, fehrist nahi.

     Malik ne purane software ka parcha bheja aur saath hamara: "font sizes
     match karni hain." Tasveer me asal farq font ka nahi, SHAKAL ka tha.

     Purana software ek booking ka ek LABEL chhapta tha — graahak ka naam sab
     se bara, phir phone aur jagah — aur wo parchi delivery par chipak jati
     thi. Hamara kaghaz 7 column ki ek satar chhapta tha, 13px par. Malik us
     satar ko KAAT kar nikal rahe the aur purane label ke saath mila kar dikha
     rahe the: yani wo is kaghaz ko label ki tarah hi istemaal kar rahe the.

     7 column par bara font A4 par sama hi nahi sakta — teen column kat jate.
     Is liye shakal badli gayi hai, sirf adad nahi.

     ── OONCHAI KA HISAAB, aur ek tasheeh ───────────────────────────────────

     Pehli koshish me ek label 65mm ka tha — safhe par sirf CHAAR. Malik ne
     foran theek kiya: "ek page me 8 se 10 address aa jate the pehle bhi."

     A4 297mm − 24mm margin = 273mm. 8 par 34mm, 10 par 27mm. Yahan `min-height`
     30mm hai, `height` NAHI — aur ye farq ahem hai: lamba pata label ko thora
     oonchا kar deta hai aur us safhe par ek label kam aata hai, magar pata
     KAT-ta nahi. Thay height rakhne par lamba pata chup chaap gayab ho jata,
     aur delivery wale ko pata hi na chalta ke kuch likha tha.

     KOI SAFHE KA HEADER NAHI, aur ye bhi jaan-boojh kar: label kaat kar alag
     ho jata hai, is liye har label apni poori baat khud rakhta hai. Safhe ke
     sar par likha hua naam pehli hi kaat par zaaya ho jata. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Delivery Labels — {{ $events->count() }} bookings</title>
<style>
    @page { size: A4 portrait; margin: 12mm; }
    * { box-sizing: border-box; }
    html { background: #e5e7eb; }
    body {
        font-family: Arial, Helvetica, sans-serif; color: #111827;
        width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    @media print {
        html { background: #fff; }
        body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        .toolbar { display: none; }
    }

    /* Ek label. `page-break-inside: avoid` lazmi hai — adha kata hua label
       kisi kaam ka nahi. */
    .label {
        min-height: 25mm; padding: 2mm 1mm; display: flex; align-items: center;
        justify-content: space-between; gap: 6mm;
        border-bottom: 1.5px dashed #9ca3af; page-break-inside: avoid;
    }
    .label:last-child { border-bottom: none; }

    /* BAYAN TARAF — booking ka number aur din. Chhota rakha gaya hai: ye
       daftar ke liye hai, delivery wale ke liye nahi. */
    .l-left { flex: 0 0 40mm; }
    .l-no   { font-size: 13px; font-weight: bold; letter-spacing: .2px; }
    .l-day  { font-size: 15px; font-weight: bold; margin-top: 1.5mm; }
    .l-when { font-size: 12px; color: #4b5563; margin-top: 1mm; }

    /* DAYEN TARAF — wo sab jo delivery wala door se parhta hai. Naam sab se
       bara: parchi par pehli nazar usi par parti hai. */
    .l-right { flex: 1 1 auto; text-align: right; min-width: 0; }
    .l-name  { font-size: 19px; font-weight: bold; text-transform: uppercase;
               line-height: 1.15; word-wrap: break-word; }
    .l-phone { font-size: 15px; margin-top: 1mm; letter-spacing: .4px; }
    .l-venue { font-size: 14px; font-weight: bold; margin-top: 1.5mm;
               line-height: 1.25; word-wrap: break-word; }
    .l-addr  { font-size: 11px; color: #374151; margin-top: 1mm; line-height: 1.3; }

    /* Urdu naam apne font me, aur apni simt me. */
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif;
          direction: rtl; line-height: 1.8; font-size: 19px; margin-top: 1mm; }

    .toolbar { text-align: center; margin-bottom: 10px; }
    .empty { text-align: center; color: #6b7280; padding: 40px 0; font-size: 15px; }
</style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()" class="doc-btn">
            Print {{ $events->count() }} {{ \Illuminate\Support\Str::plural('label', $events->count()) }}
        </button>
            @include('tenant.catering.documents.partials.send-to-network', ['kind' => 'address_sheet'])
        <div style="color:#6b7280;font-size:12px;margin-top:4px">
            {{ $businessName }} · printed {{ app(\App\Support\TenantClock::class)->now()->format('d M Y g:i A') }}
            · cut along the dashed lines
        </div>
    </div>

    @forelse($events as $event)
        <div class="label">
            <div class="l-left">
                <div class="l-no">{{ $event->event_no }}</div>
                <div class="l-day">{{ $event->event_date->format('D d-m-Y') }}</div>
                <div class="l-when">
                    {{ $event->service_time ? \Carbon\Carbon::parse($event->service_time)->format('g:i A') : '—' }}
                    · {{ number_format($event->pax) }} PAX
                </div>
            </div>
            <div class="l-right">
                <div class="l-name">{{ $event->customer_name }}</div>
                @if($event->customer_name_ur)
                    <div class="ur">{{ $event->customer_name_ur }}</div>
                @endif
                <div class="l-phone">{{ $event->customer_phone ?? '—' }}</div>
                <div class="l-venue">{{ $event->venue ?? '—' }}</div>
                @if($event->customer_address && $event->customer_address !== $event->venue)
                    <div class="l-addr">{{ $event->customer_address }}</div>
                @endif
            </div>
        </div>
    @empty
        <div class="empty">No bookings selected.</div>
    @endforelse
</body>
</html>
