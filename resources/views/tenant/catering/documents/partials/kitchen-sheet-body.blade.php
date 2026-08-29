{{-- KASHIF-CATERING-OPERATOR-UI-1: the printable body, extracted verbatim
     so the single document and the bulk composition render from ONE source and
     can never drift apart. Wrapper markup (html/head/CSS shell) stays with the
     including page. --}}
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
            {{-- CAT-PROD-002 — the KITCHEN's number, not the store's.
                 This printed only what our store issues, so a material the
                 customer is bringing appeared as 0 and effectively vanished from
                 the sheet. The kitchen still has to cook with it: the dish needs
                 eight kilos of rice whoever carries it through the door. Both
                 numbers are printed, and where they differ the sheet says why. --}}
            <tr>
                <th>{{ $t('Material', 'خام مال') }}</th>
                <th class="num">{{ $t('Kitchen Needs', 'باورچی خانہ کو درکار') }}</th>
                <th class="num">{{ $t('From Our Store', 'ہمارے اسٹور سے') }}</th>
                <th>{{ $t('Unit', 'یونٹ') }}</th>
                <th>{{ $t('Used By', 'استعمال') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($requirements as $req)
            @php
                // Releases frozen before this distinction existed carry only the
                // one figure; for those the two answers really were the same.
                $physical = (float) ($req['physical_qty'] ?? $req['required_qty']);
                $ours = (float) $req['required_qty'];
                $supplied = (float) ($req['customer_supplied_qty'] ?? max($physical - $ours, 0));
                $fmt = fn ($n) => rtrim(rtrim(number_format($n, 3), '0'), '.');
            @endphp
            <tr>
                <td>
                    {{ $req['name'] }}
                    @if($supplied > 0)
                        <div style="font-size:10px; color:#7c2d12;">
                            {{ $t('Customer supplied', 'گاہک فراہم کرے گا') }} — {{ $fmt($supplied) }} {{ $req['unit_code'] }}
                        </div>
                    @endif
                </td>
                <td class="num"><strong>{{ $fmt($physical) }}</strong></td>
                <td class="num">
                    {{ $fmt($ours) }}
                    @if($ours <= 0 && $physical > 0)
                        <div style="font-size:10px; color:#7c2d12;">{{ $t('none', 'کچھ نہیں') }}</div>
                    @endif
                </td>
                <td>{{ $req['unit_code'] }}</td>
                <td>{{ implode(', ', $req['used_by'] ?? []) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
