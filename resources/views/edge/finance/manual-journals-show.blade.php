{{-- W4 R8.6 — one MANUAL JOURNAL on the Branch Server (Online tenant/finance/manual-journals/show.blade.php).
     Reverse is NOT offered: offline journal reversal is an owner-dependent item (a new finance event + Cloud ingestion). --}}
@extends('edge.finance.layout')

@section('title', 'Manual Journal')

@section('nav')
    @if($canIndex)
        <a class="navbtn" id="manual-journals-link" href="{{ url('/edge/local/pos/finance/manual-journals') }}">Manual Journals</a>
    @endif
@endsection

@section('content')
@php $pl = $journal['payload'] ?? []; $lines = $pl['lines'] ?? []; $t = $pl['totals'] ?? ['debit' => $journal['amount'], 'credit' => $journal['amount']]; @endphp
<div class="page-head">
    <div>
        <h2>{{ $journal['sync']['official_reference_no'] ?? ('EDGE-' . substr($journal['event_uuid'], -10)) }}
            <span class="badge {{ $journal['sync']['state'] }}" id="manual-journal-sync">{{ $journal['sync']['label'] }}</span></h2>
        <p>Finance — Manual Journal Entry (Accounts Payable)</p>
    </div>
    @if($canIndex)
        <a class="btn" href="{{ url('/edge/local/pos/finance/manual-journals') }}">Back</a>
    @endif
</div>

<div class="note" id="manual-journal-reverse-note">Reversal is not offered on the branch server. Once this journal is posted at the Cloud, it can be reversed from the Online POS.</div>

<div class="card">
    <div class="card-b">
        <dl class="kv" id="manual-journal-header">
            <dt>Entry Date</dt><dd>{{ $pl['entry_date'] ?? $journal['business_date'] }}</dd>
            <dt>Entry No</dt><dd>{{ $journal['sync']['official_reference_no'] ?? ('EDGE-' . substr($journal['event_uuid'], -10)) }}</dd>
            <dt>Reference</dt><dd>{{ $journal['reference_no'] ?: '—' }}</dd>
            <dt>Posted By</dt><dd>{{ $journal['user_name'] ?? '—' }}</dd>
            <dt>Posted At</dt><dd>{{ $journal['created_at'] }}</dd>
            <dt>Description / Memo</dt><dd><strong>{{ $journal['description'] }}</strong></dd>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-h">Journal Lines</div>
    <div class="card-b table-wrap">
        <table id="manual-journal-lines">
            <thead><tr><th>Account</th><th>Branch</th><th>Supplier</th><th>Cash/Bank</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
            <tbody>
            @foreach($lines as $l)
                <tr>
                    <td><code>{{ $l['account_code'] ?? '' }}</code> {{ $l['account_name'] ?? '' }}</td>
                    <td>{{ $branchName }}</td>
                    <td>{{ $l['supplier_name'] ?? '—' }}</td>
                    <td>{{ $l['cash_bank_code'] ?? '—' }}</td>
                    <td class="wrap">{{ $l['description'] ?? '' }}</td>
                    <td class="num">{{ (float) ($l['debit'] ?? 0) > 0 ? number_format((float) $l['debit'], 2) : '' }}</td>
                    <td class="num">{{ (float) ($l['credit'] ?? 0) > 0 ? number_format((float) $l['credit'], 2) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="5" style="text-align:right">Totals</td><td class="num">{{ number_format((float) $t['debit'], 2) }}</td><td class="num">{{ number_format((float) $t['credit'], 2) }}</td></tr></tfoot>
        </table>
    </div>
</div>

@if(! empty($journal['effects']))
    <div class="card">
        <div class="card-h">Provisional effect on this branch server</div>
        <div class="card-b table-wrap">
            <table id="manual-journal-effects">
                <thead><tr><th>Dimension</th><th class="num">Payable change</th><th class="num">Cash/Bank change</th></tr></thead>
                <tbody>
                @foreach($journal['effects'] as $e)
                    <tr>
                        <td>{{ $e['cloud_supplier_id'] ? 'Supplier #' . $e['cloud_supplier_id'] : ($e['cloud_cash_bank_account_id'] ? 'Cash/Bank #' . $e['cloud_cash_bank_account_id'] : '—') }}</td>
                        <td class="num">{{ number_format((float) $e['payable_delta'], 2) }}</td>
                        <td class="num">{{ number_format((float) $e['cash_delta'], 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
