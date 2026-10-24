{{--
    PURCHASE-RETURN-V2 — the new purchase-return page (Master switch "New purchase screens").

    One page, three ways in: from a GRN (its lines with what can still go back), without a bill (search an item,
    pick its batch), or both. Quantities in Box / Strip / Tablet; the stock leaves the batch picked; a reason on
    each line; "This supplier's expired stock" brings every expired batch in at once. Save a draft, or post it.
--}}
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Supplier Return <span class="text-muted fw-normal fs-5" lang="ur">سپلائر کو واپسی</span></h1>
        <p class="fw-medium text-muted mb-0">Box / Strip / Tablet, from the batch it goes back from. Save a draft, or post it at once.</p>
    </div>
    <a href="{{ url('/purchase-returns') }}" class="btn btn-light">Back</a>
</div>

@include('tenant.purchasing.partials.how-to-use', ['guide' => [
    'what' => 'Send goods back to a supplier. Stock goes down and the supplier owes you for them (Supplier Credit).',
    'steps' => ['Choose the supplier, and the Goods Receipt the goods came on if you know it.', 'Choose each item and the quantity going back (and its batch).', 'Give the reason, then save as a draft or post.'],
    'effects' => ['Stock' => 'Goes down by what goes back, from the batch picked.', 'Supplier balance' => 'Goes down by the value of the return — a Supplier Credit.', 'Payments' => 'None. The credit pays the supplier\'s open invoices automatically, or the supplier deducts it on their next invoice.'],
    'example' => '2 boxes at Rs 100 go back: stock goes down by 2 boxes and the supplier owes you Rs 200, which pays Rs 200 of an open invoice.',
    'blocked' => ['More than was received on that Goods Receipt.', 'More than the batch holds.'],
]])

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/purchase-returns') }}" novalidate id="pret-form">
    @csrf
    <div class="card mb-3">
        <div class="card-header"><strong>Who and where</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                @include('tenant.purchasing.partials.supplier-box', ['selectedSupplierId' => old('supplier_id', $goodsReceipt?->supplier_id), 'mode' => 'return', 'currentPoId' => null])
            </div>
            <div class="col-md-3">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id" class="form-select" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id', $goodsReceipt?->branch_id ?? ($branches->count() === 1 ? $branches->first()->id : 0)) === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="return_date" class="form-label required">Date</label>
                <input id="return_date" type="date" name="return_date" class="form-control" required value="{{ old('return_date', now()->toDateString()) }}">
            </div>
            <div class="col-md-3">
                <label for="reason_code" class="form-label">Reason for all lines <span class="text-muted small">(each line can differ)</span></label>
                <select id="reason_code" name="reason_code" class="form-select">
                    <option value="">— pick per line —</option>
                    @foreach(['expired' => 'Expired', 'damaged' => 'Damaged / broken', 'wrong_item' => 'Wrong item', 'over_supply' => 'Too many sent', 'quality_issue' => 'Quality problem', 'price_dispute' => 'Price problem', 'other' => 'Other'] as $code => $word)
                        <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $word }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="ret-grn" class="form-label">From a goods receipt <span class="text-muted small">— optional</span></label>
                <select id="ret-grn" class="form-select">
                    <option value="">— no receipt: pick items below —</option>
                    @if($goodsReceipt)<option value="{{ $goodsReceipt->id }}" selected>{{ $goodsReceipt->grn_no }} · {{ $goodsReceipt->receipt_date?->format('d M') }}</option>@endif
                </select>
                @if($goodsReceipt)<input type="hidden" name="goods_receipt_id" value="{{ $goodsReceipt->id }}">@endif
            </div>
            <div class="col-md-8">
                <label for="notes" class="form-label">Notes</label>
                <input id="notes" name="notes" class="form-control" value="{{ old('notes') }}" placeholder="e.g. collected by the distributor's rider">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Items going back</strong></div>
        <div class="card-body">
            @include('tenant.purchasing.partials.entry-area', ['mode' => 'return', 'prefillLines' => $grnLines, 'purchaseOrder' => null, 'goodsReceipt' => $goodsReceipt])
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-primary" type="submit" name="_post" value="1" id="ret-post-btn">Post return</button>
        <button class="btn btn-light" type="submit" name="_post" value="0" id="ret-save-btn">Save as draft</button>
        <a href="{{ url('/purchase-returns') }}" class="btn btn-link">Cancel</a>
        <span class="small text-muted">Posting takes the stock out of those batches and lowers what you owe this supplier. It cannot be changed after.</span>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var GRNS = @json($supplierGrns), CURRENT = @json($goodsReceipt?->id);
    var sel = document.getElementById('supplier_id'), branch = document.getElementById('branch_id'), grn = document.getElementById('ret-grn');
    var CREATE = @json(url('/purchase-returns/create'));
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    // This supplier's last receipts (this branch first) — picking one opens its lines; typed lines are kept.
    function fill() {
        var rows = (GRNS[sel.value] || []).filter(function (g) { return !branch.value || String(g.branch) === String(branch.value); });
        grn.innerHTML = '<option value="">— no receipt: pick items below —</option>' + rows.map(function (g) {
            return '<option value="' + g.id + '"' + (String(g.id) === String(CURRENT) ? ' selected' : '') + '>' + esc(g.no) + ' · ' + esc(g.date || '') + '</option>';
        }).join('');
    }
    sel.addEventListener('change', fill); branch.addEventListener('change', fill); fill();
    grn.addEventListener('change', function () {
        if (String(grn.value) === String(CURRENT || '')) return;
        location.href = CREATE + (grn.value ? '?goods_receipt_id=' + grn.value : '');
    });
    // Posting is checked in the entry area's preview (Ctrl+S or the buttons): everything once more, Enter posts.

})();
</script>
@endpush
