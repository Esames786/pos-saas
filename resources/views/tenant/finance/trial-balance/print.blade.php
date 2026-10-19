@php
    $sided = [\App\Http\Controllers\Tenant\Finance\TrialBalanceController::class, 'sided'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Trial Balance {{ $from }} to {{ $to }}</title>
<style>
    @page { margin: 18mm 12mm 18mm 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
    h1 { font-size: 13pt; margin: 0; }
    .company { font-size: 10pt; font-weight: bold; }
    .meta { font-size: 8pt; color: #444; margin: 2px 0 8px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th { background: #eee; font-weight: bold; text-align: left; padding: 3px 4px; border-bottom: 1px solid #999; }
    td { padding: 2px 4px; border-bottom: 1px solid #e3e3e3; vertical-align: top; }
    tr { page-break-inside: avoid; }
    .r { text-align: right; white-space: nowrap; }
    .acct td { font-weight: bold; }
    .party td { color: #444; font-size: 8pt; }
    .party .name { padding-left: 14px; }
    .totals { margin-top: 10px; page-break-inside: avoid; }
    .totals td { border: 1px solid #bbb; padding: 4px; }
    .totals .label { background: #f3f3f3; font-weight: bold; }
    .sign { margin-top: 30px; width: 100%; page-break-inside: avoid; }
    .sign td { border: none; width: 33%; padding-top: 26px; }
    .sign span { display: inline-block; border-top: 1px solid #333; width: 85%; padding-top: 3px; }
</style>
</head>
<body>
    <div class="company">{{ $company }}</div>
    <h1>Trial Balance</h1>
    <div class="meta">
        Period {{ $from }} to {{ $to }} &middot; Branch: {{ $branchLabel }} &middot; Posted entries only
        &middot; Printed {{ now()->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:9%">Code</th>
                <th style="width:31%">Account</th>
                <th style="width:9%">Type</th>
                <th class="r" style="width:13%">Opening</th>
                <th class="r" style="width:12%">Debit</th>
                <th class="r" style="width:12%">Credit</th>
                <th class="r" style="width:14%">Balance</th>
            </tr>
        </thead>
        <tbody>
        @foreach($rows as $r)
            <tr class="acct">
                <td>{{ $r['code'] }}</td>
                <td>{{ $r['name'] }}</td>
                <td>{{ ucfirst($r['type']) }}</td>
                <td class="r">{{ $sided($r['opening_debit'], $r['opening_credit']) }}</td>
                <td class="r">{{ $r['period_debit'] > 0 ? number_format($r['period_debit'], 2) : '' }}</td>
                <td class="r">{{ $r['period_credit'] > 0 ? number_format($r['period_credit'], 2) : '' }}</td>
                <td class="r">{{ $sided($r['closing_debit'], $r['closing_credit']) }}</td>
            </tr>
            @foreach($parties[$r['account_id']] ?? [] as $p)
            <tr class="party">
                <td></td>
                <td class="name">{{ $p['label'] }}</td>
                <td></td>
                <td class="r">{{ $sided($p['opening_debit'], $p['opening_credit']) }}</td>
                <td class="r">{{ $p['period_debit'] > 0 ? number_format($p['period_debit'], 2) : '' }}</td>
                <td class="r">{{ $p['period_credit'] > 0 ? number_format($p['period_credit'], 2) : '' }}</td>
                <td class="r">{{ $sided($p['closing_debit'], $p['closing_credit']) }}</td>
            </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>

    {{-- Kept on one page: a totals block split across two pages is checked by nobody. --}}
    <table class="totals" id="tb-print-totals">
        <tr>
            <td class="label"></td>
            <td class="label r">Debit</td>
            <td class="label r">Credit</td>
        </tr>
        <tr>
            <td class="label">Opening</td>
            <td class="r">{{ number_format($totals['opening_debit'], 2) }}</td>
            <td class="r">{{ number_format($totals['opening_credit'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Movement in the period</td>
            <td class="r">{{ number_format($totals['period_debit'], 2) }}</td>
            <td class="r">{{ number_format($totals['period_credit'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Closing</td>
            <td class="r">{{ number_format($totals['closing_debit'], 2) }}</td>
            <td class="r">{{ number_format($totals['closing_credit'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Difference (closing)</td>
            <td class="r" colspan="2">{{ number_format($difference, 2) }} {{ $difference == 0 ? '— balanced' : '— OUT OF BALANCE' }}</td>
        </tr>
    </table>

    <table class="sign">
        <tr>
            <td><span>Prepared by</span></td>
            <td><span>Verified by</span></td>
            <td><span>Date</span></td>
        </tr>
    </table>
</body>
</html>
