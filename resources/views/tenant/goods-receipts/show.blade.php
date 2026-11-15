@extends('layouts.app')

@section('title', 'Goods Receipt: ' . $goodsReceipt->grn_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Goods Receipt</h1>
        <p class="fw-medium"><code>{{ $goodsReceipt->grn_no }}</code></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Back</a>
        @if(!$goodsReceipt->bill && $goodsReceipt->status === 'posted')
            @can('tenant.purchase-bills.create')
                <a href="{{ url('/purchase-bills/create?goods_receipt_id=' . $goodsReceipt->id) }}"
                   class="btn btn-primary">Record Supplier Invoice</a>
            @endcan
            {{-- GRN-VOID-1: only while no bill exists. Once a bill is out, the supplier has been
                 told what they are owed, and the way back is a Purchase Return. --}}
            @can('tenant.goods-receipts.void')
                <form method="POST" action="{{ url('/goods-receipts/' . $goodsReceipt->id . '/void') }}"
                      onsubmit="return confirm('Void {{ $goodsReceipt->grn_no }}?\n\nThe stock it brought in will be taken back out TODAY. Stock cannot be taken out on an old date without changing what it cost, so a stock void is never back-dated. The receipt stays on record as voided. This cannot be undone.');">
                    @csrf
                    <input type="hidden" name="void_reason" value="Voided from GRN screen">
                    <button type="submit" class="btn btn-outline-danger">Void Receipt</button>
                </form>
            @endcan
        @endif
        {{-- PURCHASE-EDIT-1: correct the receipt (and its invoice) here, or void them together. Greyed with the reason when
             this receipt is one the edit does not correct by itself. --}}
        @if(($editOn ?? false) && $goodsReceipt->status === 'posted' && ! ($editing ?? false))
            @can('tenant.goods-receipts.revise')
                @if($blocked ?? null)
                    <span class="btn btn-outline-secondary disabled" aria-disabled="true" title="{{ $blocked }}" data-grn-edit-blocked>&#9998; Edit</span>
                @else
                    <a href="{{ url('/goods-receipts/' . $goodsReceipt->id) }}?edit=1" class="btn btn-primary" data-grn-edit>&#9998; Edit</a>
                @endif
            @endcan
            @if($goodsReceipt->bill && ! ($blocked ?? null))
                @can('tenant.goods-receipts.void')
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#grn-void-panel"
                            aria-controls="grn-void-panel" data-grn-void>Void</button>
                @endcan
            @endif
        @endif
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(($editOn ?? false) && $goodsReceipt->status === 'posted' && ($blocked ?? null))
    <div class="alert alert-light border small" data-grn-blocked>
        <strong>Edit / Void:</strong> {{ $blocked }}
    </div>
@endif

@if(($editOn ?? false) && $goodsReceipt->bill && $goodsReceipt->status === 'posted' && ! ($blocked ?? null))
    @can('tenant.goods-receipts.void')
        <div class="collapse mb-3 {{ $errors->has('void') || $errors->has('void_reason') || $errors->has('void_date_mode') ? 'show' : '' }}" id="grn-void-panel">
            <form method="POST" action="{{ url('/goods-receipts/' . $goodsReceipt->id . '/void') }}" class="card card-body border-danger" data-grn-void-form>
                @csrf
                <input type="hidden" name="revision_no" value="{{ (int) $goodsReceipt->revision_no }}">
                <h5 class="mb-1">Void {{ $goodsReceipt->grn_no }} and its invoice {{ $goodsReceipt->bill->bill_no }}</h5>
                <p class="small text-muted mb-3">
                    The goods it brought in come back out of stock today. The invoice
                    ({{ number_format((float) $goodsReceipt->bill->grand_total, 2) }}) comes off the supplier's account.
                    Anything already paid on it stays with the supplier as "Not on any invoice". Both stay on record as voided.
                </p>
                <label class="form-label" for="grn-void-reason">Why is it being voided? <span class="text-danger">*</span></label>
                <input type="text" name="void_reason" id="grn-void-reason" class="form-control mb-3" required minlength="3" maxlength="255"
                       value="{{ old('void_reason') }}">
                @include('partials.void-date-choice', ['originalDate' => $goodsReceipt->bill->bill_date, 'idPrefix' => 'grn'])
                <div><button type="submit" class="btn btn-danger">Void receipt and invoice</button></div>
            </form>
        </div>
    @endcan
@endif

@if($editing ?? false)
    @include('tenant.goods-receipts.partials.edit-form')
@else

@if($goodsReceipt->status === 'voided')
    <div class="alert alert-warning">
        <strong>This receipt is voided.</strong>
        The stock it brought in has been taken back out
        @if($goodsReceipt->voided_at) on {{ $goodsReceipt->voided_at->format('d-M-Y H:i') }} @endif.
        @if($goodsReceipt->void_reason) &mdash; {{ $goodsReceipt->void_reason }} @endif
    </div>
@endif

<div class="card mb-3">
    <div class="card-header"><strong>Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->supplier?->name }}</dd>

            <dt class="col-sm-3">Branch</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->branch?->name }}</dd>

            <dt class="col-sm-3">Purchase Order</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->purchaseOrder?->po_no ?? '—' }}</dd>

            <dt class="col-sm-3">Receipt Date</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->receipt_date?->format('Y-m-d') }}</dd>

            <dt class="col-sm-3">Received By</dt>
            <dd class="col-sm-9">{{ $goodsReceipt->receivedBy?->name ?? '—' }}</dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-success">{{ ucfirst($goodsReceipt->status) }}</span>
            </dd>

            <dt class="col-sm-3">Supplier Invoice</dt>
            <dd class="col-sm-9">
                @if($goodsReceipt->bill)
                    <a href="{{ url('/purchase-bills/' . $goodsReceipt->bill->id) }}">
                        {{ $goodsReceipt->bill->bill_no }}
                    </a>
                @else
                    <span class="badge bg-warning text-dark">Pending</span>
                @endif
            </dd>

            {{-- GRN-EXTRA-CHARGES-1: the charge raised what these goods cost, so the receipt has
                 to say so — a cost that moved invisibly is a cost nobody can check. --}}
            @if((float) ($goodsReceipt->extra_charges ?? 0) > 0)
                <dt class="col-sm-3">Extra Charges</dt>
                <dd class="col-sm-9">
                    {{ number_format((float) $goodsReceipt->extra_charges, 2) }}
                    @if($goodsReceipt->extra_charges_note)
                        &mdash; {{ $goodsReceipt->extra_charges_note }}
                    @endif
                    <div class="form-text">Split across the lines below by value and included in their stock cost.</div>
                </dd>
            @endif
            @if($goodsReceipt->notes)
                <dt class="col-sm-3">Notes</dt>
                <dd class="col-sm-9">{{ $goodsReceipt->notes }}</dd>
            @endif
        </dl>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Received Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Goods receipt product lines</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Variant</th>
                <th scope="col">Batch</th>
                <th scope="col">Expiry</th>
                <th scope="col">Qty</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Line Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($goodsReceipt->lines as $line)
                <tr>
                    <td>
                        <code>{{ $line->product?->sku }}</code>
                        <small class="d-block">{{ $line->product?->name }}</small>
                        {{-- GRN-NON-STOCK-1: bought, not stocked — its cost goes to 5100 at the bill. --}}
                        @if($line->affects_stock === false)<span class="badge bg-light text-dark border">Purchase only — no stock</span>@endif
                    </td>
                    <td>{{ $line->variant?->name ?? '—' }}</td>
                    <td>{{ $line->batch_no ?: '—' }}</td>
                    <td>{{ $line->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>
                        {{ number_format($line->quantity_received, 3) }}
                        {{-- PURCHASING-V2-1: what was typed on the new screen ("6 Box @ 892 / Box", "Bonus (free): 1 Box"). --}}
                        @if($line->base_quantity !== null && $line->notes)<small class="d-block text-muted">{{ $line->notes }}</small>@endif
                    </td>
                    <td>{{ number_format($line->unit_cost, 4) }}</td>
                    <td>{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            {{-- GRN-CHARGES-VISIBLE-1: the receipt's own total, charge included, where the eye ends up. --}}
            @php
                $grnGoods = $goodsReceipt->lines->sum('line_total');
                $grnCharges = (float) ($goodsReceipt->extra_charges ?? 0);
            @endphp
            <tfoot class="table-light">
                <tr>
                    <td colspan="6" class="text-end">Goods</td>
                    <td>{{ number_format($grnGoods, 2) }}</td>
                </tr>
                @if($grnCharges > 0)
                <tr>
                    <td colspan="6" class="text-end">
                        Extra Charges
                        @if($goodsReceipt->extra_charges_note)<span class="text-muted">&mdash; {{ $goodsReceipt->extra_charges_note }}</span>@endif
                    </td>
                    <td>{{ number_format($grnCharges, 2) }}</td>
                </tr>
                @endif
                <tr class="fw-bold">
                    <td colspan="6" class="text-end">Total</td>
                    <td id="grn-total">{{ number_format($grnGoods + $grnCharges, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@endif

<div class="card">
    <div class="card-header"><strong>Inventory Ledger Entries</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Stock ledger entries from this goods receipt</caption>
            <thead>
            <tr>
                <th scope="col">Product</th>
                <th scope="col">Branch</th>
                <th scope="col">Direction</th>
                <th scope="col">Qty</th>
                <th scope="col">Unit Cost</th>
                <th scope="col">Balance After</th>
            </tr>
            </thead>
            <tbody>
            @forelse($ledgers as $ledger)
                <tr>
                    <td>{{ $ledger->product?->name }}</td>
                    <td>{{ $ledger->branch?->name }}</td>
                    <td>
                        <span class="badge bg-{{ $ledger->direction === 'in' ? 'success' : 'danger' }}">
                            {{ ucfirst($ledger->direction) }}
                        </span>
                    </td>
                    <td>{{ number_format($ledger->quantity, 3) }}</td>
                    <td>{{ number_format($ledger->unit_cost, 4) }}</td>
                    <td>{{ number_format($ledger->balance_after, 3) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-3">No ledger entries.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- PURCHASE-EDIT-1: every edit and void of this receipt, newest first, in the words it was saved with. --}}
@if(($editOn ?? false) && ($revisions ?? collect())->isNotEmpty())
    <div class="card mt-3" data-grn-history>
        <div class="card-header"><strong>Change history</strong> <span class="text-muted small">(badlav ki history)</span></div>
        <div class="card-body">
            @foreach($revisions as $rev)
                <div class="mb-3">
                    <div class="fw-semibold">
                        {{ $rev->action === 'void' ? 'Voided' : 'Change ' . $rev->revision_no }}
                        &mdash; {{ app(\App\Support\TenantClock::class)->format($rev->created_at, 'd M Y h:i A') }}
                        @if($rev->createdBy) &mdash; {{ $rev->createdBy->name }} @endif
                    </div>
                    @if($rev->action === 'void' && $rev->reason)
                        <div class="small text-muted">Why: {{ $rev->reason }}</div>
                    @endif
                    <ul class="mb-0 small">
                        @foreach((array) $rev->changes as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
