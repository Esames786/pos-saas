@extends('layouts.app')

@section('title', 'Create Supplier Payment')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Supplier Payment</h1>
        <p class="fw-medium text-muted mb-0">Step 4 of the purchasing flow — pay the supplier.</p>
    </div>
    <a href="{{ url('/supplier-payments') }}" class="btn btn-light">Back</a>
</div>

<div class="card border-primary-subtle mb-3">
    <div class="card-body d-flex flex-wrap align-items-start gap-3 py-2">
        <span class="badge bg-primary-subtle text-primary-emphasis mt-1"><i class="ti ti-cash me-1"></i>Reduces payable</span>
        <div class="small">
            A <strong>Supplier Payment</strong> reduces the supplier's payable balance and updates the supplier ledger.
            Choose <strong>Pay From (Cash/Bank)</strong> only if money actually leaves that account.
            <div class="text-muted mt-1">Flow: PO → GRN → Purchase Bill → <strong>Supplier Payment</strong>.</div>
        </div>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

{{-- Live payment summary --}}
<div class="card mb-3" id="payment-summary">
    <div class="card-body py-2">
        <div class="row text-center g-2 small">
            <div class="col-6 col-md-3"><div class="text-muted">Supplier Balance</div><div class="fw-bold fs-6" id="sum-supplier">—</div></div>
            <div class="col-6 col-md-3"><div class="text-muted">Selected Bill Outstanding</div><div class="fw-bold fs-6" id="sum-bill">—</div></div>
            <div class="col-6 col-md-3"><div class="text-muted">This Payment</div><div class="fw-bold fs-6 text-primary" id="sum-amount">0.00</div></div>
            <div class="col-6 col-md-3"><div class="text-muted">Remaining After</div><div class="fw-bold fs-6" id="sum-remaining">—</div></div>
        </div>
        <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small d-none" id="cashbank-warn">
            <i class="ti ti-alert-triangle me-1"></i>No Cash/Bank account selected for a <span id="cashbank-method"></span> payment — this will record the supplier ledger only, with <strong>no cash/bank movement</strong>. Select an account above if money actually left one.
        </div>
    </div>
</div>

