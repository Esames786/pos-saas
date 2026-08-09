<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sales Report — {{ $filters['date_from'] }} → {{ $filters['date_to'] }}</title>
@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $has = fn (string $s) => in_array($s, $sections, true);
@endphp
<style>
    body { font-family: 'Courier New', monospace; color: #000; margin: 0 auto; padding: 8px; }
    /* PRINT-PARITY: thermal carries the SAME columns as A4 — only width/font differ. */
    @if($mode === 'thermal')
    body { width: {{ $paper === '58mm' ? '52mm' : '72mm' }}; font-size: 10px; }
    h1 { font-size: 13px; text-align: center; margin: 4px 0; }
    h2 { font-size: 11px; border-top: 1px dashed #000; padding-top: 4px; margin: 8px 0 4px; }
    h3 { font-size: 10px; margin: 6px 0 2px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { vertical-align: top; word-break: break-word; text-align: left; padding: 1px 2px; }
    th { border-bottom: 1px dashed #000; }
    th.amt, td.amt { text-align: right; white-space: nowrap; }
    .total { border-top: 1px dashed #000; font-weight: bold; }
    @else
    body { width: 190mm; font-family: Arial, sans-serif; font-size: 12px; }
    h1 { font-size: 18px; margin: 4px 0; }
    h2 { font-size: 14px; margin: 14px 0 6px; border-bottom: 1px solid #333; }
    h3 { font-size: 12px; margin: 8px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th, td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; }
    th.amt, td.amt { text-align: right; }
    .total { font-weight: bold; background: #f2f2f2; }
    @endif
    .no-print { text-align: center; margin: 8px 0; }
    @media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="no-print"><button onclick="window.print()">Print</button></div>

<h1>{{ app()->bound('tenant') ? app('tenant')->business_name : 'Bingoo POS' }}</h1>
<div style="text-align:center">
    Sales Report ({{ $mode === 'thermal' ? 'Z / End of Day' : 'Standard' }})<br>
    {{ $filters['date_from'] }} → {{ $filters['date_to'] }}<br>
    Generated {{ now()->format('d-M-Y H:i') }}
</div>

@if($has('overview') && $overview)
<h2>OVERALL</h2>
<table>
    <tr><td>Orders</td><td class="amt">{{ $overview['orders'] }}</td></tr>
    <tr><td>Sold Qty</td><td class="amt">{{ $qty($overview['sold_qty']) }}</td></tr>
    <tr><td>Returned Qty</td><td class="amt">{{ $qty($overview['returned_qty']) }}</td></tr>
    <tr class="total"><td>Net Qty</td><td class="amt">{{ $qty($overview['net_qty']) }}</td></tr>
    <tr><td>Gross Sales</td><td class="amt">{{ $fmt($overview['gross_sales']) }}</td></tr>
    <tr><td>Discount</td><td class="amt">{{ $fmt($overview['discount']) }}</td></tr>
    <tr><td>Tax</td><td class="amt">{{ $fmt($overview['tax']) }}</td></tr>
    <tr><td>Service Charge</td><td class="amt">{{ $fmt($overview['service_charge']) }}</td></tr>
    <tr><td>Delivery Charge</td><td class="amt">{{ $fmt($overview['delivery_charge']) }}</td></tr>
    <tr><td>Returns/Refunds</td><td class="amt">{{ $fmt($overview['returns_amount']) }}</td></tr>
    <tr class="total"><td>NET SALES</td><td class="amt">{{ $fmt($overview['net_sales']) }}</td></tr>
</table>
@endif

@if($has('order_types') && $orderTypes !== null)
<h2>ORDER TYPES</h2>
<table>
    <tr><th>Type</th><th class="amt">Orders</th><th class="amt">Net Qty</th><th class="amt">Net Sales</th></tr>
    @foreach($orderTypes as $r)
        <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ collect($orderTypes)->sum('orders') }}</td><td class="amt">{{ $qty(collect($orderTypes)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($orderTypes)->sum('net_sales')) }}</td></tr>
</table>
@endif

@if($has('categories') && $categories !== null)
<h2>CATEGORIES</h2>
<table>
    <tr><th>Category</th><th class="amt">Orders</th><th class="amt">Net Qty</th><th class="amt">Net</th></tr>
    @foreach($categories as $root)
        <tr class="total"><td>{{ $root['name'] }}</td><td class="amt">{{ (int) $root['orders'] }}</td><td class="amt">{{ $qty($root['net_qty']) }}</td><td class="amt">{{ $fmt($root['net']) }}</td></tr>
        @foreach($root['children'] as $c)
            @if($c['id'] !== $root['id'])<tr><td>&nbsp;&nbsp;{{ $c['name'] }}</td><td class="amt">{{ (int) $c['orders'] }}</td><td class="amt">{{ $qty($c['net_qty']) }}</td><td class="amt">{{ $fmt($c['net']) }}</td></tr>@endif
        @endforeach
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt"></td><td class="amt">{{ $qty(collect($categories)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($categories)->sum('net')) }}</td></tr>
</table>
@endif

@if($has('items') && $items !== null)
<h2>ITEMS</h2>
<table>
    <tr><th>Item</th><th class="amt">Net Qty</th><th class="amt">Net</th></tr>
    @foreach($items as $r)
        <tr><td>{{ $r->item }}{{ $r->variant ? ' (' . $r->variant . ')' : '' }}</td><td class="amt">{{ $qty($r->net_qty) }}</td><td class="amt">{{ $fmt($r->net) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty(collect($items)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($items)->sum('net')) }}</td></tr>
</table>
@endif

@if($has('waiters') && $waiters !== null)
<h2>WAITERS</h2>
<table>
    <tr><th>Waiter</th><th class="amt">Orders</th><th class="amt">Net Qty</th><th class="amt">Net Sales</th></tr>
    @foreach($waiters as $r)
        <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ collect($waiters)->sum('orders') }}</td><td class="amt">{{ $qty(collect($waiters)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($waiters)->sum('net_sales')) }}</td></tr>
</table>
@endif

@if($has('order_type_combos') && $combos !== null)
<h2>BY ORDER TYPE</h2>
@foreach($combos['categories'] as $orderType => $rows)
    <h3>{{ strtoupper($orderType) }} — CATEGORIES</h3>
    <table>
        <tr><th>Category</th><th class="amt">Orders</th><th class="amt">Net Qty</th><th class="amt">Net</th></tr>
        @foreach($rows as $r)
            <tr><td>{{ $r['label'] }}</td><td class="amt">{{ (int) $r['orders'] }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net']) }}</td></tr>
        @endforeach
        <tr class="total"><td>TOTAL</td><td class="amt"></td><td class="amt">{{ $qty(collect($rows)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net')) }}</td></tr>
    </table>
@endforeach
@foreach($combos['items'] as $orderType => $rows)
    <h3>{{ strtoupper($orderType) }} — ITEMS</h3>
    <table>
        <tr><th>Item</th><th class="amt">Net Qty</th><th class="amt">Net</th></tr>
        @foreach($rows as $r)
            <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net']) }}</td></tr>
        @endforeach
        <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty(collect($rows)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net')) }}</td></tr>
    </table>
@endforeach
@foreach($combos['waiters'] as $orderType => $rows)
    <h3>{{ strtoupper($orderType) }} — WAITERS</h3>
    <table>
        <tr><th>Waiter</th><th class="amt">Orders</th><th class="amt">Sales</th></tr>
        @foreach($rows as $r)
            <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $fmt($r['grand_total']) }}</td></tr>
        @endforeach
        <tr class="total"><td>TOTAL</td><td class="amt">{{ collect($rows)->sum('orders') }}</td><td class="amt">{{ $fmt(collect($rows)->sum('grand_total')) }}</td></tr>
    </table>
@endforeach
@endif

@if($has('cancellations') && $cancellations !== null)
<h2>CANCELLATIONS (voided / decreased after KOT)</h2>
<table>
    <tr><th>Item</th><th>Type</th><th>Reason</th><th class="amt">Events</th><th class="amt">-Qty</th></tr>
    @forelse($cancellations['rows'] as $r)
        <tr><td>{{ $r['item'] }}</td><td>{{ $r['order_type'] }}</td><td>{{ $r['reason'] }}</td><td class="amt">{{ $r['events'] }}</td><td class="amt">-{{ $qty($r['qty']) }}</td></tr>
    @empty
        <tr><td colspan="5">No cancellations in this period.</td></tr>
    @endforelse
    <tr class="total"><td>TOTAL</td><td></td><td></td><td class="amt">{{ $cancellations['total_events'] }}</td><td class="amt">-{{ $qty($cancellations['total_qty']) }}</td></tr>
</table>
@endif

@if($has('overview') && $overview && !empty($overview['payments']))
<h2>PAYMENTS</h2>
<table>
    @foreach($overview['payments'] as $method => $amount)
        <tr><td style="text-transform: capitalize">{{ str_replace('_', ' ', $method) }}</td><td class="amt">{{ $fmt($amount) }}</td></tr>
    @endforeach
</table>
@endif

@if($has('cash_bank') && $cashBank !== null)
<h2>CASH &amp; BANK (money position)</h2>
<table>
    <tr><td>Opening Cash (float)</td><td class="amt">{{ $fmt($cashBank['shifts']['opening_cash'] ?? 0) }}</td></tr>
    <tr><td>Expected Cash</td><td class="amt">{{ $fmt($cashBank['expected_cash_formula']) }}</td></tr>
    <tr><td>Counted Cash</td><td class="amt">{{ $fmt($cashBank['shifts']['counted_cash'] ?? 0) }}</td></tr>
    <tr><td>Variance</td><td class="amt">{{ $fmt($cashBank['shifts']['cash_variance'] ?? 0) }}</td></tr>
    @foreach($cashBank['movements'] as $m)
        <tr><td>{{ $m['label'] }} ({{ $m['direction'] }})</td><td class="amt">{{ $fmt($m['amount']) }}</td></tr>
    @endforeach
    <tr class="total"><td>Net Cash Movement</td><td class="amt">{{ $fmt($cashBank['net_cash_movement']) }}</td></tr>
</table>
<p style="text-align:center; font-size: 10px">Opening float / transfers are never counted as sales revenue.</p>
@endif
</body>
</html>
