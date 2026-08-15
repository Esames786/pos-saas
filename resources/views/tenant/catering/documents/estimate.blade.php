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
<style>
    @page { size: A4 portrait; margin: 14mm; }
    * { box-sizing: border-box; }
    /* @page margin applies to PAPER only. On screen the sheet is drawn at real
       A4 size on a grey desk so the preview matches what comes out of the
       printer — otherwise the document sits flush against the browser edge and
       looks nothing like the print. Undone inside @media print so the paper
       uses @page alone and margins are never doubled. */
    html { background: #e5e7eb; }
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; font-size: 13px; line-height: 1.5;
        width: 210mm; min-height: 297mm; margin: 12px auto; padding: 14mm;
        background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.18);
    }
    @media screen and (max-width: 230mm) {
        body { width: 100%; min-height: 0; margin: 0; padding: 10mm; box-shadow: none; }
    }
    @media print {
        html { background: #fff; }
        body { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
    /* Nastaliq descenders clip at the body's Latin leading. */
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif; direction: rtl; line-height: 2; }
    .doc-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 12px; }
    .brand { font-size: 26px; font-weight: bold; letter-spacing: 0.5px; }
    .brand-sub { color: #6b7280; font-size: 12px; }
    .doc-title { text-align: {{ $isUr ? 'left' : 'right' }}; }
    .doc-title h2 { margin: 0; font-size: 20px; }
    .meta-grid { display: flex; gap: 24px; margin: 14px 0; }
    .meta-box { flex: 1; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 14px; }
    .meta-box h4 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; }
    .meta-row { display: flex; justify-content: space-between; padding: 2px 0; }
    .meta-row .k { color: #6b7280; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.items th { background: #111827; color: #fff; padding: 8px; font-size: 12px; text-align: {{ $isUr ? 'right' : 'left' }}; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    table.items tr:nth-child(even) td { background: #f9fafb; }
    .item-ur { color: #374151; font-size: 14px; }
    .totals { width: 46%; margin-{{ $isUr ? 'right' : 'left' }}: auto; margin-top: 10px; border-collapse: collapse; }
    .totals td { padding: 5px 8px; }
    .totals .k { color: #6b7280; }
    .totals .num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    .totals .grand td { font-weight: bold; font-size: 15px; border-top: 2px solid #111827; border-bottom: 3px double #111827; }
    .terms { margin-top: 18px; font-size: 11px; color: #6b7280; white-space: pre-line; }
    .footer { margin-top: 26px; display: flex; justify-content: space-between; font-size: 12px; }
    .sig { border-top: 1px solid #9ca3af; padding-top: 4px; width: 200px; text-align: center; color: #6b7280; }
    /* Sits in the grey gutter beside the sheet. It used to overlap the document
       header — in Urdu it flipped to the left and covered the estimate number. */
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; z-index: 10; }
    @media print { .print-bar { display: none; } }
    /* Keep rows, totals and signatures whole across a page break; repeat the
       header row on every continuation page. A 15-line estimate spills to p2. */
    table.items thead { display: table-header-group; }
    table.items tr, .totals, .terms, .footer, .meta-box { break-inside: avoid; page-break-inside: avoid; }
</style>
</head>
<body>
<div class="print-bar">
    <button onclick="window.print()" style="padding: 8px 18px; cursor: pointer;">Print</button>
</div>

<div class="doc-header">
    <div>
        <div class="brand">{{ $businessName }}</div>
        <div class="brand-sub">{{ $t('Catering & Events', 'کیٹرنگ اینڈ ایونٹس') }}</div>
    </div>
    <div class="doc-title">
        <h2>{{ $t('ESTIMATE', 'تخمینہ') }}</h2>
        <div><strong>{{ $event->event_no }} / Q{{ $estimate->version_no }}</strong></div>
        <div style="color:#6b7280;">{{ $t('Date', 'تاریخ') }}: {{ ($estimate->sent_at ?? $estimate->updated_at)->format('d M Y') }}</div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <h4>{{ $t('Customer', 'کسٹمر') }}</h4>
        <div style="font-weight:bold; font-size: 15px;">
            @if($isUr && $event->customer_name_ur)
                <span class="ur">{{ $event->customer_name_ur }}</span>
            @else
                {{ $event->customer_name }}
                @if($isBoth && $event->customer_name_ur)
                    <span class="ur" style="font-weight:normal;"> — {{ $event->customer_name_ur }}</span>
                @endif
            @endif
        </div>
        @if($event->customer_phone)<div class="meta-row"><span class="k">{{ $t('Phone', 'فون') }}</span><span dir="ltr">{{ $event->customer_phone }}</span></div>@endif
        @if($event->customer_address)<div class="meta-row"><span class="k">{{ $t('Address', 'پتہ') }}</span><span>{{ $event->customer_address }}</span></div>@endif
    </div>
    <div class="meta-box">
        <h4>{{ $t('Event', 'تقریب') }}</h4>
        @if($event->event_type)<div class="meta-row"><span class="k">{{ $t('Type', 'قسم') }}</span><span>{{ $event->event_type }}</span></div>@endif
        <div class="meta-row"><span class="k">{{ $t('Date', 'تاریخ') }}</span><span>{{ $event->event_date->format('l, d F Y') }}</span></div>
        @if($event->service_time)<div class="meta-row"><span class="k">{{ $t('Time', 'وقت') }}</span><span>{{ \Carbon\Carbon::parse($event->service_time)->format('g:i A') }}</span></div>@endif
        @if($event->venue)<div class="meta-row"><span class="k">{{ $t('Venue', 'مقام') }}</span><span>{{ $event->venue }}</span></div>@endif
        <div class="meta-row"><span class="k">{{ $t('Guests (PAX)', 'مہمان') }}</span><span><strong>{{ number_format($event->pax) }}</strong></span></div>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 34px;">#</th>
            <th class="num" style="width: 70px;">{{ $t('Qty', 'مقدار') }}</th>
            <th style="width: 60px;">{{ $t('Unit', 'یونٹ') }}</th>
            <th>{{ $t('Item', 'آئٹم') }}</th>
            <th style="width: 22%;">{{ $t('Instructions', 'ہدایات') }}</th>
            <th class="num" style="width: 80px;">{{ $t('Rate', 'ریٹ') }}</th>
            <th class="num" style="width: 95px;">{{ $t('Amount', 'رقم') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($estimate->lines as $line)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
            <td>{{ $line->unit_code }}</td>
            <td>
                @if($isUr && $line->item_name_ur)
                    <span class="ur">{{ $line->item_name_ur }}</span>
                @else
                    {{ $line->item_name }}
                    @if($isBoth && $line->item_name_ur)
                        <div class="item-ur ur">{{ $line->item_name_ur }}</div>
                    @endif
                @endif
            </td>
            <td>{{ $line->instructions }}</td>
            <td class="num">{{ number_format($line->rate, 2) }}</td>
            <td class="num">{{ number_format($line->amount, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="k">{{ $t('Subtotal', 'کل رقم') }}</td><td class="num">{{ number_format($estimate->subtotal, 2) }}</td></tr>
    @if($estimate->service_charge_amount > 0)
        <tr><td class="k">{{ $t('Service Charges', 'سروس چارجز') }}</td><td class="num">{{ number_format($estimate->service_charge_amount, 2) }}</td></tr>
    @endif
    @if($estimate->other_charge_amount > 0)
        <tr><td class="k">{{ $estimate->other_charge_label ?: $t('Other Charges', 'دیگر چارجز') }}</td><td class="num">{{ number_format($estimate->other_charge_amount, 2) }}</td></tr>
    @endif
    @if($estimate->discount_amount > 0)
        <tr><td class="k">{{ $t('Discount', 'رعایت') }}</td><td class="num">-{{ number_format($estimate->discount_amount, 2) }}</td></tr>
    @endif
    @if($estimate->tax_amount > 0)
        <tr><td class="k">{{ $t('Tax', 'ٹیکس') }}</td><td class="num">{{ number_format($estimate->tax_amount, 2) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ $t('Net Total', 'کل واجب الادا') }}</td><td class="num">{{ number_format($estimate->grand_total, 2) }}</td></tr>
    @if($advanceTotal > 0)
        <tr><td class="k">{{ $t('Advance Received', 'ایڈوانس وصول شدہ') }}</td><td class="num">{{ number_format($advanceTotal, 2) }}</td></tr>
        <tr><td style="font-weight:bold;">{{ $t('Balance', 'بقایا') }}</td><td class="num" style="font-weight:bold;">{{ number_format(max($estimate->grand_total - $advanceTotal, 0), 2) }}</td></tr>
    @endif
</table>

@if($estimate->terms)
    <div class="terms"><strong>{{ $t('Terms & Notes', 'شرائط و ضوابط') }}:</strong>
{{ $estimate->terms }}</div>
@endif

<div class="footer">
    <div class="sig">{{ $t('Prepared By', 'تیار کردہ') }}</div>
    <div class="sig">{{ $t('Customer Approval', 'کسٹمر کی منظوری') }}</div>
</div>
</body>
</html>
