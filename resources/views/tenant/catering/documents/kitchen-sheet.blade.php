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
<style>
    @page { size: A4 portrait; margin: 12mm; }
    * { box-sizing: border-box; }
    body {
        font-family: {{ $isUr ? "'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif" : "Arial, Helvetica, sans-serif" }};
        color: #111827; margin: 0; font-size: 14px; line-height: 1.5;
    }
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Urdu Typesetting', 'Noto Nastaliq Urdu', serif; direction: rtl; }
    .head { border: 3px solid #111827; border-radius: 8px; padding: 12px 18px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .head .customer { font-size: 26px; font-weight: bold; }
    .head .big { font-size: 20px; font-weight: bold; }
    .head .label { font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; }
    .badge-row { display: flex; gap: 18px; margin-top: 6px; flex-wrap: wrap; }
    .doc-meta { display: flex; justify-content: space-between; margin: 8px 2px; color: #6b7280; font-size: 12px; }
    h3.station { background: #111827; color: #fff; padding: 6px 12px; border-radius: 4px; margin: 16px 0 0; font-size: 15px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { text-align: {{ $isUr ? 'right' : 'left' }}; border-bottom: 2px solid #111827; padding: 8px; font-size: 12px; text-transform: uppercase; color: #374151; }
    table.items th.num, table.items td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.items td { padding: 10px 8px; border-bottom: 1px solid #d1d5db; vertical-align: top; }
    .item-name { font-size: 17px; font-weight: bold; }
    .item-ur { font-size: 18px; }
    .qty { font-size: 18px; font-weight: bold; white-space: nowrap; }
    .instructions { color: #374151; }
    .req { margin-top: 22px; page-break-inside: avoid; }
    .req h3 { border-bottom: 2px solid #111827; padding-bottom: 4px; font-size: 14px; }
    table.req-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.req-table th { text-align: {{ $isUr ? 'right' : 'left' }}; padding: 5px 8px; background: #f3f4f6; }
    table.req-table th.num, table.req-table td.num { text-align: {{ $isUr ? 'left' : 'right' }}; }
    table.req-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
    .print-bar { position: fixed; top: 10px; {{ $isUr ? 'left' : 'right' }}: 10px; }
    @media print { .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar">
    <button onclick="window.print()" style="padding: 8px 18px; cursor: pointer;">Print</button>
</div>

<div class="doc-meta">
    <span>{{ $businessName }} — {{ $t('KITCHEN / SERVICE SHEET', 'کچن شیٹ') }}</span>
    <span>{{ $release->release_no }} · {{ $release->released_at->format('d M Y g:i A') }}</span>
</div>

<div class="head">
    <div>
        <div class="label">{{ $t('Customer / Event', 'کسٹمر / تقریب') }}</div>
        <div class="customer">
            @if($isUr && !empty($snapshot['customer_name_ur']))
                <span class="ur">{{ $snapshot['customer_name_ur'] }}</span>
            @else
                {{ $snapshot['customer_name'] ?? '' }}
                @if($isBoth && !empty($snapshot['customer_name_ur']))
                    <span class="ur" style="font-size:22px;"> — {{ $snapshot['customer_name_ur'] }}</span>
                @endif
            @endif
        </div>
        <div class="badge-row">
            <span><span class="label">{{ $t('Event #', 'تقریب نمبر') }}</span> <strong>{{ $snapshot['event_no'] ?? '' }}</strong></span>
            @if(!empty($snapshot['event_type']))<span><strong>{{ $snapshot['event_type'] }}</strong></span>@endif
            @if(!empty($snapshot['customer_phone']))<span dir="ltr">{{ $snapshot['customer_phone'] }}</span>@endif
        </div>
    </div>
    <div>
        <div class="label">{{ $t('Date & Time', 'تاریخ و وقت') }}</div>
        <div class="big">{{ \Carbon\Carbon::parse($snapshot['event_date'])->format('D, d M Y') }}</div>
        @if(!empty($snapshot['service_time']))
            <div class="big">{{ \Carbon\Carbon::parse($snapshot['service_time'])->format('g:i A') }}</div>
        @endif
    </div>
    <div>
        <div class="label">{{ $t('Venue', 'مقام') }}</div>
        <div class="big">{{ $snapshot['venue'] ?? '—' }}</div>
        <div class="label" style="margin-top: 6px;">{{ $t('Guests (PAX)', 'مہمان') }}</div>
        <div class="big">{{ number_format($snapshot['pax'] ?? 0) }}</div>
    </div>
</div>

@foreach($byStation as $station => $lines)
    @if($station !== '')
        <h3 class="station">{{ $t('Station', 'اسٹیشن') }}: {{ $station }}</h3>
    @elseif($byStation->count() > 1)
        <h3 class="station">{{ $t('General', 'عمومی') }}</h3>
    @endif
    <table class="items">
        <thead>
            <tr>
                <th class="num" style="width: 110px;">{{ $t('Qty', 'مقدار') }}</th>
                <th>{{ $t('Item', 'آئٹم') }}</th>
                <th style="width: 38%;">{{ $t('Instructions', 'ہدایات') }}</th>
                <th style="width: 70px;">{{ $t('Done', 'مکمل') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
            <tr>
                <td class="num"><span class="qty">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }} {{ $line->unit_code }}</span></td>
                <td>
                    @if($isUr && $line->item_name_ur)
                        <div class="item-ur ur">{{ $line->item_name_ur }}</div>
                    @else
                        <div class="item-name">{{ $line->item_name }}</div>
                        @if($isBoth && $line->item_name_ur)
                            <div class="item-ur ur">{{ $line->item_name_ur }}</div>
                        @endif
                    @endif
                </td>
                <td class="instructions">{{ $line->instructions }}</td>
                <td style="text-align:center; font-size: 18px;">☐</td>
            </tr>
            @endforeach
        </tbody>
    </table>
@endforeach

@if(!empty($requirements))
<div class="req">
    <h3>{{ $t('Consolidated Raw Material Requirements (planning)', 'مجموعی خام مال کی ضروریات') }}</h3>
    <table class="req-table">
        <thead>
            <tr>
                <th>{{ $t('Material', 'خام مال') }}</th>
                <th class="num">{{ $t('Required', 'درکار') }}</th>
                <th>{{ $t('Unit', 'یونٹ') }}</th>
                <th>{{ $t('Used By', 'استعمال') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($requirements as $req)
            <tr>
                <td>{{ $req['name'] }}</td>
                <td class="num"><strong>{{ rtrim(rtrim(number_format($req['required_qty'], 3), '0'), '.') }}</strong></td>
                <td>{{ $req['unit_code'] }}</td>
                <td>{{ implode(', ', $req['used_by'] ?? []) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
</body>
</html>
