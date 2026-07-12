@extends('layouts.app')

@section('title', 'Purchase Return ' . $return->return_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">
            {{ $return->return_no }}
            @if($return->isPosted())
                <span class="badge bg-success align-middle">Posted</span>
            @elseif($return->isCancelled())
                <span class="badge bg-secondary align-middle">Cancelled</span>
            @else
                <span class="badge bg-warning text-dark align-middle">Draft</span>
            @endif
        </h1>
        <p class="fw-medium text-muted mb-0">
            {{ $return->supplier?->name }} · {{ $return->branch?->name }} · {{ $return->return_date?->format('Y-m-d') }}
            @if($return->goodsReceipt) · Source: <a href="{{ url('/goods-receipts/' . $return->goods_receipt_id) }}">{{ $return->goodsReceipt->grn_no }}</a> @endif
        </p>
    </div>
    <div class="d-flex gap-2">
        @if($return->isDraft())
            @can('tenant.purchase-returns.edit')
                <a href="{{ url('/purchase-returns/' . $return->id . '/edit') }}" class="btn btn-primary">Edit Draft</a>
            @endcan
            @can('tenant.purchase-returns.post')
                <form method="POST" action="{{ url('/purchase-returns/' . $return->id . '/post') }}" class="d-inline" id="pret-post-form">
                    @csrf
                    <button class="btn btn-success" type="submit"><i class="ti ti-check me-1"></i>Post Return</button>
                </form>
            @endcan
            @can('tenant.purchase-returns.cancel')
                <form method="POST" action="{{ url('/purchase-returns/' . $return->id . '/cancel') }}" class="d-inline"
                      onsubmit="return confirm('Cancel this draft return?')">
                    @csrf
                    <button class="btn btn-outline-danger">Cancel Draft</button>
                </form>
            @endcan
        @endif
        <a href="{{ url('/purchase-returns') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-body row g-3 small">
                <div class="col-md-3"><div class="text-muted">Supplier</div><div class="fw-semibold">{{ $return->supplier?->name }}</div></div>
                <div class="col-md-3"><div class="text-muted">Branch</div><div class="fw-semibold">{{ $return->branch?->name }}</div></div>
                <div class="col-md-3"><div class="text-muted">Reason</div><div class="fw-semibold">{{ $return->reason_code ? ucwords(str_replace('_', ' ', $return->reason_code)) : '—' }}</div></div>
                <div class="col-md-3"><div class="text-muted">Created By</div><div class="fw-semibold">{{ $return->createdBy?->name ?? '—' }}</div></div>
                @if($return->isPosted())
                    <div class="col-md-3"><div class="text-muted">Posted By</div><div class="fw-semibold">{{ $return->postedBy?->name }}</div></div>
                    <div class="col-md-3"><div class="text-muted">Posted At</div><div class="fw-semibold">{{ $return->posted_at?->format('Y-m-d H:i') }}</div></div>
                    <div class="col-md-3"><div class="text-muted">GL Journal</div><div class="fw-semibold">{{ $return->journalEntry?->entry_no ?? ($return->journal_entry_id ? '#' . $return->journal_entry_id : '—') }}</div></div>
                @endif
                @if($return->notes)
                    <div class="col-12"><div class="text-muted">Notes</div><div>{{ $return->notes }}</div></div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-warning-subtle">
            <div class="card-body small">
                <span class="badge bg-warning-subtle text-warning-emphasis mb-2"><i class="ti ti-truck-return me-1"></i>Supplier Return</span>
                @if($return->isPosted())
                    <div>Stock was <strong>reduced</strong> (movement <code>purchase_return</code>, FEFO) and the supplier payable was <strong>decreased</strong> (Dr AP / Cr Inventory). Posted returns are immutable.</div>
                @else
                    <div>Draft — <strong>no stock or finance impact yet</strong>. Posting reduces official branch stock and the supplier payable; a fully paid supplier goes into credit.</div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Product</th><th>Variant</th><th>Source GRN Line</th>
                    <th class="text-end">Qty</th><th class="text-end">Unit Cost</th>
                    <th class="text-end">Discount</th><th class="text-end">Tax</th><th class="text-end">Line Total</th>
                    <th>Reason</th><th>Notes</th>
                </tr>
            </thead>
            <tbody>
            @foreach($return->lines as $line)
                <tr>
                    <td>{{ $line->product?->sku ? $line->product->sku . ' — ' : '' }}{{ $line->product?->name }}</td>
                    <td>{{ $line->variant?->name ?? 'Default' }}</td>
                    <td class="small">{{ $line->source_line_id ? '#' . $line->source_line_id . ($line->sourceGrnLine?->batch_no ? ' · ' . $line->sourceGrnLine->batch_no : '') : 'standalone' }}</td>
                    <td class="text-end fw-semibold">{{ number_format($line->quantity, 3) }} {{ $line->product?->unit?->code }}</td>
                    <td class="text-end">{{ number_format($line->unit_cost, 4) }}</td>
                    <td class="text-end">{{ number_format($line->discount_amount, 2) }}</td>
                    <td class="text-end">{{ number_format($line->tax_amount, 2) }}</td>
                    <td class="text-end">{{ number_format($line->line_total, 2) }}</td>
                    <td>
                        @if($line->reason_code)
                            <span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $line->reason_code)) }}</span>
                        @else — @endif
                    </td>
                    <td class="small text-muted">{{ $line->notes ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="7" class="text-end">Subtotal / Discount / Tax</td>
                    <td class="text-end">{{ number_format($return->subtotal, 2) }} / {{ number_format($return->discount_total, 2) }} / {{ number_format($return->tax_total, 2) }}</td>
                    <td colspan="2"></td>
                </tr>
                <tr>
                    <td colspan="7" class="text-end">Grand Total</td>
                    <td class="text-end fs-6">{{ number_format($return->grand_total, 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var form = document.getElementById('pret-post-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        if (this.dataset.confirmed) return;
        e.preventDefault();
        var f = this;
        Swal.fire({
            title: 'Post this purchase return?',
            html: 'This will <strong>reduce official branch stock</strong> and <strong>reduce the supplier payable</strong> by '
                + @json(number_format($return->grand_total, 2)) + '.<br>It cannot be edited after posting.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Post Return',
        }).then(function (res) { if (res.isConfirmed) { f.dataset.confirmed = '1'; f.submit(); } });
    });
})();
</script>
@endpush
@endsection
