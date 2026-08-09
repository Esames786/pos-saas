<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sales Report — {{ $filters['date_from'] }} → {{ $filters['date_to'] }}</title>
@php $fmt = fn ($v) => number_format((float) $v, 2); @endphp
<style>
    body { font-family: 'Courier New', monospace; color: #000; margin: 0 auto; padding: 8px; }
    @if($mode === 'thermal')
    body { width: {{ $paper === '58mm' ? '52mm' : '72mm' }}; font-size: 11px; }
    h1 { font-size: 13px; text-align: center; margin: 4px 0; }
    h2 { font-size: 12px; border-top: 1px dashed #000; padding-top: 4px; margin: 8px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; word-break: break-word; }
    td.amt { text-align: right; white-space: nowrap; }
    .total { border-top: 1px dashed #000; font-weight: bold; }
    @else
    body { width: 190mm; font-family: Arial, sans-serif; font-size: 12px; }
    h1 { font-size: 18px; margin: 4px 0; }
    h2 { font-size: 14px; margin: 14px 0 6px; border-bottom: 1px solid #333; }
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

<h2>OVERALL</h2>
<table>
    <tr><td>Orders</td><td class="amt">{{ $overview['orders'] }}</td></tr>
    <tr><td>Sold Qty</td><td class="amt">{{ $fmt($overview['sold_qty']) }}</td></tr>
    <tr><td>Returned Qty</td><td class="amt">{{ $fmt($overview['returned_qty']) }}</td></tr>
    <tr><td>Gross Sales</td><td class="amt">{{ $fmt($overview['gross_sales']) }}</td></tr>
    <tr><td>Discount</td><td class="amt">{{ $fmt($overview['discount']) }}</td></tr>
    <tr><td>Tax</td><td class="amt">{{ $fmt($overview['tax']) }}</td></tr>
    <tr><td>Service Charge</td><td class="amt">{{ $fmt($overview['service_charge']) }}</td></tr>
    <tr><td>Delivery Charge</td><td class="amt">{{ $fmt($overview['delivery_charge']) }}</td></tr>
    <tr><td>Returns/Refunds</td><td class="amt">{{ $fmt($overview['returns_amount']) }}</td></tr>
    <tr class="total"><td>NET SALES</td><td class="amt">{{ $fmt($overview['net_sales']) }}</td></tr>
</table>

<h2>ORDER TYPES</h2>
<table>
    @if($mode !== 'thermal')<tr><th>Type</th><th class="amt">Orders</th><th class="amt">Net Qty</th><th class="amt">Net Sales</th></tr>@endif
    @foreach($orderTypes as $r)
        <tr><td>{{ $r['label'] }}</td>@if($mode !== 'thermal')<td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $fmt($r['net_qty']) }}</td>@endif<td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
    @endforeach
</table>

<h2>CATEGORIES</h2>
<table>
    @foreach($categories as $root)
        <tr class="total"><td>{{ $root['name'] }}</td><td class="amt">{{ $fmt($root['net']) }}</td></tr>
        @foreach($root['children'] as $c)
            @if($c['id'] !== $root['id'])<tr><td>&nbsp;&nbsp;{{ $c['name'] }}</td><td class="amt">{{ $fmt($c['net']) }}</td></tr>@endif
        @endforeach
    @endforeach
</table>

<h2>WAITERS</h2>
<table>
    @foreach($waiters as $r)
        <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
    @endforeach
</table>

<h2>PAYMENTS</h2>
<table>
    @foreach($overview['payments'] as $method => $amount)
        <tr><td style="text-transform: capitalize">{{ str_replace('_', ' ', $method) }}</td><td class="amt">{{ $fmt($amount) }}</td></tr>
    @endforeach
</table>

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
</body>
</html>
