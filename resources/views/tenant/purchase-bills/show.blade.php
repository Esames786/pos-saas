@extends('layouts.app')

@section('title', 'Supplier Invoice: ' . $purchaseBill->bill_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Supplier Invoice</h1>
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
    <div class="card-header"><strong>Invoice Details</strong></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Supplier</dt>
            <dd class="col-sm-9">{{ $purchaseBill->supplier?->name }}</dd>

            <dt class="col-sm-3">Goods Receipt</dt>
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

            <dt class="col-sm-3">Invoice Date</dt>
            <dd class="col-sm-9">{{ $purchaseBill->bill_date?->format('Y-m-d') }}</dd>

            <dt class="col-sm-3">Due Date</dt>
            <dd class="col-sm-9">{{ $purchaseBill->due_date?->format('Y-m-d') ?? '—' }}</dd>

            <dt class="col-sm-3">Subtotal</dt>
            <dd class="col-sm-9">{{ number_format($purchaseBill->subtotal, 2) }}</dd>
            {{-- GRN-BILL-CHARGES-1: the receipt's cartage is part of this payable, so it is
                 named here rather than buried inside the subtotal. --}}
            @if((float) ($purchaseBill->extra_charges ?? 0) > 0)
                <dt class="col-sm-3">Extra Charges</dt>
                <dd class="col-sm-9">
                    {{ number_format((float) $purchaseBill->extra_charges, 2) }}
                    @if($purchaseBill->goodsReceipt?->extra_charges_note)
                        &mdash; {{ $purchaseBill->goodsReceipt->extra_charges_note }}
                    @endif
                </dd>
            @endif

            @if($purchaseBill->discount_total > 0)
                <dt class="col-sm-3">Discount</dt>
                <dd class="col-sm-9">{{ number_format($purchaseBill->discount_total, 2) }}</dd>
            @endif

            @if($purchaseBill->tax_total > 0)
                <dt class="col-sm-3">Tax</dt>
                <dd class="col-sm-9">{{ number_format($purchaseBill->tax_total, 2) }}</dd>
            @endif

            {{-- SUPPLIER-NET-BILL-1: returns this invoice already takes off (each credited once, here) --}}
            @foreach($purchaseBill->nettings as $netting)
                <dt class="col-sm-3">Less return</dt>
                <dd class="col-sm-9">− {{ number_format((float) $netting->amount, 2) }}
                    <span class="text-muted small">{{ $netting->purchaseReturn?->return_no }} — already taken off by the supplier</span></dd>
            @endforeach
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
    <div class="card-header"><strong>Invoice Lines</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Supplier invoice product lines</caption>
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

@if($runningAccount ?? false)
{{-- SUPPLIER-CREDIT-CLARITY-1 (owner, 26 Oct): on a running account one payment can pay several invoices and one invoice
     can be paid by several payments, so the page says exactly which — and how much of each is on THIS invoice. --}}
<div class="card" id="paid-by">
    <div class="card-header"><strong>How this invoice was paid</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Payments and returns that paid this invoice</caption>
            <thead>
            <tr>
                <th scope="col">Paid by</th>
                <th scope="col">Date</th>
                <th scope="col" class="text-end">Amount on this invoice</th>
            </tr>
            </thead>
            <tbody>
            @forelse($paidBy as $p)
                <tr>
                    <td><a href="{{ url(($p['type'] === 'payment' ? '/supplier-payments/' : '/purchase-returns/') . $p['id']) }}">{{ $p['label'] }}</a></td>
                    <td>{{ $p['date'] }}</td>
                    <td class="text-end">{{ number_format($p['amount'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center text-muted py-3">Nothing has paid this invoice yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($moves->isNotEmpty())
        <div class="card-body border-top small" id="credit-moves">
            <strong>Moved between invoices</strong>
            <ul class="mb-0 ps-3">
                @foreach($moves as $m)
                    <li>{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('Y-m-d H:i') }} —
                        {{ number_format((float) $m->amount, 2) }} of
                        {{ $m->source_type === 'payment' ? 'Payment ' . ($sourceNos['payment'][$m->source_id] ?? '#' . $m->source_id) : 'Return ' . ($sourceNos['return'][$m->source_id] ?? '#' . $m->source_id) }}
                        moved from {{ $billNos[$m->from_purchase_bill_id] ?? '#' . $m->from_purchase_bill_id }}
                        to {{ $billNos[$m->to_purchase_bill_id] ?? '#' . $m->to_purchase_bill_id }}
                        by {{ $movers[$m->moved_by] ?? '—' }}: {{ $m->reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($moveTo->isNotEmpty())
        <div class="card-body border-top" id="move-credit">
            <strong class="d-block mb-1">Move to another invoice</strong>
            <p class="small text-muted mb-2">Was a payment put on the wrong invoice? Move its part from this invoice to another open
                invoice of {{ $purchaseBill->supplier?->name }}. This invoice then owes that much again and the other one that much less.
                The supplier's balance, cash / bank and the accounts do not change.</p>
            @foreach(['move', 'source', 'to_bill_id', 'amount', 'reason'] as $field)
                @error($field)<div class="alert alert-danger py-2 mb-2">{{ $message }}</div>@enderror
            @endforeach
            <form method="POST" action="{{ url('/purchase-bills/' . $purchaseBill->id . '/move-credit') }}" class="row g-2 align-items-end" id="move-credit-form">
                @csrf
                <div class="col-md-6">
                    <label for="mv-source" class="form-label">Move from</label>
                    <select name="source" id="mv-source" class="form-select" required>
                        <option value="">— choose —</option>
                        @foreach($paidBy as $p)
                            <option value="{{ $p['type'] }}:{{ $p['id'] }}" data-amount="{{ $p['amount'] }}" data-label="{{ $p['label'] }}"
                                @selected(old('source') === $p['type'] . ':' . $p['id'])>{{ $p['label'] }} ({{ number_format($p['amount'], 2) }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="mv-to" class="form-label">To invoice</label>
                    <select name="to_bill_id" id="mv-to" class="form-select" required>
                        <option value="">— choose —</option>
                        @foreach($moveTo as $b)
                            <option value="{{ $b->id }}" data-balance="{{ (float) $b->balance_due }}" data-no="{{ $b->bill_no }}"
                                @selected((int) old('to_bill_id') === $b->id)>{{ $b->bill_no }} — {{ $b->bill_date?->format('Y-m-d') }} — owes {{ number_format((float) $b->balance_due, 2) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="mv-amount" class="form-label">Amount</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="mv-amount" class="form-control" required value="{{ old('amount') }}">
                </div>
                <div class="col-md-7">
                    <label for="mv-reason" class="form-label">Why?</label>
                    <input type="text" name="reason" id="mv-reason" class="form-control" required minlength="3" maxlength="255"
                        value="{{ old('reason') }}" placeholder="e.g. this payment was for the other invoice">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-warning w-100" id="mv-submit">Move</button>
                </div>
            </form>
        </div>
        @include('tenant.catering.partials.submit-guard')
        @push('scripts')
        <script>
        (function () {
            var src = document.getElementById('mv-source'), to = document.getElementById('mv-to'), amt = document.getElementById('mv-amount');
            var BILL = @json($purchaseBill->bill_no);
            function pick() {
                var s = src.selectedOptions[0], t = to.selectedOptions[0];
                if (!s || !s.value || !t || !t.value || amt.dataset.touched) return;
                amt.value = Math.min(parseFloat(s.dataset.amount), parseFloat(t.dataset.balance)).toFixed(2);
            }
            src.addEventListener('change', pick);
            to.addEventListener('change', pick);
            amt.addEventListener('input', function () { amt.dataset.touched = '1'; });
            document.getElementById('mv-submit').addEventListener('click', function (e) {
                var s = src.selectedOptions[0], t = to.selectedOptions[0];
                if (!s || !s.value || !t || !t.value || !amt.value) return;   // the browser asks for what is missing
                var n = parseFloat(amt.value).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                if (!confirm('Move Rs ' + n + ' of ' + s.dataset.label + ' from ' + BILL + ' to ' + t.dataset.no + '?\n\n'
                    + BILL + ' will owe Rs ' + n + ' more and ' + t.dataset.no + ' Rs ' + n + ' less. The supplier\'s balance does not change.')) {
                    e.preventDefault();
                }
            });
        })();
        </script>
        @endpush
    @endif
</div>
@else
<div class="card">
    <div class="card-header"><strong>Payments</strong></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-nowrap align-middle mb-0">
            <caption class="visually-hidden">Payments against this invoice</caption>
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
@endif
@endsection