<form method="POST" action="{{ url('/supplier-payments') }}" novalidate>
    @csrf

    <div class="card">
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="supplier_id" class="form-label required">Supplier</label>
                <select id="supplier_id" name="supplier_id"
                        class="form-select @error('supplier_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}"
                                data-balance="{{ (float) $supplier->current_balance }}"
                                @selected(old('supplier_id', $bill?->supplier_id) == $supplier->id)>
                            {{ $supplier->name }}
                            (Balance: {{ number_format($supplier->current_balance, 2) }})
                        </option>
                    @endforeach
                </select>
                @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="purchase_bill_id" class="form-label">Against Bill</label>
                <select id="purchase_bill_id" name="purchase_bill_id"
                        class="form-select @error('purchase_bill_id') is-invalid @enderror">
                    <option value="">No specific bill (general payment)</option>
                    @foreach($bills as $payableBill)
                        <option value="{{ $payableBill->id }}"
                                data-balance="{{ (float) $payableBill->balance_due }}"
                                @selected(old('purchase_bill_id', $bill?->id) == $payableBill->id)>
                            {{ $payableBill->bill_no }} — {{ $payableBill->supplier?->name }}
                            — Balance: {{ number_format($payableBill->balance_due, 2) }}
                        </option>
                    @endforeach
                </select>
                @error('purchase_bill_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="branch_id" class="form-label required">Branch</label>
                <select id="branch_id" name="branch_id"
                        class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}"
                                @selected(old('branch_id', $bill?->branch_id) == $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="payment_date" class="form-label required">Payment Date</label>
                <input id="payment_date" type="date" name="payment_date" required
                       class="form-control @error('payment_date') is-invalid @enderror"
                       value="{{ old('payment_date', now()->toDateString()) }}">
                @error('payment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="cash_bank_account_id" class="form-label">Pay From (Cash/Bank)</label>
                <select id="cash_bank_account_id" name="cash_bank_account_id"
                        class="form-select @error('cash_bank_account_id') is-invalid @enderror">
                    <option value="">— None (no cash/bank effect) —</option>
                    @foreach($cashBankAccounts as $cba)
                        <option value="{{ $cba->id }}" @selected(old('cash_bank_account_id') == $cba->id)>
                            {{ $cba->code }} — {{ $cba->name }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">Select to deduct this payment from a cash/bank balance.</small>
                @error('cash_bank_account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="payment_method" class="form-label required">Payment Method</label>
                <select id="payment_method" name="payment_method"
                        class="form-select @error('payment_method') is-invalid @enderror" required>
                    <option value="cash"          @selected(old('payment_method', 'cash') === 'cash')>Cash</option>
                    <option value="bank_transfer" @selected(old('payment_method') === 'bank_transfer')>Bank Transfer</option>
                    <option value="cheque"        @selected(old('payment_method') === 'cheque')>Cheque</option>
                    <option value="card"          @selected(old('payment_method') === 'card')>Card</option>
                    <option value="other"         @selected(old('payment_method') === 'other')>Other</option>
                </select>
                @error('payment_method') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="amount" class="form-label required">Amount</label>
                <input id="amount" type="number" step="0.01" min="0.01" name="amount" required
                       class="form-control @error('amount') is-invalid @enderror"
                       value="{{ old('amount', $bill?->balance_due) }}">
                @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="reference_no" class="form-label">Reference No</label>
                <input id="reference_no" name="reference_no"
                       class="form-control @error('reference_no') is-invalid @enderror"
                       value="{{ old('reference_no') }}"
                       placeholder="Transaction / receipt ref">
                @error('reference_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="bank_name" class="form-label">Bank Name</label>
                <input id="bank_name" name="bank_name" class="form-control" value="{{ old('bank_name') }}">
            </div>

            <div class="col-md-3">
                <label for="account_no" class="form-label">Account No</label>
                <input id="account_no" name="account_no" class="form-control" value="{{ old('account_no') }}">
            </div>

            <div class="col-md-3">
                <label for="transaction_ref" class="form-label">Transaction Ref</label>
                <input id="transaction_ref" name="transaction_ref" class="form-control" value="{{ old('transaction_ref') }}">
            </div>

            <div class="col-md-3">
                <label for="cheque_no" class="form-label">Cheque No</label>
                <input id="cheque_no" name="cheque_no" class="form-control" value="{{ old('cheque_no') }}">
            </div>

            <div class="col-md-3">
                <label for="cheque_date" class="form-label">Cheque Date</label>
                <input id="cheque_date" type="date" name="cheque_date" class="form-control" value="{{ old('cheque_date') }}">
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit"
                        onclick="return confirm('Post this supplier payment?')">Post Payment</button>
                <a href="{{ url('/supplier-payments') }}" class="btn btn-light ms-2">Cancel</a>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var supplier = document.getElementById('supplier_id');
    var billSel  = document.getElementById('purchase_bill_id');
    var amount   = document.getElementById('amount');
    var method   = document.getElementById('payment_method');
    var cashBank = document.getElementById('cash_bank_account_id');

    function optNum(sel){ var o = sel && sel.options[sel.selectedIndex]; var v = o ? o.getAttribute('data-balance') : null; return v==null?null:Number(v); }
    function fmt(n){ return (Math.round(n*100)/100).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }

    function refresh(){
        var supBal = optNum(supplier);
        var billBal = optNum(billSel);
        var amt = Number(amount && amount.value || 0);
        document.getElementById('sum-supplier').textContent = supBal==null ? '—' : fmt(supBal);
        document.getElementById('sum-bill').textContent = billBal==null ? '—' : fmt(billBal);
        document.getElementById('sum-amount').textContent = fmt(amt);
        // Remaining = bill outstanding if a bill selected, else supplier balance, minus amount.
        var base = billBal!=null ? billBal : supBal;
        var remEl = document.getElementById('sum-remaining');
        if (base==null){ remEl.textContent='—'; remEl.className='fw-bold fs-6'; }
        else { var rem = base - amt; remEl.textContent = fmt(rem); remEl.className = 'fw-bold fs-6 ' + (rem < -0.01 ? 'text-danger' : 'text-success'); }

        // Cash/bank warning: money-moving methods with no account selected.
        var moneyMethods = ['cash','bank_transfer','cheque','card'];
        var needsAccount = method && moneyMethods.indexOf(method.value) !== -1;
        var noAccount = !cashBank || !cashBank.value;
        var warn = document.getElementById('cashbank-warn');
        if (warn){
            warn.classList.toggle('d-none', !(needsAccount && noAccount));
            var m = document.getElementById('cashbank-method'); if (m && method) m.textContent = method.options[method.selectedIndex].text;
        }
    }
    [supplier, billSel, amount, method, cashBank].forEach(function(el){ if(el){ el.addEventListener('change', refresh); el.addEventListener('input', refresh); } });
    refresh();
})();
</script>
@endpush
@endsection
