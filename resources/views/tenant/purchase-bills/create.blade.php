@extends('layouts.app')

@section('title', 'Supplier bill')

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
    .pb .pb-foot { display:flex; justify-content:flex-end; gap:22px; flex-wrap:wrap; padding:8px 12px; border-top:1px solid var(--line); background:#FBFCFD; border-radius:0 0 12px 12px; font-size:.92rem; }
    .pb .pb-foot b { font-variant-numeric:tabular-nums; }
    .pb .pb-q { margin:12px 0 0; padding:12px 14px; border:1px solid var(--line); border-radius:12px; background:#fff; }
    .pb .pb-q legend { font-size:.95rem; font-weight:800; color:var(--navy); margin-bottom:4px; float:none; width:auto; }
    .pb .pb-opts { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-top:8px; }
    .pb .pb-opt { display:flex; gap:10px; align-items:flex-start; border:1px solid #CBD3DE; border-radius:10px; padding:10px 12px; cursor:pointer; background:#fff; }
    .pb .pb-opt.on { border:2px solid var(--gold); background:var(--goldbg); padding:9px 11px; }
    .pb .pb-opt.off { opacity:.55; cursor:not-allowed; }
    .pb .pb-opt b { display:block; color:var(--navy); }
    .pb .pb-note { margin-top:8px; font-size:.86rem; color:#5E3D00; background:var(--goldbg); border-radius:8px; padding:6px 10px; }
    .pb .pb-block { margin-top:8px; font-size:.86rem; color:var(--bad); background:#FDECEC; border-radius:8px; padding:6px 10px; font-weight:600; }
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
    @media (max-width: 991.98px) {
        .pb .pb-grid { grid-template-columns:minmax(0, 1fr); }
        .pb .pb-side { position:static; }
        .pb .pb-items { max-height:none; }
    }
    @media (max-width: 575.98px) { .pb .pb-opts { grid-template-columns:minmax(0, 1fr); } .pb .pb-strip a { margin-left:0; } }
</style>
@endpush

@section('content')
@php
    $pv = $preview ?? null;
    $returns = $pv['returns'] ?? [];
    $returnsTotal = collect($returns)->sum('amount');
    $moves = collect($returns)->flatMap(fn ($r) => $r['allocations'])->values();
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<div class="pb">
    <div class="pb-head">
        <h1>Supplier bill <span class="pb-ur">سپلائر کا بل</span></h1>
        <a href="{{ url('/purchase-bills') }}" class="pb-btn2" style="margin:0;padding:0 14px">Back to bills</a>
    </div>

    @if($errors->any())
        <div class="pb-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    @if(! $goodsReceipt)
        {{-- Pick the receipt first: the bill is built from it, and the screen shows exactly what it will post. --}}
        <form method="GET" action="{{ url('/purchase-bills/create') }}" class="pb-card" style="padding:14px 16px">
            <div class="pb-pick">
                <div class="pb-f" style="flex:1 1 320px">
                    <label for="goods_receipt_id">Goods receipt (GRN) to bill</label>
                    <select id="goods_receipt_id" name="goods_receipt_id" class="pb-in" required>
                        <option value="">— Choose a receipt with no bill yet —</option>
                        @foreach($receipts as $receipt)
                            <option value="{{ $receipt->id }}" @selected(old('goods_receipt_id') == $receipt->id)>{{ $receipt->grn_no }} — {{ $receipt->supplier?->name }} ({{ $receipt->receipt_date?->format('d M Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="pb-btn" style="width:auto;padding:0 22px;margin:0">Open receipt</button>
            </div>
        </form>
    @else
    <form method="POST" action="{{ url('/purchase-bills') }}" id="pb-form" novalidate>
        @csrf
        <input type="hidden" name="goods_receipt_id" value="{{ $goodsReceipt->id }}">

        <div class="pb-strip" aria-label="Receipt">
            <span>Supplier <b>{{ $goodsReceipt->supplier?->name }}</b></span>
            <span>Receipt <b>{{ $goodsReceipt->grn_no }}</b></span>
            <span>Branch <b>{{ $goodsReceipt->branch?->name }}</b></span>
            <span>Received <b>{{ $goodsReceipt->receipt_date?->format('d M Y') }}</b></span>
            <a href="{{ url('/purchase-bills/create') }}">Change receipt</a>
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
                {{-- Asked only when goods from this receipt went back before its bill. Nothing pre-picked. --}}
                <fieldset class="pb-q" id="returns-on-invoice">
                    <legend>Is receipt ka maal bill se pehle wapas gaya — supplier ka invoice kaisa hai? <span class="text-danger">*</span></legend>
                    <div class="pb-muted">
                        @foreach($returns as $r){{ $r['return_no'] }} ({{ $r['date'] }}) <b>{{ $money($r['amount']) }}</b>@if(! $loop->last) · @endif @endforeach
                        — supplier ko pehle hi credit ho chuka.
                    </div>
                    <div class="pb-opts" role="radiogroup" aria-label="How the invoice treats the returns">
                        <label class="pb-opt" for="roi-separate">
                            <input class="form-check-input mt-1" type="radio" name="returns_on_invoice" value="separate" id="roi-separate" required @checked(old('returns_on_invoice') === 'separate')>
                            <span><b>Sab maal ka invoice</b><span class="pb-muted">For everything received — the {{ $money($returnsTotal) }} stays a credit and is taken off once.</span></span>
                        </label>
                        <label class="pb-opt {{ $pv['blocked'] ? 'off' : '' }}" for="roi-netted">
                            <input class="form-check-input mt-1" type="radio" name="returns_on_invoice" value="netted" id="roi-netted" required @checked(old('returns_on_invoice') === 'netted' && ! $pv['blocked']) @disabled($pv['blocked'])>
                            <span><b>Wapsi ghata kar invoice</b><span class="pb-muted">Already less the returns — the supplier took {{ $money($returnsTotal) }} off this invoice.</span></span>
                        </label>
                    </div>
                    @if($pv['blocked'])
                        <div class="pb-block" role="note">{{ $pv['blocked'] }}</div>
                    @elseif($moves->isNotEmpty())
                        <div class="pb-note" id="pb-move-note">
                            @foreach($moves as $m)
                                This credit is on <b>{{ $m['bill_no'] }}</b> now — "Wapsi ghata kar" moves {{ $money($m['amount']) }} off it: its outstanding goes {{ $money($m['due_now']) }} → {{ $money($m['due_after']) }}. You confirm it on review.@if(! $loop->last)<br>@endif
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
                        @error('supplier_invoice_no') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="bill_date">Bill date <span class="text-danger">*</span></label>
                        <input id="bill_date" type="date" name="bill_date" required class="pb-in @error('bill_date') is-invalid @enderror" value="{{ old('bill_date', now()->toDateString()) }}">
                        @error('bill_date') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="due_date">Due date</label>
                        <input id="due_date" type="date" name="due_date" class="pb-in @error('due_date') is-invalid @enderror" value="{{ old('due_date') }}">
                        @error('due_date') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="discount_amount">Discount on invoice</label>
                        <input id="discount_amount" name="discount_amount" inputmode="decimal" class="pb-in pb-num pb-sel @error('discount_amount') is-invalid @enderror" value="{{ old('discount_amount', 0) }}">
                        @error('discount_amount') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f">
                        <label for="tax_amount">Tax on invoice</label>
                        <input id="tax_amount" name="tax_amount" inputmode="decimal" class="pb-in pb-num pb-sel @error('tax_amount') is-invalid @enderror" value="{{ old('tax_amount', 0) }}">
                        @error('tax_amount') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                    <div class="pb-f pb-f-notes">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="1" class="pb-in @error('notes') is-invalid @enderror" maxlength="1000">{{ old('notes') }}</textarea>
                        @error('notes') <div class="pb-err">{{ $message }}</div> @enderror
                    </div>
                </div>
            </section>

            <aside class="pb-side" aria-label="Bill summary">
                <div class="pb-card pb-sum" id="pb-sum"
                     data-received="{{ $pv['received'] }}" data-returns="{{ $returnsTotal }}" data-has-returns="{{ empty($returns) ? 0 : 1 }}"
                     data-credit-separate="{{ $pv['credit']['separate'] }}" data-credit-netted="{{ $pv['credit']['netted'] }}">
                    <h2>Bill summary</h2>
                    <dl class="pb-rows">
                        <dt>Received value</dt><dd>{{ $money($pv['received']) }}</dd>
                        <dt data-row="disc" hidden>Discount</dt><dd data-row="disc" data-v="disc" hidden></dd>
                        <dt data-row="tax" hidden>Tax</dt><dd data-row="tax" data-v="tax" hidden></dd>
                        @if(! empty($returns))
                            <dt>Return adjustment</dt><dd data-v="adj">—</dd>
                            <dd class="pb-sub" data-v="adj-note">Choose how the invoice treats the return.</dd>
                        @endif
                        <dt class="pb-inv">Actual supplier invoice</dt>
                        <dd class="pb-inv"><b id="bill-total-preview" data-base="{{ $pv['received'] }}">{{ empty($returns) ? $money($pv['received'] - (float) old('discount_amount', 0) + (float) old('tax_amount', 0)) : '—' }}</b></dd>
                        <dt>Paid now <span class="pb-muted">(supplier credit)</span></dt><dd data-v="paid">—</dd>
                        <dt class="pb-rem">Remaining</dt><dd class="pb-rem" data-v="rem">—</dd>
                    </dl>
                    <button type="button" class="pb-btn" id="pb-review">Review bill</button>
                    <a href="{{ url('/purchase-bills') }}" class="pb-btn2">Cancel</a>
                </div>
            </aside>
        </div>

        {{-- Review: everything once more; a credit moving off another bill is said in words and must be confirmed. --}}
        <div class="pb-rv" id="pb-rv" hidden role="dialog" aria-modal="true" aria-labelledby="pb-rv-title">
            <div class="pb-rv-box">
                <h2 id="pb-rv-title">Review bill — {{ $goodsReceipt->supplier?->name }}</h2>
                <dl class="pb-rows" id="pb-rv-rows"></dl>
                @if($moves->isNotEmpty() && ! $pv['blocked'])
                <div class="pb-move" id="pb-rv-move" hidden>
                    @foreach($moves as $m)
                        <div><b>{{ $m['bill_no'] }}</b> se Rs {{ $money($m['amount']) }} allocation hatay ga; uska outstanding {{ $money($m['due_now']) }} se {{ $money($m['due_after']) }} ho jayega.</div>
                    @endforeach
                    @if($canReallocate)
                        <label for="confirm_reallocation"><input type="checkbox" class="form-check-input mt-1" name="confirm_reallocation" id="confirm_reallocation" value="1">
                            Confirm — ye credit upar likhe bill se hata kar is invoice par lagaya jaye. (Mere naam se record hoga.)</label>
                    @else
                        <div class="pb-block" style="margin-top:8px">Only someone who can void supplier payments can move a credit between bills. Choose "Sab maal ka invoice", or ask them.</div>
                    @endif
                    @error('confirm_reallocation') <div class="pb-err">{{ $message }}</div> @enderror
                </div>
                @endif
                <div class="pb-rv-actions">
                    <button type="submit" class="pb-btn" id="pb-post">Post bill</button>
                    <button type="button" class="pb-btn2" id="pb-back">Back to edit</button>
                </div>
            </div>
        </div>
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
    var hasReturns = sum.dataset.hasReturns === '1';
    var CAN_MOVE = @json((bool) ($canReallocate ?? false));
    var MOVES = @json($moves->isNotEmpty() && ! ($pv['blocked'] ?? false));
    function num(id) { var el = document.getElementById(id); var n = parseFloat(String(el && el.value || '0').replace(/,/g, '')); return isFinite(n) ? n : 0; }
    function money(v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function choice() { var c = form.querySelector('input[name="returns_on_invoice"]:checked'); return c ? c.value : null; }
    function set(k, text) { sum.querySelectorAll('[data-v="' + k + '"]').forEach(function (el) { el.textContent = text; }); }
    function row(k, show) { sum.querySelectorAll('[data-row="' + k + '"]').forEach(function (el) { el.hidden = !show; }); }

    // What the server will post — the same rules: the invoice is the received value, less discount, plus tax, less the
    // returns only when the invoice is already less them. The return is subtracted ONCE: either from the invoice
    // (netted) or as credit paying it (separate) — never both.
    function calc() {
        var c = hasReturns ? choice() : 'none';
        var disc = num('discount_amount'), tax = num('tax_amount');
        row('disc', disc > 0); set('disc', '− ' + money(disc));
        row('tax', tax > 0); set('tax', '+ ' + money(tax));
        form.querySelectorAll('.pb-opt').forEach(function (o) { o.classList.toggle('on', !!o.querySelector('input:checked')); });
        if (hasReturns && !c) {
            out.textContent = '—'; set('paid', '—'); set('rem', '—'); set('adj', '—');
            set('adj-note', 'Choose how the invoice treats the return.');
            return null;
        }
        var returns = Number(sum.dataset.returns || 0);
        var invoice = Number(sum.dataset.received || 0) - disc + tax - (c === 'netted' ? returns : 0);
        var credit = Number(c === 'netted' ? sum.dataset.creditNetted : sum.dataset.creditSeparate) || 0;
        var paid = Math.max(0, Math.min(credit, invoice));
        if (hasReturns) {
            set('adj', c === 'netted' ? '− ' + money(returns) : money(returns));
            set('adj-note', c === 'netted' ? 'Taken off by the supplier on this invoice.' : 'Not in the invoice — it is a credit, used below.');
        }
        out.textContent = money(invoice); set('paid', paid > 0 ? '− ' + money(paid) : '0.00'); set('rem', money(invoice - paid));
        return { c: c, invoice: invoice, paid: paid };
    }

    // Numbers: the whole value is selected on focus, so typing replaces it (no backspacing).
    form.querySelectorAll('.pb-sel').forEach(function (el) {
        // only while it still has focus: select() also focuses, and a quick Tab onward must not be pulled back here
        el.addEventListener('focus', function () { setTimeout(function () { if (document.activeElement === el) el.select(); }, 0); });
        el.addEventListener('input', calc);
    });
    form.querySelectorAll('input[name="returns_on_invoice"]').forEach(function (el) { el.addEventListener('change', calc); });

    var rv = document.getElementById('pb-rv'), reviewBtn = document.getElementById('pb-review');
    function openReview() {
        var r = calc();
        if (!r) {
            var first = form.querySelector('input[name="returns_on_invoice"]:not([disabled])');
            if (first) first.focus();
            var box = document.getElementById('returns-on-invoice'); if (box) box.scrollIntoView({ block: 'center' });
            return;
        }
        var rows = [['Received value', money(Number(sum.dataset.received || 0))]];
        if (num('discount_amount') > 0) rows.push(['Discount', '− ' + money(num('discount_amount'))]);
        if (num('tax_amount') > 0) rows.push(['Tax', '+ ' + money(num('tax_amount'))]);
        if (hasReturns) rows.push(['Return adjustment', r.c === 'netted' ? '− ' + money(Number(sum.dataset.returns)) + ' (on the invoice)' : money(Number(sum.dataset.returns)) + ' (credit)']);
        rows.push(['Actual supplier invoice', money(r.invoice)], ['Paid now (supplier credit)', r.paid > 0 ? '− ' + money(r.paid) : '0.00'], ['Remaining', money(r.invoice - r.paid)]);
        var dl = document.getElementById('pb-rv-rows'); dl.innerHTML = '';
        rows.forEach(function (x, i) {
            var dt = document.createElement('dt'), dd = document.createElement('dd');
            dt.textContent = x[0]; dd.textContent = x[1];
            if (i === rows.length - 1) { dt.className = dd.className = 'pb-rem'; } else if (x[0] === 'Actual supplier invoice') { dt.className = dd.className = 'pb-inv'; }
            dl.appendChild(dt); dl.appendChild(dd);
        });
        var move = document.getElementById('pb-rv-move'), post = document.getElementById('pb-post'), ok = document.getElementById('confirm_reallocation');
        var moving = MOVES && r.c === 'netted';
        if (move) move.hidden = !moving;
        if (ok && !moving) ok.checked = false;
        post.disabled = moving && (!CAN_MOVE || !(ok && ok.checked));
        rv.hidden = false;
        (moving && ok ? ok : post).focus();
    }
    function closeReview() { rv.hidden = true; reviewBtn.focus(); }
    reviewBtn.addEventListener('click', openReview);
    document.getElementById('pb-back').addEventListener('click', closeReview);
    var ok = document.getElementById('confirm_reallocation');
    if (ok) ok.addEventListener('change', function () { document.getElementById('pb-post').disabled = !ok.checked; });
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
        // posting: a second click or Enter cannot send the bill twice (the server refuses a second bill anyway)
        var post = document.getElementById('pb-post');
        setTimeout(function () { post.disabled = true; post.textContent = 'Posting…'; }, 0);
    });
    calc();
    @if($errors->has('confirm_reallocation'))
    openReview();
    @endif
})();
</script>
@endpush
