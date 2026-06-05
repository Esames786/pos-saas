@extends('layouts.tenant')
@section('title', 'Supplier Purchases')
@section('content')
<div class="page-wrapper"><div class="content"><div class="page-header"><div class="page-title"><h4>Supplier Purchases</h4></div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Supplier</th><th class="text-end">Bills</th><th class="text-end">Total</th><th class="text-end">Paid</th><th>Last Purchase</th></tr></thead><tbody>@forelse($rows as $r)<tr><td>{{ $r->supplier_name }}</td><td class="text-end">{{ number_format($r->bill_count) }}</td><td class="text-end">{{ number_format($r->total_purchases, 2) }}</td><td class="text-end">{{ number_format($r->total_paid, 2) }}</td><td>{{ $r->last_purchase_date?->format('d/m/Y') }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">No suppliers.</td></tr>@endforelse</tbody></table></div></div></div></div>
@endsection
