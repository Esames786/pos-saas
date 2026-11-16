@php
    // FINANCE-PDF-DOWNLOADS-1 — the Trial Balance PDF as the hand-made sample: Debit / Credit pairs for Opening,
    // Transactions and Closing; the six heads of TB-SECTION-HEADS-1 with a total per head; party lines grey under their
    // account; the Grand Total printed from the service's totals (rounded once — never a sum of rounded rows).
    $m = fn ($v) => round((float) $v, 2) != 0.0 ? number_format((float) $v, 2) : '-';
    $from = \Illuminate\Support\Carbon::parse($from);
    $toDate = \Illuminate\Support\Carbon::parse($to);
    $openingLabel = 'Opening (' . $from->copy()->subDay()->format('j M Y') . ')';
    $txnLabel = 'Transactions ' . ($from->isSameMonth($toDate)
        ? $from->format('j') . '–' . $toDate->format('j M')
        : $from->format('j M' . ($from->isSameYear($toDate) ? '' : ' Y')) . ' – ' . $toDate->format('j M Y'));
    $closingLabel = 'Closing Balance (' . $toDate->format('j M Y') . ')';
    $period = \App\Support\FinancePdf::longDate($from->toDateString()) . ' to ' . \App\Support\FinancePdf::longDate($to);
    $cols = ['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'];
    $sectionTotals ??= \App\Http\Controllers\Tenant\Finance\TrialBalanceController::sectionTotals($sections ?? []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Trial Balance {{ $from->toDateString() }} to {{ $to }}</title>
<style>
    @page { margin: 16mm 10mm 18mm 10mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 7pt; color: #1b2430; }
    /* Fixed widths: the account column keeps its room, the six money columns share the rest evenly. */
    #tb-print { table-layout: fixed; }
    .top { text-align: center; margin-bottom: 8px; }
    .company { font-size: 13pt; font-weight: bold; color: #13233a; }
    .title { font-size: 10pt; font-weight: bold; color: #2b3e57; margin-top: 2px; }
    .period { font-size: 7.5pt; color: #5b6b80; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    thead th { font-weight: bold; padding: 3px 4px; }
    thead tr.groups th { text-align: center; font-size: 6.6pt; border-top: 1.5px solid #13233a; border-bottom: 1px solid #8a9ab0; }
    thead tr.cols th { border-bottom: 1.5px solid #13233a; text-align: right; }
    thead tr.cols th.l { text-align: left; }
    td { padding: 2px 4px; vertical-align: top; }
    tr { page-break-inside: avoid; }
    .r { text-align: right; white-space: nowrap; }
    .code { font-family: "DejaVu Sans Mono", monospace; color: #4a5d78; font-weight: bold; white-space: nowrap; }
    .sect th { text-align: left; font-weight: bold; color: #13233a; padding-top: 8px; border-bottom: 1px solid #c9d3df; }
    .head td, .leaf td { border-top: 1px solid #e3e8ef; font-weight: bold; }
    .party td { color: #6b7a90; font-size: 6.5pt; padding-top: 1px; padding-bottom: 1px; }
    .sub td { font-weight: bold; background: #eef2f6; border-top: 1px solid #8a9ab0; }
    .grand td { font-weight: bold; font-size: 7pt; background: #e2e8f0; border-top: 1.5px solid #13233a; border-bottom: 3px double #13233a; }
    .foot { margin-top: 12px; text-align: center; font-size: 7pt; color: #8a9ab0; }
    .foot .bad { color: #c0392b; font-weight: bold; }
    .sign { margin-top: 26px; width: 100%; page-break-inside: avoid; }
    .sign td { border: none; width: 33%; padding-top: 22px; }
    .sign span { display: inline-block; border-top: 1px solid #333; width: 85%; padding-top: 3px; }
</style>
</head>
<body>
    <div class="top">
        <div class="company">{{ $company }}</div>
        <div class="title">Trial Balance{{ ($showParties ?? true) && $parties ? ' — with Party Detail' : '' }}</div>
        <div class="period">For the period {{ $period }} · Amounts in PKR · Branch: {{ $branchLabel }} · Posted entries only</div>
    </div>

    <table id="tb-print">
        <thead>
            <tr class="groups">
                <th style="width:6%"></th><th style="width:26%"></th>
                <th colspan="2">{{ $openingLabel }}</th>
                <th colspan="2">{{ $txnLabel }}</th>
                <th colspan="2">{{ $closingLabel }}</th>
            </tr>
            <tr class="cols">
                <th class="l">Code</th><th class="l">Account / Party</th>
                @for($i = 0; $i < 3; $i++)<th style="width:11.3%">Debit</th><th style="width:11.3%">Credit</th>@endfor
            </tr>
        </thead>
        <tbody>
        @if($grouped ?? false)
        {{-- TB-SECTION-HEADS-1: the same heads, parents and children as the screen; a total per head over its accounts. --}}
        @foreach($sections as $s)
            <tr class="sect"><th colspan="8">{{ $s['label'] }}</th></tr>
            @foreach($s['lines'] as $l)
            <tr class="{{ $l['kind'] === 'head' ? 'head' : 'leaf' }}">
                <td class="code">{{ $l['code'] }}</td>
                <td style="padding-left: {{ 4 + $l['depth'] * 10 }}px">{{ $l['name'] }}</td>
                @foreach($cols as $c)<td class="r">{{ $m($l[$c]) }}</td>@endforeach
            </tr>
            @if($l['kind'] !== 'head')
            @foreach($parties[$l['account_id']] ?? [] as $p)
            <tr class="party">
                <td></td>
                <td style="padding-left: {{ 14 + $l['depth'] * 10 }}px">{{ $p['label'] }}</td>
                @foreach($cols as $c)<td class="r">{{ $m($p[$c]) }}</td>@endforeach
            </tr>
            @endforeach
            @endif
            @endforeach
            <tr class="sub">
                <td></td>
                <td>Total {{ $s['label'] }}</td>
                @foreach($cols as $c)<td class="r">{{ $m($sectionTotals[$s['key']][$c] ?? 0) }}</td>@endforeach
            </tr>
        @endforeach
        @else
        @foreach($rows as $r)
            <tr class="leaf">
                <td class="code">{{ $r['code'] }}</td>
                <td>{{ $r['name'] }}</td>
                @foreach($cols as $c)<td class="r">{{ $m($r[$c]) }}</td>@endforeach
            </tr>
            @foreach($parties[$r['account_id']] ?? [] as $p)
            <tr class="party">
                <td></td>
                <td style="padding-left: 14px">{{ $p['label'] }}</td>
                @foreach($cols as $c)<td class="r">{{ $m($p[$c]) }}</td>@endforeach
            </tr>
            @endforeach
        @endforeach
        @endif
            <tr class="grand" id="tb-print-grand">
                <td></td>
                <td>Grand Total</td>
                @foreach($cols as $c)<td class="r">{{ number_format((float) $totals[$c], 2) }}</td>@endforeach
            </tr>
        </tbody>
    </table>

    <div class="foot">
        {{ \App\Support\FinancePdf::preparedLine() }} · Trial Balance difference:
        <span class="{{ round((float) $difference, 2) != 0.0 ? 'bad' : '' }}">{{ number_format((float) $difference, 2) }}</span>
    </div>

    <table class="sign">
        <tr>
            <td><span>Prepared by</span></td>
            <td><span>Verified by</span></td>
            <td><span>Date</span></td>
        </tr>
    </table>
</body>
</html>
