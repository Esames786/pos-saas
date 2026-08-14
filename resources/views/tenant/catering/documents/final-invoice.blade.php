{{-- CATERING-V1-CLOSURE-1 (§5): A4 final invoice rendered from the immutable
     issue-time snapshot — never from live estimate/advance rows. --}}
@php
    $isUr = $lang === 'ur';
    $isBoth = $lang === 'both';
    $t = function (string $en, string $ur) use ($isUr) { return $isUr ? $ur : $en; };
    $s = $invoice->snapshot;
@endphp
<!DOCTYPE html>
<html lang="{{ $isUr ? 'ur' : 'en' }}" dir="{{ $isUr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ $invoice->invoice_no }} — Final Invoice</title>
<style>
    @page { size: A4 portrait; margin: 14mm; }
    * { box-sizing: border-box; }
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; margin: 0; font-size: 13px; line-height: 1.5;
    }
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif; direction: rtl; }
    .doc-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #111827; padding-bottom: 12px; }
    .brand { font-size: 26px; font-weight: bold; }
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
    .totals .grand td { font-weight: bold; font-size: 15px; border-top: 2px solid #111827; }
    .totals .due td { font-weight: bold; font-size: 16px; border-top: 3px double #111827; }
    .paid-stamp { display: inline-block; border: 3px solid #15803d; color: #15803d; padding: 4px 18px; border-radius: 6px; font-size: 18px; font-weight: bold; transform: rotate(-4deg); }
    .adv { margin-top: 14px; width: 46%; margin-{{ $isUr ? 'right' : 'left' }}: auto; font-size: 12px; color: #6b7280; }
    .footer { margin-top: 26px; display: flex; justify-content: space-between; font-size: 12px; }
    .sig { border-top: 1px solid #9ca3af; padding-top: 4px; width: 200px; text-align: center; color: #6b7280; }
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; }
    @media print { .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()" style="padding: 8px 18px; cursor: pointer;">Print</button></div>

<div class="doc-header">
    <div>
        <div class="brand">{{ $businessName }}</div>
        <div class="brand-sub">{{ $t('Catering & Events', 'کیٹرنگ اینڈ ایونٹس') }}</div>
    </div>
    <div class="doc-title">
        <h2>{{ $t('FINAL INVOICE', 'حتمی رسید') }}</h2>
        <div><strong>{{ $invoice->invoice_no }}</strong></div>
        <div style="color:#6b7280;">{{ $t('Event', 'تقریب') }}: {{ $s['event_no'] ?? '' }} / Q{{ $s['estimate_version'] ?? '' }}</div>
        <div style="color:#6b7280;">{{ $t('Issued', 'اجراء') }}: {{ $invoice->issued_at->format('d M Y') }}</div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <h4>{{ $t('Customer', 'کسٹمر') }}</h4>
        <div style="font-weight:bold; font-size: 15px;">
            @if($isUr && !empty($s['customer_name_ur']))
                <span class="ur">{{ $s['customer_name_ur'] }}</span>
            @else
                {{ $s['customer_name'] ?? '' }}
                @if($isBoth && !empty($s['customer_name_ur']))
                    <span class="ur" style="font-weight:normal;"> — {{ $s['customer_name_ur'] }}</span>
                @endif
            @endif
        </div>
        @if(!empty($s['customer_phone']))<div class="meta-row"><span class="k">{{ $t('Phone', 'فون') }}</span><span dir="ltr">{{ $s['customer_phone'] }}</span></div>@endif
        @if(!empty($s['customer_address']))<div class="meta-row"><span class="k">{{ $t('Address', 'پتہ') }}</span><span>{{ $s['customer_address'] }}</span></div>@endif
    </div>
    <div class="meta-box">
        <h4>{{ $t('Event', 'تقریب') }}</h4>
        @if(!empty($s['event_type']))<div class="meta-row"><span class="k">{{ $t('Type', 'قسم') }}</span><span>{{ $s['event_type'] }}</span></div>@endif
        <div class="meta-row"><span class="k">{{ $t('Date', 'تاریخ') }}</span><span>{{ \Carbon\Carbon::parse($s['event_date'])->format('l, d F Y') }}</span></div>
        @if(!empty($s['service_time']))<div class="meta-row"><span class="k">{{ $t('Time', 'وقت') }}</span><span>{{ \Carbon\Carbon::parse($s['service_time'])->format('g:i A') }}</span></div>@endif
        @if(!empty($s['venue']))<div class="meta-row"><span class="k">{{ $t('Venue', 'مقام') }}</span><span>{{ $s['venue'] }}</span></div>@endif
        <div class="meta-row"><span class="k">{{ $t('Guests (PAX)', 'مہمان') }}</span><span><strong>{{ number_format($s['pax'] ?? 0) }}</strong></span></div>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 34px;">#</th>
            <th class="num" style="width: 70px;">{{ $t('Qty', 'مقدار') }}</th>
            <th style="width: 60px;">{{ $t('Unit', 'یونٹ') }}</th>
            <th>{{ $t('Item', 'آئٹم') }}</th>
            <th class="num" style="width: 85px;">{{ $t('Rate', 'ریٹ') }}</th>
            <th class="num" style="width: 100px;">{{ $t('Amount', 'رقم') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach(($s['lines'] ?? []) as $line)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 3), '0'), '.') }}</td>
            <td>{{ $line['unit_code'] ?? '' }}</td>
            <td>
                @if($isUr && !empty($line['item_name_ur']))
                    <span class="ur">{{ $line['item_name_ur'] }}</span>
                @else
                    {{ $line['item_name'] }}
                    @if($isBoth && !empty($line['item_name_ur']))
                        <div class="item-ur ur">{{ $line['item_name_ur'] }}</div>
                    @endif
                @endif
            </td>
            <td class="num">{{ number_format($line['rate'], 2) }}</td>
            <td class="num">{{ number_format($line['amount'], 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="k">{{ $t('Subtotal', 'کل رقم') }}</td><td class="num">{{ number_format($invoice->subtotal, 2) }}</td></tr>
    @if($invoice->service_charge_amount > 0)
        <tr><td class="k">{{ $t('Service Charges', 'سروس چارجز') }}</td><td class="num">{{ number_format($invoice->service_charge_amount, 2) }}</td></tr>
    @endif
    @if($invoice->other_charge_amount > 0)
        <tr><td class="k">{{ $invoice->other_charge_label ?: $t('Other Charges', 'دیگر چارجز') }}</td><td class="num">{{ number_format($invoice->other_charge_amount, 2) }}</td></tr>
    @endif
    @if($invoice->discount_amount > 0)
        <tr><td class="k">{{ $t('Discount', 'رعایت') }}</td><td class="num">-{{ number_format($invoice->discount_amount, 2) }}</td></tr>
    @endif
    @if($invoice->tax_amount > 0)
        <tr><td class="k">{{ $t('Tax', 'ٹیکس') }}</td><td class="num">{{ number_format($invoice->tax_amount, 2) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ $t('Net Total', 'کل واجب الادا') }}</td><td class="num">{{ number_format($invoice->grand_total, 2) }}</td></tr>
    <tr><td class="k">{{ $t('Advances Received', 'ایڈوانس وصول شدہ') }}</td><td class="num">-{{ number_format($invoice->advance_total, 2) }}</td></tr>
    <tr class="due"><td>{{ $t('Balance Due', 'بقایا رقم') }}</td><td class="num">{{ number_format($invoice->balance_due, 2) }}</td></tr>
</table>

@if($invoice->balance_due <= 0)
    <div style="text-align: {{ $isUr ? 'left' : 'right' }}; margin-top: 10px;">
        <span class="paid-stamp">{{ $t('FULLY PAID', 'مکمل ادا شدہ') }}</span>
    </div>
@endif

@if(!empty($s['advances']))
<div class="adv">
    <strong>{{ $t('Advance history', 'ایڈوانس کی تفصیل') }}:</strong>
    @foreach($s['advances'] as $advance)
        {{ \Carbon\Carbon::parse($advance['received_date'])->format('d M') }} — {{ number_format($advance['amount'], 2) }}@if(!empty($advance['reference'])) ({{ $advance['reference'] }}) @endif @if(!$loop->last), @endif
    @endforeach
</div>
@endif

<div class="footer">
    <div class="sig">{{ $t('Prepared By', 'تیار کردہ') }}</div>
    <div class="sig">{{ $t('Received By', 'وصول کنندہ') }}</div>
</div>
</body>
</html>
