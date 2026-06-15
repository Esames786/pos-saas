@extends('layouts.app')

@section('title', 'Record Customer Payment')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Record Customer Payment</h4>
                <h6>Reduces the customer receivable; optionally increases a cash/bank balance</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/finance/customer-payments') }}" class="btn btn-secondary">Back</a>
            </div>
        </div>

        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ url('/finance/customer-payments') }}">
            @csrf
            <div class="card">
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label required" for="customer_id">Customer</label>
                        <select id="customer_id" name="customer_id" class="form-select" required>
                            <option value="">— Select —</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}" {{ (int) old('customer_id', $selectedCustomer ?? 0) === $c->id ? 'selected' : '' }}>
                                    {{ $c->name }} (Balance: {{ number_format((float) $c->current_balance, 2) }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="sales_order_id">Against Sale (optional)</label>
                        <select id="sales_order_id" name="sales_order_id" class="form-select">
                            <option value="">On account (no specific sale)</option>
                            @foreach($openOrders as $o)
                                <option value="{{ $o->id }}" data-customer="{{ $o->customer_id }}" {{ (int) old('sales_order_id') === $o->id ? 'selected' : '' }}>
                                    {{ $o->sale_no }} — Due: {{ number_format((float) $o->balance_due, 2) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ (int) old('branch_id') === $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label required" for="payment_date">Payment Date</label>
                        <input type="date" id="payment_date" name="payment_date" class="form-control" value="{{ old('payment_date', now()->toDateString()) }}" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label required" for="amount">Amount</label>
                        <input type="number" step="0.0001" min="0.01" id="amount" name="amount" class="form-control" value="{{ old('amount') }}" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="cash_bank_account_id">Deposit To (Cash/Bank)</label>
                        <select id="cash_bank_account_id" name="cash_bank_account_id" class="form-select">
                            <option value="">— None (no cash/bank effect) —</option>
                            @foreach($cashBankAccounts as $cba)
                                <option value="{{ $cba->id }}" {{ (int) old('cash_bank_account_id') === $cba->id ? 'selected' : '' }}>{{ $cba->code }} — {{ $cba->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="payment_method">Method</label>
                        <select id="payment_method" name="payment_method" class="form-select">
                            @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other'] as $v => $l)
                                <option value="{{ $v }}" {{ old('payment_method', 'cash') === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="reference_no">Reference No</label>
                        <input type="text" id="reference_no" name="reference_no" class="form-control" value="{{ old('reference_no') }}">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label" for="notes">Notes</label>
                        <input type="text" id="notes" name="notes" class="form-control" value="{{ old('notes') }}">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Record this customer payment?')">Record Payment</button>
                        <a href="{{ url('/finance/customer-payments') }}" class="btn btn-secondary ms-2">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
@endsection
