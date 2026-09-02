<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sales Report — {{ $filters['date_from'] }} → {{ $filters['date_to'] }}</title>
@php
    // Thermal Z / End-of-Day prints ROUND figures — no ".00" tail — so the counter reads clean
    // whole rupees; A4 keeps 2 decimals for the formal ledger. (Menu prices are whole rupees, so
    // rounding is exact; the raw values still drive every sum, only the printed figure is rounded.)
    $fmt = ($mode === 'thermal')
        ? fn ($v) => number_format((float) $v, 0)
        : fn ($v) => number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $has = fn (string $s) => in_array($s, $sections, true);

    // 72mm fits roughly 42 monospace characters. Seven columns do NOT fit: the name column gets
    // squeezed to nothing and "Beverages" prints one letter per line. So thermal keeps every
    // figure but stacks each row as name + a "sold − returned = net" line, while A4 keeps the
    // wide table. Same numbers, layout chosen for the paper.
    $isThermal = $mode === 'thermal';

    /**
     * THERMAL = REAL COLUMNS, four narrow ones: label + Sold / Ret / Net.
     *
     * 72mm holds only ~45 monospace characters, so the A4 seven-column table cannot fit — the
     * name column collapsed and printed one letter per line. Inline "sold - returned = net" did
     * fit but was hard to read. So the NAME takes its own full-width line and its figures sit
     * beneath in aligned columns, under a header printed once per table:
     *
     *              Sold     Ret     Net
     *   Beverages
     *   Qty           3       2       1
     *   Amt      280.00  170.00  110.00
     *
     * Widest realistic figures line is "Amt" plus three 10-character amounts ≈ 34 characters.
     */
    $tHead = fn () => '<tr><th></th><th class="amt">Sold</th><th class="amt">Ret</th><th class="amt">Net</th></tr>';
    $tCancelHead = fn () => '<tr><th colspan="2">Item / reason</th><th class="amt">Events</th><th class="amt">-Qty</th></tr>';

    // A long item name wraps over two/three lines on a narrow roll and pushes the layout around.
    // Keep any trailing "(size)" — a shop reads the size — and truncate the head with an ellipsis
    // so every name stays on ONE line. Short names (categories, most items) are returned untouched.
    $nameMax = (($paper ?? '80mm') === '58mm') ? 28 : 34;
    $shortName = function (string $name) use ($nameMax) {
        if (mb_strlen($name) <= $nameMax) {
            return $name;
        }
        if (preg_match('/^(.*\S)\s*(\([^)]*\))\s*$/u', $name, $m)) {
            $tail = ' ' . $m[2];
            $headMax = $nameMax - mb_strlen($tail) - 1;
            if ($headMax >= 8) {
                return rtrim(mb_substr($m[1], 0, $headMax)) . '…' . $tail;
            }
        }
        return rtrim(mb_substr($name, 0, $nameMax - 1)) . '…';
    };

    /** An entry carrying both quantities and money (categories, items). */
    $tEntry = function (string $name, $sQ, $rQ, $nQ, $sV, $rV, $nV, bool $bold = false, string $indent = '') use ($qty, $fmt, $shortName) {
        $cls = $bold ? ' class="total"' : '';
        // The name a reader scans for is always emphasised; a total's name carries both.
        $nameCls = ' class="name' . ($bold ? ' total' : '') . '"';

        return '<tr' . $nameCls . '><td colspan="4">' . $indent . e($shortName($name)) . '</td></tr>'
             . '<tr><td class="lbl">' . $indent . 'Qty</td>'
             . '<td class="amt">' . $qty($sQ) . '</td><td class="amt">' . $qty($rQ) . '</td><td class="amt">' . $qty($nQ) . '</td></tr>'
             . '<tr' . $cls . '><td class="lbl">' . $indent . 'Amt</td>'
             . '<td class="amt">' . $fmt($sV) . '</td><td class="amt">' . $fmt($rV) . '</td><td class="amt">' . $fmt($nV) . '</td></tr>';
    };

    /** An order-level entry — money only, no line quantities (order types, waiters). */
    $tOrders = function (string $name, $orders, $billed, $ret, $net, bool $bold = false) use ($fmt) {
        $cls = $bold ? ' class="total"' : '';
        $nameCls = ' class="name' . ($bold ? ' total' : '') . '"';

        return '<tr' . $nameCls . '><td colspan="4">' . e($name) . '</td></tr>'
             . '<tr' . $cls . '><td class="lbl">' . (int) $orders . ' ord</td>'
             . '<td class="amt">' . $fmt($billed) . '</td><td class="amt">' . $fmt($ret) . '</td><td class="amt">' . $fmt($net) . '</td></tr>';
    };

    // Item/category nets cover MERCHANDISE only; the order-level charges (delivery, tax, service,
    // tips, less discount) belong to the order, not to any line. Printed alone those totals look
    // like they contradict NET SALES, so every line-based section closes with the bridge that gets
    // it there. The gap is derived as (NET SALES − line net), so it ALWAYS closes — the old
    // delivery-only figure silently vanished the moment a discount/tax existed (line net + delivery
    // did not equal net sales), which is exactly why the global totals stopped reconciling.
    $bridgeNetSales = (float) ($bridge['net_sales'] ?? 0);
    $bridgeRows = function (float $lineNet, int $span) use ($bridgeNetSales, $fmt) {
        $netCharges = round($bridgeNetSales - $lineNet, 2);
        if (abs($netCharges) <= 0.01) {
            return '';
        }
        $label = fn ($t) => '<td' . ($span > 1 ? ' colspan="' . ($span - 1) . '"' : '') . '>' . $t . '</td>';

        return '<tr>' . $label('Plus Delivery &amp; Other Charges (net)') . '<td class="amt">' . $fmt($netCharges) . '</td></tr>'
             . '<tr class="total">' . $label('= NET SALES') . '<td class="amt">' . $fmt($bridgeNetSales) . '</td></tr>';
    };

    // Per-order-type bridge: the BY ORDER TYPE categories/items are MERCHANDISE only, but this
    // type's NET SALES includes its order-level charges (delivery etc.). Close the gap so a
    // delivery total no longer looks like it contradicts the cash. Uses the engine's per-type
    // totals; prints only when there is a charge to explain.
    $otBridge = function (string $typeLabel, int $span) use ($combos, $fmt) {
        $t = $combos['totals'][$typeLabel] ?? null;
        if (! $t || abs((float) $t['net_charges']) <= 0.01) {
            return '';
        }
        $label = fn ($x) => '<td' . ($span > 1 ? ' colspan="' . ($span - 1) . '"' : '') . '>' . $x . '</td>';

        return '<tr>' . $label('Plus Delivery &amp; Other Charges (net)') . '<td class="amt">' . $fmt($t['net_charges']) . '</td></tr>'
             . '<tr class="total">' . $label('= NET SALES') . '<td class="amt">' . $fmt($t['net_sales']) . '</td></tr>';
    };
