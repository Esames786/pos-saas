@extends('layouts.app')

@section('title', 'Supplier Invoice')

@push('styles')
<style>
    /* SUPPLIER-NET-BILL-1 UI — compact, the Bingoo purchase family (navy action, gold selection, red only for errors). */
    .pb { --navy:#092C4C; --navy2:#0D3D69; --gold:#B8902E; --goldbg:#FFF8E6; --line:#E3E8EE; --ink2:#4E5B6B; --ok:#1E7A50; --bad:#B42318; }
    .pb .pb-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px; }
    .pb .pb-head h1 { font-size:1.35rem; font-weight:800; color:var(--navy); margin:0; }
    .pb .pb-head .pb-ur { font-size:.95rem; color:var(--ink2); font-weight:600; margin-left:6px; }
    .pb .pb-strip { display:flex; flex-wrap:wrap; gap:4px 22px; align-items:center; background:#F8FAFC; border:1px solid var(--line); border-radius:12px; padding:8px 14px; margin-bottom:12px; font-size:.92rem; }
    .pb .pb-strip b { color:var(--navy); }
    .pb .pb-strip a { margin-left:auto; font-weight:700; }
    .pb .pb-grid { display:grid; grid-template-columns:minmax(0, 1fr) 320px; gap:14px; align-items:start; }
    .pb .pb-card { background:#fff; border:1px solid var(--line); border-radius:12px; }
    .pb .pb-items { max-height:min(42vh, 360px); overflow:auto; border-radius:12px 12px 0 0; }
    .pb .pb-tbl { width:100%; margin:0; font-size:.93rem; }
    .pb .pb-tbl th { position:sticky; top:0; z-index:1; background:#F4F6FA; color:var(--ink2); font-size:.72rem; font-weight:800; letter-spacing:.03em; text-transform:uppercase; padding:8px 10px; border-bottom:1px solid var(--line); }
    .pb .pb-tbl td { padding:7px 10px; border-bottom:1px solid #EEF2F6; vertical-align:middle; }
    .pb .pb-num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .pb .pb-muted { color:var(--ink2); font-size:.82rem; }
    .pb .pb-hint { color:var(--ink2); font-size:.76rem; margin-top:3px; }
    .pb .pb-foot { display:flex; justify-content:flex-end; gap:22px; flex-wrap:wrap; padding:8px 12px; border-top:1px solid var(--line); background:#FBFCFD; border-radius:0 0 12px 12px; font-size:.92rem; }
    .pb .pb-foot b { font-variant-numeric:tabular-nums; }
    .pb .pb-q { margin:12px 0 0; padding:12px 14px; border:1px solid var(--line); border-radius:12px; background:#fff; }
    .pb .pb-q legend { font-size:.95rem; font-weight:800; color:var(--navy); margin-bottom:4px; float:none; width:auto; }
    .pb .pb-opts { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-top:8px; }
    .pb .pb-opt { display:flex; gap:10px; align-items:flex-start; border:1px solid #CBD3DE; border-radius:10px; padding:10px 12px; cursor:pointer; background:#fff; }
    .pb .pb-opt.on { border:2px solid var(--gold); background:var(--goldbg); padding:9px 11px; }
    .pb .pb-opt.off { opacity:.55; cursor:not-allowed; }
    .pb .pb-opt b { display:block; color:var(--navy); }
    .pb .pb-ded { margin-top:10px; display:grid; gap:8px; }
    .pb .pb-ded-row { display:grid; grid-template-columns:minmax(0, 1fr) 150px; gap:6px 10px; align-items:center; }
    .pb .pb-ded-row label { margin:0; font-size:.88rem; font-weight:700; color:#2A3A4E; text-transform:none; letter-spacing:0; }
    .pb .pb-note { margin-top:8px; font-size:.86rem; color:#5E3D00; background:var(--goldbg); border-radius:8px; padding:6px 10px; }
    .pb .pb-block { margin-top:8px; font-size:.86rem; color:var(--bad); background:#FDECEC; border-radius:8px; padding:6px 10px; font-weight:600; white-space:pre-line; }
    /* fields: 4 across (tax sits beside notes) when the main column is wide, 2 across when not — by its own width */
    .pb .pb-main { container-type:inline-size; min-width:0; }
    .pb .pb-fields { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-top:12px; }
    .pb .pb-f-notes { grid-column:1 / -1; }
    @container (min-width: 620px) { .pb .pb-fields { grid-template-columns:repeat(4, minmax(0, 1fr)); } .pb .pb-f-notes { grid-column:span 3; } }
    @container (max-width: 300px) { .pb .pb-fields { grid-template-columns:minmax(0, 1fr); } }
    .pb .pb-f label { display:block; font-size:.72rem; font-weight:800; color:var(--ink2); letter-spacing:.03em; text-transform:uppercase; margin-bottom:3px; }
    .pb .pb-in { height:40px; width:100%; border:1px solid #CBD3DE; border-radius:10px; padding:0 10px; background:#fff; font-size:.95rem; }
    .pb textarea.pb-in { height:auto; min-height:40px; padding:8px 10px; }
    .pb .pb-in:focus { outline:3px solid var(--gold); outline-offset:0; border-color:var(--gold); }
    .pb .pb-in.is-invalid { border-color:var(--bad); }
    .pb .pb-err { color:var(--bad); font-size:.8rem; font-weight:600; margin-top:3px; }
    /* the theme styles every <aside> as a padded, white, full-height scroller — not this one */
    .pb .pb-side { position:sticky; top:84px; padding:0; background:none; border:0; height:auto; overflow:visible; }
    .pb .pb-sum { padding:14px 16px; }
    .pb .pb-sum h2 { font-size:.72rem; font-weight:800; color:var(--ink2); letter-spacing:.03em; text-transform:uppercase; margin:0 0 8px; }
    .pb .pb-rows { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:6px 0; font-size:.93rem; margin:0; }
    .pb .pb-rows dt { font-weight:600; color:#2A3A4E; }
    .pb .pb-rows dd { margin:0; padding-left:10px; text-align:right; font-variant-numeric:tabular-nums; font-weight:700; white-space:nowrap; }
    .pb .pb-rows dd.pb-sub { grid-column:1 / -1; margin-top:-4px; padding-left:0; font-size:.78rem; color:var(--ink2); text-align:left; font-weight:400; white-space:normal; }
    .pb .pb-rows .pb-inv { border-top:1px solid var(--line); padding-top:6px; color:var(--navy); font-weight:800; }
    .pb .pb-rows .pb-rem { border-top:2px solid var(--navy); padding-top:8px; color:var(--navy); font-size:1.3rem; font-weight:800; }
    .pb .pb-btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; height:46px; border:0; border-radius:10px; background:var(--navy); color:#fff; font-weight:800; font-size:1rem; margin-top:14px; }
    .pb .pb-btn:hover { background:var(--navy2); }
    .pb .pb-btn:focus-visible, .pb .pb-btn2:focus-visible, .pb .pb-opt:focus-within { outline:3px solid var(--gold); outline-offset:2px; }
    .pb .pb-btn[disabled] { background:#9AA8B8; cursor:not-allowed; }
    .pb .pb-btn2 { display:flex; align-items:center; justify-content:center; height:40px; border:1px solid #CBD3DE; border-radius:10px; background:#fff; color:#2A3A4E; font-weight:700; text-decoration:none; margin-top:8px; }
    .pb .pb-alert { border-radius:10px; padding:8px 12px; background:#FDECEC; color:var(--bad); font-weight:700; margin-bottom:10px; }
    .pb .pb-rv { position:fixed; inset:0; z-index:1060; background:rgba(9,20,40,.45); display:flex; align-items:flex-start; justify-content:center; padding:6vh 16px; overflow:auto; }
    .pb .pb-rv-box { background:#fff; border-radius:16px; width:100%; max-width:560px; padding:18px 20px; box-shadow:0 24px 60px rgba(9,44,76,.3); }
    .pb .pb-rv-box h2 { font-size:1.15rem; font-weight:800; color:var(--navy); margin:0 0 10px; }
    .pb .pb-move { border:1px solid #E8C979; background:var(--goldbg); border-radius:10px; padding:10px 12px; margin-top:12px; font-size:.95rem; color:#2A3A4E; }
    .pb .pb-move label { display:flex; gap:8px; align-items:flex-start; margin-top:8px; font-weight:700; color:var(--navy); }
    .pb .pb-rv-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
    .pb .pb-rv-actions .pb-btn, .pb .pb-rv-actions .pb-btn2 { width:auto; flex:1 1 160px; margin-top:0; }
    .pb .pb-pick { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    /* phone / tablet: the balance due and the action stay on screen, however long the list (review, 21 Oct) */
    .pb .pb-mbar { display:none; }
    @media (max-width: 991.98px) {
        .pb .pb-grid { grid-template-columns:minmax(0, 1fr); }
        .pb .pb-side { position:static; }
        .pb .pb-items { max-height:none; }
        .pb.pb-has-form { padding-bottom:76px; }
        .pb .pb-mbar { display:flex; position:fixed; left:0; right:0; bottom:0; z-index:1040; gap:12px; align-items:center; justify-content:space-between;
            background:#fff; border-top:1px solid var(--line); box-shadow:0 -6px 18px rgba(9,44,76,.12);
            padding:10px max(16px, env(safe-area-inset-right, 0px)) calc(10px + env(safe-area-inset-bottom, 0px)) max(16px, env(safe-area-inset-left, 0px)); }
        .pb .pb-mbar span { font-size:.8rem; color:var(--ink2); font-weight:700; }
        .pb .pb-mbar b { display:block; font-size:1.15rem; color:var(--navy); font-variant-numeric:tabular-nums; }
        .pb .pb-mbar .pb-btn { width:auto; margin:0; padding:0 18px; flex:0 0 auto; }
    }
    @media (max-width: 575.98px) {
        .pb .pb-opts { grid-template-columns:minmax(0, 1fr); } .pb .pb-strip a { margin-left:0; } .pb .pb-ded-row { grid-template-columns:minmax(0, 1fr); }
        /* a phone: the rate and its unit may take two lines, so the Amount column stays on screen */
        .pb .pb-tbl { font-size:.86rem; } .pb .pb-tbl th, .pb .pb-tbl td { padding:6px; } .pb .pb-tbl .pb-num { white-space:normal; }
    }
</style>
@endpush

@section('content')
@php
    $pv = $preview ?? null;
    $returns = $pv['returns'] ?? [];
    $returnsTotal = collect($returns)->sum('amount');
    $moves = collect($pv['moves'] ?? []);
    $canMove = collect($returns)->flatMap(fn ($r) => $r['allocations'])->isNotEmpty();   // some return credit pays another invoice
    $planJson = ['credit' => $pv['credit'] ?? [], 'returns' => $returns];
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<div class="pb {{ $goodsReceipt ? 'pb-has-form' : '' }}">
    <div class="pb-head">
        <h1>Supplier Invoice <span class="pb-ur" lang="ur">سپلائر کا بل</span></h1>
        <a href="{{ url('/purchase-bills') }}" class="pb-btn2" style="margin:0;padding:0 14px">Back to invoices</a>
    </div>

    @include('tenant.purchasing.partials.how-to-use', ['guide' => [
        'what' => 'Record the invoice your supplier sent for one goods receipt. The goods are already in stock from the receipt; this records what you owe for them.',
        'steps' => [
            'Choose the goods receipt the invoice is for.',
            'Check the items, quantities and rates against the supplier\'s invoice.',
            'If goods went back before the invoice, choose Full invoice (before returns) or Net invoice (returns already deducted), and enter how much the supplier deducted.',
            'Type the supplier\'s invoice number and date, and any discount or tax printed on it.',
            'Press Review invoice, check the summary, then Post invoice.',
        ],
        'effects' => [
            'Stock' => 'No change — it moved when the goods were received.',
            'Supplier balance' => 'Goes up by the invoice total. A deducted return is recorded with it, so it is never deducted twice.',
            'Payments' => 'None is made here. Supplier credit you already have (returns, advances) is applied automatically; pay the rest under Supplier Payments.',
        ],
        'example' => 'Goods received for Rs 1,000; Rs 200 went back before the invoice. Full invoice: invoice 1,000, Rs 200 supplier credit applied, Rs 800 remaining. Net invoice: invoice 800, Rs 800 remaining. If the supplier deducted only Rs 100, enter 100: invoice 900, the other Rs 100 stays as supplier credit and is applied, Rs 800 remaining.',
        'blocked' => [
            'An invoice is already recorded for this goods receipt.',
            'A return\'s credit already paid another invoice that is now fully paid — a net invoice would reopen it, so ask your accountant first.',
            'Moving supplier credit off another open invoice needs a person who can void supplier payments, and their tick on the review.',
            'The accounting entry could not be posted — nothing is saved, so try again.',
        ],
    ]])

    @if($errors->any())
        <div class="pb-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    @if(! $goodsReceipt)
        {{-- Pick the receipt first: the invoice is built from it, and the screen shows exactly what it will post. --}}
        <form method="GET" action="{{ url('/purchase-bills/create') }}" class="pb-card" style="padding:14px 16px">
            <div class="pb-pick">
                <div class="pb-f" style="flex:1 1 320px">
                    <label for="goods_receipt_id">Goods receipt to invoice</label>
                    <select id="goods_receipt_id" name="goods_receipt_id" class="pb-in" required>
                        <option value="">— Choose a goods receipt with no invoice yet —</option>
                        @foreach($receipts as $receipt)
                            <option value="{{ $receipt->id }}" @selected(old('goods_receipt_id') == $receipt->id)>{{ $receipt->grn_no }} — {{ $receipt->supplier?->name }} ({{ $receipt->receipt_date?->format('d M Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="pb-btn" style="width:auto;padding:0 22px;margin:0">Open</button>
            </div>
        </form>
    @else
    <form method="POST" action="{{ url('/purchase-bills') }}" id="pb-form" novalidate>
        @csrf
        <input type="hidden" name="goods_receipt_id" value="{{ $goodsReceipt->id }}">

        <div class="pb-strip" aria-label="Goods receipt">
            <span>Supplier <b>{{ $goodsReceipt->supplier?->name }}</b></span>
            <span>Goods receipt <b>{{ $goodsReceipt->grn_no }}</b></span>
            <span>Branch <b>{{ $goodsReceipt->branch?->name }}</b></span>
            <span>Received <b>{{ $goodsReceipt->receipt_date?->format('d M Y') }}</b></span>
            <a href="{{ url('/purchase-bills/create') }}">Change goods receipt</a>
        </div>

        <div class="pb-grid">
            <section class="pb-main" aria-label="Items and invoice">
                <div class="pb-card">
                    {{-- a long list scrolls here; Chrome gives the box a Tab stop then, so the keyboard can scroll it --}}
                    <div class="pb-items" role="region" aria-label="Items received">
                        <table class="pb-tbl">
                            <caption class="visually-hidden">Goods received on {{ $goodsReceipt->grn_no }}</caption>
                            <thead><tr><th scope="col">Item</th><th scope="col" class="pb-num">Qty</th><th scope="col" class="pb-num">Rate</th><th scope="col" class="pb-num">Amount</th></tr></thead>
                            <tbody>
                            @foreach($goodsReceipt->lines as $line)
                                @php $unit = $line->product?->unit?->name ?: 'unit'; @endphp
                                <tr>
                                    <td>{{ $line->product?->name }}@if($line->variant && ! $line->variant->is_default) <span class="pb-muted">· {{ $line->variant->name }}</span>@endif
                                        @if((float) $line->unit_cost == 0) <span class="pb-muted">· free</span>@endif</td>
                                    <td class="pb-num">{{ rtrim(rtrim(number_format((float) $line->quantity_received, 3), '0'), '.') }} <span class="pb-muted">{{ $unit }}</span></td>
                                    <td class="pb-num">{{ $money($line->unit_cost) }} <span class="pb-muted">/ {{ $unit }}</span></td>
                                    <td class="pb-num">{{ $money((float) $line->quantity_received * (float) $line->unit_cost) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="pb-foot">
                        <span>Goods <b>{{ $money($pv['goods']) }}</b></span>
                        @if($pv['charges'] > 0)
                            <span>Extra Charges @if($goodsReceipt->extra_charges_note)<span class="text-muted">&mdash; {{ $goodsReceipt->extra_charges_note }}</span>@endif <b>{{ $money($pv['charges']) }}</b></span>
                        @endif
                        <span>{{ $goodsReceipt->lines->count() }} {{ $goodsReceipt->lines->count() === 1 ? 'line' : 'lines' }}</span>
                    </div>
                </div>

                @if(! empty($returns))
                {{-- Asked only when goods from this receipt went back before its invoice. Nothing pre-picked. --}}
                <fieldset class="pb-q" id="returns-on-invoice">
                    <legend>Goods from this receipt were returned before the invoice. How does the supplier's invoice treat them? <span class="text-danger">*</span></legend>
                    <div class="pb-muted">
                        Supplier returns: @foreach($returns as $r){{ $r['return_no'] }} ({{ $r['date'] }}) <b>{{ $money($r['amount']) }}</b>@if(! $loop->last) · @endif @endforeach
                        — already credited to the supplier.
                    </div>
                    <div class="pb-opts" role="radiogroup" aria-label="How the invoice treats the returns">
                        <label class="pb-opt" for="roi-separate">
                            <input class="form-check-input mt-1" type="radio" name="returns_on_invoice" value="separate" id="roi-separate" required @checked(old('returns_on_invoice') === 'separate')>
                            <span><b>Full invoice — before returns</b><span class="pb-muted">The invoice lists everything received. The {{ $money($returnsTotal) }} stays supplier credit and is applied once.</span></span>
                        </label>
                        <label class="pb-opt {{ $pv['blocked'] ? 'off' : '' }}" for="roi-netted">
                            <input class="form-check-input mt-1" type="radio" name="returns_on_invoice" value="netted" id="roi-netted" required @checked(old('returns_on_invoice') === 'netted' && ! $pv['blocked']) @disabled($pv['blocked'])>
                            <span><b>Net invoice — returns already deducted</b><span class="pb-muted">The supplier took the returns (all or part) off this invoice.</span></span>
                        </label>
                    </div>
                    @if(! $pv['blocked'])
                    {{-- Net invoice: what the supplier deducted for each return — the whole return unless the paper says less. --}}
                    <div class="pb-ded" id="pb-ded" hidden>
                        @foreach($returns as $r)
                        <div class="pb-ded-row">
                            <label for="ded-{{ $r['id'] }}">Deducted for {{ $r['return_no'] }} <span class="pb-muted">(return {{ $money($r['amount']) }})</span></label>
                            <input id="ded-{{ $r['id'] }}" name="netted_amounts[{{ $r['id'] }}]" inputmode="decimal" class="pb-in pb-num pb-sel @error('netted_amounts.' . $r['id']) is-invalid @enderror"
                                   value="{{ old('netted_amounts.' . $r['id'], number_format($r['amount'], 2, '.', '')) }}" data-max="{{ $r['amount'] }}" aria-describedby="ded-hint-{{ $r['id'] }}">
                            <div class="pb-hint" id="ded-hint-{{ $r['id'] }}" style="grid-column:1 / -1">As shown on the supplier's invoice. Less than the return? The rest stays supplier credit.</div>
                            @error('netted_amounts.' . $r['id']) <div class="pb-err" style="grid-column:1 / -1">{{ $message }}</div> @enderror
                        </div>
                        @endforeach
                    </div>
                    @endif
                    @if($pv['blocked'])
                        <div class="pb-block" role="note">{{ $pv['blocked'] }}</div>
                    @else
                        <div class="pb-note" id="pb-move-note" @if($moves->isEmpty()) hidden @endif>
                            @foreach($moves as $m)
                                Part of this credit pays <b>{{ $m['bill_no'] }}</b> now. A net invoice moves {{ $money($m['amount']) }} off it: its outstanding goes from {{ $money($m['due_now']) }} to {{ $money($m['due_after']) }}. You confirm this on the review.@if(! $loop->last)<br>@endif
                            @endforeach
                        </div>
                    @endif
                    @error('returns_on_invoice') <div class="pb-err">{{ $message }}</div> @enderror
                </fieldset>
                @endif

                <div class="pb-fields">
                    <div class="pb-f">
                        <label for="supplier_invoice_no">Supplier invoice no.</label>
                        <input id="supplier_invoice_no" name="supplier_invoice_no" class="pb-in @error('supplier_invoice_no') is-invalid @enderror" value="{{ old('supplier_invoice_no') }}" maxlength="100" autocomplete="off">
                        <div class="pb-hint">As printed on the invoice.</div>
                        @error('supplier_invoice_no') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="bill_date">Invoice date <span class="text-danger">*</span></label>
                        <input id="bill_date" type="date" name="bill_date" required class="pb-in @error('bill_date') is-invalid @enderror" value="{{ old('bill_date', now()->toDateString()) }}">
                        @error('bill_date') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="due_date">Due date</label>
                        <input id="due_date" type="date" name="due_date" class="pb-in @error('due_date') is-invalid @enderror" value="{{ old('due_date') }}">
                        <div class="pb-hint">When the supplier expects payment.</div>
                        @error('due_date') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="discount_amount">Discount on invoice</label>
                        <input id="discount_amount" name="discount_amount" inputmode="decimal" class="pb-in pb-num pb-sel @error('discount_amount') is-invalid @enderror" value="{{ old('discount_amount', 0) }}">
                        <div class="pb-hint">Only if printed on the invoice.</div>
                        @error('discount_amount') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="tax_amount">Tax on invoice</label>
                        <input id="tax_amount" name="tax_amount" inputmode="decimal" class="pb-in pb-num pb-sel @error('tax_amount') is-invalid @enderror" value="{{ old('tax_amount', 0) }}">
                        <div class="pb-hint">Only if printed on the invoice.</div>
                        @error('tax_amount') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f pb-f-notes">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="1" class="pb-in @error('notes') is-invalid @enderror" maxlength="1000">{{ old('notes') }}</textarea>
                        @error('notes') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                </div>
            </section>

            <aside class="pb-side" aria-label="Invoice summary">
                <div class="pb-card pb-sum" id="pb-sum"
                     data-received="{{ $pv['received'] }}" data-returns="{{ $returnsTotal }}" data-has-returns="{{ empty($returns) ? 0 : 1 }}"
                     data-credit-separate="{{ $pv['credit']['separate'] }}" data-credit-netted="{{ $pv['credit']['netted'] }}">
                    <h2>Invoice summary</h2>
                    <dl class="pb-rows">
                        <dt>Received value</dt><dd>{{ $money($pv['received']) }}</dd>
                        <dt data-row="disc" hidden>Discount</dt><dd data-row="disc" data-v="disc" hidden></dd>
                        <dt data-row="tax" hidden>Tax</dt><dd data-row="tax" data-v="tax" hidden></dd>
                        @if(! empty($returns))
                            <dt>Return adjustment</dt><dd data-v="adj">—</dd>
                            <dd class="pb-sub" data-v="adj-note">Choose how the invoice treats the returns.</dd>
                        @endif
                        <dt class="pb-inv">Actual supplier invoice</dt>
                        <dd class="pb-inv"><b id="bill-total-preview" data-base="{{ $pv['received'] }}">{{ empty($returns) ? $money($pv['received'] - (float) old('discount_amount', 0) + (float) old('tax_amount', 0)) : '—' }}</b></dd>
                        <dt>Supplier credit applied</dt><dd data-v="paid">—</dd>
                        <dt class="pb-rem">Remaining</dt><dd class="pb-rem" data-v="rem">—</dd>
                    </dl>
                    <button type="button" class="pb-btn" id="pb-review">Review invoice</button>
                    <a href="{{ url('/purchase-bills') }}" class="pb-btn2">Cancel</a>
                    {{-- phone / tablet only: always on screen --}}
                    <div class="pb-mbar" id="pb-mbar" role="region" aria-label="Remaining and review">
                        <span>Remaining <b data-v="rem">—</b></span>
                        <button type="button" class="pb-btn" id="pb-review-bar" tabindex="-1">Review invoice</button>
                    </div>
                </div>
            </aside>
        </div>

        {{-- Review: everything once more; a credit moving off another invoice is said in words and must be confirmed. --}}
        <div class="pb-rv" id="pb-rv" hidden role="dialog" aria-modal="true" aria-labelledby="pb-rv-title">
            <div class="pb-rv-box">
                <h2 id="pb-rv-title">Review invoice — {{ $goodsReceipt->supplier?->name }}</h2>
                <dl class="pb-rows" id="pb-rv-rows"></dl>
                @if(! $pv['blocked'] && $canMove)
                <div class="pb-move" id="pb-rv-move" hidden>
                    <div id="pb-rv-move-text"></div>
                    @if($canReallocate)
                        <label for="confirm_reallocation"><input type="checkbox" class="form-check-input mt-1" name="confirm_reallocation" id="confirm_reallocation" value="1">
                            I confirm: move this credit off the invoice named above and onto this invoice. (Recorded under my name.)</label>
                    @else
                        <div class="pb-block" id="pb-rv-cannot" style="margin-top:8px">Only a person who can void supplier payments can move a credit between invoices. Ask them to post this invoice.</div>
                    @endif
                    @error('confirm_reallocation') <div class="pb-err">{{ $message }}</div> @enderror
                </div>
                @endif
                {{-- SUPPLIER-CREDIT-CLARITY-1 (owner, 26 Oct): the advance this invoice uses — which payments and returns, how much
                     from each — said here, and ticked before Post. --}}
                @if(! empty($pv['credit']['sources']))
                <div class="pb-move" id="pb-rv-credit" hidden>
                    <div><b>This invoice uses the supplier's advance: <span id="pb-rv-credit-total"></span></b></div>
                    <ul class="mb-1 ps-3" id="pb-rv-credit-list"></ul>
                    <div class="pb-sub">It is money you already paid this supplier (or goods you sent back) that no invoice used yet.</div>
                    <label for="confirm_credit"><input type="checkbox" class="form-check-input mt-1" name="confirm_credit" id="confirm_credit" value="1">
                        Use this advance on this invoice.</label>
                    @error('confirm_credit') <div class="pb-err">{{ $message }}</div> @enderror
                </div>
                @endif
                <div class="pb-rv-actions">
                    <button type="submit" class="pb-btn" id="pb-post">Post invoice</button>
                    <button type="button" class="pb-btn2" id="pb-back">Back to edit</button>
                </div>
            </div>
        </div>
        {{-- The data the summary is worked out from — the same numbers the server posts with. --}}
        <script type="application/json" id="pb-plan">@json($planJson)</script>
    </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('pb-form');
    if (!form) return;
    var sum = document.getElementById('pb-sum'), out = document.getElementById('bill-total-preview');
    var plan = JSON.parse(document.getElementById('pb-plan').textContent || '{}');
    var hasReturns = sum.dataset.hasReturns === '1';
    var CAN_MOVE = @json((bool) ($canReallocate ?? false));
    var CREDIT_ASKED = @json($errors->has('confirm_credit'));   // SUPPLIER-CREDIT-CLARITY-1: the server asked for the advance tick
    function num(id) { var el = document.getElementById(id); var n = parseFloat(String(el && el.value || '0').replace(/,/g, '')); return isFinite(n) ? n : 0; }
    function money(v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function r2(v) { return Math.round(v * 100) / 100; }
    function choice() { var c = form.querySelector('input[name="returns_on_invoice"]:checked'); return c ? c.value : null; }
    function set(k, text) { sum.querySelectorAll('[data-v="' + k + '"]').forEach(function (el) { el.textContent = text; }); }
    function row(k, show) { sum.querySelectorAll('[data-row="' + k + '"]').forEach(function (el) { el.hidden = !show; }); }

    // Net invoice: what was deducted for each return (as typed; blank = the whole return). null = a wrong amount.
    function deducted() {
        var total = 0, bad = false, byId = {};
        (plan.returns || []).forEach(function (r) {
            var el = document.getElementById('ded-' + r.id), raw = el ? String(el.value).replace(/,/g, '').trim() : '';
            var v = raw === '' ? r.amount : parseFloat(raw);
            var wrong = !isFinite(v) || v <= 0 || v > r.amount + 0.005;
            if (el) el.classList.toggle('is-invalid', wrong);
            bad = bad || wrong; byId[r.id] = wrong ? 0 : v; total += wrong ? 0 : v;
        });
        return bad ? null : { total: r2(total), byId: byId };
    }
    // Where it comes from — the server's rule: the return's unused credit first, then off other invoices (open first, newest first).
    function movesFor(byId) {
        var moves = [];
        (plan.returns || []).forEach(function (r) {
            var rest = r2((byId[r.id] || 0) - r.unused);
            r.allocations.slice().sort(function (a, b) { return (a.settled - b.settled) || (b.bill_id - a.bill_id); }).forEach(function (a) {
                if (rest <= 0.005) return;
                var take = r2(Math.min(rest, a.amount));
                moves.push({ bill_no: a.bill_no, amount: take, due_now: a.due_now, due_after: r2(a.due_now + take), settled: a.settled });
                rest = r2(rest - take);
            });
        });
        return moves;
    }

    // What the server will post. The return is subtracted ONCE: from the invoice (net) or as credit paying it (full).
    function calc() {
        var c = hasReturns ? choice() : 'none';
        var disc = num('discount_amount'), tax = num('tax_amount'), cr = plan.credit || {};
        row('disc', disc > 0); set('disc', '− ' + money(disc));
        row('tax', tax > 0); set('tax', '+ ' + money(tax));
        form.querySelectorAll('.pb-opt').forEach(function (o) { o.classList.toggle('on', !!o.querySelector('input:checked')); });
        var ded = document.getElementById('pb-ded'); if (ded) ded.hidden = c !== 'netted';
        var note = document.getElementById('pb-move-note');
        if (hasReturns && !c) {
            out.textContent = '—'; set('paid', '—'); set('rem', '—'); set('adj', '—');
            set('adj-note', 'Choose how the invoice treats the returns.');
            if (note) note.hidden = true;
            return null;
        }
        var received = Number(sum.dataset.received || 0), less = 0, credit = 0, moves = [];
        if (c === 'netted') {
            var d = deducted();
            if (!d) { out.textContent = '—'; set('paid', '—'); set('rem', '—'); set('adj', '—'); set('adj-note', 'Check the amount deducted.'); return null; }
            less = d.total; moves = movesFor(d.byId);
            var unusedTaken = (plan.returns || []).reduce(function (s, r) { return s + Math.min(d.byId[r.id] || 0, r.unused); }, 0);
            credit = cr.enabled ? Math.max(0, Math.min(-(cr.balance + less), cr.unallocated - unusedTaken)) : 0;
            set('adj', '− ' + money(less));
            set('adj-note', less < Number(sum.dataset.returns) - 0.005 ? 'Deducted by the supplier on this invoice; the rest stays supplier credit.' : 'Deducted by the supplier on this invoice.');
        } else {
            credit = cr.enabled ? Math.max(0, Math.min(-cr.balance, cr.unallocated)) : 0;
            if (hasReturns) { set('adj', money(Number(sum.dataset.returns))); set('adj-note', 'Not on the invoice — it is supplier credit, applied below.'); }
        }
        if (note) {
            note.hidden = moves.length === 0;
            note.innerHTML = '';
            moves.forEach(function (m, i) {
                if (i) note.appendChild(document.createElement('br'));
                note.appendChild(document.createTextNode('Part of this credit pays ' + m.bill_no + ' now. A net invoice moves ' + money(m.amount)
                    + ' off it: its outstanding goes from ' + money(m.due_now) + ' to ' + money(m.due_after) + '. You confirm this on the review.'));
            });
        }
        var invoice = r2(received - disc + tax - less);
        var paid = r2(Math.max(0, Math.min(credit, invoice)));
        out.textContent = money(invoice); set('paid', paid > 0 ? '− ' + money(paid) : '0.00'); set('rem', money(invoice - paid));
        return { c: c, invoice: invoice, paid: paid, less: less, moves: moves, deducted: c === 'netted' ? d.byId : {} };
    }

    // Numbers: the whole value is selected on focus, so typing replaces it (no backspacing).
    form.querySelectorAll('.pb-sel').forEach(function (el) {
        // only while it still has focus: select() also focuses, and a quick Tab onward must not be pulled back here
        el.addEventListener('focus', function () { setTimeout(function () { if (document.activeElement === el) el.select(); }, 0); });
        el.addEventListener('input', calc);
    });
    form.querySelectorAll('input[name="returns_on_invoice"]').forEach(function (el) { el.addEventListener('change', calc); });

    var rv = document.getElementById('pb-rv'), reviewBtn = document.getElementById('pb-review'), lastOpener = reviewBtn;
    function openReview(e) {
        lastOpener = (e && e.currentTarget && e.currentTarget.id === 'pb-review-bar') ? document.getElementById('pb-review-bar') : reviewBtn;
        var r = calc();
        if (!r) {
            var bad = form.querySelector('.pb-ded .is-invalid');
            var first = bad || form.querySelector('input[name="returns_on_invoice"]:not([disabled])');
            if (first) first.focus();
            var box = document.getElementById('returns-on-invoice'); if (box) box.scrollIntoView({ block: 'center' });
            return;
        }
        var rows = [['Received value', money(Number(sum.dataset.received || 0))]];
        if (num('discount_amount') > 0) rows.push(['Discount', '− ' + money(num('discount_amount'))]);
        if (num('tax_amount') > 0) rows.push(['Tax', '+ ' + money(num('tax_amount'))]);
        if (hasReturns) rows.push(['Return adjustment', r.c === 'netted' ? '− ' + money(r.less) : money(Number(sum.dataset.returns)), r.c === 'netted' ? 'Deducted on the invoice.' : 'Supplier credit, applied below.']);
        rows.push(['Actual supplier invoice', money(r.invoice)], ['Supplier credit applied', r.paid > 0 ? '− ' + money(r.paid) : '0.00'], ['Remaining', money(r.invoice - r.paid)]);
        var dl = document.getElementById('pb-rv-rows'); dl.innerHTML = '';
        rows.forEach(function (x, i) {
            var dt = document.createElement('dt'), dd = document.createElement('dd');
            dt.textContent = x[0]; dd.textContent = x[1];
            if (i === rows.length - 1) { dt.className = dd.className = 'pb-rem'; } else if (x[0] === 'Actual supplier invoice') { dt.className = dd.className = 'pb-inv'; }
            dl.appendChild(dt); dl.appendChild(dd);
            if (x[2]) { var sub = document.createElement('dd'); sub.className = 'pb-sub'; sub.textContent = x[2]; dl.appendChild(sub); }
        });
        var move = document.getElementById('pb-rv-move'), text = document.getElementById('pb-rv-move-text'), post = document.getElementById('pb-post'), ok = document.getElementById('confirm_reallocation');
        var moving = r.c === 'netted' && r.moves.length > 0;
        if (move) move.hidden = !moving;
        if (text) {
            text.innerHTML = '';
            r.moves.forEach(function (m) {
                var div = document.createElement('div'), b = document.createElement('b');
                div.appendChild(document.createTextNode('Rs ' + money(m.amount) + ' of this credit will be moved off '));
                b.textContent = m.bill_no; div.appendChild(b);
                div.appendChild(document.createTextNode(': its outstanding goes from ' + money(m.due_now) + ' to ' + money(m.due_after) + '.'));
                text.appendChild(div);
            });
        }
        if (ok && !moving) ok.checked = false;
        // SUPPLIER-CREDIT-CLARITY-1: the advance it uses, payment by payment (oldest first, as the server takes it).
        var creditBox = document.getElementById('pb-rv-credit'), creditOk = document.getElementById('confirm_credit');
        // The server asked for the tick (CREDIT_ASKED) even if this screen worked out no advance: always show it then.
        usingCredit = !!creditBox && (r.paid > 0.005 || CREDIT_ASKED);
        if (creditBox) {
            creditBox.hidden = !usingCredit;
            var list = document.getElementById('pb-rv-credit-list'); list.innerHTML = '';
            document.getElementById('pb-rv-credit-total').textContent = 'Rs ' + money(r.paid);
            var left = r.paid, unused = {};
            (plan.returns || []).forEach(function (x) { unused[x.id] = x.unused; });
            ((plan.credit || {}).sources || []).forEach(function (s) {
                // a return this net invoice deducts is spent on it, not advance
                var rest = s.type === 'return' && r.deducted[s.id] ? r2(s.remaining - Math.min(r.deducted[s.id], unused[s.id] || 0)) : s.remaining;
                if (left <= 0.005 || rest <= 0.005) return;
                var take = Math.min(left, rest), li = document.createElement('li');
                li.textContent = s.label + ' (' + s.date + '): Rs ' + money(take);
                list.appendChild(li);
                left = Math.round((left - take) * 100) / 100;
            });
            if (!usingCredit && creditOk) creditOk.checked = false;
        }
        movingNow = moving;
        updatePost();
        rv.hidden = false;
        (moving && ok ? ok : (usingCredit && creditOk ? creditOk : post)).focus();
    }
    // Post waits for every tick the review asks for: a credit moving off another invoice, and the advance this one uses.
    var movingNow = false, usingCredit = false;
    function updatePost() {
        var ok = document.getElementById('confirm_reallocation'), creditOk = document.getElementById('confirm_credit');
        document.getElementById('pb-post').disabled = (movingNow && (!CAN_MOVE || !(ok && ok.checked)))
            || (usingCredit && !(creditOk && creditOk.checked));
    }
    function closeReview() { rv.hidden = true; (lastOpener && lastOpener.offsetParent !== null ? lastOpener : reviewBtn).focus(); }
    reviewBtn.addEventListener('click', openReview);
    document.getElementById('pb-review-bar').addEventListener('click', openReview);
    document.getElementById('pb-back').addEventListener('click', closeReview);
    var ok = document.getElementById('confirm_reallocation');
    if (ok) ok.addEventListener('change', updatePost);
    var creditTick = document.getElementById('confirm_credit');
    if (creditTick) creditTick.addEventListener('change', updatePost);
    rv.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); closeReview(); return; }
        if (e.key === 'Tab') {   // stay inside the review
            var f = Array.prototype.filter.call(rv.querySelectorAll('button, input'), function (el) { return !el.disabled && el.offsetParent !== null; });
            if (!f.length) return;
            var i = f.indexOf(document.activeElement);
            if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
        }
    });
    // Enter in a field opens the review instead of posting straight away.
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && rv.hidden && e.target.tagName === 'INPUT') { e.preventDefault(); openReview(); }
    });
    form.addEventListener('submit', function (e) {
        if (rv.hidden) { e.preventDefault(); openReview(); return; }
        // posting: a second click or Enter cannot send the invoice twice (the server refuses a second one anyway)
        var post = document.getElementById('pb-post');
        setTimeout(function () { post.disabled = true; post.textContent = 'Posting…'; }, 0);
    });
    calc();
    @if($errors->has('confirm_reallocation') || $errors->has('confirm_credit'))
    openReview();
    @endif
})();
</script>
@endpush
