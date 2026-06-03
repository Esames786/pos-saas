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
        @endphp
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
        hr       { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        table    { width: 100%; border-collapse: collapse; }
        td       { vertical-align: top; padding: 2px 0; }
        .item-qty  { width: 15%; text-align: right; padding-right: 4px; }
        .item-name { width: 85%; }
        .print-btn { display: block; margin: 12px auto; padding: 8px 24px; cursor: pointer; font-size: 14px; }
        @media print { .print-btn, .no-print { display: none !important; } }
    </style>
</head>
<body>

<div class="center bold" style="font-size:{{ $fontSize + 4 }}px">KOT</div>
@if($isReprint)
<div class="center bold">** REPRINT **</div>
@endif

<hr>

@if(!($layout?->show_branch_name === false))
<div class="center">{{ $salesOrder->branch?->name }}</div>
@endif

@if(!($layout?->show_order_no === false))
<div>Order: <span class="bold">{{ $salesOrder->sale_no ?? $salesOrder->order_no }}</span></div>
@endif

<div>Time: {{ now()->format('d/m/Y H:i') }}</div>

@if(!($layout?->show_table_info === false) && $salesOrder->restaurantTable)
<div class="bold" style="font-size:{{ $fontSize + 2 }}px">
    Table: {{ $salesOrder->restaurantTable->table_no }}
    @if($salesOrder->restaurantTable->floor)
        / {{ $salesOrder->restaurantTable->floor->name }}
    @endif
</div>
@if($salesOrder->restaurantTableSession?->waiter)
<div>Waiter: {{ $salesOrder->restaurantTableSession->waiter->name }}</div>
@endif
@endif

@if(!($layout?->show_cashier_name === false) && $salesOrder->createdBy)
<div>Cashier: {{ $salesOrder->createdBy->name }}</div>
@endif

<hr>

<table>
    <thead>
        <tr>
            <td class="item-qty bold">Qty</td>
            <td class="item-name bold">Item</td>
        </tr>
    </thead>
    <tbody>
        @foreach($kotLines as $line)
        @php
            $totalQty = (float) $line->quantity;

            if ($isReprint) {
                $displayQty = $totalQty;
                $isAddition = false;
            } elseif (isset($lineQuantities) && $lineQuantities->has((string) $line->id)) {
                // Use exact quantity stored in payload at job creation time.
                $displayQty = (float) $lineQuantities->get((string) $line->id);
                $isAddition = $totalQty > $displayQty;  // more was ordered than this delta
            } else {
                // Fallback for old jobs without line_quantities in payload.
                $sentQty    = (float) ($line->kot_sent_quantity ?? 0);
                $displayQty = max($totalQty - $sentQty, 0);
                $isAddition = $sentQty > 0;
            }
        @endphp
        <tr>
            <td class="item-qty bold" style="font-size:{{ $fontSize + 2 }}px">
                @if($isAddition)
                    <span style="font-size:{{ $fontSize - 1 }}px">ADD</span><br>
                @endif
                {{ number_format($displayQty, 2) }}
                @if($isAddition)
                    <br><small style="font-weight:normal">(T:{{ number_format($totalQty, 2) }})</small>
                @endif
            </td>
            <td class="item-name">
                <span class="bold">{{ $line->product_name }}</span>
                @if($line->variant_name)
                    <br><small>{{ $line->variant_name }}</small>
                @endif
                @if($line->kitchen_note)
                    <br><small>⚑ {{ $line->kitchen_note }}</small>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

<hr>

@if($layout?->footer_text)
<div class="center">{{ $layout->footer_text }}</div>
@endif

<div class="no-print" style="text-align:center;margin:12px 0;display:flex;gap:8px;justify-content:center">
    <button class="print-btn" style="margin:0" onclick="window.print()">🖨 Print KOT</button>
    <form method="POST" action="{{ url('/printing/jobs/' . $job->id . '/mark-printed') }}" style="display:inline">
        @csrf
        <button type="submit" class="print-btn" style="margin:0;background:#198754;color:#fff;border:none;cursor:pointer">✔ Mark Printed</button>
    </form>
</div>

@if($job->printer?->printer_type === 'browser')
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
@endif

</body>
</html>
