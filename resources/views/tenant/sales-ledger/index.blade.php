@extends('layouts.app')

@section('title', 'Sales Ledger')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Sales Ledger</h1>
        <p class="fw-medium">Audit trail of all sales financial entries.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ url('/sales-ledger') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="branch-filter" class="form-label">Branch</label>
                <select id="branch-filter" name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="type-filter" class="form-label">Entry Type</label>
                <select id="type-filter" name="entry_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="sale_total"   @selected(request('entry_type') === 'sale_total')>Sale Total</option>
                    <option value="sale_payment" @selected(request('entry_type') === 'sale_payment')>Payment</option>
                    <option value="sale_discount" @selected(request('entry_type') === 'sale_discount')>Discount</option>
                    <option value="sale_tax"     @selected(request('entry_type') === 'sale_tax')>Tax</option>
                    <option value="sale_return"  @selected(request('entry_type') === 'sale_return')>Return</option>
                    <option value="refund"       @selected(request('entry_type') === 'refund')>Refund</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-dark" type="submit">Filter</button>
                <a href="{{ url('/sales-ledger') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table table-nowrap align-middle">
            <caption class="visually-hidden">Sales ledger entries</caption>
            <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Branch</th>
                <th scope="col">Sale No</th>
                <th scope="col">Entry Type</th>
                <th scope="col">Direction</th>
                <th scope="col">Amount</th>
                <th scope="col">Posted By</th>
            </tr>
            </thead>
            <tbody>
            @forelse($ledgers as $entry)
                <tr>
                    <td>{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $entry->branch?->name }}</td>
                    <td>
                        @if($entry->order)
                            @can('tenant.sales-orders.show')
                                <a href="{{ url('/sales-orders/' . $entry->order->id) }}">
                                    <code>{{ $entry->order->sale_no }}</code>
                                </a>
                            @else
                                <code>{{ $entry->order->sale_no }}</code>
                            @endcan
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>{{ str_replace('_', ' ', ucfirst($entry->entry_type)) }}</td>
                    <td>
                        <span class="badge bg-{{ $entry->direction === 'credit' ? 'success' : 'secondary' }}">
                            {{ ucfirst($entry->direction) }}
                        </span>
                    </td>
                    <td>{{ number_format($entry->amount, 2) }}</td>
                    <td>{{ $entry->createdBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No ledger entries found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $ledgers->links() }}</div>
    </div>
</div>
@endsection
