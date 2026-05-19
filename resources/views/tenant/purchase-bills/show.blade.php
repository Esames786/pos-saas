@extends('layouts.app')

@section('title', 'Bill: ' . $purchaseBill->bill_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Purchase Bill</h1>
        <p class="fw-medium"><code>{{ $purchaseBill->bill_no }}</code></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/purchase-bills') }}" class="btn btn-light">Back</a>
        @if(in_array($purchaseBill->status, ['posted', 'partial']))
            @can('tenant.supplier-payments.create')
                <a href="{{ url('/supplier-payments/create?purchase_bill_id=' . $purchaseBill->id) }}"
                   class="btn btn-primary">Pay Supplier</a>
            @endcan
        @endif
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Bill Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9">{{ $purchaseBill->supplier?->name }}</dd>

            <dt class="col-sm-3">GRN</dt>
            <dd class="col-sm-9">
                @if($purchaseBill->goodsReceipt)
                    <a href="{{ url('/goods-receipts/' . $purchaseBill->goodsReceipt->id) }}">
                        {{ $purchaseBill->goodsReceipt->grn_no }}
                    </a>
                @else
                    —
                @endif
            </dd>

            @if($purchaseBill->supplier_invoice_no)
                <dt class="col-sm-3">Supplier Invoice</dt>
                <dd class="col-sm-9">{{ $purchaseBill->supplier_invoice_no }}</dd>
            @endif

            <dt class="col-sm-3">Bill Date</dt>
            <dd class="col-sm-9">{{ $purchaseBill->bill_date?->format('Y-m-d') }}</dd>

            <dt class="col-sm-3">Due Date</dt>
            <dd class="col-sm-9">{{ $purchaseBill->due_date?->format('Y-m-d') ?? '—' }}</dd>

            <dt class="col-sm-3">Subtotal</dt>
            <dd class="col-sm-9">{{ number_format($purchaseBill->subtotal, 2) }}</dd>

            @if($purchaseBill->discount_total > 0)
                <dt class="col-sm-3">Discount</dt>
                <dd class="col-sm-9">{{ number_format($purchaseBill->discount_total, 2) }}</dd>
            @endif

            @if($purchaseBill->tax_total > 0)
                <dt class="col-sm-3">Tax</dt>
                <dd class="col-sm-9">{{ number_format($purchaseBill->tax_total, 2) }}</dd>
            @endif

            <dt class="col-sm-3">Grand Total</dt>
            <dd class="col-sm-9"><strong>{{ number_format($purchaseBill->grand_total, 2) }}</strong></dd>

            <dt class="col-sm-3">Amount Paid</dt>
            <dd class="col-sm-9">{{ number_format($purchaseBill->amount_paid, 2) }}</dd>

            <dt class="col-sm-3">Balance Due</dt>
            <dd class="col-sm-9"><strong class="{{ $purchaseBill->balance_due > 0 ? 'text-danger' : 'text-success' }}">
                {{ number_format($purchaseBill->balance_due, 2) }}
            </strong></dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-{{ match($purchaseBill->status) {
                    'paid' => 'success', 'partial' => 'warning', 'draft' => 'secondary', default => 'primary'
                } }} {{ $purchaseBill->status === 'partial' ? 'text-dark' : '' }}">
                    {{ ucfirst($purchaseBill->status) }}
                </span>
            </dd>

            <dt class="col-sm-3">Posted By</dt>
            <dd class="col-sm-9">{{ $purchaseBill->postedBy?->name ?? '—' }}</dd>
        </dl>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Bill Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Purchase bill product lines</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>
                <th scope="col">Qty</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Line Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($purchaseBill->lines as $line)
                <tr>
                    <td>
                        <code>{{ $line->product?->sku }}</code>
                        <small class="d-block">{{ $line->product?->name }}</small>
                    </td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
                    <td>{{ number_format($line->quantity, 3) }}</td>
                    <td>{{ number_format($line->unit_cost, 4) }}</td>
                    <td>{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Payments</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Payments against this bill</caption>
            <thead>
            <tr>
                <th scope="col">Payment No</th>
                <th scope="col">Date</th>
                <th scope="col">Method</th>
                <th scope="col">Amount</th>
                <th scope="col">Paid By</th>
            </tr>
            </thead>
            <tbody>
            @forelse($purchaseBill->payments as $payment)
                <tr>
                    <td><code>{{ $payment->payment_no }}</code></td>
                    <td>{{ $payment->payment_date?->format('Y-m-d') }}</td>
                    <td>{{ str_replace('_', ' ', ucfirst($payment->payment_method)) }}</td>
                    <td>{{ number_format($payment->amount, 2) }}</td>
                    <td>{{ $payment->paidBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-3">No payments recorded.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
