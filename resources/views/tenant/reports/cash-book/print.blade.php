@php
    // CASH-BOOK-REPORT-1 — print copy of exactly what the filters show (all entries, not one page).
    $money = fn ($v) => number_format((float) $v, 2);
    $tenantName = app()->bound('tenant') ? app('tenant')->business_name : config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Cash Book {{ $filters['date_from'] }} to {{ $filters['date_to'] }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111; margin: 16px; }
        h1 { font-size: 16px; margin: 0; }
        .sub { color: #555; margin: 2px 0 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #bbb; padding: 3px 5px; vertical-align: top; }
        th { background: #f0f0f0; text-align: left; }
        .r { text-align: right; white-space: nowrap; }
        h2 { font-size: 13px; margin: 14px 0 4px; }
        tfoot td { font-weight: bold; background: #f7f7f7; }
        @media print { body { margin: 0; } h2 { break-after: avoid; } tr { break-inside: avoid; } }
    </style>
</head>
<body onload="window.print()">
    <h1>{{ $tenantName }} — Cash Book</h1>
    <div class="sub">{{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('d M Y') }} · printed {{ now()->format('d M Y H:i') }}</div>

    <table>
        <thead><tr><th>Cash / Bank</th><th class="r">Opening</th><th class="r">In</th><th class="r">Out</th><th class="r">Closing</th></tr></thead>
        <tbody>
            @foreach($summary as $s)
                <tr><td>{{ $s['account']->name }}</td><td class="r">{{ $money($s['opening']) }}</td><td class="r">{{ $money($s['in']) }}</td><td class="r">{{ $money($s['out']) }}</td><td class="r">{{ $money($s['closing']) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td>Total</td><td class="r">{{ $money($totals['opening']) }}</td><td class="r">{{ $money($totals['in']) }}</td><td class="r">{{ $money($totals['out']) }}</td><td class="r">{{ $money($totals['closing']) }}</td></tr></tfoot>
    </table>

    @if($mode === 'daily')
        @foreach($summary as $id => $s)
            @continue(empty($daily[$id]))
            <h2>{{ $s['account']->name }}</h2>
            <table>
                <thead><tr><th>Date</th><th class="r">Opening</th><th class="r">In</th><th class="r">Out</th><th class="r">Closing</th></tr></thead>
                <tbody>
                    @foreach($daily[$id] as $d)
                        <tr><td>{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d M Y') }}</td><td class="r">{{ $money($d['opening']) }}</td><td class="r">{{ $d['in'] ? $money($d['in']) : '' }}</td><td class="r">{{ $d['out'] ? $money($d['out']) : '' }}</td><td class="r">{{ $money($d['closing']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @else
        @foreach(collect($entries)->groupBy('cash_bank_account_id') as $id => $rows)
            <h2>{{ $summary[$id]['account']->name }} — opening {{ $money($summary[$id]['opening']) }}</h2>
            <table>
                <thead><tr><th>Date</th><th>Type</th><th>Ref</th><th>From / to</th><th class="r">In</th><th class="r">Out</th><th class="r">Balance</th></tr></thead>
                <tbody>
                    @foreach($rows as $e)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($e->transaction_date)->format('d M Y') }}</td>
                            <td>{{ $e->type_label }}</td><td>{{ $e->ref }}</td><td>{{ $e->party }}</td>
                            <td class="r">{{ $e->direction === 'in' ? $money($e->amount) : '' }}</td>
                            <td class="r">{{ $e->direction === 'out' ? $money($e->amount) : '' }}</td>
                            <td class="r">{{ $money($e->balance) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="4">Period total</td><td class="r">{{ $money($summary[$id]['in']) }}</td><td class="r">{{ $money($summary[$id]['out']) }}</td><td class="r">{{ $money($summary[$id]['closing']) }}</td></tr></tfoot>
            </table>
        @endforeach
    @endif
</body>
</html>
