{{-- W4 R9.4 — one PURCHASE RETURN on the Branch Server (Online tenant/purchase-returns/show.blade.php). No Edit / Cancel
     Draft here: offline purchase-return drafts are an owner-dependent item (R9.2); a branch-server return is posted at once. --}}
@extends('edge.finance.layout')

@section('title', 'Purchase Return')

@section('nav')
    @if($canIndex)
        <a class="navbtn" id="purchase-return-list-link" href="{{ url('/edge/local/pos/purchase-return-list') }}">Purchase Returns</a>
    @endif
@endsection

@section('content')
@php $pl = $return['payload'] ?? []; @endphp
<div class="page-head">
    <div>
        <h2>{{ $return['sync']['official_reference_no'] ?? ('EDGE-' . substr($return['event_uuid'], -10)) }}
            <span class="badge {{ $return['sync']['state'] }}" id="purchase-return-sync">{{ $return['sync']['label'] }}</span></h2>
        <p>Purchase Return — {{ $pl['supplier_name'] ?? ('Supplier #' . $return['cloud_supplier_id']) }}</p>
    </div>
    @if($canIndex)
        <a class="btn" href="{{ url('/edge/local/pos/purchase-return-list') }}">Back</a>
    @endif
</div>

<div class="card">
    <div class="card-b">
        <dl class="kv" id="purchase-return-details">
            <dt>Branch</dt><dd>{{ $branchName }}</dd>
            <dt>Supplier</dt><dd>{{ $pl['supplier_name'] ?? '—' }} @if(! empty($pl['supplier_code']))<code>{{ $pl['supplier_code'] }}</code>@endif</dd>
            <dt>Source GRN</dt><dd>{{ $pl['grn_no'] ?? ('#' . $return['cloud_grn_id']) }}</dd>
            @if(! empty($pl['bill_no']))<dt>Purchase Bill</dt><dd>{{ $pl['bill_no'] }}</dd>@endif
            <dt>Return Date</dt><dd>{{ $return['return_date'] }}</dd>
            <dt>Reason</dt><dd>{{ $return['reason_code'] ? ucwords(str_replace('_', ' ', $return['reason_code'])) : '—' }}</dd>
            <dt>Notes</dt><dd>{{ $return['notes'] ?? '—' }}</dd>
            <dt>Posted By</dt><dd>{{ $return['user_name'] ?? '—' }}</dd>
            <dt>Posted At</dt><dd>{{ $return['created_at'] }}</dd>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-h">Lines</div>
    <div class="card-b table-wrap">
        <table id="purchase-return-lines">
            <thead><tr><th>Product</th><th>Source GRN Line</th><th class="num">Qty</th><th class="num">Unit Cost</th><th class="num">Line Total</th><th>Reason</th></tr></thead>
            <tbody>
            @foreach($return['lines'] as $line)
                @php $p = $products[$line['product_id']] ?? null; @endphp
                <tr>
                    <td>{{ $p && $p->sku ? $p->sku . ' — ' : '' }}{{ $p?->name ?? ('#' . $line['product_id']) }}</td>
                    <td>#{{ $line['cloud_grn_line_id'] }}</td>
                    <td class="num">{{ number_format((float) $line['quantity'], 3) }}</td>
                    <td class="num">{{ number_format((float) $line['unit_cost'], 4) }}</td>
                    <td class="num">{{ number_format((float) $line['line_total'], 2) }}</td>
                    <td>{{ $line['reason_code'] ? ucwords(str_replace('_', ' ', $line['reason_code'])) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="4" style="text-align:right">Grand Total</td><td class="num">{{ number_format((float) $return['grand_total'], 2) }}</td><td></td></tr></tfoot>
        </table>
    </div>
</div>
@endsection
