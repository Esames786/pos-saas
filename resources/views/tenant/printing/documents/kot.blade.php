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
        <tr>
            <td class="item-qty bold" style="font-size:{{ $fontSize + 2 }}px">{{ $line->quantity }}</td>
            <td class="item-name">
                <span class="bold">{{ $line->product?->name }}</span>
                @if($line->variant)
                    <br><small>{{ $line->variant->name }}</small>
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

<button class="print-btn no-print" onclick="window.print()">🖨 Print KOT</button>

@if($job->printer?->printer_type === 'browser')
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
@endif

</body>
</html>
