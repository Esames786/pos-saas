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
<div class="center big">** {{ strtoupper(str_replace('_', ' ', $salesOrder->order_type ?? 'SALE')) }} **</div>
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

<div>TIME: {{ now()->format('h:i A') }}</div>
<div>{{ now()->format('D d-M-Y') }}</div>

<hr>

<table class="{{ $dividers ? 'col-div' : '' }}">
    <thead>
        <tr>
            <td class="item-qty bold">QTY</td>
            <td class="item-name bold">ITEM</td>
        </tr>
    </thead>
    <tbody>
        @foreach($kotLines as $line)
        @if(($line->line_kind ?? 'standard') === 'combo_header') @continue @endif
        @php
            $totalQty = (float) $line->quantity;

            if ($isReprint) {
                $displayQty = $totalQty;
                $isAddition = false;
            } elseif (isset($lineQuantities) && $lineQuantities->has((string) ($line->line_id ?? $line->id ?? ''))) {
                // Use exact quantity stored in payload at job creation time.
                $displayQty = (float) $lineQuantities->get((string) ($line->line_id ?? $line->id));
                $isAddition = $totalQty > $displayQty;  // more was ordered than this delta
            } else {
                // Fallback for old jobs without line_quantities in payload.
                $sentQty    = (float) ($line->kot_sent_quantity ?? 0);
                $displayQty = max($totalQty - $sentQty, 0);
                $isAddition = $sentQty > 0;
            }
        @endphp
        <tr>
            <td class="item-qty ibig">
                @if($isAddition)
                    <span style="font-size:{{ $fontSize - 1 }}px">ADD</span><br>
                @endif
                {{ rtrim(rtrim(number_format($displayQty, 3, '.', ''), '0'), '.') }}
                @if($isAddition)
                    <br><small style="font-weight:normal">(T:{{ rtrim(rtrim(number_format($totalQty, 3, '.', ''), '0'), '.') }})</small>
                @endif
            </td>
            <td class="item-name">
                <span class="ibig">{{ ($eventType ?? null) === 'addition' ? '(R) ' : '' }}{{ strtoupper($line->product_name) }}</span>
                @if($line->variant_name && strcasecmp(trim($line->variant_name), trim((string) $line->product_name)) !== 0)
                    <br><small>{{ $line->variant_name }}</small>
                @endif
                @foreach(($line->modifiers ?? []) as $modifier)
                    @if(!empty($modifier['name']))
                        <br><small>+ {{ $modifier['name'] }}</small>
                    @endif
                @endforeach
                @if($line->kitchen_note)
                    <br><small>⚑ {{ $line->kitchen_note }}</small>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

<hr>

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