@endphp
<style>
    body { font-family: 'Courier New', monospace; color: #000; margin: 0 auto; padding: 8px; }
    /* PRINT-PARITY: thermal carries the same figures as A4 in a paper-appropriate layout. */
    @if($mode === 'thermal')
    /* Thermal heads print grey and thin — the counter could not read 10px normal weight. Bold at
       12px is the legibility win; 58mm stays smaller because it has ~20% less width to give. */
    /* Emphasis is WEIGHT ONLY — never a bigger font. The numeric tables are measured to
       fit 72mm exactly; enlarging a row overflowed the width and word-break shattered the
       labels one character per line. 'Courier New' has 400 and 700, so normal detail against
       700 headers/names/net rows reads as bold without touching a single column width. */
    body {
        width: {{ $paper === '58mm' ? '52mm' : '72mm' }};
        font-size: {{ $paper === '58mm' ? '11px' : '12px' }};
        font-weight: 400;
    }
    h1 { font-size: {{ $paper === '58mm' ? '14px' : '15px' }}; font-weight: 700; text-align: center; margin: 4px 0; }
    h2 { font-size: {{ $paper === '58mm' ? '13px' : '15px' }}; font-weight: 700; border-top: 1px dashed #000; padding-top: 4px; margin: 8px 0 4px; }
    h3 { font-size: {{ $paper === '58mm' ? '12px' : '13px' }}; font-weight: 700; margin: 6px 0 2px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { vertical-align: top; word-break: break-word; text-align: left; padding: 1px 2px; }
    th { border-bottom: 1px dashed #000; }
    th.amt, td.amt { text-align: right; white-space: nowrap; }
    /* The Qty/Amt/"N ord" row labels are the only breakable cell in a figures row, so when the
       nowrap amount columns claim the width the browser shatters the label one char per line
       ("Qt"/"y"). Keeping the 3-char label on one line costs no width and stops the shatter. */
    td.lbl { white-space: nowrap; }
    /* The Qty/Amt figure rows print in 400 weight and came out FAINT on the roll while the bold
       headings read fine. A hair of text-stroke thickens the figures + their Qty/Amt label so they
       show clearly in print — a paint-only effect, so it never changes a column's width. Headings
       (th, .name) are left as they are. Tune the 0.2px up/down for more/less darkness. */
    td.amt, td.lbl { -webkit-text-stroke: 0.2px currentColor; }
    /* Each entry is three rows deep — name, Qty, Amt — so a long list runs together and the eye
       cannot find where one item ends and the next begins. A dashed rule above every NAME row
       fences each entry off. Bold entries (heads, TOTAL) already carry the same rule through
       .total, so they gain no second line. */
    .name { font-weight: 700; border-top: 1px dashed #000; }
    /* …except the very first entry of a table, which would otherwise sit under two rules: the
       column header's own border and its. */
    tr:first-child + tr.name { border-top: 0; }
    .total { border-top: 1px dashed #000; font-weight: 700; }
    .name td, .total td { font-weight: 700; }
    @else
    body { width: 190mm; font-family: Arial, sans-serif; font-size: 12px; }
    h1 { font-size: 18px; margin: 4px 0; }
    h2 { font-size: 14px; margin: 14px 0 6px; border-bottom: 1px solid #333; }
    h3 { font-size: 12px; margin: 8px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th, td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; }
    th.amt, td.amt { text-align: right; }
    .name { font-weight: bold; }
    .total { font-weight: bold; background: #f2f2f2; }
    @endif
    .no-print { text-align: center; margin: 8px 0; }
    @media print { .no-print { display: none; } }
</style>
</head>
<body>
@unless($embedded ?? false)
<div class="no-print"><button onclick="window.print()">Print</button></div>
@endunless

<h1>{{ app()->bound('tenant') ? app('tenant')->business_name : 'Bingoo POS' }}</h1>
<div style="text-align:center">
    Sales Report ({{ $mode === 'thermal' ? 'Z / End of Day' : 'Standard' }})<br>
    {{ $filters['date_from'] }} → {{ $filters['date_to'] }}<br>
    Generated {{ app(\App\Support\TenantClock::class)->now()->format('d-M-Y H:i') }}
</div>

@if($has('overview') && $overview)
<h2>OVERALL</h2>
<table>
    <tr><td>Orders</td><td class="amt">{{ $overview['orders'] }}</td></tr>
    <tr><td>Sold Qty</td><td class="amt">{{ $qty($overview['sold_qty']) }}</td></tr>
    <tr><td>Returned Qty</td><td class="amt">{{ $qty($overview['returned_qty']) }}</td></tr>
    <tr class="total"><td>Net Qty</td><td class="amt">{{ $qty($overview['net_qty']) }}</td></tr>
    <tr><td>Items Sold</td><td class="amt">{{ $fmt($overview['gross_sales']) }}</td></tr>
    <tr><td>Less Discount</td><td class="amt">-{{ $fmt($overview['discount']) }}</td></tr>
    <tr><td>Plus Tax</td><td class="amt">{{ $fmt($overview['tax']) }}</td></tr>
    <tr><td>Plus Service Charge</td><td class="amt">{{ $fmt($overview['service_charge']) }}</td></tr>
    <tr><td>Plus Delivery Charge</td><td class="amt">{{ $fmt($overview['delivery_charge']) }}</td></tr>
    <tr><td>Plus Tips</td><td class="amt">{{ $fmt($overview['tips']) }}</td></tr>
    <tr class="total"><td>BILLED TO CUSTOMERS</td><td class="amt">{{ $fmt($overview['grand_total']) }}</td></tr>
    <tr><td>Less Posted Returns</td><td class="amt">-{{ $fmt($overview['returns_amount']) }}</td></tr>
    <tr class="total"><td>NET SALES</td><td class="amt">{{ $fmt($overview['net_sales']) }}</td></tr>
</table>
<h3>CASH FROM SALES</h3>
<table>
    <tr><td>Cash Collected</td><td class="amt">{{ $fmt($overview['cash_collected']) }}</td></tr>
    <tr><td>Cash Refunds Paid</td><td class="amt">-{{ $fmt($overview['cash_refunds']) }}</td></tr>
    <tr class="total"><td>NET CASH FROM SALES</td><td class="amt">{{ $fmt($overview['net_cash_from_sales']) }}</td></tr>
    @if($overview['returns_not_refunded'] > 0)
        <tr><td>Returns Without Refund</td><td class="amt">{{ $fmt($overview['returns_not_refunded']) }}</td></tr>
    @endif
</table>
@endif

@if($has('order_types') && $orderTypes !== null)
<h2>ORDER TYPES</h2>
@if($isThermal)
<table>
    {!! $tHead() !!}
    @foreach($orderTypes as $r)
        {!! $tOrders($r['label'], $r['orders'], $r['grand_total'], $r['returns_amount'], $r['net_sales']) !!}
    @endforeach
    {!! $tOrders('TOTAL', collect($orderTypes)->sum('orders'), collect($orderTypes)->sum('grand_total'), collect($orderTypes)->sum('returns_amount'), collect($orderTypes)->sum('net_sales'), true) !!}
</table>
@else
<table>
    <tr><th>Type</th><th class="amt">Orders</th><th class="amt">Billed</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
    @foreach($orderTypes as $r)
        <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $fmt($r['grand_total']) }}</td><td class="amt">{{ $fmt($r['returns_amount']) }}</td><td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ collect($orderTypes)->sum('orders') }}</td><td class="amt">{{ $fmt(collect($orderTypes)->sum('grand_total')) }}</td><td class="amt">{{ $fmt(collect($orderTypes)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($orderTypes)->sum('net_sales')) }}</td></tr>
</table>
@endif
@endif

@if($has('categories') && $categories !== null)
<h2>CATEGORIES</h2>
@if($isThermal)
<table>
    {!! $tHead() !!}
    @foreach($categories as $root)
        {!! $tEntry($root['name'], $root['sold_qty'], $root['returned_qty'], $root['net_qty'], $root['net'], $root['returns_amount'], $root['net_value'], true) !!}
        @foreach($root['children'] as $c)
            @if($c['id'] !== $root['id'])
                {!! $tEntry($c['name'], $c['sold_qty'], $c['returned_qty'], $c['net_qty'], $c['net'], $c['returns_amount'], $c['net_value'], false, ' ') !!}
            @endif
        @endforeach
    @endforeach
    {!! $tEntry('TOTAL', collect($categories)->sum('sold_qty'), collect($categories)->sum('returned_qty'), collect($categories)->sum('net_qty'), collect($categories)->sum('net'), collect($categories)->sum('returns_amount'), collect($categories)->sum('net_value'), true) !!}
    {!! $bridgeRows((float) collect($categories)->sum('net_value'), 4) !!}
</table>
@else
<table>
    <tr><th>Category</th><th class="amt">Sold Qty</th><th class="amt">Ret Qty</th><th class="amt">Net Qty</th><th class="amt">Sold</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
    @foreach($categories as $root)
        <tr class="total"><td>{{ $root['name'] }}</td><td class="amt">{{ $qty($root['sold_qty']) }}</td><td class="amt">{{ $qty($root['returned_qty']) }}</td><td class="amt">{{ $qty($root['net_qty']) }}</td><td class="amt">{{ $fmt($root['net']) }}</td><td class="amt">{{ $fmt($root['returns_amount']) }}</td><td class="amt">{{ $fmt($root['net_value']) }}</td></tr>
        @foreach($root['children'] as $c)
            @if($c['id'] !== $root['id'])<tr><td>&nbsp;&nbsp;{{ $c['name'] }}</td><td class="amt">{{ $qty($c['sold_qty']) }}</td><td class="amt">{{ $qty($c['returned_qty']) }}</td><td class="amt">{{ $qty($c['net_qty']) }}</td><td class="amt">{{ $fmt($c['net']) }}</td><td class="amt">{{ $fmt($c['returns_amount']) }}</td><td class="amt">{{ $fmt($c['net_value']) }}</td></tr>@endif
        @endforeach
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty(collect($categories)->sum('sold_qty')) }}</td><td class="amt">{{ $qty(collect($categories)->sum('returned_qty')) }}</td><td class="amt">{{ $qty(collect($categories)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($categories)->sum('net')) }}</td><td class="amt">{{ $fmt(collect($categories)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($categories)->sum('net_value')) }}</td></tr>
    {!! $bridgeRows((float) collect($categories)->sum('net_value'), 7) !!}
</table>
@endif
@endif

@if($has('items') && $items !== null)
<h2>ITEMS</h2>
@if($isThermal)
<table>
    {!! $tHead() !!}
    @foreach($items as $r)
        {!! $tEntry($r->item . ($r->variant ? ' (' . $r->variant . ')' : ''), $r->sold_qty, $r->returned_qty, $r->net_qty, $r->net, $r->returns_amount, $r->net_value) !!}
    @endforeach
    {!! $tEntry('TOTAL', collect($items)->sum('sold_qty'), collect($items)->sum('returned_qty'), collect($items)->sum('net_qty'), collect($items)->sum('net'), collect($items)->sum('returns_amount'), collect($items)->sum('net_value'), true) !!}
    {!! $bridgeRows((float) collect($items)->sum('net_value'), 4) !!}
</table>
@else
<table>
    <tr><th>Item</th><th class="amt">Sold Qty</th><th class="amt">Ret Qty</th><th class="amt">Net Qty</th><th class="amt">Sold</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
    @foreach($items as $r)
        <tr><td>{{ $r->item }}{{ $r->variant ? ' (' . $r->variant . ')' : '' }}</td><td class="amt">{{ $qty($r->sold_qty) }}</td><td class="amt">{{ $qty($r->returned_qty) }}</td><td class="amt">{{ $qty($r->net_qty) }}</td><td class="amt">{{ $fmt($r->net) }}</td><td class="amt">{{ $fmt($r->returns_amount) }}</td><td class="amt">{{ $fmt($r->net_value) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty(collect($items)->sum('sold_qty')) }}</td><td class="amt">{{ $qty(collect($items)->sum('returned_qty')) }}</td><td class="amt">{{ $qty(collect($items)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($items)->sum('net')) }}</td><td class="amt">{{ $fmt(collect($items)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($items)->sum('net_value')) }}</td></tr>
    {!! $bridgeRows((float) collect($items)->sum('net_value'), 7) !!}
</table>
@endif
@endif

{{-- ITEMS BY CATEGORY — ITEMS-BY-CATEGORY-1. The same rows as ITEMS, filed under their category
     heads with a subtotal each — the shape the client's old software prints. The head is the ROOT
     category so every subtotal reconciles against CATEGORIES; a sub-head appears only where that
     root really has children with sales. --}}
@if($has('category_items') && ($categoryItems ?? null) !== null)
@php $ciRows = collect($categoryItems); @endphp
<h2>ITEMS BY CATEGORY</h2>
@if($isThermal)
<table>
    {!! $tHead() !!}
    {{-- A head is printed the way CATEGORIES prints one: bold, over a dashed rule, carrying its
         own figures. It was a bare <strong> line before — on a roll of monospace text that reads
         as just another item, and the shop could not see where one category ended and the next
         began. The indent then does the rest of the work: sub-head one space, item two. --}}
    @foreach($categoryItems as $head)
        {!! $tEntry($head['head'], $head['sold_qty'], $head['returned_qty'], $head['net_qty'], $head['net'], $head['returns_amount'], $head['net_value'], true) !!}
        @foreach($head['groups'] as $group)
            @if($head['nested'])
                {!! $tEntry($group['name'], $group['sold_qty'], $group['returned_qty'], $group['net_qty'], $group['net'], $group['returns_amount'], $group['net_value'], false, ' ') !!}
            @endif
            @foreach($group['items'] as $item)
                {!! $tEntry($item['item'] . ($item['variant'] ? ' (' . $item['variant'] . ')' : ''), $item['sold_qty'], $item['returned_qty'], $item['net_qty'], $item['net'], $item['returns_amount'], $item['net_value'], false, $head['nested'] ? '  ' : ' ') !!}
            @endforeach
        @endforeach
    @endforeach
    {!! $tEntry('TOTAL', $ciRows->sum('sold_qty'), $ciRows->sum('returned_qty'), $ciRows->sum('net_qty'), $ciRows->sum('net'), $ciRows->sum('returns_amount'), $ciRows->sum('net_value'), true) !!}
    {{-- Merchandise only, like every other line-based section: close the gap to NET SALES, or the
         total reads as though it contradicted the day's takings. --}}
    {!! $bridgeRows((float) $ciRows->sum('net_value'), 4) !!}
</table>
@else
<table>
    <tr><th>Category / Item</th><th class="amt">Sold Qty</th><th class="amt">Ret Qty</th><th class="amt">Net Qty</th><th class="amt">Sold</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
    @foreach($categoryItems as $head)
        <tr class="total"><td><strong>{{ strtoupper($head['head']) }}</strong></td><td class="amt">{{ $qty($head['sold_qty']) }}</td><td class="amt">{{ $qty($head['returned_qty']) }}</td><td class="amt">{{ $qty($head['net_qty']) }}</td><td class="amt">{{ $fmt($head['net']) }}</td><td class="amt">{{ $fmt($head['returns_amount']) }}</td><td class="amt">{{ $fmt($head['net_value']) }}</td></tr>
        @foreach($head['groups'] as $group)
            @if($head['nested'])
                <tr><td><strong>&nbsp;&nbsp;{{ $group['name'] }}</strong></td><td class="amt">{{ $qty($group['sold_qty']) }}</td><td class="amt">{{ $qty($group['returned_qty']) }}</td><td class="amt">{{ $qty($group['net_qty']) }}</td><td class="amt">{{ $fmt($group['net']) }}</td><td class="amt">{{ $fmt($group['returns_amount']) }}</td><td class="amt">{{ $fmt($group['net_value']) }}</td></tr>
            @endif
            @foreach($group['items'] as $item)
                <tr><td>{!! $head['nested'] ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '&nbsp;&nbsp;' !!}{{ $item['item'] }}{{ $item['variant'] ? ' (' . $item['variant'] . ')' : '' }}</td><td class="amt">{{ $qty($item['sold_qty']) }}</td><td class="amt">{{ $qty($item['returned_qty']) }}</td><td class="amt">{{ $qty($item['net_qty']) }}</td><td class="amt">{{ $fmt($item['net']) }}</td><td class="amt">{{ $fmt($item['returns_amount']) }}</td><td class="amt">{{ $fmt($item['net_value']) }}</td></tr>
            @endforeach
        @endforeach
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty($ciRows->sum('sold_qty')) }}</td><td class="amt">{{ $qty($ciRows->sum('returned_qty')) }}</td><td class="amt">{{ $qty($ciRows->sum('net_qty')) }}</td><td class="amt">{{ $fmt($ciRows->sum('net')) }}</td><td class="amt">{{ $fmt($ciRows->sum('returns_amount')) }}</td><td class="amt">{{ $fmt($ciRows->sum('net_value')) }}</td></tr>
    {!! $bridgeRows((float) $ciRows->sum('net_value'), 7) !!}
</table>
@endif
@endif

{{-- DEALS — DEAL-CATEGORY-1. Grouped under their own heads, the way the client's old software
     prints DEALS / MIDNIGHT DEAL 1 / CLASSIC PLATTER. ITEMS above no longer carries them. --}}
@if($has('deals') && ($deals ?? null) !== null)
<h2>DEALS</h2>
@php $dealRows = collect($deals); $currentHead = null; @endphp
@if($isThermal)
<table>
    {!! $tHead() !!}
    @foreach($deals as $r)
        @if($r['head'] !== $currentHead)
            @php $currentHead = $r['head']; @endphp
            <tr><td colspan="7"><strong>{{ strtoupper($currentHead) }}</strong></td></tr>
        @endif
        {!! $tEntry($r['deal'], $r['sold_qty'], $r['returned_qty'], $r['net_qty'], $r['net'], $r['returns_amount'], $r['net_value']) !!}
    @endforeach
    {!! $tEntry('TOTAL', $dealRows->sum('sold_qty'), $dealRows->sum('returned_qty'), $dealRows->sum('net_qty'), $dealRows->sum('net'), $dealRows->sum('returns_amount'), $dealRows->sum('net_value'), true) !!}
</table>
@else
<table>
    <tr><th>Deal</th><th class="amt">Sold Qty</th><th class="amt">Ret Qty</th><th class="amt">Net Qty</th><th class="amt">Sold</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
    @foreach($deals as $r)
        @if($r['head'] !== $currentHead)
            @php $currentHead = $r['head']; @endphp
            <tr><td colspan="7"><strong>{{ $currentHead }}</strong></td></tr>
        @endif
        <tr><td>{{ $r['deal'] }}</td><td class="amt">{{ $qty($r['sold_qty']) }}</td><td class="amt">{{ $qty($r['returned_qty']) }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net']) }}</td><td class="amt">{{ $fmt($r['returns_amount']) }}</td><td class="amt">{{ $fmt($r['net_value']) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty($dealRows->sum('sold_qty')) }}</td><td class="amt">{{ $qty($dealRows->sum('returned_qty')) }}</td><td class="amt">{{ $qty($dealRows->sum('net_qty')) }}</td><td class="amt">{{ $fmt($dealRows->sum('net')) }}</td><td class="amt">{{ $fmt($dealRows->sum('returns_amount')) }}</td><td class="amt">{{ $fmt($dealRows->sum('net_value')) }}</td></tr>
</table>
@endif
@endif

@if($has('waiters') && $waiters !== null)
<h2>WAITERS</h2>
@if($isThermal)
<table>
    {!! $tHead() !!}
    @foreach($waiters as $r)
        {!! $tOrders($r['label'], $r['orders'], $r['grand_total'], $r['returns_amount'], $r['net_sales']) !!}
    @endforeach
    {!! $tOrders('TOTAL', collect($waiters)->sum('orders'), collect($waiters)->sum('grand_total'), collect($waiters)->sum('returns_amount'), collect($waiters)->sum('net_sales'), true) !!}
</table>
@else
<table>
    <tr><th>Waiter</th><th class="amt">Orders</th><th class="amt">Billed</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
    @foreach($waiters as $r)
        <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $fmt($r['grand_total']) }}</td><td class="amt">{{ $fmt($r['returns_amount']) }}</td><td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL</td><td class="amt">{{ collect($waiters)->sum('orders') }}</td><td class="amt">{{ $fmt(collect($waiters)->sum('grand_total')) }}</td><td class="amt">{{ $fmt(collect($waiters)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($waiters)->sum('net_sales')) }}</td></tr>
</table>
@endif
@endif

@if($has('order_type_combos') && $combos !== null)
<h2>BY ORDER TYPE</h2>
@foreach($combos['categories'] as $orderType => $rows)
    <h3>{{ strtoupper($orderType) }} — CATEGORIES</h3>
    <table>
        @if($isThermal)
            {!! $tHead() !!}
            @foreach($rows as $r)
                {!! $tEntry($r['label'], $r['sold_qty'], $r['returned_qty'], $r['net_qty'], $r['net'], $r['returns_amount'], $r['net_value']) !!}
            @endforeach
        @else
        <tr><th>Category</th><th class="amt">Sold Qty</th><th class="amt">Ret Qty</th><th class="amt">Net Qty</th><th class="amt">Sold</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
        @foreach($rows as $r)
            <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $qty($r['sold_qty']) }}</td><td class="amt">{{ $qty($r['returned_qty']) }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net']) }}</td><td class="amt">{{ $fmt($r['returns_amount']) }}</td><td class="amt">{{ $fmt($r['net_value']) }}</td></tr>
        @endforeach
        @endif
        @if($isThermal)
            {!! $tEntry('TOTAL', collect($rows)->sum('sold_qty'), collect($rows)->sum('returned_qty'), collect($rows)->sum('net_qty'), collect($rows)->sum('net'), collect($rows)->sum('returns_amount'), collect($rows)->sum('net_value'), true) !!}
            {!! $otBridge($orderType, 4) !!}
        @else
            <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty(collect($rows)->sum('sold_qty')) }}</td><td class="amt">{{ $qty(collect($rows)->sum('returned_qty')) }}</td><td class="amt">{{ $qty(collect($rows)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net_value')) }}</td></tr>
            {!! $otBridge($orderType, 7) !!}
        @endif
    </table>
@endforeach
@foreach($combos['items'] as $orderType => $rows)
    <h3>{{ strtoupper($orderType) }} — ITEMS</h3>
    <table>
        @if($isThermal)
            {!! $tHead() !!}
            @foreach($rows as $r)
                {!! $tEntry($r['label'], $r['sold_qty'], $r['returned_qty'], $r['net_qty'], $r['net'], $r['returns_amount'], $r['net_value']) !!}
            @endforeach
        @else
        <tr><th>Item</th><th class="amt">Sold Qty</th><th class="amt">Ret Qty</th><th class="amt">Net Qty</th><th class="amt">Sold</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
        @foreach($rows as $r)
            <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $qty($r['sold_qty']) }}</td><td class="amt">{{ $qty($r['returned_qty']) }}</td><td class="amt">{{ $qty($r['net_qty']) }}</td><td class="amt">{{ $fmt($r['net']) }}</td><td class="amt">{{ $fmt($r['returns_amount']) }}</td><td class="amt">{{ $fmt($r['net_value']) }}</td></tr>
        @endforeach
        @endif
        @if($isThermal)
            {!! $tEntry('TOTAL', collect($rows)->sum('sold_qty'), collect($rows)->sum('returned_qty'), collect($rows)->sum('net_qty'), collect($rows)->sum('net'), collect($rows)->sum('returns_amount'), collect($rows)->sum('net_value'), true) !!}
            {!! $otBridge($orderType, 4) !!}
        @else
            <tr class="total"><td>TOTAL</td><td class="amt">{{ $qty(collect($rows)->sum('sold_qty')) }}</td><td class="amt">{{ $qty(collect($rows)->sum('returned_qty')) }}</td><td class="amt">{{ $qty(collect($rows)->sum('net_qty')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net_value')) }}</td></tr>
            {!! $otBridge($orderType, 7) !!}
        @endif
    </table>
@endforeach
@foreach($combos['waiters'] as $orderType => $rows)
    <h3>{{ strtoupper($orderType) }} — WAITERS</h3>
    @if($isThermal)
    <table>
        {!! $tHead() !!}
        @foreach($rows as $r)
            {!! $tOrders($r['label'], $r['orders'], $r['grand_total'], $r['returns_amount'], $r['net_sales']) !!}
        @endforeach
        {!! $tOrders('TOTAL', collect($rows)->sum('orders'), collect($rows)->sum('grand_total'), collect($rows)->sum('returns_amount'), collect($rows)->sum('net_sales'), true) !!}
    </table>
    @else
    <table>
        <tr><th>Waiter</th><th class="amt">Orders</th><th class="amt">Billed</th><th class="amt">Returns</th><th class="amt">Net</th></tr>
        @foreach($rows as $r)
            <tr><td>{{ $r['label'] }}</td><td class="amt">{{ $r['orders'] }}</td><td class="amt">{{ $fmt($r['grand_total']) }}</td><td class="amt">{{ $fmt($r['returns_amount']) }}</td><td class="amt">{{ $fmt($r['net_sales']) }}</td></tr>
        @endforeach
        <tr class="total"><td>TOTAL</td><td class="amt">{{ collect($rows)->sum('orders') }}</td><td class="amt">{{ $fmt(collect($rows)->sum('grand_total')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('returns_amount')) }}</td><td class="amt">{{ $fmt(collect($rows)->sum('net_sales')) }}</td></tr>
    </table>
    @endif
@endforeach
@endif

@if($has('cancellations') && $cancellations !== null)
<h2>CANCELLATIONS (voided / decreased after KOT)</h2>
@if($isThermal)
<table>
    {!! $tCancelHead() !!}
    @forelse($cancellations['rows'] as $r)
        <tr><td colspan="4">{{ $r['item'] }}</td></tr>
        <tr>
            <td colspan="2">{{ $r['order_type'] }} / {{ $r['reason'] }}</td>
            <td class="amt">{{ $r['events'] }}</td>
            <td class="amt">-{{ $qty($r['qty']) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">No cancellations in this period.</td></tr>
    @endforelse
    <tr class="total">
        <td colspan="2">TOTAL</td>
        <td class="amt">{{ $cancellations['total_events'] }}</td>
        <td class="amt">-{{ $qty($cancellations['total_qty']) }}</td>
    </tr>
</table>
@else
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
@endif

@if($has('overview') && $overview && !empty($overview['payments']))
<h2>PAYMENTS COLLECTED</h2>
<table>
    @foreach($overview['payments'] as $method => $amount)
        <tr><td style="text-transform: capitalize">{{ str_replace('_', ' ', $method) }}</td><td class="amt">{{ $fmt($amount) }}</td></tr>
    @endforeach
    <tr class="total"><td>TOTAL COLLECTED</td><td class="amt">{{ $fmt(collect($overview['payments'])->sum()) }}</td></tr>
    {{-- Printed alone this total contradicts NET SALES: it is money taken BEFORE refunds went
         back out. Close the loop here so the page never ends on an unexplained figure. --}}
    <tr><td>Less Refunds Paid</td><td class="amt">-{{ $fmt($overview['refunds_recorded']) }}</td></tr>
    <tr class="total"><td>= NET RECEIVED</td><td class="amt">{{ $fmt(collect($overview['payments'])->sum() - (float) $overview['refunds_recorded']) }}</td></tr>
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
