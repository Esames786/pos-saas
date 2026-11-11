<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KOT — {{ $salesOrder->sale_no ?? $salesOrder->order_no }}</title>
    <style>
        @php
            $fontSize  = $layout?->kot_font_size ?? 14;
            $paperSize = $layout?->paper_size ?? '80mm';
            $width     = match($paperSize) { '58mm' => '52mm', '80mm' => '72mm', default => '180mm' };

            // PREVIEW MUST WRAP WHERE THE PAPER WRAPS.
            // These bands mirror EscPosPayloadService::scaleFor(). The printer fits 42 characters
            // at normal width and halves that for each width step, so the preview derives its font
            // size from that character budget instead of using the px value directly — otherwise
            // the screen shows a line the printer would chop in half.
            $scaleW = match (true) { $fontSize <= 17 => 1, $fontSize <= 20 => 2, default => 3 };
            $scaleH = match (true) { $fontSize <= 14 => 1, $fontSize <= 17 => 2, $fontSize <= 20 => 2, default => 3 };
            $cols   = max((int) floor(42 / $scaleW), 8);
            // Courier advances 0.6em per character, so this many chars exactly fill the paper.
            $bigFont = 'calc(' . $width . ' / ' . ($cols * 0.6) . ')';

            // Item rows carry their own size (item_font_size) so the kitchen can shrink them below
            // the big heading — mirrors EscPosPayloadService's $rowBig. Null = the KOT font.
            $itemFontSize = $layout?->item_font_size ?? $fontSize;
            $iScaleW = match (true) { $itemFontSize <= 17 => 1, $itemFontSize <= 20 => 2, default => 3 };
            $iScaleH = match (true) { $itemFontSize <= 14 => 1, $itemFontSize <= 17 => 2, $itemFontSize <= 20 => 2, default => 3 };
            $iCols   = max((int) floor(42 / $iScaleW), 8);
            $itemFont = 'calc(' . $width . ' / ' . ($iCols * 0.6) . ')';
            $dividers = (bool) ($layout?->show_column_dividers ?? false);

            // KOT-DEAL-HEADING-1 — mirrors EscPosPayloadService::kot(): the deal name one step TALLER
            // than an item row (never wider), its items one step SHORTER at normal width.
            $dealHeading = (bool) ($layout?->show_deal_as_heading ?? false);
            $dScaleW = $iScaleW;
            $dScaleH = min($iScaleH + 1, 8);
            $cScaleH = max($iScaleH - 1, 1);
            $normalFont = 'calc(' . $width . ' / ' . (42 * 0.6) . ')';
        @endphp
        /* Without an @page rule the browser used its own page size and ~10mm margins, which on a
           continuous roll prints a blank band above the ticket and wastes paper below it. */
        @page { size: {{ $width }} auto; margin: 0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: {{ $fontSize }}px;
            width: {{ $width }};
            margin: 0 auto;
            padding: 4px;
            color: #000;
        }
        .center  { text-align: center; }
        .bold    { font-weight: bold; }
        /* What the kitchen reads, at the printer's real character budget. */
        .big     { font-size: {{ $bigFont }}; font-weight: bold; line-height: 1.15; overflow-wrap: break-word; }
        @if($scaleH > $scaleW)
        /* Taller but not wider: the glyphs stretch vertically, the line still holds 42 characters. */
        .big     { display: block; transform: scaleY({{ $scaleH }}); transform-origin: left top; margin-bottom: {{ ($scaleH - 1) * 1.2 }}em; }
        @endif
        /* Item-row scale, its own character budget from item_font_size. */
        .ibig    { font-size: {{ $itemFont }}; font-weight: bold; line-height: 1.15; overflow-wrap: break-word; }
        @if($iScaleH > $iScaleW)
        .ibig    { display: block; transform: scaleY({{ $iScaleH }}); transform-origin: left top; margin-bottom: {{ ($iScaleH - 1) * 1.2 }}em; }
        @endif
        @if($dealHeading)
        /* KOT-DEAL-HEADING-1: the deal name a step taller than an item row; its items a step smaller. */
        .dbig    { font-size: {{ $itemFont }}; font-weight: bold; line-height: 1.15; overflow-wrap: break-word; display: block; transform: scaleY({{ round($dScaleH / $dScaleW, 3) }}); transform-origin: left top; margin-bottom: {{ round(($dScaleH / $dScaleW - 1) * 1.2, 3) }}em; }
        .cbig    { font-size: {{ $normalFont }}; font-weight: bold; line-height: 1.15; overflow-wrap: break-word; display: block; padding-left: 4ch; }
        @if($cScaleH > 1)
        .cbig    { transform: scaleY({{ $cScaleH }}); transform-origin: left top; margin-bottom: {{ ($cScaleH - 1) * 1.2 }}em; }
        @endif
        @endif
        hr       { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        table    { width: 100%; border-collapse: collapse; }
        td       { vertical-align: top; padding: 2px 0; }
        .item-qty  { width: 15%; text-align: right; padding-right: 4px; }
        .item-name { width: 85%; }
        /* Column divider line between Qty | Item, matching the thermal `|`. */
        table.col-div td { border-left: 1px solid #000; }
        table.col-div td:first-child { border-left: none; }
        /* Dashed rule under each KOT item, so rows read as boxes (matches the thermal ticket). */
        tbody td { border-bottom: 1px dashed #000; padding: 3px 0; }
        /* KOT-DEAL-HEADING-1: no rule inside a deal — one rule after its last item. */
        tbody tr.deal-head td, tbody tr.deal-item td { border-bottom: none; padding: 1px 0; }
        tbody tr.deal-item.deal-last td { border-bottom: 1px dashed #000; padding-bottom: 3px; }
        .print-btn { display: block; margin: 12px auto; padding: 8px 24px; cursor: pointer; font-size: 14px; }
        @media print {
            .print-btn, .no-print { display: none !important; }
            body { width: {{ $width }}; margin: 0; padding: 1mm 1.5mm 0; }
        }
    </style>
</head>
<body>

<div class="center bold" style="font-size:{{ $fontSize + 4 }}px">
    @if(($eventType ?? 'normal') === 'cancel')
        CANCEL KOT #{{ ($sequenceNo ?? 0) ?: 1 }}
    @elseif(($eventType ?? 'normal') === 'addition')
        ADDITION KOT #{{ ($sequenceNo ?? 0) ?: 1 }}
    @elseif(($eventType ?? 'normal') === 'duplicate')
        DUPLICATE KOT #{{ ($sequenceNo ?? 0) ?: 1 }}
    @else
        KOT #{{ ($sequenceNo ?? 0) ?: 1 }}
    @endif
</div>
@if(($eventType ?? '') === 'duplicate')
<div class="center bold">DUPLICATE {{ max($copyNo ?? 1, 1) }}</div>
@endif
{{-- KOT-HEADING-STARS-1 — wohi switch jo thermal parchi par lagta hai, taake preview aur kaghaz
     ek hi baat kahein. `!== false` likha hai (na ke `=== true`) kyunki jis tenant ki layout row
     abhi purani hai us me ye column hai hi nahi — us soorat me aaj wali soorat qaayam rehni
     chahiye, yani sitare.

     ⚠️ Upar wale `KOT #n` par sitare JAAN-BOOJH KAR nahi jore gaye: is blade par wo pehle se
     nahi hain (thermal par hain — purana ikhtilaf). Jorne se har tenant ka preview badal jata,
     jabke kisi ne maanga nahi. Switch band hone par dono khud ba khud mil jate hain. --}}
@php $kotStars = ($layout?->show_heading_stars ?? true) !== false; @endphp
<div class="center big">{{ $kotStars ? '** ' : '' }}{{ strtoupper(str_replace('_', ' ', $salesOrder->order_type ?? 'SALE')) }}{{ $kotStars ? ' **' : '' }}</div>
@if(!($layout?->show_category_header === false) && !empty($kotCategory ?? null))
@php
    // Brackets are dropped when they would no longer fit, exactly as the ESC/POS builder does —
    // otherwise a nearly-full category name wraps its closing bracket onto a line of its own.
    $catText = mb_strlen('[ ' . strtoupper($kotCategory) . ' ]') <= $cols
        ? '[ ' . strtoupper($kotCategory) . ' ]'
        : strtoupper($kotCategory);
@endphp
<div class="center big">{{ $catText }}</div>
@endif

<hr>

@if(!($layout?->show_branch_name === false))
<div class="center">{{ $salesOrder->branch?->name }}</div>
@endif

@if(!($layout?->show_order_no === false))
<div class="center">{{ $salesOrder->sale_no ?? $salesOrder->order_no }}</div>
@endif

{{-- BILL-BARCODE-1: the bill number, big, so the billing counter scans this KOT and the order comes up.
     Only where the layout switch is SAVED on (default OFF — no tenant's KOT changes on deploy). --}}
@php
    $billCode = (data_get($layout, 'show_bill_barcode') === true)
        ? (data_get($salesOrder, 'bill_code') ?: (data_get($salesOrder, 'id') ? \App\Support\Code128::billCode((int) data_get($salesOrder, 'id')) : null))
        : null;
@endphp
@if($billCode)
<div class="center" style="margin:6px 0 2px">{!! \App\Support\Code128::svg($billCode, 60, 2) !!}</div>
<div class="center big">{{ $billCode }}</div>
@endif

<hr>

{{-- Field ORDER + casing mirror EscPosPayloadService::kot() exactly, so the preview equals the
     paper: TABLE / WAITER (big, uppercase, no floor) → CASHIER → VEHICLE → TIME + date. --}}
@if(!($layout?->show_table_info === false) && $salesOrder->restaurantTable)
<div class="big">TABLE: {{ $salesOrder->restaurantTable->table_no }}</div>
@if($salesOrder->restaurantTableSession?->waiter)
<div class="big">WAITER: {{ strtoupper($salesOrder->restaurantTableSession->waiter->name) }}</div>
@endif
@endif

@if(!($layout?->show_cashier_name === false) && $salesOrder->createdBy)
<div>CASHIER: {{ $salesOrder->createdBy->name }}</div>
@endif

@if(!($layout?->show_vehicle_number === false) && $salesOrder->vehicle_number)
<div class="big">VEHICLE: {{ $salesOrder->vehicle_number }}</div>
@endif

{{-- KOT-TIME-TRUTH-1: wohi waqt jo printer par jaata hai — usi helper se, us hi timezone me.
     Pehle yahan now() tha aur koi timezone nahi: preview sarwar ka UTC dikhata tha jabke parchi
     Karachi ka waqt chhapti thi, yani do screenon par do jawab. --}}
@php
    $kotTz      = app(\App\Support\TenantClock::class)->normalize($salesOrder->shift?->timezone_name)
                  ?? app(\App\Support\TenantClock::class)->normalize($salesOrder->branch?->timezone)
                  ?? \App\Support\TenantClock::DEFAULT_TIMEZONE;
    // Ye blade TEEN jagah se render hota hai: asli print job ka preview, aur Layout screen ka
    // live preview jo koi job deta hi nahi (wo settings dikhata hai, kisi parchi ki nakal nahi).
    // $job ko farz kar lena us screen ko 500 kar deta hai — wohi hua tha.
    $kotJob     = $job ?? null;
    $kotOrdered = (\App\Support\KotTicketTime::orderedAt($kotJob, $salesOrder) ?? now())->timezone($kotTz);
    $kotReprint = \App\Support\KotTicketTime::reprintAt($kotJob);
@endphp
<div>TIME: {{ $kotOrdered->format('h:i A') }}</div>
<div>{{ $kotOrdered->format('D d-M-Y') }}</div>
@if($kotReprint)
<div>REPRINT: {{ $kotReprint->timezone($kotTz)->format('d/m/Y h:i A') }}</div>
@endif

<hr>

<table class="{{ $dividers ? 'col-div' : '' }}">
    <thead>
        <tr>
            <td class="item-qty bold">QTY</td>
            <td class="item-name bold">ITEM</td>
        </tr>
    </thead>
    <tbody>
        @php
            // The quantity this slip prints for a line — the same rule the item row uses.
            $kotIsReprint = (bool) ($isReprint ?? false);
            $kotLineQty = $lineQuantities ?? collect();
            $kotQtyOf = function ($l) use ($kotIsReprint, $kotLineQty) {
                if ($kotIsReprint) {
                    return (float) $l->quantity;
                }
                if ($kotLineQty->has((string) ($l->line_id ?? $l->id ?? ''))) {
                    return (float) $kotLineQty->get((string) ($l->line_id ?? $l->id));
                }

                return max((float) $l->quantity - (float) ($l->kot_sent_quantity ?? 0), 0);
            };
            $kotFmtQty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.');
            // Same rule as EscPosPayloadService::unitSuffix(): a piece count prints bare, a real measure keeps its unit.
            $kotUnit = fn ($code) => ($code && ! in_array(strtoupper(trim((string) $code)), ['EA','EACH','PC','PCS','PIECE','PIECES','NOS','NO','UNIT','UNITS','QTY'], true)) ? ' ' . $code : '';
        @endphp
        @if($dealHeading)
            {{-- KOT-DEAL-HEADING-1: the deal name ONCE, its items for this station under it. --}}
            @foreach(\App\Support\KotDealGroups::build($kotLines, $salesOrder->lines ?? collect(), $comboNames ?? [], $kotQtyOf) as $entry)
                @if($entry['kind'] === 'item')
                    @include('tenant.printing.documents._kot_item_row', ['line' => $entry['line']])
                @else
                    <tr class="deal-head">
                        <td class="item-qty dbig">{{ $entry['qty'] !== null ? $kotFmtQty($entry['qty']) : '' }}</td>
                        <td class="item-name"><span class="dbig">{{ ($eventType ?? null) === 'addition' ? '(R) ' : '' }}{{ strtoupper($entry['name']) }}</span></td>
                    </tr>
                    @foreach($entry['lines'] as $dealLine)
                        <tr class="deal-item{{ $loop->last ? ' deal-last' : '' }}">
                            <td class="item-qty"></td>
                            <td class="item-name">
                                <span class="cbig">{{ $kotFmtQty($kotQtyOf($dealLine)) }}{{ $kotUnit($dealLine->unit_code) }}&nbsp;{{ strtoupper($dealLine->product_name) }}</span>
                                @if($dealLine->variant_name && strcasecmp(trim($dealLine->variant_name), trim((string) $dealLine->product_name)) !== 0)
                                    <small style="padding-left:8ch">{{ $dealLine->variant_name }}</small>
                                @endif
                                @foreach(($dealLine->modifiers ?? []) as $modifier)
                                    @if(!empty($modifier['name']))
                                        <br><small style="padding-left:8ch">+ {{ $modifier['name'] }}</small>
                                    @endif
                                @endforeach
                                @if($dealLine->kitchen_note)
                                    <br><small style="padding-left:8ch">⚑ {{ $dealLine->kitchen_note }}</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
        @else
            @foreach($kotLines as $line)
            @if(($line->line_kind ?? 'standard') === 'combo_header') @continue @endif
            @include('tenant.printing.documents._kot_item_row')
            @endforeach
        @endif
    </tbody>
</table>

<hr>

{{-- KOT-ORDER-NOTE-1: the same order note (and switch) the paper KOT prints. --}}
@php
    $kitchenNote = method_exists($salesOrder, 'kitchenNote') ? $salesOrder->kitchenNote() : (trim((string) ($salesOrder->notes ?? '')) ?: null);
@endphp
@if($kitchenNote !== null && ($layout?->show_order_note ?? false) === true)
<div><strong>ORDER NOTE:</strong> {{ $kitchenNote }}</div>
<hr>
@endif

@if($layout?->show_bingoo_branding ?? false)
<div class="center">BingooPos</div>
<div class="center">Bingoopos.com</div>
@endif
@if($layout?->footer_text)
<div class="center">{{ $layout->footer_text }}</div>
@endif

<div class="no-print" style="text-align:center;margin:12px 0;display:flex;gap:8px;justify-content:center">
    <button class="print-btn" style="margin:0" onclick="window.print()">🖨 Print KOT</button>
    @if(!empty($job))
    <form method="POST" action="{{ url('/printing/jobs/' . $job->id . '/mark-printed') }}" style="display:inline">
        @csrf
        <button type="submit" class="print-btn" style="margin:0;background:#198754;color:#fff;border:none;cursor:pointer">✔ Mark Printed</button>
    </form>
    @endif
</div>

@if(!empty($job) && $job->printer?->printer_type === 'browser')
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
@endif

</body>
</html>
