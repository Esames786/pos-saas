@php
    $editing = isset($expenseVoucher);
    $v = $expenseVoucher ?? null;

    $rows = old('lines');
    if (! $rows) {
        if ($editing) {
            $rows = $v->lines->map(fn ($l) => [
                'expense_category_id' => $l->expense_category_id,
                'description'         => $l->description,
                'amount'              => $l->amount,
                'tax_amount'          => $l->tax_amount,
            ])->all();
        } else {
            $rows = [['expense_category_id' => '', 'description' => '', 'amount' => '', 'tax_amount' => '']];
        }
    }
@endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Expense' : 'New Expense')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit Expense ' . $v->voucher_no : 'New Expense' }}</h4>
                <h6>Finance — Expense Voucher</h6>
            </div>
        </div>

        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ $editing ? url('/finance/expenses/' . $v->id) : url('/finance/expenses') }}">
            @csrf
            @if($editing) @method('PUT') @endif

            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="branch_id">Branch</label>
                            <select id="branch_id" name="branch_id" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" {{ (int) old('branch_id', $v->branch_id ?? 0) === $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="cash_bank_account_id">Pay From (Cash/Bank)</label>
                            <select id="cash_bank_account_id" name="cash_bank_account_id" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($cashBankAccounts as $cb)
                                    <option value="{{ $cb->id }}" {{ (int) old('cash_bank_account_id', $v->cash_bank_account_id ?? 0) === $cb->id ? 'selected' : '' }}>{{ $cb->code }} — {{ $cb->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="expense_date">Expense Date</label>
                            <input type="date" id="expense_date" name="expense_date" class="form-control"
                                value="{{ old('expense_date', $editing ? optional($v->expense_date)->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="payment_date">Payment Date</label>
                            <input type="date" id="payment_date" name="payment_date" class="form-control"
                                value="{{ old('payment_date', $editing ? optional($v->payment_date)->format('Y-m-d') : '') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="payee_name">Payee</label>
                            <input type="text" id="payee_name" name="payee_name" class="form-control" value="{{ old('payee_name', $v->payee_name ?? '') }}">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="voucher_no">Voucher No <small class="text-muted">(auto if blank)</small></label>
                            <input type="text" id="voucher_no" name="voucher_no" class="form-control" value="{{ old('voucher_no', $v->voucher_no ?? '') }}">
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label" for="notes">Notes</label>
                            <input type="text" id="notes" name="notes" class="form-control" value="{{ old('notes', $v->notes ?? '') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Expense Lines</h6>
                    <div class="table-responsive">
                        <table class="table" id="lineTable">
                            <caption class="visually-hidden">Expense lines</caption>
                            <thead class="thead-light">
                                <tr>
                                    <th scope="col" style="width:28%">Category</th>
                                    <th scope="col">Description</th>
                                    <th scope="col" style="width:15%">Amount</th>
                                    <th scope="col" style="width:15%">Tax</th>
                                    <th scope="col" style="width:5%"></th>
                                </tr>
                            </thead>
                            <tbody id="lineRows">
                                @foreach($rows as $i => $row)
                                <tr>
                                    <td>
                                        <select name="lines[{{ $i }}][expense_category_id]" class="form-select" required>
                                            <option value="">— Category —</option>
                                            @foreach($categories as $c)
                                                <option value="{{ $c->id }}" {{ (int) ($row['expense_category_id'] ?? 0) === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="text" name="lines[{{ $i }}][description]" class="form-control" value="{{ $row['description'] ?? '' }}"></td>
                                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][amount]" class="form-control" value="{{ $row['amount'] ?? '' }}" required></td>
                                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][tax_amount]" class="form-control" value="{{ $row['tax_amount'] ?? '' }}"></td>
                                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="ti ti-x"></i></button></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addRow"><i class="ti ti-plus me-1"></i>Add Line</button>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Update Draft' : 'Save Draft' }}</button>
                <a href="{{ url('/finance/expenses') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>

        <template id="rowTemplate">
            <tr>
                <td>
                    <select name="lines[__INDEX__][expense_category_id]" class="form-select" required>
                        <option value="">— Category —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </td>
                <td><input type="text" name="lines[__INDEX__][description]" class="form-control"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[__INDEX__][amount]" class="form-control" required></td>
                <td><input type="number" step="0.0001" min="0" name="lines[__INDEX__][tax_amount]" class="form-control"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="ti ti-x"></i></button></td>
            </tr>
        </template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var rows = document.getElementById('lineRows');
    var tpl = document.getElementById('rowTemplate');
    var addBtn = document.getElementById('addRow');
    var idx = {{ count($rows) }};

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var html = tpl.innerHTML.replace(/__INDEX__/g, idx++);
            rows.insertAdjacentHTML('beforeend', html);
        });
    }

    rows.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-row');
        if (!btn) return;
        if (rows.querySelectorAll('tr').length > 1) {
            btn.closest('tr').remove();
        }
    });
});
</script>
@endpush
