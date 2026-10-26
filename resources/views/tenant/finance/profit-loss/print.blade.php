@php
    // FINANCE-PDF-DOWNLOADS-1 / PNL-DETAIL-1 — the P&L PDF, as the hand-made sample sent to the client: the same tree and
    // detail as the screen (ProfitLossReport, one build), every total statement()'s. Brackets for a negative amount.
    $fmt = fn (float $v) => $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2);
    $period = \App\Support\FinancePdf::longDate($pl['period']['from']) . ' to ' . \App\Support\FinancePdf::longDate($pl['period']['to']);
    $net = (float) $pl['net_profit'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Profit and Loss {{ $pl['period']['from'] }} to {{ $pl['period']['to'] }}</title>
<style>
    @page { margin: 16mm 14mm 18mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #1b2430; }
    .top { text-align: center; margin-bottom: 10px; }
    .company { font-size: 14pt; font-weight: bold; color: #13233a; }
    .title { font-size: 11pt; font-weight: bold; color: #2b3e57; margin-top: 2px; }
    .period { font-size: 8pt; color: #5b6b80; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    thead th { font-size: 8pt; font-weight: bold; text-align: left; padding: 5px 6px; border-top: 1.5px solid #13233a; border-bottom: 1.5px solid #13233a; }
    td { padding: 3px 6px; vertical-align: top; }
    tr { page-break-inside: avoid; }
    .r { text-align: right; white-space: nowrap; }
    .code { width: 13%; font-family: "DejaVu Sans Mono", monospace; font-size: 7.5pt; color: #6b7a90; white-space: nowrap; }
    .sect td { font-weight: bold; color: #13233a; padding-top: 10px; }
    .head td { font-weight: bold; }
    .part td { font-size: 7.5pt; color: #6b7a90; padding-top: 1px; padding-bottom: 1px; }
    .total td { font-weight: bold; border-top: 1px solid #8a9ab0; }
    .profit td { font-weight: bold; background: #e9eef4; border-top: 1.5px solid #13233a; border-bottom: 1.5px solid #13233a; }
    .net td { font-weight: bold; font-size: 10pt; background: #e9eef4; border-top: 1.5px solid #13233a; border-bottom: 3px double #13233a; }
    .margin td { font-style: italic; color: #6b7a90; }
    .empty td { color: #8a9ab0; font-style: italic; }
    .foot { margin-top: 14px; text-align: center; font-size: 7.5pt; color: #8a9ab0; }
</style>
</head>
<body>
    <div class="top">
        <div class="company">{{ $company }}</div>
        <div class="title">Profit and Loss Statement</div>
        <div class="period">For the period {{ $period }} · Amounts in PKR{{ $branchLabel ? ' · Branch: ' . $branchLabel : '' }}</div>
    </div>

    <table id="pl-print">
        <thead>
            <tr><th style="width:13%">Particulars</th><th></th><th class="r">Amount</th></tr>
        </thead>
        <tbody>
        @foreach($report['parts'] as $part => $lines)
            @if($part !== 'discounts')
                <tr class="sect"><td colspan="3">{{ $labels[$part] }}</td></tr>
            @endif
            @forelse($lines as $l)
                <tr class="{{ $l['kind'] === 'head' ? 'head' : 'line' }}">
                    <td class="code">{{ $l['code'] }}</td>
                    <td style="padding-left: {{ 6 + $l['depth'] * 12 }}px">{{ $part === 'discounts' ? 'Less: ' : '' }}{{ $l['name'] }}</td>
                    <td class="r">{{ $part === 'discounts' ? $fmt(-$l['amount']) : $fmt($l['amount']) }}</td>
                </tr>
                @foreach($l['detail'] as $d)
                    <tr class="part">
                        <td class="code"></td>
                        <td style="padding-left: {{ 18 + $l['depth'] * 12 }}px">{{ $d['label'] }}</td>
                        <td class="r">{{ $fmt($d['amount']) }}</td>
                    </tr>
                @endforeach
            @empty
                @if($part !== 'discounts')
                    <tr class="empty"><td class="code"></td><td>None in this period</td><td class="r">0.00</td></tr>
                @endif
            @endforelse

            @if($part === 'revenue')
                <tr class="total"><td class="code"></td><td>Gross Revenue</td><td class="r">{{ $fmt($pl['gross_revenue']) }}</td></tr>
            @elseif($part === 'discounts')
                <tr class="total"><td class="code"></td><td>Net Revenue</td><td class="r">{{ $fmt($pl['net_revenue']) }}</td></tr>
            @elseif($part === 'cost_of_sales')
                <tr class="total"><td class="code"></td><td>Total Cost of Sales</td><td class="r">{{ $fmt($pl['total_cogs']) }}</td></tr>
                <tr class="profit"><td class="code"></td><td>Gross Profit</td><td class="r">{{ $fmt($pl['gross_profit']) }}</td></tr>
                @if($pl['net_revenue'] > 0)
                    <tr class="margin"><td class="code"></td><td>Gross Profit Margin</td><td class="r">{{ number_format($pl['gross_margin_percent'], 1) }}%</td></tr>
                @endif
            @else
                <tr class="total"><td class="code"></td><td>Total Operating Expenses</td><td class="r">{{ $fmt($pl['total_expenses']) }}</td></tr>
                <tr class="net"><td class="code"></td><td>Net {{ $net >= 0 ? 'Profit' : 'Loss' }}</td><td class="r">{{ $fmt($net) }}</td></tr>
                @if($pl['net_revenue'] > 0)
                    <tr class="margin"><td class="code"></td><td>Net Profit Margin</td><td class="r">{{ number_format($pl['net_margin_percent'], 1) }}%</td></tr>
                @endif
            @endif
        @endforeach
        </tbody>
    </table>

    <div class="foot">{{ \App\Support\FinancePdf::preparedLine() }}</div>
</body>
</html>
