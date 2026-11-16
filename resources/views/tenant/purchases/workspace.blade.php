@extends('layouts.app')

@section('title', 'New purchase')

{{--
    W3/W4 S4 — the purchase workspace (App\Http\Controllers\Tenant\PurchaseWorkspaceController). One screen and one Post
    for a purchase: goods arriving now (a Goods Receipt; its Supplier Invoice now or later; a Payment if chosen), or goods
    already received (their Supplier Invoice; a Payment if chosen). The server checks every step and posts the chosen ones
    together, or none. The entry area is the Goods Receipt screen's own.
--}}
@php
    $canReceipt = $allowed['receipt'] ?? false;
    $canInvoice = $allowed['invoice'] ?? false;
    $canPay = ($allowed['payment'] ?? false) && $canInvoice;
    $start = old('start', $canReceipt ? $start : 'received');
    $pick = old('goods_receipt_id', $pickedReceipt);
@endphp

@push('styles')
<style>
    .pw { --navy:#1B2A4A; --gold:#B8902E; --line:#E3E8EE; --ink2:#4E5B6B; --ok:#1E7A50; --bad:#B42318; }
    .pw .pw-card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px 18px; margin-bottom:14px; }
    .pw .pw-card > h2 { font-size:1.05rem; font-weight:800; color:var(--navy); margin:0 0 10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .pw .pw-step { display:inline-flex; width:26px; height:26px; border-radius:50%; background:var(--navy); color:#fff; font-size:.85rem; align-items:center; justify-content:center; }
    .pw .pw-help { color:var(--ink2); font-size:.85rem; }
    .pw .pw-choice { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:10px; }
    .pw .pw-opt { position:relative; display:block; border:2px solid var(--line); border-radius:12px; padding:12px 14px; cursor:pointer; margin:0; }
    .pw .pw-opt input { position:absolute; opacity:0; inset:0; cursor:pointer; }
    .pw .pw-opt b { display:block; color:#212B36; }
    .pw .pw-opt span { color:var(--ink2); font-size:.85rem; }
    .pw .pw-opt:has(input:checked) { border-color:var(--gold); background:#FFF8E6; }
    .pw .pw-opt:has(input:focus-visible) { outline:3px solid var(--navy); outline-offset:2px; }
    .pw .pw-opt:has(input:disabled) { opacity:.55; cursor:not-allowed; }
    .pw .yn { display:inline-flex; border:1px solid #CBD3DE; border-radius:10px; overflow:hidden; }
    .pw .yn label { margin:0; padding:8px 16px; min-width:84px; text-align:center; cursor:pointer; font-weight:700; background:#fff; }
    .pw .yn input { position:absolute; opacity:0; width:1px; height:1px; }
    .pw .yn input:checked + label { background:var(--navy); color:#fff; }
    .pw .yn input:focus-visible + label { outline:3px solid var(--gold); outline-offset:-3px; }
    .pw .pw-err { color:var(--bad); font-weight:700; font-size:.9rem; }
    .pw .pw-sum { display:grid; grid-template-columns:1fr auto; gap:4px 16px; max-width:420px; font-variant-numeric:tabular-nums; }
    .pw .pw-sum dt { font-weight:600; color:var(--ink2); }
    .pw .pw-sum dd { margin:0; text-align:right; font-weight:700; }
    .pw .pw-post { position:sticky; bottom:0; z-index:20; background:#fff; border:1px solid var(--line); border-radius:14px; padding:10px 14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; box-shadow:0 -4px 16px rgba(9,44,76,.08); }
    .pw [hidden] { display:none !important; }
</style>
@endpush

@section('content')
<div class="pw">
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">New purchase</h1>
        <p class="fw-medium text-muted mb-0">The goods, the supplier's invoice and a payment — recorded together with one Post.</p>
    </div>
    <a href="{{ url('/goods-receipts') }}" class="btn btn-light">Goods Receipts</a>
</div>

@include('tenant.purchasing.partials.how-to-use', ['guide' => [
    'what' => 'Record a purchase in one go: the goods that arrived, the supplier\'s invoice for them, and a payment — or only the parts you have today.',
    'steps' => ['Choose "Goods arriving now", or "Goods already received" for a goods receipt that waits for its invoice.', 'Add each item as on the Goods Receipt screen.', 'Record the supplier\'s invoice now, or leave it for later.', 'Pay now if you are paying, then Post. Check the summary, and press Post again to record it.'],
    'effects' => ['Stock' => 'Goes up by what arrived (goods arriving now only — goods already received are never received again).', 'Supplier balance' => 'Goes up by the invoice, and down by the payment.', 'Cash / bank' => 'Goes down by the payment, from the account you choose.'],
    'example' => '10 cartons arrive at Rs 500 with Rs 200 cartage; the invoice says Rs 5,200; you pay Rs 3,000 in cash. One Post: stock +10 cartons, the supplier is owed Rs 2,200, cash −3,000.',
    'blocked' => ['Paying more than this invoice owes (after Supplier Credit) — an advance is recorded under Payments.', 'A part your role does not include: nothing is saved, and the screen says which part.', 'An invoice for a goods receipt that already has one, or one that was voided.'],
]])

<form method="POST" action="{{ url('/purchases') }}" id="pw-form" novalidate>
    @csrf
    <input type="hidden" name="_entry_v2" value="1">
    @if($purchaseOrder)<input type="hidden" name="purchase_order_id" value="{{ $purchaseOrder->id }}">@endif
    <div class="alert alert-danger" role="alert" id="pw-errors" tabindex="-1" hidden></div>

    {{-- 1. what arrives --}}
    <section class="pw-card" aria-labelledby="pw-h-start">
        <h2 id="pw-h-start"><span class="pw-step">1</span> What are you recording?</h2>
        <div class="pw-choice" role="radiogroup" aria-labelledby="pw-h-start">
            <label class="pw-opt">
                <input type="radio" name="start" value="new" @checked($start === 'new') @disabled(! $canReceipt)>
                <b>Goods arriving now</b>
                <span>Receive them into stock. Record the supplier's invoice now or later.</span>
                @unless($canReceipt)<span class="d-block pw-err">Receiving goods is not part of your role.</span>@endunless
            </label>
            <label class="pw-opt">
                <input type="radio" name="start" value="received" @checked($start === 'received') @disabled(! $canInvoice)>
                <b>Goods already received</b>
                <span>Their goods receipt is posted and waits for its invoice. Their stock is not received again.</span>
                @unless($canInvoice)<span class="d-block pw-err">Recording supplier invoices is not part of your role.</span>@endunless
            </label>
        </div>
    </section>

    {{-- 2a. goods arriving now: the Goods Receipt screen's header and entry area --}}
    <section class="pw-card" data-for="new" aria-labelledby="pw-h-goods" @if($start !== 'new') hidden @endif>
        <h2 id="pw-h-goods"><span class="pw-step">2</span> Goods received @if($purchaseOrder)<span class="badge text-bg-light">from order {{ $purchaseOrder->po_no }}</span>@endif</h2>
        <div class="row g-3 mb-2">
            <div class="col-md-4">
                @include('tenant.purchasing.partials.supplier-box', ['selectedSupplierId' => old('supplier_id', $purchaseOrder?->supplier_id), 'mode' => 'grn', 'currentPoId' => $purchaseOrder?->id])
            </div>
            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id', $purchaseOrder?->branch_id ?? ($branches->count() === 1 ? $branch->id : null)) == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="receipt_date" class="form-label required">Date received</label>
                <input id="receipt_date" type="date" name="receipt_date" class="form-control" value="{{ old('receipt_date', now()->toDateString()) }}" required>
            </div>
            <div class="col-md-4">
                <label for="extra_charges" class="form-label">Extra charges (Rs)</label>
                <input type="number" step="0.01" min="0" id="extra_charges" name="extra_charges" class="form-control" value="{{ old('extra_charges') }}" placeholder="0.00">
                <div class="form-text">Cartage, labour — added to the goods' cost by value. Not a stock item.</div>
            </div>
            <div class="col-md-8">
                <label for="extra_charges_note" class="form-label">Charges description</label>
                <input type="text" maxlength="255" id="extra_charges_note" name="extra_charges_note" class="form-control" value="{{ old('extra_charges_note') }}" placeholder="e.g. Cartage, Labour">
            </div>
            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="1" class="form-control">{{ old('notes') }}</textarea>
            </div>
        </div>
        @include('tenant.purchasing.partials.entry-area', ['mode' => 'grn', 'prefillLines' => $prefillLines ?? [], 'purchaseOrder' => $purchaseOrder ?? null, 'newUrl' => '/purchases/new'])
    </section>

    {{-- 2b. goods already received: the receipt that waits for its invoice --}}
    <section class="pw-card" data-for="received" aria-labelledby="pw-h-rec" @if($start !== 'received') hidden @endif>
        <h2 id="pw-h-rec"><span class="pw-step">2</span> The goods receipt</h2>
        <label for="pw-grn" class="form-label required">Goods receipt waiting for its invoice</label>
        <select id="pw-grn" name="goods_receipt_id" class="form-select">
            <option value="">— Choose —</option>
            @foreach($waiting as $g)
                <option value="{{ $g->id }}" data-supplier="{{ $g->supplier_id }}" @selected((string) $pick === (string) $g->id)>{{ $g->grn_no }} — {{ $g->supplier?->name }} — {{ $g->branch?->name }} ({{ $g->receipt_date?->format('d M Y') }})</option>
            @endforeach
        </select>
        <div class="pw-help mt-1">One goods receipt has one invoice; it cannot be split or joined with another here.</div>
        <div id="pw-grn-info" class="mt-2" aria-live="polite"></div>
    </section>

    {{-- 3. the supplier's invoice --}}
    <section class="pw-card" aria-labelledby="pw-h-inv">
        <h2 id="pw-h-inv"><span class="pw-step">3</span> Supplier Invoice</h2>
        @if($canInvoice)
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2" data-for="new" @if($start !== 'new') hidden @endif>
                <span class="fw-bold" id="pw-inv-q">Record the supplier's invoice now?</span>
                <div class="yn" role="radiogroup" aria-labelledby="pw-inv-q">
                    <input type="radio" name="record_invoice" id="pw-inv-y" value="1" @checked(old('record_invoice', '1') === '1')><label for="pw-inv-y">Yes</label>
                    <input type="radio" name="record_invoice" id="pw-inv-n" value="0" @checked(old('record_invoice', '1') === '0')><label for="pw-inv-n">No — later</label>
                </div>
            </div>
            <div id="pw-inv-later" class="pw-help" hidden>The goods go into stock now. Record the invoice later — here with "Goods already received", or on Supplier Invoices.</div>
            <div class="row g-3" id="pw-inv-fields">
                <div class="col-md-3">
                    <label for="pw-inv-no" class="form-label">Supplier's invoice number</label>
                    <input id="pw-inv-no" type="text" maxlength="100" name="invoice[supplier_invoice_no]" class="form-control" value="{{ old('invoice.supplier_invoice_no') }}">
                </div>
                <div class="col-md-3">
                    <label for="pw-inv-date" class="form-label required">Invoice date</label>
                    <input id="pw-inv-date" type="date" name="invoice[bill_date]" class="form-control" value="{{ old('invoice.bill_date', now()->toDateString()) }}">
                </div>
                <div class="col-md-3">
                    <label for="pw-inv-due" class="form-label">Due date</label>
                    <input id="pw-inv-due" type="date" name="invoice[due_date]" class="form-control" value="{{ old('invoice.due_date') }}">
                </div>
                <div class="col-md-3">
                    <label for="pw-inv-tax" class="form-label">Tax on the invoice (Rs)</label>
                    <input id="pw-inv-tax" type="number" step="0.01" min="0" name="invoice[tax_amount]" class="form-control" value="{{ old('invoice.tax_amount') }}" placeholder="0.00">
                </div>
                <div class="col-12" id="pw-returns" hidden>
                    <span class="fw-bold d-block mb-1" id="pw-ret-q">Goods from this receipt went back before the invoice. Does the supplier's invoice already take them off?</span>
                    <div class="yn" role="radiogroup" aria-labelledby="pw-ret-q">
                        <input type="radio" name="invoice[returns_on_invoice]" id="pw-ret-full" value="{{ \App\Services\Purchasing\PurchaseBillService::SEPARATE }}"><label for="pw-ret-full">No — full invoice</label>
                        <input type="radio" name="invoice[returns_on_invoice]" id="pw-ret-net" value="{{ \App\Services\Purchasing\PurchaseBillService::NETTED }}"><label for="pw-ret-net">Yes — net invoice</label>
                    </div>
                    <div class="pw-help mt-1" id="pw-ret-list"></div>
                </div>
                {{-- SUPPLIER-CREDIT-CLARITY-1 (owner, 26 Oct): the advance this invoice uses, payment by payment, ticked before Post. --}}
                <div class="col-12" id="pw-credit" hidden>
                    <span class="fw-bold d-block">This invoice uses the supplier's advance: <span id="pw-credit-total"></span></span>
                    <ul class="mb-1 ps-3 pw-help" id="pw-credit-list"></ul>
                    <label for="pw-credit-ok"><input type="checkbox" class="form-check-input" name="invoice[confirm_credit]" id="pw-credit-ok" value="1">
                        Use this advance on this invoice.</label>
                </div>
                <div class="col-12 pw-help">The invoice total comes from the goods received (and their extra charges). If the supplier's paper differs, correct a line's price before you Post. A difference found after the goods were posted is settled with a Supplier Return or an authorized adjustment — a posted goods receipt is never edited.</div>
            </div>
        @else
            <p class="pw-help mb-0">Recording supplier invoices is not part of your role, so this Post records the goods only. The invoice can be recorded later by someone who may.</p>
        @endif
    </section>

    {{-- 4. a payment --}}
    <section class="pw-card" aria-labelledby="pw-h-pay" id="pw-pay-card">
        <h2 id="pw-h-pay"><span class="pw-step">4</span> Payment</h2>
        @if($canPay)
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                <span class="fw-bold" id="pw-pay-q">Pay the supplier now?</span>
                <div class="yn" role="radiogroup" aria-labelledby="pw-pay-q">
                    <input type="radio" name="pay" id="pw-pay-n" value="0" @checked(old('pay', '0') === '0')><label for="pw-pay-n">No</label>
                    <input type="radio" name="pay" id="pw-pay-y" value="1" @checked(old('pay', '0') === '1')><label for="pw-pay-y">Yes</label>
                </div>
            </div>
            <div class="row g-3" id="pw-pay-fields" @if(old('pay', '0') !== '1') hidden @endif>
                <div class="col-md-3">
                    <label for="pw-pay-amt" class="form-label required">Amount to pay (Rs)</label>
                    <input id="pw-pay-amt" type="number" step="0.01" min="0.01" name="payment[amount]" class="form-control" value="{{ old('payment.amount') }}" inputmode="decimal">
                    <div class="pw-help" id="pw-pay-says">Up to what this invoice owes after Supplier Credit.</div>
                </div>
                <div class="col-md-3">
                    <label for="pw-pay-acc" class="form-label required">Paid from</label>
                    <select id="pw-pay-acc" name="payment[cash_bank_account_id]" class="form-select">
                        <option value="">— Choose —</option>
                        @foreach($cashAccounts as $a)<option value="{{ $a->id }}" @selected((string) old('payment.cash_bank_account_id') === (string) $a->id)>{{ $a->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="pw-pay-how" class="form-label required">How</label>
                    <select id="pw-pay-how" name="payment[payment_method]" class="form-select">
                        @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('payment.payment_method', 'cash') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="pw-pay-date" class="form-label required">Payment date</label>
                    <input id="pw-pay-date" type="date" name="payment[payment_date]" class="form-control" value="{{ old('payment.payment_date', now()->toDateString()) }}">
                </div>
                <div class="col-md-2">
                    <label for="pw-pay-ref" class="form-label">Reference</label>
                    <input id="pw-pay-ref" type="text" maxlength="100" name="payment[reference_no]" class="form-control" value="{{ old('payment.reference_no') }}">
                </div>
                <div class="col-12 pw-help">The server checks the amount again when you Post, against the invoice's own balance after Supplier Credit. Paying more is refused here — an advance is recorded under <a href="{{ url('/supplier-payments/create') }}">Payments</a>.</div>
            </div>
        @else
            <p class="pw-help mb-0">{{ $canInvoice ? 'Paying suppliers is not part of your role.' : 'A payment here pays this purchase\'s invoice, which your role does not record.' }} Payments are recorded by someone who may.</p>
        @endif
    </section>

    <div class="pw-post" role="group" aria-label="Post this purchase">
        <button class="btn btn-primary btn-lg fw-bold" type="submit" id="grn-post-btn">Post</button>
        <span class="pw-help">One Post records the chosen parts together. If any part fails, nothing is saved, the screen says which part, and everything you typed stays.</span>
    </div>
    <div id="pw-done" class="alert alert-success mt-3" role="status" tabindex="-1" hidden></div>
</form>
</div>
@endsection

@push('scripts')
<script>
@include('tenant.purchases.partials.workspace-script')
</script>
@endpush
