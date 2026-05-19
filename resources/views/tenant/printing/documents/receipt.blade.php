<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt — {{ $salesOrder->sale_no ?? $salesOrder->order_no }}</title>
    <style>
        @php
            $fontSize  = $layout?->font_size ?? 12;
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
        .right   { text-align: right; }
        .bold    { font-weight: bold; }
        hr       { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        table    { width: 100%; border-collapse: collapse; }
        td       { vertical-align: top; }
        .item-name  { width: 55%; }
        .item-qty   { width: 10%; text-align: right; }
        .item-price { width: 17%; text-align: right; }
        .item-total { width: 18%; text-align: right; }
        .totals td:first-child { width: 60%; text-align: right; padding-right: 4px; }
        .totals td:last-child  { width: 40%; text-align: right; }
        .print-btn { display: block; margin: 12px auto; padding: 8px 24px; cursor: pointer; font-size: 14px; }
        @media print { .print-btn, .no-print { display: none !important; } }
    </style>
</head>
<body>

@if(!($layout?->show_logo === false) && $layout?->logo_path)
<div class="center" style="margin-bottom:4px">
    <img src="{{ asset('storage/' . $layout->logo_path) }}" style="max-width:80px;max-height:40px">
</div>
@endif

@if(!($layout?->show_branch_name === false))
<div class="center bold">{{ $salesOrder->branch?->name }}</div>
@endif

@if(!($layout?->show_branch_address === false) && $salesOrder->branch?->address)
<div class="center">{{ $salesOrder->branch->address }}</div>
@endif

@if(!($layout?->show_branch_phone === false) && $salesOrder->branch?->phone)
<div class="center">Tel: {{ $salesOrder->branch->phone }}</div>
@endif

@if(!($layout?->show_tax_number === false) && $salesOrder->branch?->tax_number)
<div class="center">Tax No: {{ $salesOrder->branch->tax_number }}</div>
@endif

@if($layout?->header_text)
<div class="center" style="margin-top:4px">{{ $layout->header_text }}</div>
@endif

<hr>

<div class="bold center">RECEIPT</div>

<hr>

@if(!($layout?->show_order_no === false))
<div>Order: <span class="bold">{{ $salesOrder->sale_no ?? $salesOrder->order_no }}</span></div>
@endif

<div>Date: {{ $salesOrder->created_at?->format('d/m/Y H:i') }}</div>

@if(!($layout?->show_cashier_name === false) && $salesOrder->cashier)
<div>Cashier: {{ $salesOrder->cashier->name }}</div>
@endif

@if(!($layout?->show_customer_name === false) && $salesOrder->customer)
<div>Customer: {{ $salesOrder->customer->name }}</div>
@endif

@if(!($layout?->show_table_info === false) && $salesOrder->restaurantTable)
<div>Table: {{ $salesOrder->restaurantTable->table_no }}
    @if($salesOrder->restaurantTable->floor)
        ({{ $salesOrder->restaurantTable->floor->name }})
    @endif
</div>
@if($salesOrder->restaurantTableSession?->waiter)
<div>Waiter: {{ $salesOrder->restaurantTableSession->waiter->name }}</div>
@endif
@endif

<hr>

<table>
    <thead>
        <tr>
            <td class="item-name bold">Item</td>
            <td class="item-qty bold">Qty</td>
            <td class="item-price bold">Price</td>
            <td class="item-total bold">Total</td>
        </tr>
    </thead>
    <tbody>
        @foreach($salesOrder->lines as $line)
        <tr>
            <td class="item-name">
                @if(!($layout?->show_item_codes === false) && $line->product?->barcode)
                    <small>{{ $line->product->barcode }}</small><br>
                @endif
                {{ $line->product?->name }}
                @if($line->variant)
                    <small>({{ $line->variant->name }})</small>
                @endif
                @if($line->kitchen_note)
                    <br><small>* {{ $line->kitchen_note }}</small>
                @endif
            </td>
            <td class="item-qty">{{ $line->quantity }}</td>
            <td class="item-price">{{ number_format($line->unit_price, 2) }}</td>
            <td class="item-total">{{ number_format($line->line_total, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<hr>

<table class="totals">
    <tr>
        <td>Subtotal:</td>
        <td>{{ number_format($salesOrder->subtotal, 2) }}</td>
    </tr>
    @if($salesOrder->discount_amount > 0)
    <tr>
        <td>Discount:</td>
        <td>-{{ number_format($salesOrder->discount_amount, 2) }}</td>
    </tr>
    @endif
    @if($salesOrder->tax_amount > 0)
    <tr>
        <td>Tax:</td>
        <td>{{ number_format($salesOrder->tax_amount, 2) }}</td>
    </tr>
    @endif
    <tr>
        <td class="bold">Total:</td>
        <td class="bold">{{ number_format($salesOrder->total_amount, 2) }}</td>
    </tr>
</table>

@if(!($layout?->show_payment_breakdown === false) && $salesOrder->payments->isNotEmpty())
<hr>
<div class="bold">Payments:</div>
@foreach($salesOrder->payments as $payment)
<table class="totals">
    <tr>
        <td>{{ $payment->method?->name ?? ucfirst($payment->payment_method) }}:</td>
        <td>{{ number_format($payment->amount, 2) }}</td>
    </tr>
</table>
@endforeach
@endif

<hr>

@if($layout?->footer_text)
<div class="center" style="margin-top:4px">{{ $layout->footer_text }}</div>
@else
<div class="center">Thank you for your visit!</div>
@endif

<div class="center" style="margin-top:4px; font-size:10px">
    {{ $salesOrder->created_at?->format('d/m/Y H:i:s') }}
</div>

<button class="print-btn no-print" onclick="window.print()">🖨 Print</button>

@if($job->printer?->printer_type === 'browser')
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
@endif

</body>
</html>
